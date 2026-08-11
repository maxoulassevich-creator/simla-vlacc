<?php
/**
 * Защита личного кабинета от кеширования.
 *
 * Закрывает задачу «перешёл на другую страницу — снова просит войти»:
 * чаще всего причина в том, что плагин кеширования (WP Fastest Cache,
 * LiteSpeed и т.п.) отдаёт залогиненному посетителю страницу гостя.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Кеш.
 */
class VL_Account_Cache {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Cache|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Cache
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
		if ( ! VL_Account_Settings::get( 'no_cache', 1 ) ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'maybe_disable_cache' ), 1 );
		add_filter( 'wpfc_exclude_current_page', array( $this, 'exclude_page' ) );
		add_filter( 'litespeed_control_cacheable', array( $this, 'litespeed' ) );
		add_filter( 'rocket_cache_reject_uri', array( $this, 'rocket_reject' ) );
	}

	/**
	 * Нужно ли отключать кеш на текущей странице.
	 *
	 * @return bool
	 */
	protected function should_disable() {
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( VL_Account_Router::is_account_page() ) {
			return true;
		}

		$auth_page = (int) VL_Account_Settings::get( 'auth_page', 0 );

		if ( $auth_page && is_page( $auth_page ) ) {
			return true;
		}

		if ( vlacc_is_woo() && ( is_cart() || is_checkout() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Отключаем кеш и запрещаем прокси кешировать страницу.
	 */
	public function maybe_disable_cache() {
		if ( ! $this->should_disable() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}

		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}

		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0' );
			header( 'X-VL-Account-Cache: bypass' );
		}
	}

	/**
	 * WP Fastest Cache.
	 *
	 * @param bool $exclude Исключать ли.
	 * @return bool
	 */
	public function exclude_page( $exclude ) {
		return $this->should_disable() ? true : $exclude;
	}

	/**
	 * LiteSpeed Cache.
	 *
	 * @param bool $cacheable Кешировать ли.
	 * @return bool
	 */
	public function litespeed( $cacheable ) {
		return $this->should_disable() ? false : $cacheable;
	}

	/**
	 * WP Rocket: исключаем страницы кабинета.
	 *
	 * @param array $uris Список.
	 * @return array
	 */
	public function rocket_reject( $uris ) {
		$account = VL_Account_Settings::account_url();
		$auth    = VL_Account_Settings::auth_url();

		foreach ( array( $account, $auth ) as $url ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );

			if ( $path && '/' !== $path ) {
				$uris[] = rtrim( $path, '/' ) . '(/.*)?';
			}
		}

		return $uris;
	}
}
