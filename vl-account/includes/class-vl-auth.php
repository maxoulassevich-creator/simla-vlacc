<?php
/**
 * Авторизация: вход, выход, «волшебные» ссылки, срок жизни сессии.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Авторизация.
 */
class VL_Account_Auth {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Auth|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Auth
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
		// Задача «Если перешёл на другую страницу — снова нужно логиниться»:
		// продлеваем куки авторизации и всегда ставим «запомнить меня».
		add_filter( 'auth_cookie_expiration', array( $this, 'cookie_expiration' ), 20, 3 );

		// Вешаем на wp_loaded: он срабатывает после init, на котором
		// инициализируется сам плагин, и до вывода страницы.
		add_action( 'wp_loaded', array( $this, 'handle_magic_link' ), 1 );
		add_action( 'wp_loaded', array( $this, 'handle_logout' ), 2 );

		add_action( 'wp_login', array( $this, 'after_login' ), 10, 2 );

		// Разрешаем вход по телефону в стандартной форме WordPress/WooCommerce.
		add_filter( 'authenticate', array( $this, 'authenticate_by_phone' ), 20, 3 );

		// Куда отправлять после входа/выхода.
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 20, 3 );
		add_filter( 'woocommerce_login_redirect', array( $this, 'woo_login_redirect' ), 20, 2 );
		add_action( 'wp_logout', array( $this, 'clear_cookies' ) );
	}

	/**
	 * Срок жизни куки авторизации.
	 *
	 * @param int  $length   Длительность в секундах.
	 * @param int  $user_id  Пользователь.
	 * @param bool $remember «Запомнить меня».
	 * @return int
	 */
	public function cookie_expiration( $length, $user_id, $remember ) {
		$days = max( 1, (int) VL_Account_Settings::get( 'cookie_days', 30 ) );

		return $days * DAY_IN_SECONDS;
	}

	/**
	 * Программный вход пользователя.
	 *
	 * @param int  $user_id  Пользователь.
	 * @param bool $remember «Запомнить меня».
	 * @return true|WP_Error
	 */
	public static function login_user( $user_id, $remember = true ) {
		$user = get_user_by( 'id', (int) $user_id );

		if ( ! $user ) {
			return new WP_Error( 'vlacc_no_user', __( 'Пользователь не найден.', 'vl-account' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			add_user_to_blog( get_current_blog_id(), $user->ID, 'customer' );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember );

		do_action( 'wp_login', $user->user_login, $user );

		return true;
	}

	/**
	 * Действия после входа: перенос избранного, привязка заказов, отметка визита.
	 *
	 * @param string  $user_login Логин.
	 * @param WP_User $user       Пользователь.
	 */
	public function after_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}

		if ( ! $user ) {
			return;
		}

		update_user_meta( $user->ID, 'vlacc_last_login', current_time( 'mysql' ) );

		// Избранное гостя переносим в аккаунт.
		VL_Account_Wishlist::merge_guest_wishlist( $user->ID );

		// Гостевые заказы по e-mail/телефону — в кабинет (не чаще раза в сутки).
		$checked = get_user_meta( $user->ID, 'vlacc_orders_checked', true );

		if ( ! $checked || strtotime( $checked ) < time() - DAY_IN_SECONDS ) {
			VL_Account_Orders::attach_guest_orders( $user->ID );
			update_user_meta( $user->ID, 'vlacc_orders_checked', current_time( 'mysql' ) );
		}
	}

	/**
	 * Позволяем вводить телефон вместо логина в стандартных формах.
	 *
	 * @param null|WP_User|WP_Error $user     Результат предыдущих обработчиков.
	 * @param string                $username Логин.
	 * @param string                $password Пароль.
	 * @return null|WP_User|WP_Error
	 */
	public function authenticate_by_phone( $user, $username, $password ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}

		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}

		if ( is_email( $username ) || username_exists( $username ) ) {
			return $user;
		}

		$found = VL_Account_User::get_by_phone( $username );

		if ( ! $found ) {
			return $user;
		}

		$check = wp_check_password( $password, $found->user_pass, $found->ID );

		if ( ! $check ) {
			return new WP_Error( 'incorrect_password', VL_Account_Errors::message( 'incorrect_password' ) );
		}

		return $found;
	}

	/**
	 * Ссылка для входа без пароля (используется в письмах).
	 *
	 * @param int    $user_id  Пользователь.
	 * @param int    $ttl      Время жизни, сек.
	 * @param string $redirect Куда вести после входа.
	 * @return string
	 */
	public static function create_magic_link( $user_id, $ttl = DAY_IN_SECONDS, $redirect = '' ) {
		$key = wp_generate_password( 32, false );

		update_user_meta(
			$user_id,
			VL_Account_User::META_MAGIC,
			array(
				'hash'    => self::hash_key( $key, $user_id ),
				'expires' => time() + (int) $ttl,
			)
		);

		$url = add_query_arg(
			array(
				'vlacc_key' => $key,
				'vlacc_uid' => (int) $user_id,
			),
			$redirect ? $redirect : VL_Account_Settings::account_url()
		);

		return $url;
	}

	/**
	 * Хэш ключа «волшебной» ссылки.
	 *
	 * @param string $key     Ключ.
	 * @param int    $user_id Пользователь.
	 * @return string
	 */
	protected static function hash_key( $key, $user_id ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'vl-account';

		return hash_hmac( 'sha256', $key . '|' . (int) $user_id, $salt );
	}

	/**
	 * Обработка входа по ссылке из письма.
	 */
	public function handle_magic_link() {
		if ( empty( $_GET['vlacc_key'] ) || empty( $_GET['vlacc_uid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$key     = sanitize_text_field( wp_unslash( $_GET['vlacc_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = absint( $_GET['vlacc_uid'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$stored = get_user_meta( $user_id, VL_Account_User::META_MAGIC, true );

		$redirect = remove_query_arg( array( 'vlacc_key', 'vlacc_uid' ) );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || $stored['expires'] < time() ) {
			wp_safe_redirect( add_query_arg( 'vlacc_notice', 'link_expired', VL_Account_Settings::auth_url() ) );
			exit;
		}

		if ( ! hash_equals( (string) $stored['hash'], self::hash_key( $key, $user_id ) ) ) {
			wp_safe_redirect( add_query_arg( 'vlacc_notice', 'link_expired', VL_Account_Settings::auth_url() ) );
			exit;
		}

		delete_user_meta( $user_id, VL_Account_User::META_MAGIC );
		self::login_user( $user_id, true );

		wp_safe_redirect( $redirect ? $redirect : VL_Account_Settings::account_url() );
		exit;
	}

	/**
	 * Выход по ссылке из шорткода иконки.
	 */
	public function handle_logout() {
		if ( empty( $_GET['vlacc_action'] ) || 'logout' !== $_GET['vlacc_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vlacc-logout' ) ) {
			wp_safe_redirect( VL_Account_Settings::account_url() );
			exit;
		}

		wp_logout();

		$redirect = ! empty( $_GET['redirect_to'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: home_url( '/' );

		wp_safe_redirect( wp_validate_redirect( $redirect, home_url( '/' ) ) );
		exit;
	}

	/**
	 * Ссылка выхода.
	 *
	 * @param string $redirect Куда вернуть.
	 * @return string
	 */
	public static function logout_url( $redirect = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'vlacc_action' => 'logout',
					'redirect_to'  => rawurlencode( $redirect ? $redirect : home_url( '/' ) ),
				),
				home_url( '/' )
			),
			'vlacc-logout'
		);
	}

	/**
	 * Редирект после входа через wp-login.php.
	 *
	 * @param string           $redirect_to Куда.
	 * @param string           $requested   Запрошенный адрес.
	 * @param WP_User|WP_Error $user        Пользователь.
	 * @return string
	 */
	public function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) ) {
			return VL_Account_Settings::account_url();
		}

		return $redirect_to;
	}

	/**
	 * Редирект после входа через форму WooCommerce.
	 *
	 * @param string  $redirect Куда.
	 * @param WP_User $user     Пользователь.
	 * @return string
	 */
	public function woo_login_redirect( $redirect, $user = null ) {
		if ( $redirect && $redirect !== wc_get_page_permalink( 'myaccount' ) ) {
			return $redirect;
		}

		return VL_Account_Settings::account_url();
	}

	/**
	 * Чистим наши куки при выходе.
	 */
	public function clear_cookies() {
		if ( ! headers_sent() ) {
			setcookie( 'vlacc_wishlist', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
		}
	}
}
