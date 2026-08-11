<?php
/**
 * Мост к плагину WishSuite: избранное из его кнопок-сердечек в нашем кабинете.
 *
 * WishSuite держит избранное в своей таблице {prefix}wishsuite_list (для гостей —
 * ещё и в куке wishsuite_item_list), а VL Account — в метаполе пользователя.
 * Раздел «Избранное» в кабинете должен показывать оба списка как один, поэтому
 * данные читаются из WishSuite и объединяются с нашими, а изменения из кабинета
 * возвращаются обратно в WishSuite — чтобы сердечко у товара было закрашено.
 *
 * Плагин WishSuite не изменяется: работаем через его публичные классы
 * (\WishSuite\Manage_Data, \WishSuite\Frontend\Manage_Wishlist).
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Интеграция с WishSuite.
 */
class VL_Account_WishSuite {

	/**
	 * Кука со списком избранного гостя.
	 */
	const GUEST_COOKIE = 'wishsuite_item_list';

	/**
	 * Кука с временным ID гостя.
	 */
	const GUEST_ID_COOKIE = 'wishsuite_temp_user_id';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_WishSuite|null
	 */
	private static $instance = null;

	/**
	 * Кэш списков в рамках запроса.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Защита от зацикливания синхронизации.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_WishSuite
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
		if ( self::plugin_active() ) {
			// Список избранного кабинета = наш список + список WishSuite.
			add_filter( 'vlacc_wishlist_items', array( $this, 'merge_items' ), 10, 2 );

			// Изменения из кабинета возвращаем в WishSuite.
			add_action( 'vlacc_wishlist_updated', array( $this, 'sync_to_wishsuite' ), 10, 3 );

			// Избранное гостя переносим в аккаунт: сам WishSuite этого не делает.
			add_action( 'vlacc_wishlist_merged', array( $this, 'merge_guest' ) );
			add_action( 'wp_login', array( $this, 'merge_guest_on_login' ), 20, 2 );

			// Одно сердечко на карточке товара вместо двух.
			add_filter( 'vlacc_setting_wishlist_on_product', array( $this, 'maybe_hide_our_button' ) );
		}

		// Кнопки размеров работают и без WishSuite — в карточке товара.
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 20 );
	}

	/* ------------------------------------------------------------------
	 * Доступность
	 * ------------------------------------------------------------------ */

	/**
	 * Плагин WishSuite активен.
	 *
	 * @return bool
	 */
	public static function plugin_active() {
		return class_exists( '\WishSuite\Frontend\Manage_Wishlist' ) && class_exists( '\WishSuite\Manage_Data' );
	}

	/**
	 * Интеграция включена.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return self::plugin_active() && VL_Account_Settings::get( 'ws_enabled', 1 );
	}

	/**
	 * Режим объединения списков: merge | wishsuite | vlacc.
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = VL_Account_Settings::get( 'ws_source', 'merge' );

		return in_array( $mode, array( 'merge', 'wishsuite', 'vlacc' ), true ) ? $mode : 'merge';
	}

	/* ------------------------------------------------------------------
	 * Чтение избранного WishSuite
	 * ------------------------------------------------------------------ */

	/**
	 * Список товаров в избранном WishSuite.
	 *
	 * @param int $user_id Пользователь (0 — гость).
	 * @return array ID товаров.
	 */
	public static function items( $user_id = 0 ) {
		$user_id = (int) $user_id;

		if ( ! self::enabled() ) {
			return array();
		}

		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}

		$items = $user_id ? self::items_from_db( $user_id ) : self::items_from_cookie();

		self::$cache[ $user_id ] = $items;

		return $items;
	}

	/**
	 * Сбросить кэш списков в рамках запроса.
	 *
	 * Нужен, если избранное изменили в обход кабинета — например, кодом темы.
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * Избранное пользователя из таблицы WishSuite.
	 *
	 * @param int $user_id Пользователь.
	 * @return array
	 */
	protected static function items_from_db( $user_id ) {
		$items = array();

		try {
			$rows = \WishSuite\Manage_Data::instance()->read(
				array(
					'user_id' => (int) $user_id,
					'orderby' => 'id',
					'order'   => 'DESC',
				)
			);
		} catch ( Throwable $e ) {
			return array();
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;

			if ( $product_id ) {
				$items[] = $product_id;
			}
		}

		return array_values( array_unique( $items ) );
	}

	/**
	 * Избранное гостя из куки WishSuite.
	 *
	 * @return array
	 */
	protected static function items_from_cookie() {
		$name = self::guest_cookie_name();

		if ( empty( $_COOKIE[ $name ] ) ) {
			return array();
		}

		$raw = json_decode( wp_unslash( $_COOKIE[ $name ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/**
	 * Имя куки со списком гостя (на мультисайте у неё суффикс).
	 *
	 * @return string
	 */
	protected static function guest_cookie_name() {
		if ( self::plugin_active() ) {
			try {
				return \WishSuite\Frontend\Manage_Wishlist::instance()->get_cookie_name();
			} catch ( Throwable $e ) {
				// Ниже вернём имя по умолчанию.
			}
		}

		$name = self::GUEST_COOKIE;

		if ( is_multisite() ) {
			$name .= '_' . get_current_blog_id();
		}

		return $name;
	}

	/* ------------------------------------------------------------------
	 * Объединение списков
	 * ------------------------------------------------------------------ */

	/**
	 * Итоговое избранное кабинета.
	 *
	 * @param array $items   Наш список.
	 * @param int   $user_id Пользователь.
	 * @return array
	 */
	public function merge_items( $items, $user_id = 0 ) {
		if ( ! self::enabled() ) {
			return $items;
		}

		$items = is_array( $items ) ? $items : array();
		$mode  = self::mode();

		if ( 'vlacc' === $mode ) {
			return $items;
		}

		$external = self::items( $user_id ? (int) $user_id : get_current_user_id() );

		if ( 'wishsuite' === $mode ) {
			return $external;
		}

		// Порядок: сначала свежие товары WishSuite, затем наши.
		return array_values( array_unique( array_merge( $external, $items ) ) );
	}

	/* ------------------------------------------------------------------
	 * Запись изменений в WishSuite
	 * ------------------------------------------------------------------ */

	/**
	 * Кабинет добавил или убрал товар — повторяем в WishSuite.
	 *
	 * @param int  $product_id Товар.
	 * @param bool $state      true — добавлен, false — убран.
	 * @param int  $user_id    Пользователь.
	 */
	public function sync_to_wishsuite( $product_id, $state = false, $user_id = 0 ) {
		if ( ! self::enabled() || ! VL_Account_Settings::get( 'ws_two_way', 1 ) || self::$syncing ) {
			return;
		}

		$product_id = (int) $product_id;

		if ( ! $product_id ) {
			return;
		}

		self::$syncing = true;

		try {
			if ( $state ) {
				self::add( $product_id, $user_id );
			} else {
				self::remove( $product_id, $user_id );
			}
		} catch ( Throwable $e ) {
			vlacc_log(
				'[WishSuite] Не удалось синхронизировать избранное',
				array(
					'product_id' => $product_id,
					'error'      => $e->getMessage(),
				)
			);
		}

		self::$syncing = false;
		self::$cache   = array();
	}

	/**
	 * Добавить товар в избранное WishSuite.
	 *
	 * @param int $product_id Товар.
	 * @param int $user_id    Пользователь (0 — текущий/гость).
	 * @return bool
	 */
	public static function add( $product_id, $user_id = 0 ) {
		if ( ! self::plugin_active() ) {
			return false;
		}

		$product_id = (int) $product_id;
		$user_id    = (int) $user_id;

		if ( in_array( $product_id, self::items( $user_id ), true ) ) {
			return true;
		}

		self::$cache = array();

		// Для текущего посетителя пользуемся методом плагина: он ведёт и куки гостя.
		if ( ! $user_id || $user_id === get_current_user_id() ) {
			\WishSuite\Frontend\Manage_Wishlist::instance()->add_product( $product_id );

			return true;
		}

		\WishSuite\Manage_Data::instance()->create(
			array(
				'product_id' => $product_id,
				'user_id'    => $user_id,
			)
		);

		return true;
	}

	/**
	 * Убрать товар из избранного WishSuite.
	 *
	 * @param int $product_id Товар.
	 * @param int $user_id    Пользователь (0 — текущий/гость).
	 * @return bool
	 */
	public static function remove( $product_id, $user_id = 0 ) {
		if ( ! self::plugin_active() ) {
			return false;
		}

		$product_id = (int) $product_id;
		$user_id    = (int) $user_id;

		self::$cache = array();

		if ( ! $user_id || $user_id === get_current_user_id() ) {
			\WishSuite\Frontend\Manage_Wishlist::instance()->remove_product( $product_id );

			return true;
		}

		\WishSuite\Manage_Data::instance()->delete( $user_id, $product_id );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Перенос избранного гостя
	 * ------------------------------------------------------------------ */

	/**
	 * Перенести избранное гостя в аккаунт после входа.
	 *
	 * WishSuite пишет список гостя в куку и в таблицу под временным ID, но при
	 * входе ничего не переносит — товары «пропадают». Здесь переносим.
	 *
	 * @param int $user_id Пользователь.
	 */
	public function merge_guest( $user_id ) {
		if ( ! self::enabled() || ! VL_Account_Settings::get( 'ws_merge_guest', 1 ) ) {
			return;
		}

		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return;
		}

		$guest = self::items_from_cookie();
		$temp  = self::guest_temp_id();

		if ( $temp ) {
			$guest = array_merge( $guest, self::items_from_db( $temp ) );
		}

		$guest = array_values( array_unique( array_filter( array_map( 'absint', $guest ) ) ) );

		if ( ! $guest ) {
			return;
		}

		$existing = self::items_from_db( $user_id );
		$added    = 0;

		foreach ( $guest as $product_id ) {
			if ( in_array( $product_id, $existing, true ) ) {
				continue;
			}

			\WishSuite\Manage_Data::instance()->create(
				array(
					'product_id' => $product_id,
					'user_id'    => $user_id,
				)
			);

			++$added;
		}

		// Временные записи гостя больше не нужны.
		if ( $temp ) {
			foreach ( $guest as $product_id ) {
				\WishSuite\Manage_Data::instance()->delete( $temp, $product_id );
			}
		}

		self::clear_guest_cookies();

		self::$cache = array();

		if ( $added ) {
			vlacc_log(
				'[WishSuite] Избранное гостя перенесено в аккаунт',
				array(
					'user_id' => $user_id,
					'count'   => $added,
				)
			);
		}
	}

	/**
	 * Перенос при обычном входе в WordPress (не через формы кабинета).
	 *
	 * @param string  $login Логин.
	 * @param WP_User $user  Пользователь.
	 */
	public function merge_guest_on_login( $login, $user = null ) {
		if ( $user instanceof WP_User ) {
			$this->merge_guest( $user->ID );
		}
	}

	/**
	 * Временный ID гостя WishSuite.
	 *
	 * @return int
	 */
	protected static function guest_temp_id() {
		return isset( $_COOKIE[ self::GUEST_ID_COOKIE ] ) ? absint( wp_unslash( $_COOKIE[ self::GUEST_ID_COOKIE ] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Очистить куки гостя WishSuite.
	 */
	protected static function clear_guest_cookies() {
		$path   = COOKIEPATH ? COOKIEPATH : '/';
		$cookie = self::guest_cookie_name();

		if ( ! headers_sent() ) {
			setcookie( $cookie, '', time() - 3600, $path, COOKIE_DOMAIN );
			setcookie( self::GUEST_ID_COOKIE, '', time() - 3600, $path, COOKIE_DOMAIN );
		}

		unset( $_COOKIE[ $cookie ], $_COOKIE[ self::GUEST_ID_COOKIE ] );
	}

	/* ------------------------------------------------------------------
	 * Фронтенд
	 * ------------------------------------------------------------------ */

	/**
	 * Не выводить нашу кнопку-сердечко, если её уже выводит WishSuite.
	 *
	 * @param mixed $value Значение настройки.
	 * @return mixed
	 */
	public function maybe_hide_our_button( $value ) {
		if ( ! self::enabled() || ! VL_Account_Settings::get( 'ws_hide_our_button', 1 ) ) {
			return $value;
		}

		return 0;
	}

	/**
	 * Кнопки размеров включены.
	 *
	 * @return bool
	 */
	public static function size_buttons_enabled() {
		return (bool) VL_Account_Settings::get( 'ws_size_buttons', 1 );
	}

	/**
	 * Где выводить кнопки размеров: all — везде, wishsuite — только в быстрой корзине.
	 *
	 * @return string
	 */
	public static function size_scope() {
		$scope = VL_Account_Settings::get( 'ws_size_scope', 'all' );

		return 'wishsuite' === $scope ? 'wishsuite' : 'all';
	}

	/**
	 * Скрипт доработок: кнопки размеров и связка с WishSuite.
	 */
	public function assets() {
		if ( ! self::enabled() && ! self::size_buttons_enabled() ) {
			return;
		}

		wp_register_script(
			'vl-wishsuite',
			VLACC_URL . 'assets/js/vl-wishsuite.js',
			array(),
			VL_Account_Plugin::asset_version( 'assets/js/vl-wishsuite.js' ),
			true
		);

		wp_localize_script(
			'vl-wishsuite',
			'VLACC_WS',
			array(
				'size_buttons' => self::size_buttons_enabled(),
				'scope'        => self::size_scope(),
				'attributes'   => self::size_attributes(),
				'i18n'         => array(
					'out_of_stock' => __( 'нет в наличии', 'vl-account' ),
				),
			)
		);

		wp_enqueue_script( 'vl-wishsuite' );
	}

	/**
	 * Коды атрибутов, которые показываем кнопками.
	 *
	 * @return array Пустой массив — все атрибуты.
	 */
	public static function size_attributes() {
		$raw = (string) VL_Account_Settings::get( 'ws_size_attributes', '' );
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return array();
		}

		$list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		return array_values( array_map( 'sanitize_key', $list ) );
	}

	/* ------------------------------------------------------------------
	 * Диагностика
	 * ------------------------------------------------------------------ */

	/**
	 * Проверки связки для админки.
	 *
	 * @return array
	 */
	public static function checks() {
		$checks = array();

		$active = self::plugin_active();

		$checks[] = array(
			'title'  => __( 'Плагин WishSuite', 'vl-account' ),
			'status' => $active ? 'ok' : 'warn',
			'text'   => $active
				? __( 'Активен — избранное из его сердечек показывается в кабинете.', 'vl-account' )
				: __( 'Не найден. Кабинет работает на собственном избранном.', 'vl-account' ),
		);

		if ( ! $active ) {
			return $checks;
		}

		global $wpdb;

		$table  = $wpdb->prefix . 'wishsuite_list';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$checks[] = array(
			'title'  => __( 'Таблица избранного', 'vl-account' ),
			'status' => $exists ? 'ok' : 'error',
			'text'   => $exists
				? __( 'Найдена, список читается напрямую.', 'vl-account' )
				: __( 'Таблица wishsuite_list не создана — переактивируйте WishSuite.', 'vl-account' ),
		);

		$user_id = get_current_user_id();

		if ( $user_id ) {
			$own = get_user_meta( $user_id, VL_Account_Wishlist::META, true );
			$own = is_array( $own ) ? $own : array();

			$checks[] = array(
				'title'  => __( 'Избранное текущего пользователя', 'vl-account' ),
				'status' => 'ok',
				'text'   => sprintf(
					/* translators: 1: товаров в WishSuite, 2: товаров в кабинете. */
					esc_html__( 'В WishSuite: %1$d, в собственном хранилище кабинета: %2$d.', 'vl-account' ),
					count( self::items( $user_id ) ),
					count( $own )
				),
			);
		}

		return $checks;
	}
}
