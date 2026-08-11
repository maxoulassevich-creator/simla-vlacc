<?php
/**
 * Личный кабинет: эндпоинты, меню, вывод разделов.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Кабинет.
 */
class VL_Account_MyAccount {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_MyAccount|null
	 */
	private static $instance = null;

	/**
	 * Наши эндпоинты WooCommerce: слаг => заголовок.
	 *
	 * @return array
	 */
	public static function endpoints() {
		return array(
			'vlacc-wishlist'      => __( 'Избранное', 'vl-account' ),
			'vlacc-promo'         => __( 'Промокоды', 'vl-account' ),
			'vlacc-bonus'         => __( 'Бонусы', 'vl-account' ),
			'vlacc-subscriptions' => __( 'Подписки', 'vl-account' ),
			'vlacc-security'      => __( 'Пароль и вход', 'vl-account' ),
		);
	}

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_MyAccount
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
		add_action( 'init', array( __CLASS__, 'register_endpoints' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ), 20 );

		foreach ( array_keys( self::endpoints() ) as $endpoint ) {
			add_action( 'woocommerce_account_' . $endpoint . '_endpoint', array( $this, 'render_wc_endpoint' ) );
		}

		// Заменяем стандартную «панель» WooCommerce на нашу сводку.
		add_action( 'woocommerce_account_content', array( $this, 'maybe_render_dashboard' ), 5 );

		// Сброс ЧПУ после сохранения настроек.
		add_action( 'vlacc_settings_saved', array( $this, 'flush' ) );
	}

	/**
	 * Регистрация эндпоинтов.
	 */
	public static function register_endpoints() {
		if ( ! vlacc_is_woo() ) {
			return;
		}

		foreach ( array_keys( self::endpoints() ) as $endpoint ) {
			add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * Сброс правил ЧПУ.
	 */
	public function flush() {
		self::register_endpoints();
		flush_rewrite_rules();
	}

	/**
	 * Пункты меню WooCommerce (если тема выводит стандартное меню).
	 *
	 * @param array $items Пункты.
	 * @return array
	 */
	public function menu_items( $items ) {
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'], $items['downloads'] );

		$tabs = VL_Account_Router::tabs();

		if ( isset( $tabs['wishlist'] ) ) {
			$items['vlacc-wishlist'] = __( 'Избранное', 'vl-account' );
		}

		if ( isset( $tabs['promo'] ) ) {
			$items['vlacc-promo'] = __( 'Промокоды', 'vl-account' );
		}

		if ( isset( $tabs['bonus'] ) ) {
			$items['vlacc-bonus'] = __( 'Бонусы', 'vl-account' );
		}

		if ( isset( $tabs['subscriptions'] ) ) {
			$items['vlacc-subscriptions'] = __( 'Подписки', 'vl-account' );
		}

		if ( isset( $tabs['security'] ) ) {
			$items['vlacc-security'] = __( 'Пароль и вход', 'vl-account' );
		}

		if ( $logout ) {
			$items['customer-logout'] = __( 'Выйти', 'vl-account' );
		}

		return $items;
	}

	/**
	 * Вывод содержимого наших эндпоинтов WooCommerce.
	 */
	public function render_wc_endpoint() {
		$tab = VL_Account_Router::current_tab();

		echo '<div class="vl-account vl-account--embedded">';
		self::render_tab( $tab );
		echo '</div>';
	}

	/**
	 * Сводка вместо стандартной панели WooCommerce.
	 */
	public function maybe_render_dashboard() {
		if ( ! vlacc_is_woo() || ! is_user_logged_in() ) {
			return;
		}

		// Только на «корневой» странице аккаунта, без эндпоинтов.
		global $wp;

		if ( ! empty( $wp->query_vars ) ) {
			$known = array_merge( array_keys( self::endpoints() ), array( 'orders', 'view-order', 'edit-account', 'edit-address', 'downloads', 'customer-logout', 'lost-password' ) );

			foreach ( $known as $endpoint ) {
				if ( isset( $wp->query_vars[ $endpoint ] ) ) {
					return;
				}
			}
		}

		remove_action( 'woocommerce_account_content', 'woocommerce_account_content', 10 );

		echo '<div class="vl-account vl-account--embedded">';
		self::render_tab( 'dashboard' );
		echo '</div>';
	}

	/**
	 * Полный кабинет (шорткод [vl_account]).
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return VL_Account_Shortcodes::render_auth( array( 'notice' => __( 'Войдите, чтобы открыть личный кабинет.', 'vl-account' ) ) );
		}

		$tab = VL_Account_Router::current_tab();

		if ( 'logout' === $tab ) {
			if ( ! headers_sent() ) {
				wp_safe_redirect( VL_Account_Auth::logout_url( home_url( '/' ) ) );
				exit;
			}

			return sprintf(
				'<p class="vl-links"><a class="vl-link" href="%s">%s</a></p>',
				esc_url( VL_Account_Auth::logout_url( home_url( '/' ) ) ),
				esc_html__( 'Подтвердите выход из кабинета', 'vl-account' )
			);
		}

		return vlacc_template(
			'account.php',
			array(
				'tab'  => $tab,
				'user' => wp_get_current_user(),
			),
			true
		);
	}

	/**
	 * Вывести конкретный раздел.
	 *
	 * @param string $tab Слаг.
	 */
	public static function render_tab( $tab ) {
		$user = wp_get_current_user();

		$args = array(
			'user'    => $user,
			'user_id' => $user->ID,
			'tab'     => $tab,
		);

		do_action( 'vlacc_before_tab', $tab, $args );

		$template = 'account/' . sanitize_file_name( $tab ) . '.php';
		$file     = VLACC_PATH . 'templates/' . $template;

		if ( file_exists( $file ) || locate_template( array( 'vl-account/' . $template ) ) ) {
			vlacc_template( $template, $args );
		} else {
			do_action( 'vlacc_render_tab_' . $tab, $args );
		}

		do_action( 'vlacc_after_tab', $tab, $args );
	}

	/**
	 * Меню кабинета.
	 *
	 * @param array $args Аргументы.
	 * @return string
	 */
	public static function render_menu( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'class'   => '',
				'icons'   => true,
				'current' => VL_Account_Router::current_tab(),
			)
		);

		return vlacc_template( 'account-menu.php', $args, true );
	}
}
