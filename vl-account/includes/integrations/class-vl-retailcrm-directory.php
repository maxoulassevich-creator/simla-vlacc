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
	const DB_VERSION = '1';

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

			if ( ! $response instanceof WC_Retailcrm_Response || ! $response->isSuccessful() ) {
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
	 * Отметить телефоны, на которых сходится несколько разных аккаунтов.
	 */
	protected static function mark_conflicts() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$phones = $wpdb->get_col(
			"SELECT phone FROM {$table}
			WHERE phone <> '' AND user_id > 0
			GROUP BY phone
			HAVING COUNT(DISTINCT user_id) > 1"
		);

		foreach ( (array) $phones as $phone ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status' => 'conflict',
					'note'   => 'несколько аккаунтов',
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
		global $wpdb;

		if ( ! VL_Account_Settings::get( 'crm_directory', 1 ) || ! self::ready() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE phone = %s ORDER BY user_id DESC, id ASC LIMIT 5', $phone ),
			ARRAY_A
		);

		if ( ! $rows ) {
			return false;
		}

		// Телефон разошёлся по разным аккаунтам — молча выбирать нельзя.
		$users = array_unique( array_filter( wp_list_pluck( $rows, 'user_id' ) ) );

		if ( count( $users ) > 1 ) {
			return array_merge( $rows[0], array( 'status' => 'conflict' ) );
		}

		return $rows[0];
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

		$customer = self::search_customer( $api, $phone );

		if ( ! $customer ) {
			$customer = self::search_loyalty( $api, $phone );
		}

		if ( ! $customer ) {
			return false;
		}

		self::store( $customer );

		$row = self::from_directory( $phone );

		if ( $row ) {
			self::match_row( $row );

			return self::from_directory( $phone );
		}

		// Справочник выключен — отдаём данные как есть.
		return array(
			'crm_id'      => isset( $customer['id'] ) ? (int) $customer['id'] : 0,
			'external_id' => isset( $customer['externalId'] ) ? (int) $customer['externalId'] : 0,
			'phone'       => $phone,
			'email'       => isset( $customer['email'] ) ? strtolower( (string) $customer['email'] ) : '',
			'first_name'  => isset( $customer['firstName'] ) ? $customer['firstName'] : '',
			'last_name'   => isset( $customer['lastName'] ) ? $customer['lastName'] : '',
			'city'        => isset( $customer['address']['city'] ) ? $customer['address']['city'] : '',
			'subscribed'  => ! empty( $customer['subscribed'] ) ? 1 : 0,
			'user_id'     => 0,
			'status'      => 'live',
			'note'        => 'api',
		);
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

			if ( ! $response instanceof WC_Retailcrm_Response || ! $response->isSuccessful() ) {
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
		if ( ! is_callable( array( $api, 'getLoyaltyAccountList' ) ) ) {
			return false;
		}

		$response = $api->getLoyaltyAccountList( array( 'phoneNumber' => '+' . $phone ), 20, 1 );

		if ( ! $response instanceof WC_Retailcrm_Response || ! $response->isSuccessful() ) {
			return false;
		}

		$accounts = $response->offsetExists( 'loyaltyAccounts' ) ? (array) $response['loyaltyAccounts'] : array();

		foreach ( $accounts as $account ) {
			$crm_id = isset( $account['customerId'] ) ? (int) $account['customerId'] : 0;

			if ( ! $crm_id ) {
				continue;
			}

			$customer = $api->customersGet( $crm_id, 'id' );

			if ( $customer instanceof WC_Retailcrm_Response && $customer->isSuccessful() && $customer->offsetExists( 'customer' ) ) {
				return (array) $customer['customer'];
			}
		}

		return false;
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
