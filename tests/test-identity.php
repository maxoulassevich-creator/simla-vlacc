<?php
/**
 * Смоук-тест сопоставления и объединения аккаунтов.
 *
 * Главное, что здесь проверяется: покупатель, зарегистрированный когда-то
 * почтой, при входе по SMS попадает в свой старый аккаунт, а не в новый
 * пустой; пустышки сливаются со старым аккаунтом со всем содержимым;
 * в аккаунт с правами администратора так войти нельзя.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки WooCommerce и WordPress ---- */

class WC_Order {
	public $id;
	public $customer_id;
	public $phone;
	public $email;
	public $saved = 0;
	public $meta  = array();
	public function __construct( $id, $customer_id, $phone, $email = '' ) {
		$this->id          = $id;
		$this->customer_id = $customer_id;
		$this->phone       = $phone;
		$this->email       = $email;
	}
	public function get_id() { return $this->id; }
	public function get_customer_id() { return $this->customer_id; }
	public function set_customer_id( $id ) { $this->customer_id = (int) $id; }
	public function get_billing_phone() { return $this->phone; }
	public function get_billing_email() { return $this->email; }
	public function get_billing_first_name() { return ''; }
	public function get_billing_last_name() { return ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function save() { ++$this->saved; return $this->id; }
}

$GLOBALS['orders'] = array();

function wc_get_orders( $args = array() ) {
	$result = array();

	foreach ( $GLOBALS['orders'] as $order ) {
		if ( isset( $args['customer_id'] ) && (int) $order->get_customer_id() !== (int) $args['customer_id'] ) {
			continue;
		}

		if ( isset( $args['billing_phone'] ) && $order->get_billing_phone() !== $args['billing_phone'] ) {
			continue;
		}

		if ( isset( $args['billing_email'] ) && strtolower( $order->get_billing_email() ) !== strtolower( $args['billing_email'] ) ) {
			continue;
		}

		$result[] = $order;
	}

	return $result;
}
function wc_get_order_statuses() { return array( 'wc-completed' => 'Completed' ); }

/** Заглушка $wpdb: журналу достаточно записи в массив. */
class Fake_WPDB {
	public $prefix   = 'wp_';
	public $postmeta = 'wp_postmeta';
	public $inserted = array();
	public $updated  = array();
	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) {
		// Таблицы HPOS в тесте нет: поиск уходит в классическое хранение.
		if ( false !== stripos( $sql, 'wc_order_addresses' ) ) { return null; }

		return false !== stripos( $sql, 'SHOW TABLES' ) ? 'wp_vlacc_identity_log' : null;
	}
	public function get_results( $sql, $out = null ) { return array(); }
	public function get_col( $sql ) { return $GLOBALS['order_ids_by_digits'] ?? array(); }
	public function insert( $table, $data ) { $this->inserted[] = array( $table, $data ); return 1; }
	public function update( $table, $data, $where ) { $this->updated[] = array( $table, $data, $where ); return 1; }
	public function query( $sql ) { return 1; }
}

$GLOBALS['wpdb']                = new Fake_WPDB();
$GLOBALS['order_ids_by_digits'] = array();


require_once VLACC_PATH . 'includes/class-vl-wishlist.php';
require_once VLACC_PATH . 'includes/class-vl-orders.php';
require_once VLACC_PATH . 'includes/class-vl-identity.php';

/**
 * Завести пользователя.
 */
function make_user( $id, $email, $caps = array() ) {
	$GLOBALS['users'][ $id ] = new WP_User(
		array(
			'ID'         => $id,
			'user_email' => $email,
			'user_login' => 'user' . $id,
		)
	);
	$GLOBALS['caps'][ $id ] = $caps;

	return $GLOBALS['users'][ $id ];
}

/**
 * Сбросить состояние между сценариями.
 */
function reset_world() {
	$GLOBALS['users']         = array();
	$GLOBALS['usermeta']      = array();
	$GLOBALS['caps']          = array();
	$GLOBALS['orders']        = array();
	$GLOBALS['deleted_users']       = array();
	$GLOBALS['log']                 = array();
	$GLOBALS['order_ids_by_digits'] = array();
	VL_Account_Identity::flush_cache();
}

VL_Account_Settings::update(
	array(
		'identity_orders'          => 1,
		'identity_crm'             => 0, // CRM проверяется отдельным набором.
		'identity_merge'           => 1,
		'identity_delete_merged'   => 1,
		'identity_trust_crm_email' => 1,
		'attach_guest_orders'      => 0,
	)
);

echo "\n== 1. Кого пускать нельзя ==\n";
reset_world();

make_user( 1, 'shop@example.com', array( 'manage_options', 'manage_woocommerce' ) );
make_user( 2, 'manager@example.com', array( 'manage_woocommerce', 'edit_shop_orders' ) );
make_user( 3, 'client@example.com' );

check( 'администратор — нет', ! VL_Account_Identity::is_adoptable( 1 ) );
check( 'менеджер магазина — нет', ! VL_Account_Identity::is_adoptable( 2 ) );
check( 'покупатель — да', VL_Account_Identity::is_adoptable( 3 ) );
check( 'несуществующий — нет', ! VL_Account_Identity::is_adoptable( 999 ) );

echo "\n== 2. Технический аккаунт от входа по SMS ==\n";
reset_world();

make_user( 10, '79261234567@phone.example.test' );
make_user( 11, 'real@example.com' );

check( 'аккаунт с техпочтой — пустышка', VL_Account_Identity::is_technical( 10 ) );
check( 'аккаунт с настоящей почтой — нет', ! VL_Account_Identity::is_technical( 11 ) );

echo "\n== 3. Поиск старого аккаунта по заказам сайта ==\n";
reset_world();

make_user( 20, 'old@example.com' );
$GLOBALS['orders'][] = new WC_Order( 100, 20, '+7 (926) 123-45-67', 'old@example.com' );

check( 'аккаунт найден по заказу', 20 === VL_Account_Identity::find_by_orders( '79261234567' ) );

// Гостевой заказ к аккаунту не ведёт: почту в оформлении вписывают любую,
// иначе достаточно заказать на чужой адрес со своим телефоном — и войти
// по SMS в чужой кабинет.
reset_world();
make_user( 21, 'guest@example.com' );
$GLOBALS['orders'][] = new WC_Order( 101, 0, '+79261234567', 'guest@example.com' );

check( 'гостевой заказ по почте аккаунт не выдаёт', 0 === VL_Account_Identity::find_by_orders( '79261234567' ) );

// Тот же заказ, но оформленный из кабинета, — доказательство владения.
$GLOBALS['orders'][0]->customer_id = 21;

check( 'заказ из кабинета аккаунт выдаёт', 21 === VL_Account_Identity::find_by_orders( '79261234567' ) );

echo "\n== 3.0. Смена номера в аккаунте видна в журнале ==\n";
reset_world();

make_user( 28, 'moved@example.com' );
update_user_meta( 28, VL_Account_User::META_PHONE, '79261111111' );

VL_Account_Identity::attach_phone( 28, '79262222222' );

$changed = false;

foreach ( $GLOBALS['log'] as $entry ) {
	if ( false !== mb_strpos( $entry[0], 'conflict' ) ) { $changed = true; }
}

check( 'новый номер записан', '79262222222' === (string) get_user_meta( 28, VL_Account_User::META_PHONE, true ) );
check( 'смена номера попала в журнал', $changed, print_r( $GLOBALS['log'], true ) );

// Тот же номер повторно — это не смена, журнал молчит.
$GLOBALS['log'] = array();
VL_Account_Identity::attach_phone( 28, '79262222222' );

$again = false;

foreach ( $GLOBALS['log'] as $entry ) {
	if ( false !== mb_strpos( $entry[0], 'conflict' ) ) { $again = true; }
}

check( 'повторный вход журнал не засоряет', ! $again );

echo "\n== 3.1. Гостевой заказ не открывает чужой кабинет ==\n";
reset_world();

// Атака: заказ оформлен гостем на чужую почту, но со своим телефоном.
// Раньше заказ привязывался к чужому аккаунту, туда же записывался телефон
// атакующего — и вход по SMS открывал чужой кабинет.
VL_Account_Settings::update( array( 'auto_create_account' => 0 ) );

make_user( 30, 'victim@example.com' );

$vl_orders = VL_Account_Orders::instance();
$attack    = new WC_Order( 200, 0, '+79990001122', 'victim@example.com' );

$GLOBALS['orders'][] = $attack;
$vl_orders->after_checkout( 200, array(), $attack );

check( 'телефон атакующего в чужой профиль не записан', '' === (string) get_user_meta( 30, VL_Account_User::META_PHONE, true ), (string) get_user_meta( 30, VL_Account_User::META_PHONE, true ) );
check( 'заказ помечен как привязанный по почте', VL_Account_Orders::matched_by_email( $attack ) );
check( 'и по такому заказу вход не находит аккаунт', 0 === VL_Account_Identity::find_by_orders( '79990001122' ), (string) VL_Account_Identity::find_by_orders( '79990001122' ) );

// Заказ на почту аккаунта с правами не привязывается вовсе.
reset_world();
make_user( 31, 'shop@example.com', array( 'manage_woocommerce' ) );

$to_admin            = new WC_Order( 201, 0, '+79990002233', 'shop@example.com' );
$GLOBALS['orders'][] = $to_admin;
$vl_orders->after_checkout( 201, array(), $to_admin );

check( 'заказ не уехал в аккаунт с правами', 0 === (int) $to_admin->get_customer_id() );
check( 'телефон менеджеру не записан', '' === (string) get_user_meta( 31, VL_Account_User::META_PHONE, true ) );

// Свой заказ из кабинета: телефон записать можно — человек был авторизован.
reset_world();
make_user( 32, 'buyer@example.com' );

$own                 = new WC_Order( 202, 32, '+79261234567', 'buyer@example.com' );
$GLOBALS['orders'][] = $own;
$vl_orders->after_checkout( 202, array(), $own );

check( 'свой телефон из своего заказа записан', '79261234567' === (string) get_user_meta( 32, VL_Account_User::META_PHONE, true ) );

VL_Account_Settings::update( array( 'auto_create_account' => 1 ) );

// Номер записан «как попало» — обычный поиск по формату его не находит,
// должен сработать запасной поиск по цифрам.
reset_world();
make_user( 25, 'strange@example.com' );
$GLOBALS['orders'][]            = new WC_Order( 106, 25, '8-926-123.45.67' );
$GLOBALS['order_ids_by_digits'] = array( 106 );

check( 'нестандартная запись номера находится по цифрам', 25 === VL_Account_Identity::find_by_orders( '79261234567' ) );

reset_world();
make_user( 26, 'other@example.com' );
$GLOBALS['orders'][]            = new WC_Order( 107, 26, '+79261111111' );
$GLOBALS['order_ids_by_digits'] = array( 107 );

check( 'чужой номер из выборки отсеивается', 0 === VL_Account_Identity::find_by_orders( '79261234567' ) );

reset_world();
make_user( 22, 'one@example.com' );
make_user( 23, 'two@example.com' );
$GLOBALS['orders'][] = new WC_Order( 102, 22, '+79261234567' );
$GLOBALS['orders'][] = new WC_Order( 103, 23, '+79261234567' );

check( 'два разных аккаунта на номер — не выбираем', 0 === VL_Account_Identity::find_by_orders( '79261234567' ) );

$conflict = false;

foreach ( $GLOBALS['log'] as $entry ) {
	if ( false !== mb_strpos( $entry[0], 'conflict' ) ) { $conflict = true; }
}

check( 'конфликт записан в журнал', $conflict, print_r( $GLOBALS['log'], true ) );

reset_world();
make_user( 24, 'boss@example.com', array( 'manage_options' ) );
$GLOBALS['orders'][] = new WC_Order( 104, 24, '+79261234567' );

check( 'аккаунт администратора по заказу не отдаём', 0 === VL_Account_Identity::find_by_orders( '79261234567' ) );

echo "\n== 4. Кого возвращает поиск по телефону ==\n";
reset_world();

$old = make_user( 30, 'old@example.com' );
$new = make_user( 31, '79261234567@phone.example.test' );
$GLOBALS['orders'][] = new WC_Order( 105, 30, '+79261234567', 'old@example.com' );

check( 'настоящий аккаунт остаётся сам собой', 30 === VL_Account_Identity::resolve( $old, '79261234567' )->ID );
check( 'пустышку подменяем старым аккаунтом', 30 === VL_Account_Identity::resolve( $new, '79261234567' )->ID );

update_user_meta( 31, VL_Account_Identity::META_MERGED, 30 );
check( 'объединённый аккаунт ведёт к своему хозяину', 30 === VL_Account_Identity::resolve( $new, '79261234567' )->ID );

reset_world();
check( 'ничего не нашли — ничего не выдумываем', false === VL_Account_Identity::resolve( false, '79261234567' ) );

echo "\n== 5. Слияние переносит всё ==\n";
reset_world();

$old = make_user( 40, 'old@example.com' );
$new = make_user( 41, '79261234567@phone.example.test' );

$GLOBALS['orders'][] = new WC_Order( 200, 41, '+79261234567' );
$GLOBALS['orders'][] = new WC_Order( 201, 40, '+79261234567' );

update_user_meta( 41, VL_Account_Wishlist::META, array( 5, 6 ) );
update_user_meta( 40, VL_Account_Wishlist::META, array( 6, 7 ) );
update_user_meta( 41, VL_Account_Promo::META, array( array( 'code' => 'SMS10' ) ) );
update_user_meta( 40, VL_Account_Promo::META, array( array( 'code' => 'HELLO' ) ) );
update_user_meta( 41, VL_Account_Bonus::META_BALANCE, 300 );
update_user_meta( 40, VL_Account_Bonus::META_BALANCE, 200 );
update_user_meta( 41, 'billing_city', 'Казань' );
update_user_meta( 41, VL_Account_User::META_TELEGRAM, 'client' );
update_user_meta( 40, 'billing_city', 'Москва' );
update_user_meta( 41, VL_Account_User::META_CONSENTS, array( 'marketing' => array( 'value' => true, 'date' => '2026-01-01' ) ) );
update_user_meta( 40, VL_Account_User::META_CONSENTS, array( 'marketing' => array( 'value' => false ) ) );

$moved = VL_Account_Identity::merge( 41, 40, 'phone' );

check( 'заказ переехал', 40 === $GLOBALS['orders'][0]->get_customer_id(), (string) $GLOBALS['orders'][0]->get_customer_id() );
check( 'счётчик заказов верный', 1 === $moved['orders'], print_r( $moved, true ) );

$wishlist = get_user_meta( 40, VL_Account_Wishlist::META, true );
sort( $wishlist );
check( 'избранное объединено без дублей', array( 5, 6, 7 ) === $wishlist, print_r( $wishlist, true ) );

$promo = get_user_meta( 40, VL_Account_Promo::META, true );
check( 'промокоды перенесены', 2 === count( $promo ), print_r( $promo, true ) );

check( 'баллы сложены', 500.0 === (float) get_user_meta( 40, VL_Account_Bonus::META_BALANCE, true ) );
check( 'перенос баллов виден в истории', ! empty( get_user_meta( 40, VL_Account_Bonus::META_HISTORY, true ) ) );

check( 'заполненное поле не перетёрто', 'Москва' === get_user_meta( 40, 'billing_city', true ) );
check( 'пустое поле заполнено', 'client' === get_user_meta( 40, VL_Account_User::META_TELEGRAM, true ) );

$consents = get_user_meta( 40, VL_Account_User::META_CONSENTS, true );
check( 'согласие «да» важнее', ! empty( $consents['marketing']['value'] ), print_r( $consents, true ) );

check( 'пустышка удалена', ! isset( $GLOBALS['users'][41] ) );
check( 'записи переданы старому аккаунту', array( 41, 40 ) === ( $GLOBALS['deleted_users'][0] ?? array() ), print_r( $GLOBALS['deleted_users'], true ) );

echo "\n== 6. Вход после подтверждения кода ==\n";
reset_world();

$old = make_user( 50, 'old@example.com' );
$new = make_user( 51, '79261234567@phone.example.test' );
update_user_meta( 51, VL_Account_User::META_PHONE, '79261234567' );
$GLOBALS['orders'][] = new WC_Order( 300, 50, '+79261234567', 'old@example.com' );

$target = VL_Account_Identity::prepare_login( $new, '79261234567' );

check( 'входим в старый аккаунт', $target instanceof WP_User && 50 === $target->ID, is_object( $target ) ? $target->ID : 'нет' );
check( 'телефон записан в старый аккаунт', '79261234567' === get_user_meta( 50, VL_Account_User::META_PHONE, true ) );
check( 'номер отмечен как подтверждённый', '' !== get_user_meta( 50, VL_Account_User::META_VERIFIED, true ) );
check( 'пустышка убрана', ! isset( $GLOBALS['users'][51] ) );

echo "\n== 7. Два живых аккаунта автоматически не сливаются ==\n";
reset_world();

$old  = make_user( 60, 'old@example.com' );
$also = make_user( 61, 'second@example.com' );
update_user_meta( 61, VL_Account_User::META_PHONE, '79261234567' );

VL_Account_Identity::prepare_login( $old, '79261234567' );

check( 'второй аккаунт на месте', isset( $GLOBALS['users'][61] ) );

$conflict = false;

foreach ( $GLOBALS['log'] as $entry ) {
	if ( false !== mb_strpos( $entry[0], 'conflict' ) ) { $conflict = true; }
}

check( 'случай ушёл в журнал', $conflict, print_r( $GLOBALS['log'], true ) );

echo "\n== 8. Поиск через базу CRM ==\n";
reset_world();

VL_Account_Settings::update( array( 'identity_crm' => 1, 'identity_orders' => 0 ) );

// Подменяем справочник CRM: телефон известен, аккаунт в снимке не проставлен.
class Fake_Directory {
	public static $row     = false;
	public static $matched = 0;
	public static function find_by_phone( $phone ) { return self::$row; }
	public static function match_row( $row ) { ++self::$matched; return 'matched'; }
	public static function rows_by_phone( $phone ) { return self::$row ? array( self::$row ) : array(); }
	public static function combine( $rows ) { $rows = array_values( (array) $rows ); return $rows ? $rows[0] : false; }
	/** Те же правила, что в настоящем справочнике. */
	public static function conflict_reason( $row ) {
		if ( count( (array) ( $row['emails'] ?? array() ) ) > 1 ) { return 'разные почты'; }
		if ( count( (array) ( $row['external_ids'] ?? array() ) ) > 1 ) { return 'разные аккаунты'; }

		return '';
	}
	public static function crm_ids_by_phone( $phone ) { return self::$row ? array( (int) self::$row['crm_id'] ) : array(); }
	public static function flush_cache() {}
}

class_alias( 'Fake_Directory', 'VL_Account_RetailCRM_Directory' );

// Настоящий мост CRM включаем ключами интеграции, как на боевом сайте.
update_option(
	'woocommerce_integration-retailcrm_settings',
	array(
		'api_url' => 'https://demo.simla.com',
		'api_key' => 'key',
	)
);

make_user( 55, 'crm-client@example.com' );

Fake_Directory::$row = array(
	'id'          => 7,
	'crm_id'      => 700,
	'external_id' => 55,
	'phone'       => '79261234567',
	'email'       => 'crm-client@example.com',
	'user_id'     => 55,
	'status'      => 'matched',
);

check( 'аккаунт найден по базе CRM', 55 === (int) VL_Account_Identity::find_in_crm( '79261234567' )['user_id'] );

$found = VL_Account_Identity::find_elsewhere( '79261234567' );
check( 'источник — CRM', $found && 'crm' === $found['source'], print_r( $found, true ) );

// Снимок устарел: аккаунт завели после сверки, user_id в строке пустой.
reset_world();
make_user( 56, 'later@example.com' );

Fake_Directory::$matched = 0;
Fake_Directory::$row     = array(
	'id'          => 8,
	'crm_id'      => 701,
	'external_id' => 0,
	'phone'       => '79261234567',
	'email'       => 'later@example.com',
	'user_id'     => 0,
	'status'      => 'no_user',
);

$row = VL_Account_Identity::find_in_crm( '79261234567' );

check( 'устаревший снимок: аккаунт найден по почте', $row && 56 === (int) $row['user_id'], print_r( $row, true ) );
check( 'снимок сразу поправлен', 1 === Fake_Directory::$matched );

// Клиент CRM с правами администратора — не отдаём.
reset_world();
make_user( 57, 'boss@example.com', array( 'manage_options' ) );

Fake_Directory::$row = array(
	'id'          => 9,
	'crm_id'      => 702,
	'external_id' => 57,
	'phone'       => '79261234567',
	'email'       => 'boss@example.com',
	'user_id'     => 57,
	'status'      => 'matched',
);

check( 'админа по базе CRM не отдаём', false === VL_Account_Identity::find_in_crm( '79261234567' ) );

echo "\n== 8.1. Дубли карточек CRM: конфликт разбирается, а не отбрасывается ==\n";

// Две карточки на один телефон: одна привязана к пустышке от входа по SMS,
// другая — к старому живому аккаунту. Пускать надо в живой.
reset_world();
make_user( 58, 'old-client@example.com' );
make_user( 59, '79261234567@phone.test' );

Fake_Directory::$row = array(
	'id'          => 10,
	'crm_id'      => 703,
	'external_id' => 59,
	'phone'       => '79261234567',
	'email'       => 'old-client@example.com',
	'user_id'     => 59,
	'user_ids'    => array( 59, 58 ),
	'crm_ids'     => array( 703, 704 ),
	'first_name'  => 'Александр',
	'status'      => 'conflict',
);

$row = VL_Account_Identity::find_in_crm( '79261234567' );

check( 'конфликт: выбран живой аккаунт', $row && 58 === (int) $row['user_id'], print_r( $row, true ) );
check( 'данные склеенной карточки на месте', $row && 'Александр' === $row['first_name'] );

// Живых аккаунтов нет — берём самую старую пустышку, остальные к ней приклеятся.
reset_world();
make_user( 60, '79261234567@phone.test' );
make_user( 61, '79261234567-2@phone.test' );

Fake_Directory::$row = array(
	'id'          => 11,
	'crm_id'      => 705,
	'external_id' => 61,
	'phone'       => '79261234567',
	'email'       => '',
	'user_id'     => 61,
	'user_ids'    => array( 61, 60 ),
	'status'      => 'conflict',
);

$row = VL_Account_Identity::find_in_crm( '79261234567' );

check( 'без живых берём самую старую пустышку', $row && 60 === (int) $row['user_id'], print_r( $row, true ) );

// Два живых аккаунта: выбрать нельзя — чужой кабинет хуже пустого.
reset_world();
make_user( 62, 'first@example.com' );
make_user( 63, 'second@example.com' );

$GLOBALS['orders'][] = new WC_Order( 71, 63, '+7 926 123-45-67', 'second@example.com' );

Fake_Directory::$row = array(
	'id'          => 12,
	'crm_id'      => 706,
	'external_id' => 62,
	'phone'       => '79261234567',
	'email'       => '',
	'user_id'     => 62,
	'user_ids'    => array( 62, 63 ),
	'status'      => 'conflict',
);

$row      = VL_Account_Identity::find_in_crm( '79261234567' );
$conflict = false;

foreach ( $GLOBALS['log'] as $entry ) {
	if ( false !== mb_strpos( $entry[0], 'conflict' ) ) { $conflict = true; }
}

check( 'из двух живых не выбираем ни одного', false === $row, print_r( $row, true ) );
check( 'случай записан в журнал', $conflict, print_r( $GLOBALS['log'], true ) );

// Карточки номера принадлежат разным людям — номер не годится совсем.
reset_world();
make_user( 64, 'real-owner@example.com' );

Fake_Directory::$row = array(
	'id'           => 13,
	'crm_id'       => 707,
	'external_id'  => 64,
	'phone'        => '79261234567',
	'email'        => 'real-owner@example.com',
	'user_id'      => 64,
	'user_ids'     => array( 64 ),
	'emails'       => array( 'real-owner@example.com', 'somebody-else@example.com' ),
	'external_ids' => array( 64 ),
	'status'       => 'matched',
);

$row      = VL_Account_Identity::find_in_crm( '79261234567' );
$conflict = false;

foreach ( $GLOBALS['log'] as $entry ) {
	if ( false !== mb_strpos( $entry[0], 'conflict' ) ) { $conflict = true; }
}

check( 'разные люди на одном номере — не пускаем никуда', false === $row, print_r( $row, true ) );
check( 'и это видно в журнале', $conflict );

// Одна почта на всех карточках — обычный случай, работает как раньше.
reset_world();
make_user( 65, 'one-person@example.com' );

Fake_Directory::$row = array(
	'id'           => 14,
	'crm_id'       => 708,
	'external_id'  => 65,
	'phone'        => '79261234567',
	'email'        => 'one-person@example.com',
	'user_id'      => 65,
	'user_ids'     => array( 65 ),
	'emails'       => array( 'one-person@example.com' ),
	'external_ids' => array( 65 ),
	'status'       => 'matched',
);

check( 'один человек — аккаунт находится', 65 === (int) VL_Account_Identity::find_in_crm( '79261234567' )['user_id'] );

VL_Account_Settings::update( array( 'identity_crm' => 0, 'identity_orders' => 1 ) );

echo "\n== 9. Выключатели ==\n";
reset_world();

VL_Account_Settings::update( array( 'identity_merge' => 0 ) );

make_user( 70, 'old@example.com' );
make_user( 71, '79261234567@phone.example.test' );
update_user_meta( 71, VL_Account_User::META_PHONE, '79261234567' );

VL_Account_Identity::prepare_login( $GLOBALS['users'][70], '79261234567' );
check( 'слияние выключено — аккаунт не тронут', isset( $GLOBALS['users'][71] ) );

VL_Account_Settings::update( array( 'identity_merge' => 1, 'identity_delete_merged' => 0 ) );

reset_world();
make_user( 80, 'old@example.com' );
make_user( 81, '79261234567@phone.example.test' );
update_user_meta( 81, VL_Account_User::META_PHONE, '79261234567' );

VL_Account_Identity::merge( 81, 80, 'phone' );

check( 'без удаления аккаунт остаётся', isset( $GLOBALS['users'][81] ) );
check( 'но помечен как объединённый', 80 === (int) get_user_meta( 81, VL_Account_Identity::META_MERGED, true ) );
check( 'и телефон с него снят', '' === get_user_meta( 81, VL_Account_User::META_PHONE, true ) );

VL_Account_Settings::update( array( 'identity_delete_merged' => 1 ) );

reset_world();
VL_Account_Settings::update( array( 'identity_orders' => 0 ) );
make_user( 90, 'old@example.com' );
$GLOBALS['orders'][] = new WC_Order( 400, 90, '+79261234567' );

check( 'поиск по заказам выключается настройкой', 0 === VL_Account_Identity::find_by_orders( '79261234567' ) );
VL_Account_Settings::update( array( 'identity_orders' => 1 ) );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
