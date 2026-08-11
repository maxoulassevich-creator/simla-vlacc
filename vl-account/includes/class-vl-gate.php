<?php
/**
 * Обязательная авторизация перед покупкой.
 *
 * Гость не может положить товар в корзину и не может купить в один клик:
 * вместо этого справа выезжает панель с формой входа/регистрации по SMS.
 * После успешного входа прерванное действие повторяется автоматически.
 *
 * Блокировка стоит и на клиенте (перехват клика), и на сервере
 * (валидация WooCommerce и перехват ссылки ?add-to-cart=...), чтобы её
 * нельзя было обойти отключением JavaScript или прямой ссылкой.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Гейт «сначала войди».
 */
class VL_Account_Gate {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Gate|null
	 */
	private static $instance = null;

	/**
	 * Панель уже выведена.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Gate
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
		// Панель нужна всегда: её открывают и иконка входа, и кнопки покупки.
		add_action( 'wp_footer', array( $this, 'render_drawer' ), 20 );

		if ( ! self::enabled() ) {
			return;
		}

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'validate_store_api' ), 10, 2 );
		// WooCommerce обрабатывает ?add-to-cart= на wp_loaded с приоритетом 20 —
		// перехватываем раньше, чтобы товар вообще не попал в корзину.
		add_action( 'wp_loaded', array( $this, 'intercept_add_to_cart_url' ), 15 );
	}

	/**
	 * Включена ли обязательная авторизация перед покупкой.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! vlacc_is_woo() ) {
			return false;
		}

		return (bool) apply_filters( 'vlacc_gate_enabled', (bool) VL_Account_Settings::get( 'gate_cart', 0 ) );
	}

	/**
	 * Нужно ли блокировать текущего посетителя.
	 *
	 * @return bool
	 */
	public static function should_block() {
		if ( is_user_logged_in() || ! self::enabled() ) {
			return false;
		}

		return (bool) apply_filters( 'vlacc_gate_should_block', true );
	}

	/**
	 * Текст сообщения в панели.
	 *
	 * @return string
	 */
	public static function message() {
		$text = (string) VL_Account_Settings::get( 'gate_message', '' );

		if ( '' === trim( $text ) ) {
			$text = __( 'Чтобы добавить товар в корзину, войдите или зарегистрируйтесь — это займёт минуту. Все заказы, избранное и бонусы будут в личном кабинете.', 'vl-account' );
		}

		return $text;
	}

	/**
	 * Заголовок панели.
	 *
	 * @return string
	 */
	public static function title() {
		$text = (string) VL_Account_Settings::get( 'gate_title', '' );

		return '' !== trim( $text ) ? $text : __( 'Вход в личный кабинет', 'vl-account' );
	}

	/**
	 * Селекторы кнопок, которые перехватываем на клиенте.
	 *
	 * @return array
	 */
	public static function selectors() {
		$default = array(
			'.single_add_to_cart_button',
			'.custom-buy-now-button',
			'.add_to_cart_button',
			'.ajax_add_to_cart',
			'.wc-block-components-product-button__button',
			'[data-vl-requires-auth]',
		);

		$extra = (string) VL_Account_Settings::get( 'gate_selectors', '' );

		if ( '' !== trim( $extra ) ) {
			foreach ( explode( ',', $extra ) as $selector ) {
				$selector = trim( $selector );

				if ( '' !== $selector ) {
					$default[] = $selector;
				}
			}
		}

		return array_values( array_unique( (array) apply_filters( 'vlacc_gate_selectors', $default ) ) );
	}

	/**
	 * Серверная проверка: не даём положить товар в корзину гостю.
	 *
	 * @param bool $passed     Результат предыдущих проверок.
	 * @param int  $product_id Товар.
	 * @param int  $quantity   Количество.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id = 0, $quantity = 1 ) {
		if ( ! self::should_block() ) {
			return $passed;
		}

		if ( function_exists( 'wc_add_notice' ) && ! wc_has_notice( self::message(), 'error' ) ) {
			wc_add_notice( self::message(), 'error' );
		}

		return false;
	}

	/**
	 * То же для блочной корзины (Store API).
	 *
	 * @param mixed $product Товар.
	 * @param mixed $request Запрос.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Если гость.
	 */
	public function validate_store_api( $product = null, $request = null ) {
		if ( ! self::should_block() ) {
			return;
		}

		if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			return;
		}

		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'vlacc_login_required',
			esc_html( self::message() ),
			403
		);
	}

	/**
	 * Перехват ссылки вида /oformlenie/?add-to-cart=32153&attribute_pa_razmer=xs
	 *
	 * Возвращаем гостя на страницу товара и открываем панель входа,
	 * запомнив адрес, на который он шёл.
	 */
	public function intercept_add_to_cart_url() {
		if ( ! self::should_block() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_REQUEST['add-to-cart'] ) ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['wc-ajax'] ) ) {
			return;
		}

		$product_id = absint( $_REQUEST['add-to-cart'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested  = self::current_url();

		// Куда вернуть посетителя, чтобы показать панель: страница товара,
		// откуда он пришёл, либо текущая страница без параметра добавления.
		$back = '';

		if ( $product_id && get_post_status( $product_id ) === 'publish' ) {
			$back = get_permalink( $product_id );
		}

		if ( ! $back ) {
			$back = wp_get_referer();
		}

		if ( ! $back ) {
			$back = remove_query_arg( array( 'add-to-cart', 'quantity' ), $requested );
		}

		$back = add_query_arg(
			array(
				'vlacc_auth' => 1,
				'vlacc_next' => rawurlencode( $requested ),
			),
			$back
		);

		vlacc_log(
			'Гость остановлен перед добавлением в корзину',
			array(
				'product' => $product_id,
				'url'     => $requested,
			)
		);

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Полный адрес текущего запроса.
	 *
	 * @return string
	 */
	protected static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	/**
	 * Адрес, на который вернуть посетителя после входа.
	 *
	 * @return string
	 */
	public static function next_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['vlacc_next'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$next = esc_url_raw( rawurldecode( wp_unslash( $_GET['vlacc_next'] ) ) );

		return wp_validate_redirect( $next, '' );
	}

	/**
	 * Разметка выдвижной панели.
	 */
	public function render_drawer() {
		if ( $this->rendered || is_user_logged_in() || is_admin() ) {
			return;
		}

		// На странице, где форма входа уже выведена шорткодом, панель не нужна:
		// вторая копия формы дала бы одинаковые id полей.
		if ( VL_Account_Shortcodes::auth_rendered() ) {
			return;
		}

		if ( ! apply_filters( 'vlacc_render_drawer', true ) ) {
			return;
		}

		$this->rendered = true;

		vlacc_template(
			'auth-drawer.php',
			array(
				'title'    => self::title(),
				'message'  => self::should_block() ? self::message() : '',
				'redirect' => self::next_url(),
			)
		);
	}
}
