<?php
/**
 * Справочник покупателей RetailCRM: локальный снимок базы клиентов.
 *
 * Задача — связать старые аккаунты сайта (почта, без телефона) с новыми,
 * созданными при входе по SMS (телефон, без почты). Нужную связку CRM уже
 * хранит: плагин Simla выгружает покупателя с externalId, равным ID
 * пользователя WordPress, и с его телефоном (class-wc-retailcrm-customers.php).
 * Значит в CRM лежит готовая тройка «телефон → почта → ID старого аккаунта».
 *
 * Спрашивать CRM в момент входа — по запросу на каждый незнакомый номер,
 * а хостинг у магазина общий. Поэтому база один раз выгружается в свою
 * таблицу небольшими пачками по расписанию, и дальше сопоставление идёт
 * локально, без обращений к API.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Снимок базы покупателей CRM.
 */
class VL_Account_RetailCRM_Directory {

	/**
	 * Версия схемы таблицы.
	 */
	const DB_VERSION = '2';

	/**
	 * Опция с версией схемы.
	 */
	const DB_OPTION = 'vlacc_crm_directory_db';

	/**
	 * Опция с состоянием выгрузки.
	 */
	const STATE = 'vlacc_crm_sync_state';

	/**
	 * Хук фоновой выгрузки.
	 */
	const CRON = 'vlacc_crm_sync_batch';

	/**
	 * Сколько клиентов запрашивать за один запрос к API.
	 */
	const PAGE_SIZE = 100;

	/**
	 * Сколько запросов делать за один заход планировщика.
	 */
	const PAGES_PER_RUN = 3;

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_RetailCRM_Directory|null
	 */
	private static $instance = null;

	/**
	 * Кэш поиска по телефону в пределах запроса.
	 *
	 * @var array
	 */
	private static $lookup_cache = array();

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_RetailCRM_Directory
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		add_action( self::CRON, array( __CLASS__, 'run_batch' ) );
		add_action( 'vlacc_crm_sync_daily', array( __CLASS__, 'start_daily' ) );

		if ( VL_Account_Settings::get( 'crm_sync_daily', 0 ) && ! wp_next_scheduled( 'vlacc_crm_sync_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vlacc_crm_sync_daily' );
		}

		// Обновление плагина заменой папки не вызывает активацию — таблицу
		// справочника создаём здесь, проверка стоит одного get_option.
		if ( is_admin() && self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/* ------------------------------------------------------------------
	 * Таблица
	 * ------------------------------------------------------------------ */

	/**
	 * Имя таблицы.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'vlacc_crm_customers';
	}

	/**
	 * Создать таблицу.
	 */
	public static function install() {
		global $wpdb;

		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			crm_id bigint(20) unsigned NOT NULL DEFAULT 0,
			external_id bigint(20) unsigned NOT NULL DEFAULT 0,
			phone varchar(20) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			subscribed tinyint(1) NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'new',
			note varchar(255) NOT NULL DEFAULT '',
			crm_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY crm_phone (crm_id,phone),
			KEY phone (phone),
			KEY email (email),
			KEY external_id (external_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Таблица существует.
	 *
	 * @return bool
	 */
	public static function ready() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/* ------------------------------------------------------------------
	 * Выгрузка базы
	 * ------------------------------------------------------------------ */

	/**
	 * Состояние выгрузки.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'running'  => 0,
				'page'     => 1,
				'pages'    => 0,
				'fetched'  => 0,
				'started'  => 0,
				'finished' => 0,
				'error'    => '',
			)
		);
	}

	/**
	 * Записать состояние.
	 *
	 * @param array $values Значения.
	 */
	protected static function set_state( $values ) {
		update_option( self::STATE, wp_parse_args( $values, self::state() ), false );
	}

	/**
	 * Запустить выгрузку с начала.
	 *
	 * @return true|WP_Error
	 */
	public static function start() {
		if ( ! VL_Account_RetailCRM::enabled() ) {
			return new WP_Error( 'vlacc_crm_off', __( 'Интеграция с CRM выключена или не настроена.', 'vl-account' ) );
		}

		self::install();

		self::set_state(
			array(
				'running'  => 1,
				'page'     => 1,
				'pages'    => 0,
				'fetched'  => 0,
				'started'  => time(),
				'finished' => 0,
				'error'    => '',
			)
		);

		vlacc_log( 'Запущена сверка базы покупателей CRM' );

		self::schedule_next( 5 );

		return true;
	}

	/**
	 * Ежедневное обновление снимка.
	 */
	public static function start_daily() {
		if ( ! VL_Account_Settings::get( 'crm_sync_daily', 0 ) ) {
			return;
		}

		$state = self::state();

		// Предыдущая выгрузка ещё идёт — новую не начинаем.
		if ( ! empty( $state['running'] ) ) {
			return;
		}

		self::start();
	}

	/**
	 * Остановить выгрузку.
	 */
	public static function stop() {
		self::set_state( array( 'running' => 0 ) );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Поставить следующий заход.
	 *
	 * @param int $delay Задержка в секундах.
	 */
	protected static function schedule_next( $delay = 60 ) {
		if ( wp_next_scheduled( self::CRON ) ) {
			return;
		}

		wp_schedule_single_event( time() + (int) $delay, self::CRON );
	}

	/**
	 * Очередная пачка клиентов.
	 *
	 * Специально маленькими порциями: несколько запросов за заход, заход раз
	 * в минуту. База в несколько тысяч клиентов уезжает за четверть часа и
	 * не мешает работе сайта.
	 */
	public static function run_batch() {
		$state = self::state();

		if ( empty( $state['running'] ) ) {
			return;
		}

		$api = VL_Account_RetailCRM::api();

		if ( ! $api ) {
			self::set_state(
				array(
					'running' => 0,
					'error'   => __( 'Нет связи с CRM.', 'vl-account' ),
				)
			);

			return;
		}

		self::install();

		$page    = max( 1, (int) $state['page'] );
		$fetched = (int) $state['fetched'];
		$pages   = (int) $state['pages'];
		$done    = false;

		for ( $i = 0; $i < self::PAGES_PER_RUN; $i++ ) {
			$response = $api->customersList( array(), $page, self::PAGE_SIZE );

			if ( ! VL_Account_RetailCRM::ok( $response ) ) {
				self::set_state(
					array(
						'running' => 0,
						'page'    => $page,
						'error'   => __( 'CRM вернула ошибку при выгрузке клиентов.', 'vl-account' ),
					)
				);

				vlacc_log( 'Сверка с CRM остановлена: ошибка API', array( 'page' => $page ) );

				return;
			}

			$customers = $response->offsetExists( 'customers' ) ? (array) $response['customers'] : array();
			$pagination = $response->offsetExists( 'pagination' ) ? (array) $response['pagination'] : array();
			$pages      = isset( $pagination['totalPageCount'] ) ? (int) $pagination['totalPageCount'] : $pages;

			foreach ( $customers as $customer ) {
				$fetched += self::store( $customer );
			}

			if ( ! $customers || ( $pages && $page >= $pages ) ) {
				$done = true;
				break;
			}

			++$page;
		}

		self::set_state(
			array(
				'page'    => $done ? $page : $page + 1,
				'pages'   => $pages,
				'fetched' => $fetched,
			)
		);

		if ( $done ) {
			self::match_all();
			self::flush_cache();

			self::set_state(
				array(
					'running'  => 0,
					'finished' => time(),
				)
			);

			vlacc_log( 'Сверка базы покупателей CRM завершена', array( 'записей' => $fetched ) );

			return;
		}

		self::schedule_next( 60 );
	}

	/**
	 * Записать клиента CRM в справочник.
	 *
	 * @param array $customer Клиент из CRM.
	 * @return int Сколько строк записано.
	 */
	public static function store( $customer ) {
		global $wpdb;

		if ( ! is_array( $customer ) ) {
			return 0;
		}

		$crm_id = isset( $customer['id'] ) ? (int) $customer['id'] : 0;

		if ( ! $crm_id ) {
			return 0;
		}

		$phones = array();

		foreach ( (array) ( isset( $customer['phones'] ) ? $customer['phones'] : array() ) as $phone ) {
			$number = is_array( $phone ) ? ( isset( $phone['number'] ) ? $phone['number'] : '' ) : $phone;
			$number = VL_Account_Phone::normalize( $number );

			if ( '' !== $number ) {
				$phones[ $number ] = $number;
			}
		}

		// Клиента без телефона тоже держим: по нему сработает поиск по почте.
		if ( ! $phones ) {
			$phones[''] = '';
		}

		$row = array(
			'crm_id'      => $crm_id,
			'external_id' => isset( $customer['externalId'] ) ? (int) $customer['externalId'] : 0,
			'email'       => isset( $customer['email'] ) ? strtolower( sanitize_email( $customer['email'] ) ) : '',
			'first_name'  => isset( $customer['firstName'] ) ? sanitize_text_field( $customer['firstName'] ) : '',
			'last_name'   => isset( $customer['lastName'] ) ? sanitize_text_field( $customer['lastName'] ) : '',
			'city'        => isset( $customer['address']['city'] ) ? sanitize_text_field( $customer['address']['city'] ) : '',
			'subscribed'  => ! empty( $customer['subscribed'] ) ? 1 : 0,
			// Дата заведения карточки в CRM: по ней видно, какая из карточек
			// одного и того же человека свежее.
			'crm_created' => self::crm_date( isset( $customer['createdAt'] ) ? $customer['createdAt'] : '' ),
			'updated'     => current_time( 'mysql' ),
		);

		$saved = 0;

		foreach ( $phones as $phone ) {
			$data = array_merge( $row, array( 'phone' => $phone ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE crm_id = %d AND phone = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$crm_id,
					$phone
				)
			);

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( self::table(), $data, array( 'id' => (int) $exists ) );
			} else {
				$data['status'] = 'new';

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert( self::table(), $data );
			}

			++$saved;
		}

		return $saved;
	}

	/* ------------------------------------------------------------------
	 * Сопоставление с аккаунтами сайта
	 * ------------------------------------------------------------------ */

	/**
	 * Сопоставить весь справочник с пользователями WordPress.
	 *
	 * @return array Статистика.
	 */
	public static function match_all() {
		global $wpdb;

		if ( ! self::ready() ) {
			return array();
		}

		$stats  = array(
			'matched'  => 0,
			'no_user'  => 0,
			'conflict' => 0,
		);
		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id ASC LIMIT %d OFFSET %d', 200, $offset ),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$status = self::match_row( $row );

				if ( isset( $stats[ $status ] ) ) {
					++$stats[ $status ];
				}
			}

			$offset += 200;
		} while ( ! empty( $rows ) );

		// Один и тот же телефон у нескольких разных аккаунтов — автоматически
		// такие не трогаем, они уезжают в отчёт для ручной проверки.
		self::mark_conflicts();

		return $stats;
	}

	/**
	 * Сопоставить одну строку справочника.
	 *
	 * @param array $row Строка.
	 * @return string Статус.
	 */
	public static function match_row( $row ) {
		global $wpdb;

		$user_id = 0;
		$note    = '';

		// 1. externalId — это ID пользователя WordPress, точнее ключа нет.
		if ( ! empty( $row['external_id'] ) ) {
			$user = get_user_by( 'id', (int) $row['external_id'] );

			if ( $user ) {
				$user_id = (int) $user->ID;
				$note    = 'externalId';
			}
		}

		// 2. Почта: клиент мог быть заведён в CRM без ссылки на сайт.
		if ( ! $user_id && ! empty( $row['email'] ) ) {
			$user = get_user_by( 'email', $row['email'] );

			if ( $user ) {
				$user_id = (int) $user->ID;
				$note    = 'email';
			}
		}

		$status = $user_id ? 'matched' : 'no_user';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'user_id' => $user_id,
				'status'  => $status,
				'note'    => $note,
			),
			array( 'id' => (int) $row['id'] )
		);

		return $status;
	}

	/**
	 * Телефоны, на которых в CRM намешаны разные люди.
	 *
	 * Признак — на одном номере карточки с разными почтами или с разными
	 * externalId. Такому номеру верить нельзя: по нему нельзя ни пускать
	 * в кабинет, ни дописывать его в профили аккаунтов.
	 *
	 * @return array Номера.
	 */
	public static function mixed_phones() {
		global $wpdb;

		if ( ! self::ready() ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_col(
			"SELECT phone FROM {$table}
			WHERE phone <> ''
			GROUP BY phone
			HAVING COUNT(DISTINCT NULLIF(email, '')) > 1
				OR COUNT(DISTINCT NULLIF(external_id, 0)) > 1
				OR COUNT(DISTINCT NULLIF(user_id, 0)) > 1"
		);
	}

	/**
	 * Отметить телефоны, на которых сходится несколько разных людей.
	 */
	protected static function mark_conflicts() {
		global $wpdb;

		$table  = self::table();
		$phones = self::mixed_phones();

		foreach ( (array) $phones as $phone ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status' => 'conflict',
					'note'   => 'на номере разные люди',
				),
				array( 'phone' => $phone )
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Поиск
	 * ------------------------------------------------------------------ */

	/**
	 * Найти клиента по телефону: сначала в снимке, потом — запросом в CRM.
	 *
	 * @param string $phone Номер.
	 * @return array|false Строка справочника.
	 */
	public static function find_by_phone( $phone ) {
		$phone = VL_Account_Phone::normalize( $phone );

		if ( '' === $phone ) {
			return false;
		}

		if ( isset( self::$lookup_cache[ $phone ] ) ) {
			return self::$lookup_cache[ $phone ];
		}

		$row = self::from_directory( $phone );

		if ( ! $row && VL_Account_Settings::get( 'crm_lookup_live', 1 ) ) {
			$row = self::from_api( $phone );
		}

		self::$lookup_cache[ $phone ] = $row;

		return $row;
	}

	/**
	 * Забыть найденное в этом запросе.
	 */
	public static function flush_cache() {
		self::$lookup_cache = array();
	}

	/**
	 * Строка справочника по телефону.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	protected static function from_directory( $phone ) {
		return self::combine( self::rows_by_phone( $phone ) );
	}

	/**
	 * Склеить карточки одного человека в одну.
	 *
	 * У покупателя в CRM нередко две карточки: старая (заказы, почта) и новая,
	 * заведённая менеджером или программой лояльности. Ни одна из них не полная,
	 * поэтому за основу берём самую свежую, а пустые поля дополняем из старых.
	 * Список всех найденных карточек и аккаунтов оставляем в строке: по нему
	 * VL_Account_Identity решает, в какой аккаунт пускать.
	 *
	 * @param array $rows Строки снимка с одним телефоном.
	 * @return array|false
	 */
	public static function combine( $rows ) {
		$rows = self::sort_rows( $rows );

		if ( ! $rows ) {
			return false;
		}

		$best   = $rows[0];
		$fields = array( 'email', 'first_name', 'last_name', 'city', 'external_id', 'user_id' );

		foreach ( $rows as $row ) {
			foreach ( $fields as $field ) {
				if ( empty( $best[ $field ] ) && ! empty( $row[ $field ] ) ) {
					$best[ $field ] = $row[ $field ];
				}
			}

			// Согласие на рассылку достаточно дать один раз в любой карточке.
			if ( ! empty( $row['subscribed'] ) ) {
				$best['subscribed'] = 1;
			}
		}

		$best['crm_ids']      = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'crm_id' ) ) ) ) );
		$best['user_ids']     = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'user_id' ) ) ) ) );
		$best['external_ids'] = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'external_id' ) ) ) ) );

		$emails = array();

		foreach ( $rows as $row ) {
			$email = isset( $row['email'] ) ? strtolower( trim( (string) $row['email'] ) ) : '';

			if ( '' !== $email ) {
				$emails[ $email ] = $email;
			}
		}

		$best['emails'] = array_values( $emails );

		// Телефон разошёлся по разным аккаунтам — выбор делает
		// VL_Account_Identity: там видно, какие из аккаунтов живые.
		if ( count( $best['user_ids'] ) > 1 ) {
			$best['status'] = 'conflict';
		}

		return $best;
	}

	/**
	 * Номер принадлежит нескольким разным людям.
	 *
	 * Склеивать карточки можно только тогда, когда это карточки **одного**
	 * человека. Если на одном номере сидят разные почты или разные externalId,
	 * значит в базе намешаны разные покупатели: кто-то дал чужой номер, номер
	 * переоформили, менеджер оформлял заказ со своего телефона. Пускать по
	 * такому номеру в чужой кабинет нельзя ни при каких условиях — это хуже,
	 * чем показать пустой кабинет.
	 *
	 * @param array $row Склеенная строка справочника.
	 * @return string Пусто, если номер принадлежит одному человеку; иначе причина.
	 */
	public static function conflict_reason( $row ) {
		$emails    = isset( $row['emails'] ) ? (array) $row['emails'] : array();
		$externals = isset( $row['external_ids'] ) ? (array) $row['external_ids'] : array();

		if ( count( $emails ) > 1 ) {
			return sprintf(
				/* translators: %s — список адресов. */
				__( 'на номере карточки разных людей: %s', 'vl-account' ),
				implode( ', ', array_slice( $emails, 0, 5 ) )
			);
		}

		if ( count( $externals ) > 1 ) {
			return sprintf(
				/* translators: %s — список аккаунтов. */
				__( 'карточки номера привязаны к разным аккаунтам: %s', 'vl-account' ),
				implode( ', ', array_slice( $externals, 0, 5 ) )
			);
		}

		return '';
	}

	/**
	 * Отсортировать карточки от самой свежей к самой старой.
	 *
	 * Свежесть — дата заведения в CRM; если её нет (снимок сделан старой
	 * версией плагина), сравниваем id: он в CRM растёт.
	 *
	 * @param array $rows Строки снимка.
	 * @return array
	 */
	public static function sort_rows( $rows ) {
		$rows = array_values( array_filter( (array) $rows, 'is_array' ) );

		usort(
			$rows,
			static function ( $a, $b ) {
				$a_date = isset( $a['crm_created'] ) ? (string) $a['crm_created'] : '';
				$b_date = isset( $b['crm_created'] ) ? (string) $b['crm_created'] : '';

				$a_date = ( '' === $a_date || 0 === strpos( $a_date, '0000' ) ) ? '' : $a_date;
				$b_date = ( '' === $b_date || 0 === strpos( $b_date, '0000' ) ) ? '' : $b_date;

				if ( $a_date !== $b_date ) {
					return strcmp( $b_date, $a_date );
				}

				return (int) $b['crm_id'] - (int) $a['crm_id'];
			}
		);

		return $rows;
	}

	/**
	 * Все строки снимка с этим телефоном.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array
	 */
	public static function rows_by_phone( $phone ) {
		global $wpdb;

		$phone = VL_Account_Phone::normalize( $phone );

		if ( '' === $phone || ! VL_Account_Settings::get( 'crm_directory', 1 ) || ! self::ready() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE phone = %s LIMIT 20', $phone ),
			ARRAY_A
		);

		return self::sort_rows( $rows );
	}

	/**
	 * Строки снимка с этим адресом почты.
	 *
	 * @param string $email Адрес.
	 * @return array
	 */
	public static function rows_by_email( $email ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email || ! VL_Account_Settings::get( 'crm_directory', 1 ) || ! self::ready() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s LIMIT 20', $email ),
			ARRAY_A
		);
	}

	/**
	 * Карточки CRM с этим телефоном, от свежей к старой.
	 *
	 * @param string $phone Номер.
	 * @return array ID покупателей в CRM.
	 */
	public static function crm_ids_by_phone( $phone ) {
		$ids = array();

		foreach ( self::rows_by_phone( $phone ) as $row ) {
			$crm_id = (int) $row['crm_id'];

			if ( $crm_id ) {
				$ids[ $crm_id ] = $crm_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Строка снимка, подходящая конкретному аккаунту.
	 *
	 * При дублях в CRM берём карточку этого аккаунта, а если такой нет —
	 * самую свежую свободную (без чужого externalId).
	 *
	 * @param string $phone   Нормализованный номер.
	 * @param int    $user_id Аккаунт сайта.
	 * @return array|false
	 */
	public static function row_for_user( $phone, $user_id ) {
		$rows = self::rows_by_phone( $phone );
		$free = false;

		foreach ( $rows as $row ) {
			if ( (int) $row['external_id'] === (int) $user_id ) {
				return $row;
			}

			if ( ! $free && empty( $row['external_id'] ) ) {
				$free = $row;
			}
		}

		return $free;
	}

	/**
	 * Дата CRM в формате MySQL.
	 *
	 * @param string $value Дата из ответа CRM.
	 * @return string
	 */
	protected static function crm_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '0000-00-00 00:00:00';
		}

		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : '0000-00-00 00:00:00';
	}

	/**
	 * Запрос в CRM по телефону.
	 *
	 * Основной путь — общий поиск customers/list (он ищет и по номеру).
	 * Если магазин ведёт программу лояльности, вторым заходом пробуем найти
	 * участника по номеру: там телефон — отдельное поле фильтра.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	protected static function from_api( $phone ) {
		$api = VL_Account_RetailCRM::api();

		if ( ! $api ) {
			return false;
		}

		$customers = self::collect_from_api( $api, $phone );

		if ( ! $customers ) {
			return false;
		}

		foreach ( $customers as $customer ) {
			self::store( $customer );
		}

		$rows = self::rows_by_phone( $phone );

		if ( $rows ) {
			foreach ( $rows as $row ) {
				self::match_row( $row );
			}

			return self::from_directory( $phone );
		}

		// Справочник выключен — склеиваем то, что пришло из API.
		$live = array();

		foreach ( $customers as $customer ) {
			$live[] = self::row_from_customer( $customer, $phone );
		}

		return self::combine( $live );
	}

	/**
	 * Собрать все карточки CRM с этим телефоном.
	 *
	 * Общий поиск отдаёт одну карточку — ту, что нашлась первой. Дубли этим
	 * не ловятся, а именно на дубле обычно и лежат баллы: программа лояльности
	 * заводит покупателю свою карточку. Поэтому вторым шагом спрашиваем счёт
	 * программы по номеру — это один запрос, зато карточка с баллами не теряется.
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array Карточки CRM.
	 */
	public static function collect_from_api( $api, $phone ) {
		$customers = array();
		$found     = self::search_everywhere( $api, $phone );

		if ( $found && ! empty( $found['id'] ) ) {
			$customers[ (int) $found['id'] ] = $found;
		}

		$account = self::loyalty_account_by_phone( $api, $phone );
		$crm_id  = $account ? self::customer_id_from_account( $account ) : 0;

		if ( $crm_id && ! isset( $customers[ $crm_id ] ) ) {
			$customer = self::fetch_customer( $api, $crm_id );

			// Карточка, в которой самого номера нет, — чужая: счёт программы
			// мог быть заведён на неё по ошибке (в том числе нашей).
			if ( $customer && self::has_phone( $customer, $phone ) ) {
				$customers[ $crm_id ] = $customer;
			}
		}

		return array_values( $customers );
	}

	/**
	 * Карточка CRM в виде строки справочника.
	 *
	 * @param array  $customer Клиент CRM.
	 * @param string $phone    Нормализованный номер.
	 * @return array
	 */
	protected static function row_from_customer( $customer, $phone ) {
		return array(
			'crm_id'      => isset( $customer['id'] ) ? (int) $customer['id'] : 0,
			'external_id' => isset( $customer['externalId'] ) ? (int) $customer['externalId'] : 0,
			'phone'       => $phone,
			'email'       => isset( $customer['email'] ) ? strtolower( (string) $customer['email'] ) : '',
			'first_name'  => isset( $customer['firstName'] ) ? $customer['firstName'] : '',
			'last_name'   => isset( $customer['lastName'] ) ? $customer['lastName'] : '',
			'city'        => isset( $customer['address']['city'] ) ? $customer['address']['city'] : '',
			'subscribed'  => ! empty( $customer['subscribed'] ) ? 1 : 0,
			'crm_created' => self::crm_date( isset( $customer['createdAt'] ) ? $customer['createdAt'] : '' ),
			'user_id'     => 0,
			'status'      => 'live',
			'note'        => 'api',
		);
	}

	/**
	 * Перебрать все способы найти клиента CRM по телефону.
	 *
	 * У RetailCRM нет одного надёжного фильтра «по телефону»: общий поиск
	 * customers/list ищет по-разному в разных аккаунтах, поэтому пробуем
	 * подряд четыре пути и проверяем каждого кандидата по его же телефонам.
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	public static function search_everywhere( $api, $phone ) {
		$strategies = array(
			'customers-name'  => 'search_customer',
			'customers-phone' => 'search_customer_phone',
			'orders'          => 'search_orders',
			'loyalty'         => 'search_loyalty',
		);

		foreach ( $strategies as $name => $method ) {
			$customer = self::$method( $api, $phone );

			if ( $customer ) {
				self::log_attempt( $phone, $name, true, isset( $customer['id'] ) ? (int) $customer['id'] : 0 );

				return $customer;
			}

			self::log_attempt( $phone, $name, false, 0 );
		}

		return false;
	}

	/**
	 * Записать попытку поиска в журнал плагина.
	 *
	 * @param string $phone  Номер.
	 * @param string $method Способ.
	 * @param bool   $found  Нашли ли.
	 * @param int    $crm_id Найденный клиент.
	 */
	protected static function log_attempt( $phone, $method, $found, $crm_id ) {
		vlacc_log(
			'Поиск покупателя в CRM: ' . $method,
			array(
				'phone'  => vlacc_mask_phone( $phone ),
				'найден' => $found ? 'да' : 'нет',
				'crm_id' => $crm_id,
			)
		);
	}

	/**
	 * Поиск через фильтр «телефон» списка клиентов.
	 *
	 * Не во всех аккаунтах CRM он поддерживается: неизвестный фильтр там
	 * просто игнорируется, поэтому кандидата всё равно проверяем по телефонам.
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	protected static function search_customer_phone( $api, $phone ) {
		foreach ( self::search_terms( $phone ) as $term ) {
			$response = $api->customersList( array( 'phone' => $term ), 1, 20 );

			if ( ! VL_Account_RetailCRM::ok( $response ) ) {
				continue;
			}

			$customers = $response->offsetExists( 'customers' ) ? (array) $response['customers'] : array();

			// Фильтр мог быть проигнорирован — тогда вернётся весь список.
			if ( count( $customers ) > 5 ) {
				continue;
			}

			foreach ( $customers as $customer ) {
				if ( self::has_phone( $customer, $phone ) ) {
					return $customer;
				}
			}
		}

		return false;
	}

	/**
	 * Поиск через заказы: у заказа телефон есть всегда.
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	protected static function search_orders( $api, $phone ) {
		if ( ! is_callable( array( $api, 'ordersList' ) ) ) {
			return false;
		}

		foreach ( self::search_terms( $phone ) as $term ) {
			$response = $api->ordersList( array( 'customer' => $term ), 1, 20 );

			if ( ! VL_Account_RetailCRM::ok( $response ) ) {
				continue;
			}

			$orders = $response->offsetExists( 'orders' ) ? (array) $response['orders'] : array();

			foreach ( $orders as $order ) {
				$order_phone = isset( $order['phone'] ) ? $order['phone'] : '';
				$crm_id      = isset( $order['customer']['id'] ) ? (int) $order['customer']['id'] : 0;

				if ( ! $crm_id ) {
					continue;
				}

				$matches = VL_Account_Phone::normalize( $order_phone ) === $phone;

				if ( ! $matches && isset( $order['customer'] ) ) {
					$matches = self::has_phone( (array) $order['customer'], $phone );
				}

				if ( ! $matches ) {
					continue;
				}

				$customer = self::fetch_customer( $api, $crm_id );

				if ( $customer ) {
					return $customer;
				}
			}
		}

		return false;
	}

	/**
	 * Карточка клиента CRM по его id.
	 *
	 * @param object $api    Клиент API.
	 * @param int    $crm_id Клиент.
	 * @return array|false
	 */
	public static function fetch_customer( $api, $crm_id ) {
		$response = $api->customersGet( (int) $crm_id, 'id' );

		if ( VL_Account_RetailCRM::has( $response, 'customer' ) ) {
			return (array) $response['customer'];
		}

		return false;
	}

	/**
	 * Поиск покупателя в CRM по номеру телефона.
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	protected static function search_customer( $api, $phone ) {
		foreach ( self::search_terms( $phone ) as $term ) {
			$response = $api->customersList( array( 'name' => $term ), 1, 20 );

			if ( ! VL_Account_RetailCRM::ok( $response ) ) {
				continue;
			}

			$customers = $response->offsetExists( 'customers' ) ? (array) $response['customers'] : array();

			foreach ( $customers as $customer ) {
				if ( self::has_phone( $customer, $phone ) ) {
					return $customer;
				}
			}
		}

		return false;
	}

	/**
	 * Поиск через участников программы лояльности.
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	protected static function search_loyalty( $api, $phone ) {
		$account = self::loyalty_account_by_phone( $api, $phone );

		if ( ! $account ) {
			return false;
		}

		$crm_id = self::customer_id_from_account( $account );

		if ( ! $crm_id ) {
			return false;
		}

		$customer = self::fetch_customer( $api, $crm_id );

		// Счёт программы заводится по номеру, а карточка — по externalId, и
		// эти двое расходятся: счёт с нашим номером может висеть на карточке
		// постороннего человека. Карточку берём, только если номер есть и в ней.
		if ( ! is_array( $customer ) || ! self::has_phone( $customer, $phone ) ) {
			self::log_attempt( $phone, 'loyalty-карточка-без-номера', false, $crm_id );

			return false;
		}

		return $customer;
	}

	/**
	 * Участие в программе лояльности по номеру телефона.
	 *
	 * Если счетов несколько (дубли карточек в CRM), отдаём тот, где настоящие
	 * баллы покупателя, — правило выбора в VL_Account_RetailCRM::pick_loyalty().
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array|false
	 */
	public static function loyalty_account_by_phone( $api, $phone ) {
		$accounts = self::loyalty_accounts_by_phone( $api, $phone );

		if ( ! $accounts ) {
			return false;
		}

		return VL_Account_RetailCRM::pick_loyalty( $accounts );
	}

	/**
	 * Все счета программы лояльности с этим номером.
	 *
	 * @param object $api   Клиент API.
	 * @param string $phone Нормализованный номер.
	 * @return array
	 */
	public static function loyalty_accounts_by_phone( $api, $phone ) {
		if ( ! is_callable( array( $api, 'getLoyaltyAccountList' ) ) ) {
			return array();
		}

		$found = array();

		foreach ( array( '+' . $phone, $phone ) as $term ) {
			$response = $api->getLoyaltyAccountList( array( 'phoneNumber' => $term ), 20, 1 );

			if ( ! VL_Account_RetailCRM::ok( $response ) ) {
				continue;
			}

			$accounts = $response->offsetExists( 'loyaltyAccounts' ) ? (array) $response['loyaltyAccounts'] : array();

			foreach ( $accounts as $account ) {
				$number = isset( $account['phoneNumber'] ) ? $account['phoneNumber'] : '';

				// Фильтр CRM мог быть проигнорирован — сверяем номер сами, и
				// счёт без номера тоже не берём: раньше такой «безымянный» счёт
				// проходил проверку и приводил к чужому покупателю.
				if ( '' === $number || VL_Account_Phone::normalize( $number ) !== $phone ) {
					continue;
				}

				$key           = isset( $account['id'] ) ? (int) $account['id'] : count( $found );
				$found[ $key ] = (array) $account;
			}

			// По первому же удачному написанию номера список полный.
			if ( $found ) {
				break;
			}
		}

		return array_values( $found );
	}

	/**
	 * ID клиента CRM из записи об участии в программе.
	 *
	 * @param array $account Участие.
	 * @return int
	 */
	public static function customer_id_from_account( $account ) {
		if ( isset( $account['customerId'] ) ) {
			return (int) $account['customerId'];
		}

		if ( isset( $account['customer']['id'] ) ) {
			return (int) $account['customer']['id'];
		}

		return 0;
	}

	/**
	 * Варианты записи номера для поиска в CRM.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array
	 */
	protected static function search_terms( $phone ) {
		$terms = array( '+' . $phone, $phone );

		if ( 11 === strlen( $phone ) && '7' === $phone[0] ) {
			$terms[] = '8' . substr( $phone, 1 );
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * У клиента CRM есть такой телефон.
	 *
	 * @param array  $customer Клиент.
	 * @param string $phone    Нормализованный номер.
	 * @return bool
	 */
	public static function has_phone( $customer, $phone ) {
		foreach ( (array) ( isset( $customer['phones'] ) ? $customer['phones'] : array() ) as $item ) {
			$number = is_array( $item ) ? ( isset( $item['number'] ) ? $item['number'] : '' ) : $item;

			if ( VL_Account_Phone::normalize( $number ) === $phone ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Записать в снимок, что карточка CRM привязана к аккаунту сайта.
	 *
	 * @param int $crm_id  Покупатель в CRM.
	 * @param int $user_id Пользователь сайта.
	 */
	public static function set_external_id( $crm_id, $user_id ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'external_id' => (int) $user_id,
				'user_id'     => (int) $user_id,
				'status'      => 'matched',
				'note'        => 'привязано по телефону',
			),
			array( 'crm_id' => (int) $crm_id )
		);

		self::flush_cache();
	}

	/* ------------------------------------------------------------------
	 * Отчёт
	 * ------------------------------------------------------------------ */

	/**
	 * Сводка по справочнику.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;

		$empty = array(
			'total'    => 0,
			'matched'  => 0,
			'no_user'  => 0,
			'conflict' => 0,
			'phones'   => 0,
		);

		if ( ! self::ready() ) {
			return $empty;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$stats = $empty;

		foreach ( (array) $rows as $row ) {
			$status = isset( $row['status'] ) ? $row['status'] : '';
			$count  = (int) $row['total'];

			$stats['total'] += $count;

			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ] += $count;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$stats['phones'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE phone <> ''" );

		return $stats;
	}

	/**
	 * Строки справочника для отчёта.
	 *
	 * @param array $args Аргументы: status, search, limit, offset.
	 * @return array
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'status' => '',
				'search' => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(phone LIKE %s OR email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Забыть все карточки одного номера.
	 *
	 * Нужно, когда в CRM на номере намешаны разные люди: пока такие строки
	 * лежат в снимке, поиск по номеру будет натыкаться на них снова и снова.
	 *
	 * @param string $phone Номер в любом виде.
	 * @return int Сколько строк удалено.
	 */
	public static function forget_phone( $phone ) {
		global $wpdb;

		$phone = VL_Account_Phone::normalize( $phone );

		if ( '' === $phone || ! self::ready() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( self::table(), array( 'phone' => $phone ) );

		self::flush_cache();

		return (int) $deleted;
	}

	/**
	 * Удалить снимок.
	 */
	public static function truncate() {
		global $wpdb;

		if ( ! self::ready() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() );

		delete_option( self::STATE );
	}
}
