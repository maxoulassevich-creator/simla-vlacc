<?php
/**
 * Автозаполнение форм.
 *
 * Браузер подставляет сохранённые имя, телефон, почту и адрес только если у поля
 * правильный атрибут autocomplete. Темы и конструкторы форм его часто теряют или
 * вовсе ставят autocomplete="off" — и покупатель на оформлении заказа заполняет
 * всё руками. Здесь атрибуты расставляются на полях WooCommerce, а на остальных
 * формах сайта снимается запрет автозаполнения.
 *
 * Форму входа по номеру телефона не трогаем: там одноразовый код, и подстановка
 * сохранённых значений только мешает.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Автозаполнение.
 */
class VL_Account_Autofill {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Autofill|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Autofill
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
		if ( ! self::enabled() ) {
			return;
		}

		if ( vlacc_is_woo() ) {
			add_filter( 'woocommerce_checkout_fields', array( $this, 'checkout_fields' ), 99 );
			add_filter( 'woocommerce_default_address_fields', array( $this, 'address_fields' ), 99 );
			add_filter( 'woocommerce_form_field_args', array( $this, 'form_field_args' ), 99, 3 );

			// Данные аккаунта в пустые поля оформления.
			add_filter( 'woocommerce_checkout_get_value', array( $this, 'checkout_value' ), 99, 2 );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 21 );
	}

	/**
	 * Автозаполнение включено.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) VL_Account_Settings::get( 'autofill', 1 );
	}

	/* ------------------------------------------------------------------
	 * Поля WooCommerce
	 * ------------------------------------------------------------------ */

	/**
	 * Соответствие полей WooCommerce значениям autocomplete.
	 *
	 * @return array
	 */
	public static function map() {
		$map = array(
			'first_name' => 'given-name',
			'last_name'  => 'family-name',
			'company'    => 'organization',
			'address_1'  => 'address-line1',
			'address_2'  => 'address-line2',
			'city'       => 'address-level2',
			'state'      => 'address-level1',
			'postcode'   => 'postal-code',
			'country'    => 'country',
			'email'      => 'email',
			'phone'      => 'tel',
		);

		return (array) apply_filters( 'vlacc_autofill_map', $map );
	}

	/**
	 * Значение autocomplete для поля по его ключу.
	 *
	 * @param string $key Ключ поля, например billing_first_name.
	 * @return string
	 */
	public static function value_for( $key ) {
		$key = (string) $key;
		$map = self::map();

		// Раздел важен: браузер отдельно помнит адрес доставки и плательщика.
		$section = '';

		if ( 0 === strpos( $key, 'billing_' ) ) {
			$section = 'billing ';
			$field   = substr( $key, 8 );
		} elseif ( 0 === strpos( $key, 'shipping_' ) ) {
			$section = 'shipping ';
			$field   = substr( $key, 9 );
		} else {
			$field = $key;
		}

		if ( ! isset( $map[ $field ] ) ) {
			return '';
		}

		// tel и email браузеры понимают и без секции, а с ней иногда игнорируют.
		if ( in_array( $map[ $field ], array( 'tel', 'email' ), true ) ) {
			return $map[ $field ];
		}

		return $section . $map[ $field ];
	}

	/**
	 * Поля оформления заказа.
	 *
	 * @param array $fields Поля.
	 * @return array
	 */
	public function checkout_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		foreach ( $fields as $section => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( $group as $key => $field ) {
				$value = self::value_for( $key );

				if ( '' === $value ) {
					continue;
				}

				$field['autocomplete'] = $value;

				if ( ! isset( $field['custom_attributes'] ) || ! is_array( $field['custom_attributes'] ) ) {
					$field['custom_attributes'] = array();
				}

				$field['custom_attributes']['autocomplete'] = $value;

				$fields[ $section ][ $key ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Поля адреса (используются и в кабинете WooCommerce).
	 *
	 * @param array $fields Поля.
	 * @return array
	 */
	public function address_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$map = self::map();

		foreach ( $fields as $key => $field ) {
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}

			$field['autocomplete']  = $map[ $key ];
			$fields[ $key ]         = $field;
		}

		return $fields;
	}

	/**
	 * Любое поле, отрисованное через woocommerce_form_field().
	 *
	 * @param array  $args  Аргументы поля.
	 * @param string $key   Ключ поля.
	 * @param mixed  $value Значение.
	 * @return array
	 */
	public function form_field_args( $args, $key = '', $value = null ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$autocomplete = isset( $args['autocomplete'] ) ? (string) $args['autocomplete'] : self::value_for( $key );

		if ( '' === $autocomplete || 'off' === $autocomplete ) {
			$autocomplete = self::value_for( $key );
		}

		if ( '' === $autocomplete ) {
			return $args;
		}

		if ( ! isset( $args['custom_attributes'] ) || ! is_array( $args['custom_attributes'] ) ) {
			$args['custom_attributes'] = array();
		}

		$args['custom_attributes']['autocomplete'] = $autocomplete;
		$args['autocomplete']                      = $autocomplete;

		return $args;
	}

	/**
	 * Подставить данные аккаунта в пустые поля оформления.
	 *
	 * У покупателя, зарегистрированного по SMS, телефон лежит в поле кабинета,
	 * а биллинг может быть пустым — тогда на оформлении он вводил номер заново.
	 *
	 * @param mixed  $value Значение поля.
	 * @param string $key   Ключ поля.
	 * @return mixed
	 */
	public function checkout_value( $value, $key = '' ) {
		if ( ! empty( $value ) || ! is_user_logged_in() ) {
			return $value;
		}

		$user_id = get_current_user_id();

		switch ( $key ) {
			case 'billing_phone':
			case 'shipping_phone':
				$phone = VL_Account_User::get_phone( $user_id );

				return $phone ? VL_Account_Phone::format( $phone ) : $value;

			case 'billing_email':
				$user = get_user_by( 'id', $user_id );

				return ( $user && VL_Account_User::has_real_email( $user ) ) ? $user->user_email : $value;

			case 'billing_first_name':
			case 'shipping_first_name':
				$user = get_user_by( 'id', $user_id );

				return $user && $user->first_name ? $user->first_name : $value;

			case 'billing_last_name':
			case 'shipping_last_name':
				$user = get_user_by( 'id', $user_id );

				return $user && $user->last_name ? $user->last_name : $value;
		}

		return $value;
	}

	/* ------------------------------------------------------------------
	 * Остальные формы сайта
	 * ------------------------------------------------------------------ */

	/**
	 * Скрипт, снимающий запрет автозаполнения с форм темы.
	 */
	public function assets() {
		if ( ! VL_Account_Settings::get( 'autofill_fix_forms', 1 ) ) {
			return;
		}

		wp_register_script(
			'vl-autofill',
			VLACC_URL . 'assets/js/vl-autofill.js',
			array(),
			VL_Account_Plugin::asset_version( 'assets/js/vl-autofill.js' ),
			true
		);

		wp_localize_script(
			'vl-autofill',
			'VLACC_AUTOFILL',
			array(
				'map'    => self::map(),
				'ignore' => self::ignored_selectors(),
			)
		);

		wp_enqueue_script( 'vl-autofill' );
	}

	/**
	 * Формы, которые трогать нельзя.
	 *
	 * @return array
	 */
	public static function ignored_selectors() {
		$default = array(
			'[data-vl-auth]',
			'[data-vl-drawer]',
			'[data-vl-form="login"]',
			'[data-vl-form="lost"]',
			'[data-vl-form="loyalty"]',
			'.vl-auth',
		);

		return array_values( array_unique( (array) apply_filters( 'vlacc_autofill_ignore', $default ) ) );
	}
}
