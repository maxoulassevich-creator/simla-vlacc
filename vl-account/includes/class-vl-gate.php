<?php
/**
 * Обязательная авторизация перед покупкой.
 *
 * Два режима:
 *  — «оформление» (по умолчанию): гость спокойно наполняет корзину, а вход
 *    требуется на шаге «Оформить заказ». Так покупатель видит цену, доставку
 *    и корзину до того, как у него спросят телефон, — конверсия выше;
 *  — «корзина»: вход требуется ещё до добавления товара.
 *
 * В обоих случаях справа выезжает панель с формой входа по SMS, а после
 * успешного входа прерванное действие повторяется автоматически.
 *
 * Блокировка стоит и на клиенте (перехват клика), и на сервере, чтобы её
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

		if ( self::blocks_cart() ) {
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
			add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'validate_store_api' ), 10, 2 );
			// WooCommerce обрабатывает ?add-to-cart= на wp_loaded с приоритетом 20 —
			// перехватываем раньше, чтобы товар вообще не попал в корзину.
			add_action( 'wp_loaded', array( $this, 'intercept_add_to_cart_url' ), 15 );

			return;
		}

		// Режим «оформление»: корзина открыта, вход требуется на чекауте.
		add_action( 'template_redirect', array( $this, 'guard_checkout' ), 5 );

		// Серверный стоп на случай выключенного JS и блочного оформления:
		// WooCommerce сам не примет заказ от гостя.
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'force_registration_required' ), 99 );
		add_filter( 'woocommerce_checkout_registration_enabled', array( $this, 'force_registration_disabled' ), 99 );
	}

	/**
	 * Для гостя оформление заказа без аккаунта запрещено.
	 *
	 * @param bool $required Текущее значение.
	 * @return bool
	 */
	public function force_registration_required( $required ) {
		return self::should_block() ? true : $required;
	}

	/**
	 * Регистрация прямо на оформлении не нужна: аккаунт заводится по коду из SMS.
	 *
	 * @param bool $enabled Текущее значение.
	 * @return bool
	 */
	public function force_registration_disabled( $enabled ) {
		return self::should_block() ? false : $enabled;
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
	 * Режим гейта: checkout — вход на оформлении, cart — вход до корзины.
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = VL_Account_Settings::get( 'gate_mode', 'checkout' );
		$mode = 'cart' === $mode ? 'cart' : 'checkout';

		return (string) apply_filters( 'vlacc_gate_mode', $mode );
	}

	/**
	 * Вход требуется уже для добавления в корзину.
	 *
	 * @return bool
	 */
	public static function blocks_cart() {
		return self::enabled() && 'cart' === self::mode();
	}

	/**
	 * Вход требуется на шаге оформления заказа.
	 *
	 * @return bool
	 */
	public static function blocks_checkout() {
		return self::enabled() && 'checkout' === self::mode();
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
			$text = self::blocks_cart()
				? __( 'Чтобы добавить товар в корзину, войдите или зарегистрируйтесь — это займёт минуту. Все заказы, избранное и бонусы будут в личном кабинете.', 'vl-account' )
				: __( 'Чтобы оформить заказ, войдите по номеру телефона — это займёт минуту. Корзина сохранится, а заказ, бонусы и избранное будут в личном кабинете.', 'vl-account' );
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
		if ( self::blocks_cart() ) {
			$default = array(
				'.single_add_to_cart_button',
				'.custom-buy-now-button',
				'.add_to_cart_button',
				'.ajax_add_to_cart',
				'.wc-block-components-product-button__button',
				'[data-vl-requires-auth]',
			);
		} else {
			// Кнопки перехода к оформлению: классическая корзина, блочная,
			// боковая корзина Elementor и популярных виджетов.
			$default = array(
				'.checkout-button',
				'.wc-proceed-to-checkout a',
				'.wc-block-cart__submit-button',
				'.wc-block-components-checkout-place-order-button',
				'.elementor-button--checkout',
				'.elementor-menu-cart__footer-buttons a.elementor-button--checkout',
				'.xoo-wsc-ft-btn-checkout',
				'[data-vl-requires-auth]',
			);
		}

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
	 * Не пускаем гостя на страницу оформления заказа.
	 *
	 * Клик по «Оформить заказ» перехватывает скрипт и открывает панель входа,
	 * но на страницу можно попасть и по прямой ссылке, и с выключенным JS.
	 * Тогда возвращаем в корзину и открываем ту же панель — товары остаются
	 * на месте, после входа посетитель продолжает с того же шага.
	 */
	public function guard_checkout() {
		if ( ! self::should_block() || ! self::blocks_checkout() ) {
			return;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// Страница «спасибо» и оплата заказа по ссылке из письма — не трогаем.
		if ( ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) ) {
			return;
		}

		$next = self::current_url();
		$back = self::checkout_fallback_url();

		$back = add_query_arg(
			array(
				'vlacc_auth' => 1,
				'vlacc_next' => rawurlencode( $next ),
			),
			$back
		);

		vlacc_log(
			'Гость остановлен перед оформлением заказа',
			array( 'url' => $next )
		);

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Куда вернуть гостя со страницы оформления: корзина, а если её нет — страница входа.
	 *
	 * @return string
	 */
	protected static function checkout_fallback_url() {
		$cart = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';

		if ( $cart && $cart !== self::current_url() ) {
			return $cart;
		}

		return VL_Account_Settings::auth_url();
	}

	/**
	 * Адрес страницы оформления заказа — по нему скрипт узнаёт ссылки «оформить заказ».
	 *
	 * @return string
	 */
	public static function checkout_url() {
		if ( ! self::blocks_checkout() || ! function_exists( 'wc_get_checkout_url' ) ) {
			return '';
		}

		return (string) wc_get_checkout_url();
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
