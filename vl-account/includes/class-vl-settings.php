<?php
/**
 * Настройки плагина.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Хранилище настроек (одна опция vlacc_settings).
 */
class VL_Account_Settings {

	const OPTION = 'vlacc_settings';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Settings|null
	 */
	private static $instance = null;

	/**
	 * Кэш настроек.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// SMS.RU.
			'api_id'              => '',
			'sms_from'            => '',
			'delivery_method'     => 'sms',   // sms | call | sms_then_call.
			'sms_text'            => 'Код подтверждения: {code}',
			'test_mode'           => 0,       // 1 — не отправлять реально (test=1 у SMS.RU).
			'debug_show_code'     => 0,       // 1 — показывать код на экране (только для отладки).

			// Коды.
			'code_length'         => 4,
			'code_ttl'            => 300,     // Секунд.
			'resend_timeout'      => 60,      // Секунд между отправками.
			'max_attempts'        => 5,       // Попыток ввода кода.
			'max_per_phone_day'   => 10,
			'max_per_ip_hour'     => 15,

			// Логика входа/регистрации.
			'auth_mode'           => 'sms',   // sms | both (SMS + пароль).
			'show_telegram'       => 1,
			'passwordless'        => 1,       // Регистрация без пароля.
			'auto_register'       => 1,       // Незнакомый номер -> аккаунт создаётся автоматически.
			'auth_intro'          => '',      // Текст над полем телефона.
			'auth_consent_note'   => '',      // Текст согласия под кнопкой.
			'auth_marketing_box'  => 1,       // Галочка согласия на рассылку в форме входа.
			'cookie_days'         => 30,      // Срок жизни куки авторизации.
			'phone_mask'          => '+7 (___) ___-__-__',
			'default_country'     => '7',

			// Вход перед покупкой.
			'gate_cart'           => 0,       // Требовать вход перед добавлением в корзину.
			'gate_title'          => '',
			'gate_message'        => '',
			'gate_selectors'      => '',      // Доп. CSS-селекторы кнопок покупки.

			// Согласия.
			'consent_privacy'     => 1,
			'consent_privacy_text'=> 'Я согласен(на) на <a href="%s" target="_blank" rel="nofollow">обработку персональных данных</a>',
			'privacy_page'        => 0,
			'consent_marketing'   => 1,
			'consent_marketing_text' => 'Хочу получать письма о новинках и специальных предложениях',

			// Кабинет.
			'account_page'        => 0,       // ID страницы с [vl_account]; 0 — страница WooCommerce.
			'auth_page'           => 0,       // ID страницы со входом/регистрацией.
			'tabs'                => array( 'dashboard', 'orders', 'wishlist', 'promo', 'bonus', 'subscriptions', 'profile', 'security' ),
			'loyalty_page'        => 0,       // Страница условий программы лояльности.
			'wishlist_on_product' => 0,       // Кнопка «в избранное» на странице товара.

			// Промокод за регистрацию.
			'promo_mode'          => 'none',  // none | shared | personal.
			'promo_shared_code'   => '',
			'promo_prefix'        => 'HELLO',
			'promo_discount'      => 10,
			'promo_days'          => 30,

			// Заказы.
			'auto_create_account' => 1,       // Создавать ЛК при оформлении заказа.
			'attach_guest_orders' => 1,       // Привязывать гостевые заказы по e-mail/телефону.
			'match_by_phone'      => 1,

			// Письма.
			'email_on_register'   => 1,
			'email_on_autocreate' => 1,
			'email_confirm'       => 1,       // Просить подтвердить e-mail из заказа.
			'email_confirm_days'  => 7,       // Срок жизни ссылки подтверждения.

			// Внешний вид.
			'accent_color'        => '#d40000',
			'button_color'        => '#2f2f2f',
			'radius'              => 0,

			// Прочее.
			'logging'             => 1,
			'no_cache'            => 1,
		);
	}

	/**
	 * Все настройки.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			self::$cache = wp_parse_args( $saved, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Получить настройку.
	 *
	 * @param string $key     Ключ.
	 * @param mixed  $default Значение по умолчанию.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		$val = array_key_exists( $key, $all ) ? $all[ $key ] : $default;

		return apply_filters( 'vlacc_setting_' . $key, $val );
	}

	/**
	 * Сохранить массив настроек.
	 *
	 * @param array $values Значения.
	 */
	public static function update( $values ) {
		$all = wp_parse_args( $values, self::all() );
		update_option( self::OPTION, $all );
		self::$cache = null;
	}

	/**
	 * URL личного кабинета.
	 *
	 * @return string
	 */
	public static function account_url() {
		$page_id = (int) self::get( 'account_page', 0 );

		if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
			return get_permalink( $page_id );
		}

		if ( vlacc_is_woo() ) {
			$url = wc_get_page_permalink( 'myaccount' );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}

	/**
	 * URL страницы входа/регистрации.
	 *
	 * @return string
	 */
	public static function auth_url() {
		$page_id = (int) self::get( 'auth_page', 0 );

		if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
			return get_permalink( $page_id );
		}

		return self::account_url();
	}

	/**
	 * Настроен ли SMS-шлюз.
	 *
	 * @return bool
	 */
	public static function sms_ready() {
		return '' !== trim( (string) self::get( 'api_id', '' ) );
	}
}
