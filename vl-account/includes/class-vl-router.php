<?php
/**
 * Маршрутизация разделов личного кабинета.
 *
 * Работает в двух режимах:
 *  — на странице «Мой аккаунт» WooCommerce используются её эндпоинты (ЧПУ);
 *  — на любой другой странице с шорткодом [vl_account] — параметр ?vlacc_tab=...
 *
 * Это решает задачу «не работает левое меню в ЛК»: ссылки меню всегда ведут
 * на существующий адрес, независимо от настроек ЧПУ и темы.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Роутер кабинета.
 */
class VL_Account_Router {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Router|null
	 */
	private static $instance = null;

	/**
	 * Кэш текущего раздела.
	 *
	 * @var string|null
	 */
	private static $current = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Router
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
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
	}

	/**
	 * Регистрируем свой параметр.
	 *
	 * @param array $vars Переменные запроса.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'vlacc_tab';

		return $vars;
	}

	/**
	 * Описание всех разделов кабинета.
	 *
	 * @return array
	 */
	public static function tabs() {
		$tabs = array(
			'dashboard'     => array(
				'title' => __( 'Кабинет', 'vl-account' ),
				'icon'  => 'user',
			),
			'orders'        => array(
				'title' => __( 'Мои заказы', 'vl-account' ),
				'icon'  => 'orders',
			),
			'wishlist'      => array(
				'title' => __( 'Избранное', 'vl-account' ),
				'icon'  => 'heart',
			),
			'promo'         => array(
				'title' => __( 'Промокоды', 'vl-account' ),
				'icon'  => 'gift',
			),
			'bonus'         => array(
				'title' => __( 'Бонусы', 'vl-account' ),
				'icon'  => 'star',
			),
			'subscriptions' => array(
				'title' => __( 'Подписки', 'vl-account' ),
				'icon'  => 'bell',
			),
			'profile'       => array(
				'title' => __( 'Мои данные', 'vl-account' ),
				'icon'  => 'settings',
			),
			'security'      => array(
				'title' => __( 'Пароль и вход', 'vl-account' ),
				'icon'  => 'lock',
			),
		);

		$enabled = (array) VL_Account_Settings::get( 'tabs', array_keys( $tabs ) );

		foreach ( array_keys( $tabs ) as $slug ) {
			if ( ! in_array( $slug, $enabled, true ) ) {
				unset( $tabs[ $slug ] );
			}
		}

		// Адрес заказа — служебный раздел, в меню не показываем.
		$tabs['order-view'] = array(
			'title'  => __( 'Заказ', 'vl-account' ),
			'icon'   => 'orders',
			'hidden' => true,
		);

		$tabs['logout'] = array(
			'title' => __( 'Выйти', 'vl-account' ),
			'icon'  => 'logout',
		);

		return apply_filters( 'vlacc_account_tabs', $tabs );
	}

	/**
	 * Текущий раздел.
	 *
	 * @return string
	 */
	public static function current_tab() {
		if ( null !== self::$current ) {
			return self::$current;
		}

		$tab = 'dashboard';

		// 1. Наш параметр.
		$requested = get_query_var( 'vlacc_tab' );

		if ( ! $requested && isset( $_GET['vlacc_tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = sanitize_key( wp_unslash( $_GET['vlacc_tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// 2. Эндпоинты WooCommerce, если мы на её странице аккаунта.
		if ( ! $requested && vlacc_is_woo() ) {
			$map = array(
				'orders'           => 'orders',
				'view-order'       => 'order-view',
				'edit-account'     => 'profile',
				'edit-address'     => 'profile',
				'customer-logout'  => 'logout',
				'downloads'        => 'orders',
				'vlacc-wishlist'   => 'wishlist',
				'vlacc-promo'      => 'promo',
				'vlacc-bonus'      => 'bonus',
				'vlacc-subscriptions' => 'subscriptions',
				'vlacc-security'   => 'security',
			);

			foreach ( $map as $endpoint => $slug ) {
				if ( '' !== get_query_var( $endpoint, '' ) || ( isset( $GLOBALS['wp']->query_vars[ $endpoint ] ) ) ) {
					$requested = $slug;
					break;
				}
			}
		}

		$tabs = self::tabs();

		if ( $requested && isset( $tabs[ $requested ] ) ) {
			$tab = $requested;
		}

		self::$current = apply_filters( 'vlacc_current_tab', $tab );

		return self::$current;
	}

	/**
	 * ID просматриваемого заказа.
	 *
	 * @return int
	 */
	public static function current_order_id() {
		$id = 0;

		if ( isset( $_GET['vlacc_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id = absint( $_GET['vlacc_order'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $id && vlacc_is_woo() ) {
			$id = absint( get_query_var( 'view-order' ) );
		}

		return $id;
	}

	/**
	 * Ссылка на раздел.
	 *
	 * @param string $tab  Слаг раздела.
	 * @param array  $args Доп. параметры.
	 * @return string
	 */
	public static function url( $tab = 'dashboard', $args = array() ) {
		if ( 'logout' === $tab ) {
			return VL_Account_Auth::logout_url( home_url( '/' ) );
		}

		$base = VL_Account_Settings::account_url();

		// На странице WooCommerce используем её эндпоинты — красивые ЧПУ.
		$page_id = (int) VL_Account_Settings::get( 'account_page', 0 );

		if ( ! $page_id && vlacc_is_woo() ) {
			$wc_map = array(
				'orders'        => 'orders',
				'profile'       => 'edit-account',
				'wishlist'      => 'vlacc-wishlist',
				'promo'         => 'vlacc-promo',
				'bonus'         => 'vlacc-bonus',
				'subscriptions' => 'vlacc-subscriptions',
				'security'      => 'vlacc-security',
			);

			if ( 'dashboard' === $tab ) {
				return $args ? add_query_arg( $args, $base ) : $base;
			}

			if ( 'order-view' === $tab && ! empty( $args['order'] ) ) {
				return wc_get_endpoint_url( 'view-order', (int) $args['order'], $base );
			}

			if ( isset( $wc_map[ $tab ] ) ) {
				return wc_get_endpoint_url( $wc_map[ $tab ], '', $base );
			}
		}

		$query = array( 'vlacc_tab' => $tab );

		if ( 'dashboard' === $tab ) {
			$query = array();
		}

		if ( ! empty( $args['order'] ) ) {
			$query['vlacc_tab']   = 'order-view';
			$query['vlacc_order'] = (int) $args['order'];
			unset( $args['order'] );
		}

		$query = array_merge( $query, $args );

		return $query ? add_query_arg( $query, $base ) : $base;
	}

	/**
	 * Мы на странице кабинета?
	 *
	 * @return bool
	 */
	public static function is_account_page() {
		$page_id = (int) VL_Account_Settings::get( 'account_page', 0 );

		if ( $page_id && is_page( $page_id ) ) {
			return true;
		}

		if ( vlacc_is_woo() && function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		return false;
	}
}
