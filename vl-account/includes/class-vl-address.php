<?php
/**
 * Адреса доставки и оплаты в разделе «Мои данные».
 *
 * Раньше кабинет отправлял покупателя за адресами на стандартную страницу
 * WooCommerce — в другую вёрстку и на два лишних клика. Здесь те же поля
 * выводятся прямо в кабинете, заполненные всем, что о покупателе известно:
 * из его профиля, а если там пусто — из последнего заказа.
 *
 * Состав полей берётся у WooCommerce (WC_Countries::get_address_fields), так что
 * настройки магазина, обязательность и локали стран учитываются автоматически.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Адреса покупателя.
 */
class VL_Account_Address {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Address|null
	 */
	private static $instance = null;

	/**
	 * Кэш данных последнего заказа.
	 *
	 * @var array
	 */
	private static $last_order = array();

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Address
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
		add_action( 'wp_ajax_vlacc_address_save', array( $this, 'ajax_save' ) );
	}

	/* ------------------------------------------------------------------
	 * Настройки
	 * ------------------------------------------------------------------ */

	/**
	 * Блок адресов включён.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! vlacc_is_woo() || ! class_exists( 'WC_Countries' ) ) {
			return false;
		}

		// Состав полей и списки стран живут в WC()->countries — без него блока нет.
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->countries ) ) {
			return false;
		}

		return (bool) VL_Account_Settings::get( 'profile_address', 1 );
	}

	/**
	 * Показывать отдельный адрес доставки.
	 *
	 * @return bool
	 */
	public static function shipping_enabled() {
		if ( ! self::enabled() ) {
			return false;
		}

		// Магазин может вообще не доставлять товары.
		if ( function_exists( 'wc_shipping_enabled' ) && ! wc_shipping_enabled() ) {
			return false;
		}

		return (bool) VL_Account_Settings::get( 'profile_shipping', 1 );
	}

	/**
	 * Разделы адресов.
	 *
	 * @return array
	 */
	public static function sections() {
		$sections = array(
			'billing' => array(
				'title' => __( 'Данные для заказа', 'vl-account' ),
				'hint'  => __( 'Эти данные подставятся в оформление заказа — заполнять их каждый раз не придётся.', 'vl-account' ),
			),
		);

		if ( self::shipping_enabled() ) {
			$sections['shipping'] = array(
				'title' => __( 'Адрес доставки', 'vl-account' ),
				'hint'  => __( 'Если доставка на другой адрес — заполните его здесь.', 'vl-account' ),
			);
		}

		return apply_filters( 'vlacc_address_sections', $sections );
	}

	/* ------------------------------------------------------------------
	 * Скрипты
	 * ------------------------------------------------------------------ */

	/**
	 * Подключить скрипты WooCommerce для полей адреса.
	 *
	 * Список областей зависит от страны, и обновляет его штатный скрипт
	 * WooCommerce. Регистрирует его сам WooCommerce — нам остаётся включить.
	 */
	public static function enqueue_assets() {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		foreach ( array( 'wc-country-select', 'wc-address-i18n' ) as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}

		// Красивые выпадающие списки стран — стиль тоже регистрирует WooCommerce.
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}
	}

	/* ------------------------------------------------------------------
	 * Поля и значения
	 * ------------------------------------------------------------------ */

	/**
	 * Поля раздела со значениями.
	 *
	 * @param string $section billing | shipping.
	 * @param int    $user_id Пользователь.
	 * @param string $country Страна, для которой собирать поля; пусто — из данных покупателя.
	 * @return array
	 */
	public static function fields( $section, $user_id, $country = '' ) {
		if ( ! self::enabled() ) {
			return array();
		}

		$section = 'shipping' === $section ? 'shipping' : 'billing';

		// Состав полей зависит от страны: где-то нужен штат, где-то нет индекса.
		if ( '' === $country ) {
			$country = self::value( $user_id, $section . '_country' );
		}

		$country = $country ? $country : WC()->countries->get_base_country();

		$fields = WC()->countries->get_address_fields( $country, $section . '_' );

		// Телефон и почта живут выше, в блоке контактов кабинета.
		unset( $fields[ $section . '_email' ], $fields[ $section . '_phone' ] );

		foreach ( $fields as $key => $field ) {
			$fields[ $key ]['value'] = self::value( $user_id, $key );
		}

		return apply_filters( 'vlacc_address_fields', $fields, $section, $user_id );
	}

	/**
	 * Значение поля: профиль, а если пусто — последний заказ.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $key     Ключ поля, например billing_city.
	 * @return string
	 */
	public static function value( $user_id, $key ) {
		$value = get_user_meta( $user_id, $key, true );

		if ( '' !== $value && null !== $value ) {
			return (string) $value;
		}

		$value = self::from_last_order( $user_id, $key );

		return (string) apply_filters( 'vlacc_address_value', $value, $key, $user_id );
	}

	/**
	 * Забыть данные последнего заказа.
	 *
	 * Нужно, когда адрес поменялся прямо в этом запросе.
	 *
	 * @param int $user_id Пользователь; 0 — все.
	 */
	public static function flush( $user_id = 0 ) {
		if ( $user_id ) {
			unset( self::$last_order[ (int) $user_id ] );

			return;
		}

		self::$last_order = array();
	}

	/**
	 * Значение из последнего заказа покупателя.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $key     Ключ поля.
	 * @return string
	 */
	protected static function from_last_order( $user_id, $key ) {
		$user_id = (int) $user_id;

		if ( ! isset( self::$last_order[ $user_id ] ) ) {
			self::$last_order[ $user_id ] = self::collect_last_order( $user_id );
		}

		$data = self::$last_order[ $user_id ];

		if ( isset( $data[ $key ] ) ) {
			return (string) $data[ $key ];
		}

		// Адреса доставки в заказе может не быть — берём из оплаты.
		if ( 0 === strpos( $key, 'shipping_' ) ) {
			$billing = 'billing_' . substr( $key, 9 );

			if ( isset( $data[ $billing ] ) ) {
				return (string) $data[ $billing ];
			}
		}

		return '';
	}

	/**
	 * Собрать адресные поля из последнего заказа.
	 *
	 * @param int $user_id Пользователь.
	 * @return array
	 */
	protected static function collect_last_order( $user_id ) {
		$orders = VL_Account_Orders::get_user_orders( $user_id, 1 );

		if ( ! $orders ) {
			return array();
		}

		$order = reset( $orders );

		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$data   = array();
		$suffix = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

		foreach ( array( 'billing', 'shipping' ) as $section ) {
			foreach ( $suffix as $field ) {
				$getter = 'get_' . $section . '_' . $field;

				if ( ! is_callable( array( $order, $getter ) ) ) {
					continue;
				}

				$value = $order->$getter();

				if ( '' !== $value && null !== $value ) {
					$data[ $section . '_' . $field ] = $value;
				}
			}
		}

		return $data;
	}

	/**
	 * Адрес одной строкой — для сводки в кабинете.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $section billing | shipping.
	 * @return string
	 */
	public static function formatted( $user_id, $section = 'billing' ) {
		if ( ! self::enabled() ) {
			return '';
		}

		$parts = array();

		foreach ( array( 'postcode', 'country', 'state', 'city', 'address_1', 'address_2' ) as $field ) {
			$value = self::value( $user_id, $section . '_' . $field );

			if ( '' === $value ) {
				continue;
			}

			if ( 'country' === $field ) {
				$countries = WC()->countries->get_countries();
				$value     = isset( $countries[ $value ] ) ? $countries[ $value ] : $value;
			}

			if ( 'state' === $field ) {
				$country = self::value( $user_id, $section . '_country' );
				$states  = WC()->countries->get_states( $country );
				$value   = ( is_array( $states ) && isset( $states[ $value ] ) ) ? $states[ $value ] : $value;
			}

			$parts[] = $value;
		}

		return implode( ', ', $parts );
	}

	/* ------------------------------------------------------------------
	 * Сохранение
	 * ------------------------------------------------------------------ */

	/**
	 * Сохранить адрес.
	 */
	public function ajax_save() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vl-account' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Страница устарела. Обновите её и попробуйте снова.', 'vl-account' ),
					'reload'  => true,
				),
				403
			);
		}

		// Honeypot: скрытое поле должно оставаться пустым.
		if ( ! empty( $_POST['vlacc_hp'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Не получилось отправить форму.', 'vl-account' ) ), 400 );
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Сначала войдите в кабинет.', 'vl-account' ) ), 403 );
		}

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Адреса сейчас недоступны.', 'vl-account' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : 'billing';
		$section = 'shipping' === $section ? 'shipping' : 'billing';

		if ( 'shipping' === $section && ! self::shipping_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Адрес доставки отключён в настройках.', 'vl-account' ) ) );
		}

		// Страну берём из формы: от неё зависит, какие поля вообще нужны.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
		$country = isset( $_POST[ $section . '_country' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $section . '_country' ] ) ) : '';

		$fields = self::fields( $section, $user_id, $country );
		$values = array();
		$errors = array();

		foreach ( $fields as $key => $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$raw = is_string( $raw ) ? trim( sanitize_text_field( $raw ) ) : '';

			if ( ! empty( $field['required'] ) && '' === $raw ) {
				$label = isset( $field['label'] ) ? $field['label'] : $key;

				$errors[ $key ] = sprintf(
					/* translators: %s — название поля. */
					__( 'Заполните поле «%s».', 'vl-account' ),
					$label
				);

				continue;
			}

			$values[ $key ] = $raw;
		}

		// Индекс проверяем по правилам страны — иначе доставка потом не рассчитается.
		$country_key  = $section . '_country';
		$postcode_key = $section . '_postcode';

		if ( ! isset( $errors[ $postcode_key ] ) && ! empty( $values[ $postcode_key ] ) && class_exists( 'WC_Validation' ) ) {
			$country = isset( $values[ $country_key ] ) ? $values[ $country_key ] : self::value( $user_id, $country_key );

			if ( $country && ! WC_Validation::is_postcode( $values[ $postcode_key ], $country ) ) {
				$errors[ $postcode_key ] = __( 'Проверьте почтовый индекс.', 'vl-account' );
			} else {
				$values[ $postcode_key ] = wc_format_postcode( $values[ $postcode_key ], $country );
			}
		}

		if ( $errors ) {
			wp_send_json_error(
				array(
					'message' => __( 'Проверьте отмеченные поля.', 'vl-account' ),
					'errors'  => $errors,
				)
			);
		}

		self::save( $user_id, $values );

		vlacc_log(
			'Адрес обновлён в кабинете',
			array(
				'user_id' => $user_id,
				'section' => $section,
			)
		);

		wp_send_json_success(
			array(
				'message'   => __( 'Адрес сохранён.', 'vl-account' ),
				'formatted' => self::formatted( $user_id, $section ),
			)
		);
	}

	/**
	 * Записать значения покупателю.
	 *
	 * @param int   $user_id Пользователь.
	 * @param array $values  Значения полей.
	 */
	public static function save( $user_id, $values ) {
		$customer = new WC_Customer( $user_id );

		foreach ( $values as $key => $value ) {
			$setter = 'set_' . $key;

			if ( is_callable( array( $customer, $setter ) ) ) {
				$customer->$setter( $value );

				continue;
			}

			update_user_meta( $user_id, $key, $value );
		}

		$customer->save();

		// Если в профиле имя ещё не заполнено — возьмём его из адреса,
		// чтобы кабинет и письма не обращались к покупателю по логину.
		foreach ( array( 'first_name', 'last_name' ) as $name_field ) {
			foreach ( array( 'billing_', 'shipping_' ) as $prefix ) {
				if ( empty( $values[ $prefix . $name_field ] ) ) {
					continue;
				}

				if ( '' === (string) get_user_meta( $user_id, $name_field, true ) ) {
					update_user_meta( $user_id, $name_field, $values[ $prefix . $name_field ] );
				}
			}
		}

		// Данные изменились — сводка и подстановка должны их увидеть.
		self::flush( $user_id );

		do_action( 'vlacc_address_saved', $user_id, $values );
	}
}
