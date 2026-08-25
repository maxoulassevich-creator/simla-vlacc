<?php
/**
 * Сопоставление и объединение аккаунтов.
 *
 * До появления входа по SMS покупатели регистрировались обычной формой: почта
 * и пароль, телефона в профиле нет. При входе по номеру такой человек получал
 * второй, пустой аккаунт — без заказов, баллов и промокодов, а магазин
 * выглядел так, будто клиент новый.
 *
 * Здесь номер телефона доводится до старого аккаунта тремя источниками,
 * от дешёвого к дорогому:
 *
 *   1. заказы самого сайта — в заказе телефон есть почти всегда;
 *   2. снимок базы покупателей CRM (VL_Account_RetailCRM_Directory), где
 *      лежит связка «телефон → почта → ID аккаунта» (externalId);
 *   3. точечный запрос в CRM, если номер незнаком и снимка ещё нет.
 *
 * Всё, что делается автоматически, пишется в журнал: по нему видно, какой
 * аккаунт с каким объединён, что перенесено и на каком основании.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Личность покупателя: поиск аккаунта и слияние дублей.
 */
class VL_Account_Identity {

	/**
	 * Версия схемы таблицы журнала.
	 */
	const DB_VERSION = '1';

	/**
	 * Опция с версией схемы.
	 */
	const DB_OPTION = 'vlacc_identity_db';

	/**
	 * Метка «аккаунт объединён с другим».
	 */
	const META_MERGED = 'vlacc_merged_into';

	/**
	 * Метка «данные из CRM уже подтянуты».
	 */
	const META_ADOPTED = 'vlacc_crm_adopted';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Identity|null
	 */
	private static $instance = null;

	/**
	 * Кэш поиска по телефону в пределах запроса.
	 *
	 * @var array
	 */
	private static $search_cache = array();

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Identity
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
		// Поиск аккаунта по телефону достраивается заказами и базой CRM.
		add_filter( 'vlacc_user_by_phone', array( __CLASS__, 'resolve' ), 10, 2 );

		// Плагин обновляют заменой папки, без деактивации — таблицу журнала
		// создаём и в этом случае, проверка стоит одного get_option.
		if ( is_admin() && self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/* ------------------------------------------------------------------
	 * Журнал
	 * ------------------------------------------------------------------ */

	/**
	 * Имя таблицы журнала.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'vlacc_identity_log';
	}

	/**
	 * Создать таблицу журнала.
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
			created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			event varchar(20) NOT NULL DEFAULT '',
			phone varchar(20) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			from_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT '',
			note text NULL,
			PRIMARY KEY  (id),
			KEY event (event),
			KEY phone (phone),
			KEY user_id (user_id),
			KEY created (created)
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
	}

	/**
	 * Записать событие в журнал.
	 *
	 * @param string $event Событие: match | merge | adopt | conflict | skip.
	 * @param array  $data  Данные.
	 */
	public static function log( $event, $data = array() ) {
		global $wpdb;

		$data = wp_parse_args(
			$data,
			array(
				'phone'        => '',
				'email'        => '',
				'user_id'      => 0,
				'from_user_id' => 0,
				'source'       => '',
				'note'         => '',
			)
		);

		vlacc_log(
			'Сопоставление аккаунтов: ' . $event,
			array(
				'phone'   => $data['phone'] ? vlacc_mask_phone( $data['phone'] ) : '',
				'user_id' => $data['user_id'],
				'from'    => $data['from_user_id'],
				'source'  => $data['source'],
			)
		);

		if ( ! self::ready() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table(),
			array(
				'created'      => current_time( 'mysql' ),
				'event'        => substr( (string) $event, 0, 20 ),
				'phone'        => substr( (string) $data['phone'], 0, 20 ),
				'email'        => substr( (string) $data['email'], 0, 191 ),
				'user_id'      => (int) $data['user_id'],
				'from_user_id' => (int) $data['from_user_id'],
				'source'       => substr( (string) $data['source'], 0, 20 ),
				'note'         => is_scalar( $data['note'] ) ? (string) $data['note'] : wp_json_encode( $data['note'] ),
			)
		);
	}

	/**
	 * Записи журнала.
	 *
	 * @param array $args Аргументы: event, limit, offset.
	 * @return array
	 */
	public static function log_query( $args = array() ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'event'  => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['event'] ) {
			$where[]  = 'event = %s';
			$params[] = $args['event'];
		}

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Сколько событий каждого вида.
	 *
	 * @return array
	 */
	public static function log_stats() {
		global $wpdb;

		if ( ! self::ready() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT event, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY event', ARRAY_A );

		$stats = array();

		foreach ( (array) $rows as $row ) {
			$stats[ $row['event'] ] = (int) $row['total'];
		}

		return $stats;
	}

	/* ------------------------------------------------------------------
	 * Поиск аккаунта
	 * ------------------------------------------------------------------ */

	/**
	 * Достроить поиск пользователя по телефону.
	 *
	 * Только чтение: никаких записей здесь быть не должно — функция
	 * вызывается ещё до того, как человек подтвердил код из SMS.
	 *
	 * @param WP_User|false $user  Что нашёл штатный поиск.
	 * @param string        $phone Нормализованный номер.
	 * @return WP_User|false
	 */
	public static function resolve( $user, $phone ) {
		// Аккаунт, который уже объединён с другим, ведёт к своему «хозяину».
		if ( $user instanceof WP_User ) {
			$merged = (int) get_user_meta( $user->ID, self::META_MERGED, true );

			if ( $merged && $merged !== (int) $user->ID ) {
				$target = get_user_by( 'id', $merged );

				if ( $target ) {
					return $target;
				}
			}
		}

		// Нашли настоящий аккаунт (не пустышку от входа по SMS) — этого хватит.
		if ( $user instanceof WP_User && ! self::is_technical( $user ) ) {
			return $user;
		}

		$candidate = self::find_elsewhere( $phone );

		if ( ! $candidate ) {
			return $user;
		}

		// Сам себя аккаунт не заменяет.
		if ( $user instanceof WP_User && (int) $candidate['user_id'] === (int) $user->ID ) {
			return $user;
		}

		$target = get_user_by( 'id', (int) $candidate['user_id'] );

		if ( ! $target ) {
			return $user;
		}

		return $target;
	}

	/**
	 * Найти аккаунт по телефону вне профиля: заказы и база CRM.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array|false ['user_id' => int, 'source' => string, 'crm' => array|null]
	 */
	public static function find_elsewhere( $phone ) {
		$phone = VL_Account_Phone::normalize( $phone );

		if ( '' === $phone ) {
			return false;
		}

		// Один и тот же номер за запрос спрашивают несколько раз (отправка
		// кода, проверка кода, вход) — заказы и CRM дёргаем только раз.
		if ( isset( self::$search_cache[ $phone ] ) ) {
			return self::$search_cache[ $phone ];
		}

		self::$search_cache[ $phone ] = false;

		$by_orders = self::find_by_orders( $phone );

		if ( $by_orders ) {
			self::$search_cache[ $phone ] = array(
				'user_id' => $by_orders,
				'source'  => 'orders',
				'crm'     => null,
			);

			return self::$search_cache[ $phone ];
		}

		$crm = self::find_in_crm( $phone );

		if ( $crm && ! empty( $crm['user_id'] ) ) {
			self::$search_cache[ $phone ] = array(
				'user_id' => (int) $crm['user_id'],
				'source'  => 'crm',
				'crm'     => $crm,
			);
		}

		return self::$search_cache[ $phone ];
	}

	/**
	 * Сбросить кэш поиска (после слияния состав аккаунтов меняется).
	 */
	public static function flush_cache() {
		self::$search_cache = array();
	}

	/**
	 * Аккаунт по заказам сайта с этим телефоном.
	 *
	 * Телефон покупателя почти всегда есть в заказе, даже если в профиле его
	 * нет: WooCommerce пишет его в заказ, а не в пользователя.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return int ID пользователя или 0.
	 */
	public static function find_by_orders( $phone ) {
		if ( ! VL_Account_Settings::get( 'identity_orders', 1 ) || ! vlacc_is_woo() ) {
			return 0;
		}

		// Быстрый путь: точное совпадение с одним из привычных написаний номера.
		$orders = VL_Account_Orders::find_orders_by_phone( $phone );

		// Медленный, но надёжный: в старых заказах телефон записан как угодно
		// («8 926 123 45 67», «+7(926)123-45-67»), поэтому сравниваем цифры.
		if ( ! $orders ) {
			$orders = self::orders_by_digits( $phone );
		}

		if ( ! $orders ) {
			return 0;
		}

		$users = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$customer_id = (int) $order->get_customer_id();

			if ( $customer_id ) {
				$users[ $customer_id ] = $customer_id;

				continue;
			}

			// Гостевой заказ: аккаунт ищем по почте из заказа.
			$email = $order->get_billing_email();

			if ( $email ) {
				$user = get_user_by( 'email', $email );

				if ( $user ) {
					$users[ $user->ID ] = (int) $user->ID;
				}
			}
		}

		$users = array_values( array_filter( $users, array( __CLASS__, 'is_adoptable' ) ) );

		// Телефон сошёлся на нескольких аккаунтах — автоматика молчит.
		if ( count( $users ) !== 1 ) {
			if ( count( $users ) > 1 ) {
				self::log(
					'conflict',
					array(
						'phone'  => $phone,
						'source' => 'orders',
						'note'   => 'аккаунты: ' . implode( ', ', $users ),
					)
				);
			}

			return 0;
		}

		return (int) $users[0];
	}

	/**
	 * Заказы, у которых телефон совпадает по цифрам.
	 *
	 * Запрос без индекса, поэтому вызывается только когда обычный поиск ничего
	 * не нашёл, и результат кэшируется на время запроса. Сравниваем по десяти
	 * последним цифрам: код страны в старых заказах писали по-разному.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array Заказы.
	 */
	protected static function orders_by_digits( $phone ) {
		global $wpdb;

		$tail = substr( $phone, -10 );

		if ( strlen( $tail ) < 10 ) {
			return array();
		}

		$clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, ' ', ''), '(', ''), ')', ''), '-', ''), '+', ''), '.', '')";
		$like  = '%' . $tail;
		$ids   = array();

		// Заказы в отдельных таблицах (HPOS).
		$addresses = $wpdb->prefix . 'wc_order_addresses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $addresses ) ) ) {
			$sql = 'SELECT order_id FROM ' . $addresses . " WHERE address_type = 'billing' AND " . sprintf( $clean, 'phone' ) . ' LIKE %s LIMIT 20';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$ids = (array) $wpdb->get_col( $wpdb->prepare( $sql, $like ) );
		}

		// Классическое хранение заказов в постах.
		if ( ! $ids ) {
			$sql = 'SELECT post_id FROM ' . $wpdb->postmeta . " WHERE meta_key = '_billing_phone' AND " . sprintf( $clean, 'meta_value' ) . ' LIKE %s LIMIT 20';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$ids = (array) $wpdb->get_col( $wpdb->prepare( $sql, $like ) );
		}

		$orders = array();

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );

			// Перепроверяем сами: LIKE по хвосту мог зацепить чужой номер.
			if ( $order instanceof WC_Order && VL_Account_Phone::normalize( $order->get_billing_phone() ) === $phone ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}

	/**
	 * Клиент CRM по телефону.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array|false Строка справочника.
	 */
	public static function find_in_crm( $phone ) {
		if ( ! VL_Account_Settings::get( 'identity_crm', 1 ) ) {
			return false;
		}

		if ( ! class_exists( 'VL_Account_RetailCRM_Directory' ) || ! VL_Account_RetailCRM::enabled() ) {
			return false;
		}

		$row = VL_Account_RetailCRM_Directory::find_by_phone( $phone );

		if ( ! $row ) {
			return false;
		}

		if ( 'conflict' === $row['status'] ) {
			self::log(
				'conflict',
				array(
					'phone'  => $phone,
					'source' => 'crm',
					'note'   => 'один телефон у нескольких клиентов CRM',
				)
			);

			return false;
		}

		// Снимок мог устареть: аккаунт завели уже после сверки. Проверяем вживую.
		if ( empty( $row['user_id'] ) ) {
			$row['user_id'] = self::user_for_crm_row( $row );

			if ( $row['user_id'] && ! empty( $row['id'] ) ) {
				VL_Account_RetailCRM_Directory::match_row( $row );
			}
		}

		if ( ! empty( $row['user_id'] ) && ! self::is_adoptable( (int) $row['user_id'] ) ) {
			return false;
		}

		return $row;
	}

	/**
	 * Аккаунт сайта для клиента CRM: по externalId, затем по почте.
	 *
	 * @param array $row Строка справочника.
	 * @return int ID пользователя или 0.
	 */
	protected static function user_for_crm_row( $row ) {
		if ( ! empty( $row['external_id'] ) ) {
			$user = get_user_by( 'id', (int) $row['external_id'] );

			if ( $user ) {
				return (int) $user->ID;
			}
		}

		if ( ! empty( $row['email'] ) ) {
			$user = get_user_by( 'email', $row['email'] );

			if ( $user ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/* ------------------------------------------------------------------
	 * Правила безопасности
	 * ------------------------------------------------------------------ */

	/**
	 * В этот аккаунт можно пускать по совпадению телефона.
	 *
	 * Ни при каких условиях нельзя пускать так в аккаунт с правами
	 * администратора или менеджера магазина: это готовый захват магазина
	 * по одной SMS.
	 *
	 * @param int $user_id Пользователь.
	 * @return bool
	 */
	public static function is_adoptable( $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );

		if ( ! $user ) {
			return false;
		}

		$forbidden = array( 'manage_options', 'manage_woocommerce', 'edit_posts', 'edit_shop_orders', 'list_users' );

		foreach ( $forbidden as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'vlacc_identity_adoptable', true, $user );
	}

	/**
	 * Технический аккаунт: заведён входом по SMS и своей почты не имеет.
	 *
	 * @param WP_User|int $user Пользователь.
	 * @return bool
	 */
	public static function is_technical( $user ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'id', (int) $user );
		}

		if ( ! $user ) {
			return false;
		}

		return ! VL_Account_User::has_real_email( $user );
	}

	/* ------------------------------------------------------------------
	 * Вход: подтянуть данные и объединить дубли
	 * ------------------------------------------------------------------ */

	/**
	 * Подготовить аккаунт к входу после подтверждения кода.
	 *
	 * Здесь уже можно писать: номер подтверждён кодом из SMS.
	 *
	 * @param WP_User|false $user  Найденный аккаунт.
	 * @param string        $phone Нормализованный номер.
	 * @return WP_User|false Аккаунт, в который нужно входить.
	 */
	public static function prepare_login( $user, $phone ) {
		$phone = VL_Account_Phone::normalize( $phone );

		if ( '' === $phone ) {
			return $user;
		}

		// Аккаунта нет вовсе или это пустышка от прошлого входа по SMS —
		// ищем настоящий аккаунт этого человека в заказах и в CRM.
		if ( ! $user instanceof WP_User || self::is_technical( $user ) ) {
			$found = self::find_elsewhere( $phone );

			if ( $found && ( ! $user instanceof WP_User || (int) $found['user_id'] !== (int) $user->ID ) ) {
				$target = get_user_by( 'id', (int) $found['user_id'] );

				if ( $target ) {
					self::log(
						'match',
						array(
							'phone'        => $phone,
							'email'        => $target->user_email,
							'user_id'      => $target->ID,
							'from_user_id' => $user instanceof WP_User ? $user->ID : 0,
							'source'       => $found['source'],
							'note'         => $user instanceof WP_User
								? 'вход в найденный аккаунт вместо пустого'
								: 'вход в существующий аккаунт вместо создания нового',
						)
					);

					$user = $target;
				}
			}
		}

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		// Дубли: тот же телефон мог остаться на пустышках прошлых входов.
		$user = self::merge_duplicates( $user, $phone );

		self::attach_phone( $user->ID, $phone );
		self::adopt_from_crm( $user->ID, $phone );

		if ( vlacc_is_woo() ) {
			VL_Account_Orders::attach_guest_orders( $user->ID );
		}

		return $user;
	}

	/**
	 * Дописать телефон в профиль.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $phone   Нормализованный номер.
	 */
	public static function attach_phone( $user_id, $phone ) {
		if ( '' === $phone ) {
			return;
		}

		if ( (string) get_user_meta( $user_id, VL_Account_User::META_PHONE, true ) !== $phone ) {
			update_user_meta( $user_id, VL_Account_User::META_PHONE, $phone );
		}

		if ( '' === (string) get_user_meta( $user_id, 'billing_phone', true ) ) {
			update_user_meta( $user_id, 'billing_phone', VL_Account_Phone::format( $phone ) );
		}

		update_user_meta( $user_id, VL_Account_User::META_VERIFIED, current_time( 'mysql' ) );
	}

	/**
	 * Перенести данные покупателя из CRM в аккаунт.
	 *
	 * Почта, пришедшая из CRM по подтверждённому телефону, подтверждения
	 * по ссылке не требует: покупатель её не вводил, значит и подставить
	 * чужую не мог.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $phone   Нормализованный номер.
	 * @return array Что заполнено.
	 */
	public static function adopt_from_crm( $user_id, $phone ) {
		$filled = array();
		$crm    = self::find_in_crm( $phone );

		if ( ! $crm ) {
			return $filled;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return $filled;
		}

		// Почта.
		$email = isset( $crm['email'] ) ? sanitize_email( $crm['email'] ) : '';

		if ( $email && is_email( $email ) && ! VL_Account_User::has_real_email( $user ) ) {
			$owner = get_user_by( 'email', $email );

			if ( ! $owner ) {
				wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => $email,
					)
				);

				update_user_meta( $user_id, 'billing_email', $email );

				if ( VL_Account_Settings::get( 'identity_trust_crm_email', 1 ) ) {
					// Гасим просьбу подтвердить адрес — подтверждать нечего.
					delete_user_meta( $user_id, VL_Account_Email_Confirm::META_EMAIL );
					delete_user_meta( $user_id, VL_Account_Email_Confirm::META_HASH );
					update_user_meta( $user_id, 'vlacc_email_confirmed', current_time( 'mysql' ) );
				}

				$filled[] = 'email';
			}
		}

		// Имя и фамилия.
		foreach ( array( 'first_name' => 'first_name', 'last_name' => 'last_name' ) as $crm_key => $meta ) {
			$value = isset( $crm[ $crm_key ] ) ? sanitize_text_field( $crm[ $crm_key ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			if ( '' === (string) get_user_meta( $user_id, $meta, true ) ) {
				update_user_meta( $user_id, $meta, $value );
				$filled[] = $meta;
			}

			if ( '' === (string) get_user_meta( $user_id, 'billing_' . $meta, true ) ) {
				update_user_meta( $user_id, 'billing_' . $meta, $value );
			}
		}

		// Город.
		if ( ! empty( $crm['city'] ) && '' === (string) get_user_meta( $user_id, 'billing_city', true ) ) {
			update_user_meta( $user_id, 'billing_city', sanitize_text_field( $crm['city'] ) );
			$filled[] = 'city';
		}

		update_user_meta( $user_id, self::META_ADOPTED, current_time( 'mysql' ) );

		if ( $filled ) {
			self::log(
				'adopt',
				array(
					'phone'   => $phone,
					'email'   => $email,
					'user_id' => $user_id,
					'source'  => 'crm',
					'note'    => 'заполнено: ' . implode( ', ', $filled ),
				)
			);

			// Данные покупателя изменились — пусть кабинет перечитает CRM.
			if ( class_exists( 'VL_Account_RetailCRM' ) ) {
				VL_Account_RetailCRM::flush( $user_id );
			}
		}

		return $filled;
	}

	/**
	 * Только что созданный по SMS аккаунт: подтянуть в него всё, что известно.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $phone   Нормализованный номер.
	 */
	public static function after_register( $user_id, $phone ) {
		$phone = VL_Account_Phone::normalize( $phone );

		if ( ! $user_id || '' === $phone ) {
			return;
		}

		self::adopt_from_crm( $user_id, $phone );

		if ( vlacc_is_woo() ) {
			VL_Account_Orders::attach_guest_orders( $user_id );
		}
	}

	/* ------------------------------------------------------------------
	 * Слияние
	 * ------------------------------------------------------------------ */

	/**
	 * Объединить с целевым аккаунтом все пустышки с тем же телефоном.
	 *
	 * @param WP_User $user  Аккаунт, в который входим.
	 * @param string  $phone Нормализованный номер.
	 * @return WP_User Итоговый аккаунт.
	 */
	public static function merge_duplicates( $user, $phone ) {
		if ( ! VL_Account_Settings::get( 'identity_merge', 1 ) ) {
			return $user;
		}

		$duplicates = self::duplicates( $phone, $user->ID );

		foreach ( $duplicates as $duplicate_id ) {
			self::merge( $duplicate_id, $user->ID, 'phone' );
		}

		return $user;
	}

	/**
	 * Другие аккаунты с этим же телефоном.
	 *
	 * @param string $phone  Нормализованный номер.
	 * @param int    $keep   ID аккаунта, который остаётся.
	 * @return array ID аккаунтов на слияние.
	 */
	public static function duplicates( $phone, $keep ) {
		$found = get_users(
			array(
				'meta_key'   => VL_Account_User::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $phone,                      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 20,
				'fields'     => 'ID',
			)
		);

		$list = array();

		foreach ( (array) $found as $candidate_id ) {
			$candidate_id = (int) $candidate_id;

			if ( $candidate_id === (int) $keep ) {
				continue;
			}

			$candidate = get_user_by( 'id', $candidate_id );

			// Сливаем только пустышки от входа по SMS: у аккаунта с настоящей
			// почтой могла быть своя жизнь, такие случаи — в отчёт.
			if ( ! $candidate || ! self::is_technical( $candidate ) || ! self::is_adoptable( $candidate_id ) ) {
				self::log(
					'conflict',
					array(
						'phone'        => $phone,
						'user_id'      => (int) $keep,
						'from_user_id' => $candidate_id,
						'source'       => 'phone',
						'note'         => 'два живых аккаунта с одним телефоном — слияние вручную',
					)
				);

				continue;
			}

			$list[] = $candidate_id;
		}

		return $list;
	}

	/**
	 * Перенести всё из одного аккаунта в другой.
	 *
	 * @param int    $from_id Аккаунт-источник (будет погашен).
	 * @param int    $into_id Аккаунт-получатель.
	 * @param string $source  Основание слияния.
	 * @return array Что перенесено.
	 */
	public static function merge( $from_id, $into_id, $source = '' ) {
		$from_id = (int) $from_id;
		$into_id = (int) $into_id;

		$moved = array();

		if ( ! $from_id || ! $into_id || $from_id === $into_id ) {
			return $moved;
		}

		$from = get_user_by( 'id', $from_id );
		$into = get_user_by( 'id', $into_id );

		if ( ! $from || ! $into || ! self::is_adoptable( $into_id ) ) {
			return $moved;
		}

		$moved['orders']        = self::move_orders( $from_id, $into_id );
		$moved['wishlist']      = self::move_wishlist( $from_id, $into_id );
		$moved['subscriptions'] = self::move_subscriptions( $from_id, $into_id );
		$moved['carts']         = self::move_carts( $from_id, $into_id );
		$moved['promo']         = self::move_promo( $from_id, $into_id );
		$moved['bonus']         = self::move_bonus( $from_id, $into_id );

		self::move_profile( $from_id, $into_id );
		self::move_consents( $from_id, $into_id );

		// Телефон уводим с источника, иначе он снова найдётся при входе.
		delete_user_meta( $from_id, VL_Account_User::META_PHONE );
		update_user_meta( $from_id, self::META_MERGED, $into_id );

		self::log(
			'merge',
			array(
				'phone'        => (string) get_user_meta( $into_id, VL_Account_User::META_PHONE, true ),
				'email'        => $into->user_email,
				'user_id'      => $into_id,
				'from_user_id' => $from_id,
				'source'       => $source,
				'note'         => wp_json_encode( $moved ),
			)
		);

		// Пустой технический аккаунт после переноса не нужен.
		if ( VL_Account_Settings::get( 'identity_delete_merged', 1 ) && self::is_technical( $from ) ) {
			self::delete_user( $from_id, $into_id );
			$moved['deleted'] = 1;
		}

		self::flush_cache();

		do_action( 'vlacc_accounts_merged', $into_id, $from_id, $moved );

		return $moved;
	}

	/**
	 * Заказы.
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 * @return int
	 */
	protected static function move_orders( $from_id, $into_id ) {
		if ( ! vlacc_is_woo() ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $from_id,
				'limit'       => 200,
				'type'        => 'shop_order',
				'return'      => 'objects',
			)
		);

		$moved = 0;

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== $from_id ) {
				continue;
			}

			$order->set_customer_id( $into_id );
			$order->save();
			++$moved;
		}

		return $moved;
	}

	/**
	 * Избранное: наше хранилище и таблица WishSuite.
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 * @return int
	 */
	protected static function move_wishlist( $from_id, $into_id ) {
		$from_items = (array) get_user_meta( $from_id, VL_Account_Wishlist::META, true );
		$into_items = (array) get_user_meta( $into_id, VL_Account_Wishlist::META, true );

		$from_items = array_filter( array_map( 'absint', $from_items ) );
		$into_items = array_filter( array_map( 'absint', $into_items ) );

		$merged = array_values( array_unique( array_merge( $into_items, $from_items ) ) );
		$added  = count( $merged ) - count( $into_items );

		if ( $added > 0 ) {
			update_user_meta( $into_id, VL_Account_Wishlist::META, $merged );
		}

		delete_user_meta( $from_id, VL_Account_Wishlist::META );

		// Избранное WishSuite живёт в своей таблице.
		if ( class_exists( 'VL_Account_WishSuite' ) && VL_Account_WishSuite::plugin_active() ) {
			global $wpdb;

			$table = $wpdb->prefix . 'wishsuite_list';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'user_id' => $into_id ), array( 'user_id' => $from_id ) );
		}

		return max( 0, $added );
	}

	/**
	 * Подписки на поступление размера.
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 * @return int
	 */
	protected static function move_subscriptions( $from_id, $into_id ) {
		if ( ! class_exists( 'VL_Account_Stock_Notifier' ) || ! post_type_exists( 'cwginstocknotifier' ) ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'cwginstocknotifier',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => VL_Account_Stock_Notifier::META_USER,
						'value' => $from_id,
					),
				),
			)
		);

		$moved = 0;

		foreach ( (array) $posts as $post_id ) {
			update_post_meta( $post_id, VL_Account_Stock_Notifier::META_USER, $into_id );
			++$moved;
		}

		return $moved;
	}

	/**
	 * Корзины из отчёта о брошенных.
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 * @return int
	 */
	protected static function move_carts( $from_id, $into_id ) {
		global $wpdb;

		if ( ! class_exists( 'VL_Account_Carts' ) ) {
			return 0;
		}

		$table = VL_Account_Carts::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update( $table, array( 'user_id' => $into_id ), array( 'user_id' => $from_id ) );

		return (int) $updated;
	}

	/**
	 * Промокоды кабинета.
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 * @return int
	 */
	protected static function move_promo( $from_id, $into_id ) {
		$from_codes = get_user_meta( $from_id, VL_Account_Promo::META, true );
		$into_codes = get_user_meta( $into_id, VL_Account_Promo::META, true );

		$from_codes = is_array( $from_codes ) ? $from_codes : array();
		$into_codes = is_array( $into_codes ) ? $into_codes : array();

		if ( ! $from_codes ) {
			return 0;
		}

		$known = array();

		foreach ( $into_codes as $code ) {
			if ( isset( $code['code'] ) ) {
				$known[ strtoupper( $code['code'] ) ] = true;
			}
		}

		$added = 0;

		foreach ( $from_codes as $code ) {
			$key = isset( $code['code'] ) ? strtoupper( $code['code'] ) : '';

			if ( '' === $key || isset( $known[ $key ] ) ) {
				continue;
			}

			$into_codes[] = $code;
			++$added;
		}

		if ( $added ) {
			update_user_meta( $into_id, VL_Account_Promo::META, $into_codes );
		}

		delete_user_meta( $from_id, VL_Account_Promo::META );

		return $added;
	}

	/**
	 * Локальные баллы (когда программа лояльности CRM не используется).
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 * @return int
	 */
	protected static function move_bonus( $from_id, $into_id ) {
		$amount = (float) get_user_meta( $from_id, VL_Account_Bonus::META_BALANCE, true );

		if ( $amount <= 0 ) {
			return 0;
		}

		$balance = (float) get_user_meta( $into_id, VL_Account_Bonus::META_BALANCE, true );

		update_user_meta( $into_id, VL_Account_Bonus::META_BALANCE, $balance + $amount );
		update_user_meta( $from_id, VL_Account_Bonus::META_BALANCE, 0 );

		$history = get_user_meta( $into_id, VL_Account_Bonus::META_HISTORY, true );
		$history = is_array( $history ) ? $history : array();

		array_unshift(
			$history,
			array(
				'date'    => current_time( 'mysql' ),
				'amount'  => $amount,
				'comment' => __( 'Перенос баллов из объединённого аккаунта', 'vl-account' ),
			)
		);

		update_user_meta( $into_id, VL_Account_Bonus::META_HISTORY, array_slice( $history, 0, 100 ) );

		return (int) $amount;
	}

	/**
	 * Профиль: заполняем только пустые поля получателя.
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 */
	protected static function move_profile( $from_id, $into_id ) {
		$keys = array(
			'first_name',
			'last_name',
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
			VL_Account_User::META_TELEGRAM,
		);

		foreach ( $keys as $key ) {
			$value = get_user_meta( $from_id, $key, true );

			if ( '' === $value || null === $value ) {
				continue;
			}

			if ( '' === (string) get_user_meta( $into_id, $key, true ) ) {
				update_user_meta( $into_id, $key, $value );
			}
		}
	}

	/**
	 * Согласия: сохраняем данные «да», они важнее.
	 *
	 * @param int $from_id Источник.
	 * @param int $into_id Получатель.
	 */
	protected static function move_consents( $from_id, $into_id ) {
		$from = get_user_meta( $from_id, VL_Account_User::META_CONSENTS, true );
		$into = get_user_meta( $into_id, VL_Account_User::META_CONSENTS, true );

		$from = is_array( $from ) ? $from : array();
		$into = is_array( $into ) ? $into : array();

		if ( ! $from ) {
			return;
		}

		foreach ( $from as $type => $consent ) {
			$has_yes = ! empty( $into[ $type ]['value'] );

			if ( ! $has_yes && ! empty( $consent['value'] ) ) {
				$into[ $type ] = $consent;
			} elseif ( ! isset( $into[ $type ] ) ) {
				$into[ $type ] = $consent;
			}
		}

		update_user_meta( $into_id, VL_Account_User::META_CONSENTS, $into );
	}

	/**
	 * Удалить погашенный аккаунт.
	 *
	 * @param int $user_id  Кого удаляем.
	 * @param int $reassign Кому передаём его записи.
	 */
	protected static function delete_user( $user_id, $reassign ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( $user_id, $reassign );
		}
	}
}
