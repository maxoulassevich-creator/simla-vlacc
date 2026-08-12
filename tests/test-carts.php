<?php
/**
 * Смоук-тест сбора брошенных корзин и автозаполнения форм.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки WooCommerce ---- */

class WC_Product {
	private $id;
	private $name;
	private $parent;
	private $price;
	public function __construct( $id, $name, $parent = 0, $price = 0 ) {
		$this->id     = $id;
		$this->name   = $name;
		$this->parent = $parent;
		$this->price  = $price;
	}
	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function get_parent_id() { return $this->parent; }
	public function get_price() { return $this->price; }
	public function get_sku() { return 'SKU-' . $this->id; }
	public function is_type( $type ) { return 'variation' === $type && $this->parent > 0; }
}

class Fake_Cart {
	public $items = array();
	public $total = 0;
	public function get_cart() { return $this->items; }
	public function get_total( $context = 'view' ) { return $this->total; }
}

class Fake_Customer {
	public $email = '';
	public $phone = '';
	public $first = '';
	public $last  = '';
	public function get_billing_email() { return $this->email; }
	public function get_billing_phone() { return $this->phone; }
	public function get_billing_first_name() { return $this->first; }
	public function get_billing_last_name() { return $this->last; }
}

class Fake_WC {
	public $cart;
	public $customer;
	public function __construct() {
		$this->cart     = new Fake_Cart();
		$this->customer = new Fake_Customer();
	}
}

$GLOBALS['wc'] = new Fake_WC();
function WC() { return $GLOBALS['wc']; }
function get_woocommerce_currency() { return 'RUB'; }
function wc_get_formatted_variation( $v, $flat = false, $names = true ) { return 'Размер: M'; }

/**
 * Заглушка $wpdb: хранит строки в массиве.
 */
class Fake_WPDB {
	public $prefix = 'wp_';
	public $rows   = array();
	private $next  = 1;

	public function has_cap( $cap ) { return true; }
	public function get_charset_collate() { return ''; }
	public function esc_like( $text ) { return $text; }
	public function prepare( $sql, ...$args ) { return $sql; }

	public function insert( $table, $data, $formats = array() ) {
		$data['id'] = $this->next++;
		$this->rows[ $data['id'] ] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) {
		$updated = 0;
		foreach ( $this->rows as $id => $row ) {
			$match = true;
			foreach ( $where as $key => $value ) {
				if ( ! isset( $row[ $key ] ) || (string) $row[ $key ] !== (string) $value ) { $match = false; }
			}
			if ( $match ) {
				$this->rows[ $id ] = array_merge( $row, $data );
				++$updated;
			}
		}
		return $updated;
	}

	public function delete( $table, $where, $formats = array() ) {
		$deleted = 0;
		foreach ( $this->rows as $id => $row ) {
			$match = true;
			foreach ( $where as $key => $value ) {
				if ( ! isset( $row[ $key ] ) || (string) $row[ $key ] !== (string) $value ) { $match = false; }
			}
			if ( $match ) { unset( $this->rows[ $id ] ); ++$deleted; }
		}
		return $deleted;
	}

	/** Для проверки «есть ли уже такая корзина». */
	public function get_var( $sql ) {
		foreach ( $this->rows as $id => $row ) {
			if ( isset( $row['cart_key'] ) && false !== strpos( $sql, $row['cart_key'] ) ) { return $id; }
		}
		// Ключ подставляется через prepare-заглушку, поэтому ищем по единственной строке.
		return $this->rows ? array_key_first( $this->rows ) : 0;
	}

	public function get_results( $sql, $output = null ) { return array_values( $this->rows ); }
	public function query( $sql ) { return 0; }
}

$GLOBALS['wpdb'] = new Fake_WPDB();

function dbDelta( $sql ) { return array(); }

require_once VLACC_PATH . 'includes/class-vl-carts.php';
require_once VLACC_PATH . 'includes/class-vl-autofill.php';

$carts = VL_Account_Carts::instance();

echo "\n== 1. Ключ корзины ==\n";
$key = VL_Account_Carts::cart_key();
check( 'ключ создан', 32 === strlen( $key ), $key );
check( 'ключ стабилен в рамках запроса', $key === VL_Account_Carts::cart_key() );
check( 'сбор включён', VL_Account_Carts::enabled() );
check( 'порог по умолчанию 60 минут', 60 === VL_Account_Carts::abandoned_after() );
VL_Account_Settings::update( array( 'carts_abandoned_after' => 1 ) );
check( 'меньше 5 минут не ставим', 5 === VL_Account_Carts::abandoned_after() );
VL_Account_Settings::update( array( 'carts_abandoned_after' => 60 ) );

echo "\n== 2. Снимок корзины гостя ==\n";
$GLOBALS['logged_in'] = false;
WC()->cart->items = array(
	'a' => array( 'quantity' => 2, 'line_total' => 4000, 'data' => new WC_Product( 11, 'Платье — M', 10, 2000 ) ),
	'b' => array( 'quantity' => 1, 'line_total' => 1500, 'data' => new WC_Product( 20, 'Шарф', 0, 1500 ) ),
);
WC()->cart->total = 5500;

$carts->mark_dirty();
$carts->save();

$row = reset( $GLOBALS['wpdb']->rows );
check( 'корзина записана', is_array( $row ), print_r( $GLOBALS['wpdb']->rows, true ) );
check( 'товаров 3 штуки', 3 === (int) $row['item_count'], (string) $row['item_count'] );
check( 'сумма сохранена', 5500.0 === (float) $row['total'] );
check( 'статус active', 'active' === $row['status'] );
check( 'гость без пользователя', 0 === (int) $row['user_id'] );

$items = json_decode( $row['items'], true );
check( 'состав корзины сохранён', 2 === count( $items ), print_r( $items, true ) );
check( 'название товара', 'Платье — M' === $items[0]['name'] );
check( 'вариация подписана', 'Размер: M' === $items[0]['variant'], $items[0]['variant'] );
check( 'артикул сохранён', 'SKU-11' === $items[0]['sku'] );

echo "\n== 3. Контакты подтягиваются, когда появились ==\n";
WC()->customer->email = 'guest@example.com';
WC()->customer->phone = '+7 999 123-45-67';
WC()->customer->first = 'Ярослав';
$carts->mark_dirty();
$carts->save();

$row = reset( $GLOBALS['wpdb']->rows );
check( 'запись не задвоилась', 1 === count( $GLOBALS['wpdb']->rows ), (string) count( $GLOBALS['wpdb']->rows ) );
check( 'e-mail сохранён', 'guest@example.com' === $row['email'] );
check( 'телефон сохранён', '+7 999 123-45-67' === $row['phone'] );
check( 'имя сохранено', 'Ярослав' === $row['name'] );

echo "\n== 4. Технический адрес в отчёт не попадает ==\n";
WC()->customer->email = '79001234567@phone.example.test';
$carts->mark_dirty();
$carts->save();
$row = reset( $GLOBALS['wpdb']->rows );
check( 'технический e-mail отброшен', '' === $row['email'], $row['email'] );
WC()->customer->email = 'guest@example.com';

echo "\n== 5. Заказ оформлен ==\n";
$carts->mark_converted( 777 );
$carts->save();
$row = reset( $GLOBALS['wpdb']->rows );
check( 'статус converted', 'converted' === $row['status'], $row['status'] );
check( 'номер заказа сохранён', 777 === (int) $row['order_id'] );
check( 'товары не стёрты', ! empty( $row['items'] ) );

echo "\n== 6. Корзину опустошили руками ==\n";
$GLOBALS['wpdb']->rows = array();
WC()->cart->items = array( 'a' => array( 'quantity' => 1, 'line_total' => 100, 'data' => new WC_Product( 30, 'Кепка' ) ) );
$carts->mark_dirty();
$carts->save();
check( 'корзина записана', 1 === count( $GLOBALS['wpdb']->rows ) );

WC()->cart->items = array();
$carts->mark_dirty();
$carts->save();
check( 'пустая корзина удалена', 0 === count( $GLOBALS['wpdb']->rows ), print_r( $GLOBALS['wpdb']->rows, true ) );

echo "\n== 6.1. Ключ выдаётся до отправки заголовков ==\n";
unset( $_COOKIE[ VL_Account_Carts::COOKIE ] );
check( 'до изменений ключа нет', ! VL_Account_Carts::has_key() );
$carts->mark_dirty();
check( 'ключ выдан при первом же изменении корзины', VL_Account_Carts::has_key() );

echo "\n== 7. Строка для админки ==\n";
$prepared = VL_Account_Carts::prepare_row(
	array(
		'cart_key' => 'abc',
		'user_id'  => '0',
		'items'    => wp_json_encode( array( array( 'name' => 'Шарф', 'qty' => 1 ) ) ),
		'status'   => 'active',
	)
);
check( 'товары разобраны', is_array( $prepared['items'] ) && 'Шарф' === $prepared['items'][0]['name'] );
check( 'пользователь не найден', false === $prepared['user'] );

echo "\n== 8. Автозаполнение: значения autocomplete ==\n";
VL_Account_Autofill::instance();
check( 'имя плательщика', 'billing given-name' === VL_Account_Autofill::value_for( 'billing_first_name' ), VL_Account_Autofill::value_for( 'billing_first_name' ) );
check( 'город доставки', 'shipping address-level2' === VL_Account_Autofill::value_for( 'shipping_city' ) );
check( 'телефон без секции', 'tel' === VL_Account_Autofill::value_for( 'billing_phone' ) );
check( 'почта без секции', 'email' === VL_Account_Autofill::value_for( 'billing_email' ) );
check( 'индекс', 'billing postal-code' === VL_Account_Autofill::value_for( 'billing_postcode' ) );
check( 'неизвестное поле', '' === VL_Account_Autofill::value_for( 'order_comments' ) );

echo "\n== 9. Автозаполнение: поля оформления ==\n";
$fields = apply_filters(
	'woocommerce_checkout_fields',
	array(
		'billing' => array(
			'billing_first_name' => array( 'label' => 'Имя' ),
			'billing_phone'      => array( 'label' => 'Телефон', 'custom_attributes' => array( 'inputmode' => 'tel' ) ),
		),
		'order'   => array(
			'order_comments' => array( 'label' => 'Комментарий' ),
		),
	)
);
check( 'атрибут проставлен', 'billing given-name' === $fields['billing']['billing_first_name']['custom_attributes']['autocomplete'], print_r( $fields['billing']['billing_first_name'], true ) );
check( 'существующие атрибуты сохранены', 'tel' === $fields['billing']['billing_phone']['custom_attributes']['inputmode'] );
check( 'комментарий не трогаем', ! isset( $fields['order']['order_comments']['custom_attributes'] ) );

echo "\n== 10. Автозаполнение из аккаунта ==\n";
$GLOBALS['logged_in']     = true;
$GLOBALS['users'][1]      = new WP_User( array( 'ID' => 1, 'user_email' => 'real@example.com', 'first_name' => 'Ярослав', 'last_name' => '' ) );
update_user_meta( 1, VL_Account_User::META_PHONE, '79261234567' );

check( 'телефон из кабинета', '+7 (926) 123-45-67' === apply_filters( 'woocommerce_checkout_get_value', '', 'billing_phone' ), apply_filters( 'woocommerce_checkout_get_value', '', 'billing_phone' ) );
check( 'имя из аккаунта', 'Ярослав' === apply_filters( 'woocommerce_checkout_get_value', '', 'billing_first_name' ) );
check( 'почта из аккаунта', 'real@example.com' === apply_filters( 'woocommerce_checkout_get_value', '', 'billing_email' ) );
check( 'заполненное поле не перетираем', 'Иван' === apply_filters( 'woocommerce_checkout_get_value', 'Иван', 'billing_first_name' ) );

$GLOBALS['users'][1]->user_email = '79261234567@phone.example.test';
check( 'технический адрес не подставляется', '' === apply_filters( 'woocommerce_checkout_get_value', '', 'billing_email' ) );

echo "\n== 11. Формы входа по телефону не трогаем ==\n";
$ignore = VL_Account_Autofill::ignored_selectors();
check( 'панель входа в списке', in_array( '[data-vl-drawer]', $ignore, true ), print_r( $ignore, true ) );
check( 'форма входа в списке', in_array( '[data-vl-form="login"]', $ignore, true ) );
check( 'форма WooCommerce не в списке', ! in_array( '.woocommerce-form-login', $ignore, true ) );

echo "\n== ИТОГО: $pass ок, $fail ошибок ==\n";
exit( $fail > 0 ? 1 : 0 );
