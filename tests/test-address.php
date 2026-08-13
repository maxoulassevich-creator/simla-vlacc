<?php
/**
 * Смоук-тест адресов оплаты и доставки в кабинете.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки WooCommerce ---- */

class WC_Countries {
	public function get_base_country() { return 'RU'; }

	public function get_countries() {
		return array( 'RU' => 'Россия', 'KZ' => 'Казахстан' );
	}

	public function get_states( $country = '' ) {
		return 'RU' === $country ? array( 'MOW' => 'Москва' ) : array();
	}

	/**
	 * Упрощённый набор полей WooCommerce: состав зависит от страны.
	 */
	public function get_address_fields( $country = '', $prefix = '' ) {
		$fields = array(
			'first_name' => array( 'label' => 'Имя', 'required' => true ),
			'last_name'  => array( 'label' => 'Фамилия', 'required' => true ),
			'company'    => array( 'label' => 'Компания', 'required' => false ),
			'country'    => array( 'label' => 'Страна', 'required' => true, 'type' => 'country' ),
			'address_1'  => array( 'label' => 'Адрес', 'required' => true ),
			'address_2'  => array( 'label' => 'Квартира', 'required' => false ),
			'city'       => array( 'label' => 'Город', 'required' => true ),
			'postcode'   => array( 'label' => 'Индекс', 'required' => true ),
			'phone'      => array( 'label' => 'Телефон', 'required' => true ),
			'email'      => array( 'label' => 'E-mail', 'required' => true ),
		);

		// В Казахстане штат не спрашиваем, в России — спрашиваем.
		if ( 'RU' === $country ) {
			$fields['state'] = array( 'label' => 'Регион', 'required' => true, 'type' => 'state' );
		}

		$out = array();

		foreach ( $fields as $key => $field ) {
			$out[ $prefix . $key ] = $field;
		}

		return $out;
	}
}

class Fake_WC {
	public $countries;
	public function __construct() { $this->countries = new WC_Countries(); }
}

$GLOBALS['wc'] = new Fake_WC();
function WC() { return $GLOBALS['wc']; }
function wc_shipping_enabled() { return ! empty( $GLOBALS['shipping_enabled'] ); }
function wc_format_postcode( $postcode, $country = '' ) { return strtoupper( trim( (string) $postcode ) ); }

class WC_Validation {
	public static function is_postcode( $postcode, $country ) {
		// Для России достаточно шести цифр — этого хватит, чтобы проверить ветку.
		return 'RU' !== $country || (bool) preg_match( '/^\d{6}$/', (string) $postcode );
	}
}

/** Покупатель WooCommerce: сеттеры пишут в мету пользователя. */
class WC_Customer {
	private $id;
	public $saved = 0;
	public function __construct( $id = 0 ) {
		$this->id            = (int) $id;
		$GLOBALS['customer'] = $this;
	}
	public function get_id() { return $this->id; }
	public function __call( $method, $args ) {
		if ( 0 === strpos( $method, 'set_' ) ) {
			$key = substr( $method, 4 );

			// Сеттеры есть только у стандартных полей адреса.
			if ( ! preg_match( '/^(billing|shipping)_(first_name|last_name|company|address_1|address_2|city|state|postcode|country)$/', $key ) ) {
				throw new BadMethodCallException( $method );
			}

			$GLOBALS['usermeta'][ $this->id ][ $key ] = $args[0];
			return null;
		}

		throw new BadMethodCallException( $method );
	}
	public function save() { ++$this->saved; return $this->id; }
}

/** Заказ: отдаёт адресные поля через геттеры. */
class WC_Order {
	private $data;
	public function __construct( $data = array() ) { $this->data = $data; }
	public function get_id() { return 100; }
	public function __call( $method, $args ) {
		if ( 0 === strpos( $method, 'get_' ) ) {
			return $this->data[ substr( $method, 4 ) ] ?? '';
		}
		return '';
	}
}

/** Заказы кабинета: подменяем источник последнего заказа. */
class VL_Account_Orders {
	public static $orders = array();
	public static function get_user_orders( $user_id, $limit = 20 ) {
		return array_slice( self::$orders, 0, $limit, true );
	}
}

/** Ответы AJAX прерывают выполнение — здесь бросаем исключение с телом ответа. */
class Json_Response extends Exception {
	public $success;
	public $data;
	public function __construct( $success, $data ) {
		parent::__construct( 'json' );
		$this->success = $success;
		$this->data    = $data;
	}
}

function wp_send_json_error( $data = null, $code = 200 ) { throw new Json_Response( false, $data ); }
function wp_send_json_success( $data = null, $code = 200 ) { throw new Json_Response( true, $data ); }
function wp_script_is( $handle, $list = 'enqueued' ) { return false; }
function wp_style_is( $handle, $list = 'enqueued' ) { return false; }

require_once VLACC_PATH . 'includes/class-vl-address.php';

/**
 * Прогнать ajax_save() и получить ответ.
 */
function save_address( $post ) {
	$_POST = array_merge( array( 'nonce' => 'nonce' ), $post );

	try {
		VL_Account_Address::instance()->ajax_save();
	} catch ( Json_Response $r ) {
		return $r;
	}

	return null;
}

$GLOBALS['shipping_enabled'] = true;
$GLOBALS['current_user_id']  = 7;
$GLOBALS['logged_in']        = true;

echo "\n== 1. Выключатели ==\n";
check( 'по умолчанию блок включён', VL_Account_Address::enabled() );
check( 'доставка включена', VL_Account_Address::shipping_enabled() );
check( 'два раздела', array( 'billing', 'shipping' ) === array_keys( VL_Account_Address::sections() ) );

$GLOBALS['shipping_enabled'] = false;
check( 'без доставки в магазине раздела нет', ! VL_Account_Address::shipping_enabled() );
check( 'остаётся только оплата', array( 'billing' ) === array_keys( VL_Account_Address::sections() ) );
$GLOBALS['shipping_enabled'] = true;

VL_Account_Settings::update( array( 'profile_shipping' => 0 ) );
check( 'настройка выключает доставку', ! VL_Account_Address::shipping_enabled() );
VL_Account_Settings::update( array( 'profile_shipping' => 1 ) );

VL_Account_Settings::update( array( 'profile_address' => 0 ) );
check( 'настройка выключает весь блок', ! VL_Account_Address::enabled() );
check( 'полей нет', array() === VL_Account_Address::fields( 'billing', 7 ) );
check( 'сводки нет', '' === VL_Account_Address::formatted( 7 ) );
VL_Account_Settings::update( array( 'profile_address' => 1 ) );

echo "\n== 2. Состав полей ==\n";
$fields = VL_Account_Address::fields( 'billing', 7 );
check( 'префикс раздела', isset( $fields['billing_city'] ), implode( ', ', array_keys( $fields ) ) );
check( 'телефон убран — он выше в кабинете', ! isset( $fields['billing_phone'] ) );
check( 'e-mail убран', ! isset( $fields['billing_email'] ) );
check( 'у каждого поля есть значение', array_key_exists( 'value', $fields['billing_city'] ) );

$shipping = VL_Account_Address::fields( 'shipping', 7 );
check( 'раздел доставки со своим префиксом', isset( $shipping['shipping_address_1'] ) );

check( 'по умолчанию поля базовой страны', isset( $fields['billing_state'] ) );
$kz = VL_Account_Address::fields( 'billing', 7, 'KZ' );
check( 'для другой страны состав другой', ! isset( $kz['billing_state'] ), implode( ', ', array_keys( $kz ) ) );

echo "\n== 3. Значения подтягиваются из профиля ==\n";
update_user_meta( 7, 'billing_city', 'Москва' );
check( 'значение из меты', 'Москва' === VL_Account_Address::value( 7, 'billing_city' ) );

$fields = VL_Account_Address::fields( 'billing', 7 );
check( 'значение попало в поле', 'Москва' === $fields['billing_city']['value'] );

echo "\n== 4. Пустые поля берём из последнего заказа ==\n";
VL_Account_Orders::$orders = array(
	100 => new WC_Order(
		array(
			'billing_first_name' => 'Иван',
			'billing_last_name'  => 'Петров',
			'billing_country'    => 'RU',
			'billing_state'      => 'MOW',
			'billing_city'       => 'Санкт-Петербург',
			'billing_address_1'  => 'Невский проспект, 1',
			'billing_postcode'   => '190000',
		)
	),
);

VL_Account_Address::flush();

check( 'заполненное поле профиля важнее заказа', 'Москва' === VL_Account_Address::value( 7, 'billing_city' ) );
check( 'пустое поле — из заказа', 'Иван' === VL_Account_Address::value( 7, 'billing_first_name' ) );
check( 'адрес доставки берём из оплаты', 'Невский проспект, 1' === VL_Account_Address::value( 7, 'shipping_address_1' ) );
check( 'чего нет в заказе — пусто', '' === VL_Account_Address::value( 7, 'billing_company' ) );

$fields = VL_Account_Address::fields( 'shipping', 7 );
check( 'поля доставки заполнены данными заказа', 'Иван' === $fields['shipping_first_name']['value'] );

echo "\n== 5. Адрес одной строкой ==\n";
$formatted = VL_Account_Address::formatted( 7 );
check( 'индекс в начале', 0 === strpos( $formatted, '190000' ), $formatted );
check( 'страна названием', false !== mb_strpos( $formatted, 'Россия' ), $formatted );
check( 'регион названием', false !== mb_strpos( $formatted, 'Москва' ), $formatted );
check( 'улица на месте', false !== mb_strpos( $formatted, 'Невский проспект, 1' ), $formatted );

echo "\n== 6. Сохранение ==\n";
$response = save_address(
	array(
		'section'            => 'billing',
		'billing_first_name' => 'Мария',
		'billing_last_name'  => 'Соколова',
		'billing_country'    => 'RU',
		'billing_state'      => 'MOW',
		'billing_city'       => 'Казань',
		'billing_address_1'  => 'Баумана, 5',
		'billing_postcode'   => '420000',
		'billing_company'    => '',
	)
);

check( 'ответ успешный', $response && $response->success, print_r( $response ? $response->data : null, true ) );
check( 'город записан', 'Казань' === get_user_meta( 7, 'billing_city', true ) );
check( 'покупатель сохранён', isset( $GLOBALS['customer'] ) && 1 === $GLOBALS['customer']->saved );
check( 'имя профиля заполнилось из адреса', 'Мария' === get_user_meta( 7, 'first_name', true ) );
check( 'в ответ приходит сводка', ! empty( $response->data['formatted'] ) && false !== mb_strpos( $response->data['formatted'], 'Казань' ), print_r( $response->data, true ) );

update_user_meta( 7, 'first_name', 'Мария' );
$response = save_address(
	array(
		'section'            => 'billing',
		'billing_first_name' => 'Другое имя',
		'billing_last_name'  => 'Соколова',
		'billing_country'    => 'RU',
		'billing_state'      => 'MOW',
		'billing_city'       => 'Казань',
		'billing_address_1'  => 'Баумана, 5',
		'billing_postcode'   => '420000',
	)
);
check( 'заполненное имя профиля не перетирается', 'Мария' === get_user_meta( 7, 'first_name', true ) );

echo "\n== 7. Проверка полей ==\n";
$response = save_address(
	array(
		'section'            => 'billing',
		'billing_first_name' => '',
		'billing_last_name'  => 'Соколова',
		'billing_country'    => 'RU',
		'billing_state'      => 'MOW',
		'billing_city'       => 'Казань',
		'billing_address_1'  => 'Баумана, 5',
		'billing_postcode'   => '420000',
	)
);
check( 'без обязательного поля не сохраняем', $response && ! $response->success );
check( 'ошибка привязана к полю', isset( $response->data['errors']['billing_first_name'] ), print_r( $response->data, true ) );
check( 'необязательное поле не требуем', ! isset( $response->data['errors']['billing_company'] ) );

$response = save_address(
	array(
		'section'            => 'billing',
		'billing_first_name' => 'Мария',
		'billing_last_name'  => 'Соколова',
		'billing_country'    => 'RU',
		'billing_state'      => 'MOW',
		'billing_city'       => 'Казань',
		'billing_address_1'  => 'Баумана, 5',
		'billing_postcode'   => '42',
	)
);
check( 'кривой индекс отклоняем', $response && ! $response->success && isset( $response->data['errors']['billing_postcode'] ) );

// В стране без штата это поле не обязательно — и не должно требоваться.
$response = save_address(
	array(
		'section'            => 'billing',
		'billing_first_name' => 'Мария',
		'billing_last_name'  => 'Соколова',
		'billing_country'    => 'KZ',
		'billing_city'       => 'Алматы',
		'billing_address_1'  => 'Абая, 10',
		'billing_postcode'   => '050000',
	)
);
check( 'состав полей берётся по стране из формы', $response && $response->success, print_r( $response ? $response->data : null, true ) );

echo "\n== 8. Раздел доставки уважает настройку ==\n";
VL_Account_Settings::update( array( 'profile_shipping' => 0 ) );
$response = save_address( array( 'section' => 'shipping' ) );
check( 'выключенный раздел не сохраняем', $response && ! $response->success, print_r( $response ? $response->data : null, true ) );
VL_Account_Settings::update( array( 'profile_shipping' => 1 ) );

echo "\n== 9. Гость ==\n";
$GLOBALS['logged_in'] = false;
$response             = save_address( array( 'section' => 'billing' ) );
check( 'без входа адрес не сохранить', $response && ! $response->success );
$GLOBALS['logged_in'] = true;

echo "\n== 10. Шаблон формы ==\n";

function esc_html_e( $t, $d = '' ) { echo $t; }

/** Заглушка вывода поля WooCommerce. */
function woocommerce_form_field( $key, $args = array(), $value = null ) {
	echo '<p class="form-row"><label for="' . $key . '">' . ( $args['label'] ?? '' ) . '</label>';
	echo '<input class="input-text" name="' . $key . '" id="' . $key . '" value="' . htmlspecialchars( (string) $value ) . '" /></p>';
}

VL_Account_Address::flush();

ob_start();
$section = 'billing';
$title   = 'Данные для заказа';
$hint    = 'Подставим на оформлении';
$user_id = 7;
include VLACC_PATH . 'templates/parts/address-form.php';
$html = ob_get_clean();

check( 'обёртка раздела', false !== strpos( $html, 'data-vl-address="billing"' ) );
check( 'форма с секцией', false !== strpos( $html, 'data-vl-section="billing"' ) );
check( 'кнопка сохранения', false !== strpos( $html, 'data-vl-action="save-address"' ) );
check( 'ловушка для ботов на месте', false !== strpos( $html, 'vlacc_hp' ) );
check( 'поля выведены', false !== strpos( $html, 'name="billing_city"' ) );
check( 'значения подставлены', false !== strpos( $html, 'value="Алматы"' ), $html );
check( 'сводка выведена', false !== strpos( $html, 'data-vl-address-summary' ) );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
