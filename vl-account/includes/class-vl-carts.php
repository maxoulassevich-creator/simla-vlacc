<?php
/**
 * Брошенные корзины: сбор и просмотр в админке.
 *
 * Корзина WooCommerce живёт в сессии и исчезает вместе с ней — увидеть,
 * что человек набрал и не купил, в админке негде. Здесь каждая корзина
 * снимается в отдельную таблицу: кто (если известен), что именно положил,
 * на какую сумму и когда трогал последний раз.
 *
 * Гость опознаётся собственной кукой, а не сессией WooCommerce: сессия
 * меняется при входе, и корзина гостя иначе теряла бы связь с аккаунтом.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Корзины покупателей.
 */
class VL_Account_Carts {

	/**
	 * Версия схемы таблицы.
	 */
	const DB_VERSION = '1';

	/**
	 * Опция с версией схемы.
	 */
	const DB_OPTION = 'vlacc_carts_db';

	/**
	 * Кука с ключом корзины гостя.
	 */
	const COOKIE = 'vlacc_cart_key';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Carts|null
	 */
	private static $instance = null;

	/**
	 * Корзину нужно сохранить в конце запроса.
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * Заказ, в который превратилась корзина.
	 *
	 * @var int
	 */
	private $converted = 0;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Carts
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
		add_action( 'vlacc_carts_cleanup', array( __CLASS__, 'cleanup' ) );

		if ( ! self::enabled() ) {
			return;
		}

		self::maybe_install();

		if ( ! wp_next_scheduled( 'vlacc_carts_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vlacc_carts_cleanup' );
		}

		// Любое изменение корзины помечаем к сохранению — пишем один раз в конце запроса.
		foreach ( array(
			'woocommerce_add_to_cart',
			'woocommerce_after_cart_item_quantity_update',
			'woocommerce_cart_item_removed',
			'woocommerce_cart_item_restored',
			'woocommerce_cart_emptied',
			'woocommerce_applied_coupon',
			'woocommerce_removed_coupon',
			'woocommerce_checkout_update_order_review',
		) as $hook ) {
			add_action( $hook, array( $this, 'mark_dirty' ), 99 );
		}

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'mark_converted' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'mark_converted' ), 5 );

		add_action( 'shutdown', array( $this, 'save' ), 5 );

		// Корзина гостя должна найтись после входа: вход по SMS тоже
		// проходит через wp_login, так что одного хука достаточно.
		add_action( 'wp_login', array( $this, 'attach_on_login' ), 20, 2 );
	}

	/* ------------------------------------------------------------------
	 * Настройки и таблица
	 * ------------------------------------------------------------------ */

	/**
	 * Сбор корзин включён.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return vlacc_is_woo() && (bool) VL_Account_Settings::get( 'carts_enabled', 1 );
	}

	/**
	 * Имя таблицы.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'vlacc_carts';
	}

	/**
	 * Создать таблицу, если её ещё нет или схема устарела.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Создание таблицы.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			cart_key varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(32) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			items longtext NULL,
			item_count int(11) NOT NULL DEFAULT 0,
			total decimal(18,4) NOT NULL DEFAULT 0,
			currency varchar(8) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY cart_key (cart_key),
			KEY user_id (user_id),
			KEY status_updated (status,updated)
		) {$collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Через сколько минут корзина считается брошенной.
	 *
	 * @return int
	 */
	public static function abandoned_after() {
		$minutes = (int) VL_Account_Settings::get( 'carts_abandoned_after', 60 );

		return max( 5, $minutes );
	}

	/**
	 * Момент «столько-то назад» во времени сайта.
	 *
	 * Поле updated пишется через current_time(), поэтому и сравнивать нужно
	 * с локальным временем, иначе на сайтах не в UTC порог уезжает на часы.
	 *
	 * @param int $seconds Сколько секунд назад.
	 * @return string
	 */
	protected static function local_time_before( $seconds ) {
		$offset = (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );

		return gmdate( 'Y-m-d H:i:s', time() + $offset - (int) $seconds );
	}

	/* ------------------------------------------------------------------
	 * Сбор
	 * ------------------------------------------------------------------ */

	/**
	 * Ключ текущей корзины: своя кука, не зависящая от сессии WooCommerce.
	 *
	 * @return string
	 */
	public static function cart_key() {
		static $key = null;

		if ( null !== $key ) {
			// Держим суперглобал в согласии с выданным ключом: на него смотрит has_key().
			$_COOKIE[ self::COOKIE ] = $key;

			return $key;
		}

		$key = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$key = preg_replace( '/[^a-f0-9]/i', '', (string) $key );

		if ( strlen( $key ) !== 32 ) {
			$key = md5( wp_generate_password( 32, false ) . microtime() );

			if ( ! headers_sent() ) {
				setcookie(
					self::COOKIE,
					$key,
					time() + 30 * DAY_IN_SECONDS,
					COOKIEPATH ? COOKIEPATH : '/',
					COOKIE_DOMAIN,
					is_ssl(),
					true
				);
			}

			$_COOKIE[ self::COOKIE ] = $key;
		}

		return $key;
	}

	/**
	 * Ключ корзины уже выдан.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return ! empty( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Пометить корзину к сохранению.
	 *
	 * Ключ выдаём здесь, а не при сохранении: сохранение идёт на shutdown,
	 * когда заголовки уже отправлены и куку поставить нельзя — гость получал
	 * бы новый ключ на каждый запрос, а в отчёте плодились бы дубли.
	 */
	public function mark_dirty() {
		$this->dirty = true;

		self::cart_key();
	}

	/**
	 * Корзина превратилась в заказ.
	 *
	 * @param int|WC_Order $order Заказ.
	 */
	public function mark_converted( $order ) {
		$order_id = $order instanceof WC_Order ? $order->get_id() : (int) $order;

		$this->converted = $order_id;
		$this->dirty     = true;
	}

	/**
	 * Сохранить снимок корзины в конце запроса.
	 */
	public function save() {
		if ( ! $this->dirty || ! self::enabled() ) {
			return;
		}

		$this->dirty = false;

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		global $wpdb;

		$key   = self::cart_key();
		$table = self::table();
		$now   = current_time( 'mysql' );

		// Заказ оформлен: корзину не обнуляем, а помечаем как купленную.
		if ( $this->converted ) {
			$order_id        = $this->converted;
			$this->converted = 0;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'status'   => 'converted',
					'order_id' => $order_id,
					'user_id'  => get_current_user_id(),
					'updated'  => $now,
				),
				array( 'cart_key' => $key ),
				array( '%s', '%d', '%d', '%s' ),
				array( '%s' )
			);

			return;
		}

		$items = $this->snapshot();

		// Корзину опустошили руками — записи о ней не держим.
		if ( ! $items ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $table, array( 'cart_key' => $key, 'status' => 'active' ), array( '%s', '%s' ) );

			return;
		}

		$contacts = $this->contacts();

		$data = array(
			'cart_key'   => $key,
			'user_id'    => get_current_user_id(),
			'email'      => $contacts['email'],
			'phone'      => $contacts['phone'],
			'name'       => $contacts['name'],
			'items'      => wp_json_encode( $items ),
			'item_count' => array_sum( wp_list_pluck( $items, 'qty' ) ),
			'total'      => (float) WC()->cart->get_total( 'edit' ),
			'currency'   => get_woocommerce_currency(),
			'status'     => 'active',
			'updated'    => $now,
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE cart_key = %s", $key ) );

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => $exists ), $formats, array( '%d' ) );

			return;
		}

		$data['created'] = $now;
		$formats[]       = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $table, $data, $formats );
	}

	/**
	 * Снимок содержимого корзины.
	 *
	 * @return array
	 */
	protected function snapshot() {
		$items = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$product_id = $product->get_id();

			$items[] = array(
				'id'      => $product_id,
				'parent'  => $product->get_parent_id(),
				'name'    => $product->get_name(),
				'sku'     => $product->get_sku(),
				'qty'     => isset( $item['quantity'] ) ? (float) $item['quantity'] : 1,
				'price'   => (float) $product->get_price(),
				'total'   => isset( $item['line_total'] ) ? (float) $item['line_total'] : 0,
				'link'    => get_permalink( $product->get_parent_id() ? $product->get_parent_id() : $product_id ),
				'variant' => $product->is_type( 'variation' ) && function_exists( 'wc_get_formatted_variation' )
					? wc_get_formatted_variation( $product, true, false )
					: '',
			);
		}

		return $items;
	}

	/**
	 * Контакты покупателя, какие уже известны.
	 *
	 * @return array
	 */
	protected function contacts() {
		$email = '';
		$phone = '';
		$name  = '';

		if ( WC()->customer ) {
			$email = (string) WC()->customer->get_billing_email();
			$phone = (string) WC()->customer->get_billing_phone();
			$name  = trim( WC()->customer->get_billing_first_name() . ' ' . WC()->customer->get_billing_last_name() );
		}

		$user_id = get_current_user_id();

		if ( $user_id ) {
			$user = get_user_by( 'id', $user_id );

			if ( $user ) {
				if ( '' === $email && VL_Account_User::has_real_email( $user ) ) {
					$email = $user->user_email;
				}

				if ( '' === $name ) {
					$name = trim( $user->first_name . ' ' . $user->last_name );
					$name = '' !== $name ? $name : $user->display_name;
				}
			}

			if ( '' === $phone ) {
				$phone = VL_Account_User::get_phone( $user_id );
			}
		}

		// Технический адрес в отчёте не нужен.
		if ( $email && preg_match( '/@phone\./', $email ) ) {
			$email = '';
		}

		return array(
			'email' => sanitize_text_field( $email ),
			'phone' => sanitize_text_field( $phone ),
			'name'  => sanitize_text_field( $name ),
		);
	}

	/**
	 * После входа привязать корзину гостя к аккаунту.
	 *
	 * @param int|WP_User $user Пользователь.
	 */
	public function attach_to_user( $user = 0 ) {
		$user_id = $user instanceof WP_User ? $user->ID : (int) $user;
		$user_id = $user_id ? $user_id : get_current_user_id();

		// Ключа нет — значит и корзины гостя не было, привязывать нечего.
		if ( ! $user_id || ! self::enabled() || ! self::has_key() ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array( 'user_id' => $user_id ),
			array( 'cart_key' => self::cart_key() ),
			array( '%d' ),
			array( '%s' )
		);
	}

	/**
	 * То же при обычном входе в WordPress.
	 *
	 * @param string  $login Логин.
	 * @param WP_User $user  Пользователь.
	 */
	public function attach_on_login( $login, $user = null ) {
		if ( $user instanceof WP_User ) {
			$this->attach_to_user( $user->ID );
		}
	}

	/* ------------------------------------------------------------------
	 * Выборка
	 * ------------------------------------------------------------------ */

	/**
	 * Список корзин.
	 *
	 * @param array $args Аргументы: status, search, paged, per_page.
	 * @return array ['rows' => array, 'total' => int]
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => 'abandoned',
				'search'   => '',
				'paged'    => 1,
				'per_page' => 20,
			)
		);

		$table = self::table();
		$where = array( '1=1' );
		$params = array();

		$threshold = self::local_time_before( self::abandoned_after() * MINUTE_IN_SECONDS );

		if ( 'abandoned' === $args['status'] ) {
			$where[]  = "status = 'active'";
			$where[]  = 'item_count > 0';
			$where[]  = 'updated < %s';
			$params[] = $threshold;
		} elseif ( 'active' === $args['status'] ) {
			$where[]  = "status = 'active'";
			$where[]  = 'updated >= %s';
			$params[] = $threshold;
		} elseif ( 'converted' === $args['status'] ) {
			$where[] = "status = 'converted'";
		}

		if ( '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '(email LIKE %s OR phone LIKE %s OR name LIKE %s OR items LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql_where = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		$list_sql    = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY updated DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? array_map( array( __CLASS__, 'prepare_row' ), $rows ) : array(),
			'total' => $total,
		);
	}

	/**
	 * Сводка по корзинам.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;

		// Сводку показываем и в меню админки на каждой странице — короткий кэш.
		$cached = get_transient( 'vlacc_carts_stats' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table     = self::table();
		$threshold = self::local_time_before( self::abandoned_after() * MINUTE_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$abandoned = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS sum FROM {$table}
				 WHERE status = 'active' AND item_count > 0 AND updated < %s",
				$threshold
			),
			ARRAY_A
		);

		$active = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND updated >= %s", $threshold )
		);

		$converted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'converted'" );
		// phpcs:enable

		$stats = array(
			'abandoned'     => isset( $abandoned['cnt'] ) ? (int) $abandoned['cnt'] : 0,
			'abandoned_sum' => isset( $abandoned['sum'] ) ? (float) $abandoned['sum'] : 0,
			'active'        => $active,
			'converted'     => $converted,
		);

		set_transient( 'vlacc_carts_stats', $stats, MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Подготовить строку к выводу.
	 *
	 * @param array $row Строка таблицы.
	 * @return array
	 */
	public static function prepare_row( $row ) {
		$row['items']   = json_decode( isset( $row['items'] ) ? $row['items'] : '', true );
		$row['items']   = is_array( $row['items'] ) ? $row['items'] : array();
		$row['user_id'] = (int) $row['user_id'];

		$row['user'] = $row['user_id'] ? get_user_by( 'id', $row['user_id'] ) : false;

		return $row;
	}

	/**
	 * Удалить корзину.
	 *
	 * @param int $id Запись.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* ------------------------------------------------------------------
	 * Экран в админке
	 * ------------------------------------------------------------------ */

	/**
	 * Страница «Брошенные корзины».
	 */
	public static function render_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'abandoned';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$status = in_array( $status, array( 'abandoned', 'active', 'converted', 'all' ), true ) ? $status : 'abandoned';

		if ( ! self::enabled() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Брошенные корзины', 'vl-account' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Сбор корзин выключен: включите его в настройках плагина (вкладка «Заказы и письма»).', 'vl-account' ) . '</p></div></div>';

			return;
		}

		self::maybe_install();

		$per_page = 20;
		$result   = self::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'paged'    => $paged,
				'per_page' => $per_page,
			)
		);

		$stats = self::stats();
		$pages = (int) ceil( $result['total'] / $per_page );
		$base  = admin_url( 'admin.php?page=vl-account-carts' );

		$tabs = array(
			'abandoned' => sprintf(
				/* translators: %d — количество корзин. */
				__( 'Брошенные (%d)', 'vl-account' ),
				$stats['abandoned']
			),
			'active'    => sprintf(
				/* translators: %d — количество корзин. */
				__( 'Активные сейчас (%d)', 'vl-account' ),
				$stats['active']
			),
			'converted' => sprintf(
				/* translators: %d — количество корзин. */
				__( 'Стали заказом (%d)', 'vl-account' ),
				$stats['converted']
			),
			'all'       => __( 'Все', 'vl-account' ),
		);
		?>
		<div class="wrap vlacc-carts">
			<h1><?php esc_html_e( 'Брошенные корзины', 'vl-account' ); ?></h1>

			<p class="description" style="max-width:900px">
				<?php
				printf(
					/* translators: %d — минуты. */
					esc_html__( 'Корзина считается брошенной, если её не трогали больше %d минут и заказ так и не оформлен. Срок и хранение настраиваются в «Личный кабинет → Заказы и письма».', 'vl-account' ),
					(int) self::abandoned_after()
				);
				?>
			</p>

			<?php if ( $stats['abandoned'] > 0 ) : ?>
				<p>
					<strong>
						<?php
						printf(
							/* translators: 1: количество корзин, 2: сумма. */
							esc_html__( 'Сейчас брошено корзин: %1$d на сумму %2$s', 'vl-account' ),
							(int) $stats['abandoned'],
							esc_html( wp_strip_all_tags( wc_price( $stats['abandoned_sum'] ) ) )
						);
						?>
					</strong>
				</p>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php $vl_first = true; ?>
				<?php foreach ( $tabs as $vl_slug => $vl_label ) : ?>
					<li>
						<?php echo $vl_first ? '' : ' | '; ?>
						<a href="<?php echo esc_url( add_query_arg( 'status', $vl_slug, $base ) ); ?>"
							class="<?php echo $status === $vl_slug ? 'current' : ''; ?>"><?php echo esc_html( $vl_label ); ?></a>
					</li>
					<?php $vl_first = false; ?>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:12px 0 8px">
				<input type="hidden" name="page" value="vl-account-carts" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'телефон, e-mail, имя или товар', 'vl-account' ); ?>" />
					<?php submit_button( __( 'Найти', 'vl-account' ), 'secondary', '', false ); ?>
				</p>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:22%"><?php esc_html_e( 'Покупатель', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Товары в корзине', 'vl-account' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Сумма', 'vl-account' ); ?></th>
						<th style="width:170px"><?php esc_html_e( 'Последнее действие', 'vl-account' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Статус', 'vl-account' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $result['rows'] ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'Пока пусто.', 'vl-account' ); ?></td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $result['rows'] as $vl_row ) : ?>
						<tr>
							<td><?php self::render_customer( $vl_row ); ?></td>
							<td><?php self::render_items( $vl_row ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $vl_row['total'] ) ); ?></td>
							<td>
								<?php
								$vl_time = strtotime( $vl_row['updated'] );
								echo esc_html( $vl_time ? date_i18n( 'd.m.Y H:i', $vl_time ) : '—' );
								?>
								<br />
								<span class="description">
									<?php
									if ( $vl_time ) {
										printf(
											/* translators: %s — сколько времени прошло. */
											esc_html__( '%s назад', 'vl-account' ),
											esc_html( human_time_diff( $vl_time - ( (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) ), time() ) )
										);
									}
									?>
								</span>
							</td>
							<td><?php self::render_status( $vl_row ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', add_query_arg( array( 'status' => $status, 's' => $search ), $base ) ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Колонка «Покупатель».
	 *
	 * @param array $row Строка.
	 */
	protected static function render_customer( $row ) {
		$user = isset( $row['user'] ) ? $row['user'] : false;

		if ( $user instanceof WP_User ) {
			printf(
				'<strong><a href="%1$s">%2$s</a></strong><br />',
				esc_url( get_edit_user_link( $user->ID ) ),
				esc_html( $row['name'] ? $row['name'] : $user->display_name )
			);
		} else {
			printf(
				'<strong>%s</strong><br />',
				esc_html( $row['name'] ? $row['name'] : __( 'Неизвестный покупатель', 'vl-account' ) )
			);
		}

		if ( ! empty( $row['phone'] ) ) {
			printf( '<span class="description">%s</span><br />', esc_html( $row['phone'] ) );
		}

		if ( ! empty( $row['email'] ) ) {
			printf( '<span class="description">%s</span><br />', esc_html( $row['email'] ) );
		}

		if ( ! $user instanceof WP_User && empty( $row['phone'] ) && empty( $row['email'] ) ) {
			printf(
				'<span class="description">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s — короткий идентификатор корзины. */
						__( 'гость, корзина №%s', 'vl-account' ),
						substr( (string) $row['cart_key'], 0, 8 )
					)
				)
			);
		}
	}

	/**
	 * Колонка «Товары».
	 *
	 * @param array $row Строка.
	 */
	protected static function render_items( $row ) {
		if ( empty( $row['items'] ) ) {
			echo '—';

			return;
		}

		echo '<ul style="margin:0">';

		foreach ( $row['items'] as $item ) {
			$name = isset( $item['name'] ) ? $item['name'] : '';
			$qty  = isset( $item['qty'] ) ? (float) $item['qty'] : 1;
			$link = isset( $item['link'] ) ? $item['link'] : '';

			printf(
				'<li>%1$s%2$s%3$s%4$s</li>',
				$link ? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . '</a>' : esc_html( $name ),
				! empty( $item['variant'] ) ? ' <span class="description">(' . esc_html( $item['variant'] ) . ')</span>' : '',
				$qty > 1 ? ' &times; ' . esc_html( (string) (int) $qty ) : '',
				! empty( $item['sku'] ) ? ' <span class="description">' . esc_html( $item['sku'] ) . '</span>' : ''
			);
		}

		echo '</ul>';
	}

	/**
	 * Колонка «Статус».
	 *
	 * @param array $row Строка.
	 */
	protected static function render_status( $row ) {
		if ( 'converted' === $row['status'] ) {
			$order_id = (int) $row['order_id'];

			if ( $order_id && function_exists( 'wc_get_order' ) && wc_get_order( $order_id ) ) {
				printf(
					'<span style="color:#2a9d3f">%1$s</span><br /><a href="%2$s">%3$s</a>',
					esc_html__( 'Оформлен заказ', 'vl-account' ),
					esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ),
					esc_html( '#' . $order_id )
				);

				return;
			}

			echo '<span style="color:#2a9d3f">' . esc_html__( 'Оформлен заказ', 'vl-account' ) . '</span>';

			return;
		}

		$updated = strtotime( $row['updated'] );
		$limit   = strtotime( self::local_time_before( self::abandoned_after() * MINUTE_IN_SECONDS ) );

		if ( $updated && $updated < $limit ) {
			echo '<span style="color:#d98f00">' . esc_html__( 'Брошена', 'vl-account' ) . '</span>';

			return;
		}

		echo '<span class="description">' . esc_html__( 'В работе', 'vl-account' ) . '</span>';
	}

	/**
	 * Чистка старых записей.
	 */
	public static function cleanup() {
		global $wpdb;

		$days = (int) VL_Account_Settings::get( 'carts_keep_days', 30 );

		if ( $days < 1 ) {
			return;
		}

		$limit = self::local_time_before( $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE updated < %s', $limit ) );
	}
}
