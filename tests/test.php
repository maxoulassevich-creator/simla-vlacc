<?php
require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "  ok  $name\n";
	} else {
		++$fail;
		echo "FAIL  $name  $extra\n";
	}
}

/* --- Настройки Simla --- */
update_option(
	'woocommerce_integration-retailcrm_settings',
	array(
		'api_url'                => 'https://demo.simla.com',
		'api_key'                => 'key',
		'loyalty'                => 'yes',
		'loyalty_terms'          => 'Условия',
		'loyalty_personal'       => 'Персданные',
		'woo_coupon_apply_field' => 'promo_code',
	)
);

echo "\n== 1. Доступность интеграции ==\n";
check( 'plugin_active', VL_Account_RetailCRM::plugin_active() );
check( 'api_ready', VL_Account_RetailCRM::api_ready() );
check( 'loyalty_enabled', VL_Account_RetailCRM::loyalty_enabled() );
check( 'enabled', VL_Account_RetailCRM::enabled() );
check( 'loyalty_active', VL_Account_RetailCRM::loyalty_active() );

echo "\n== 2. Ошибка CRM не роняет кабинет ==\n";
WC_Retailcrm_Proxy::$responses = array(); // всё вернёт 900
$account = VL_Account_RetailCRM::account( 1, true );
check( 'статус none при отсутствии покупателя', 'none' === $account['status'], $account['status'] );
check( 'структура заполнена', isset( $account['level']['type'], $account['history'] ) );

echo "\n== 3. Ошибка списка участий ==\n";
WC_Retailcrm_Proxy::$responses = array(
	'customersGet'          => new WC_Retailcrm_Response( 200, '{"customer":{"id":77}}' ),
	'getLoyaltyAccountList' => new WC_Retailcrm_Response( 500, '{}' ),
);
$account = VL_Account_RetailCRM::account( 1, true );
check( 'статус error', 'error' === $account['status'], $account['status'] );
$cached = get_transient( 'vlacc_crm_lp_' . (int) get_option( 'vlacc_crm_cache_gen', 1 ) . '_1' );
check( 'ошибка не перетирает кэш', ! is_array( $cached ) || 'error' !== $cached['status'], print_r( $cached, true ) );

echo "\n== 4. Участие есть, но не активировано ==\n";
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[{"id":12,"active":false,"amount":0,"ordersSum":0,"nextLevelSum":5000,
	  "phoneNumber":"+79001234567","customer":{"externalId":1},
	  "level":{"name":"Базовый","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":2},
	  "loyalty":{"currency":"RUB"}}]}'
);
$account = VL_Account_RetailCRM::account( 1, true );
check( 'статус inactive', 'inactive' === $account['status'], $account['status'] );
check( 'id участия', 12 === $account['id'] );
check( 'история не запрашивалась', array() === $account['history'] );

echo "\n== 5. Активное участие ==\n";
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[
	  {"id":11,"active":false,"amount":0,"level":{"name":"Старое","type":"discount","privilegeSize":3,"privilegeSizePromo":0},"loyalty":{"currency":"RUB"}},
	  {"id":12,"active":true,"amount":320.5,"ordersSum":12000,"nextLevelSum":20000,
	   "phoneNumber":"+79001234567","customer":{"externalId":1},
	   "level":{"name":"Серебро","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":2},
	   "loyalty":{"currency":"RUB"}}]}'
);
WC_Retailcrm_Loyalty::$history = array(
	array( 'type' => 'credit_for_order', 'amount' => 100, 'createdAt' => '2026-01-05 10:00:00', 'order' => array( 'externalId' => 501 ) ),
	array( 'type' => 'charge_for_order', 'amount' => -50, 'createdAt' => '2026-02-05 10:00:00' ),
	array( 'type' => 'unknown_type', 'amount' => 5, 'createdAt' => '2026-03-05 10:00:00' ),
);
WC_Retailcrm_Loyalty::$bonuses = array(
	'burn_soon'          => array( array( 'amount' => 40, 'date' => '2026-09-01' ) ),
	'waiting_activation' => array( array( 'amount' => 10, 'date' => '2026-08-20' ) ),
);
$account = VL_Account_RetailCRM::account( 1, true );
check( 'выбрано активное участие', 'active' === $account['status'] && 12 === $account['id'], $account['id'] );
check( 'баланс', 320.5 === $account['amount'], var_export( $account['amount'], true ) );
check( 'уровень', 'Серебро' === $account['level']['name'] );
check( 'валюта', 'RUB' === $account['currency'] );
check( 'сгорание', 40.0 === $account['burn']['amount'] );
check( 'ожидание активации', 10.0 === $account['activation']['amount'] );
check( 'история 3 строки', 3 === count( $account['history'] ) );
check( 'подпись операции с заказом', false !== strpos( $account['history'][0]['description'], '501' ), $account['history'][0]['description'] );
check( 'неизвестный тип не ломает', '' !== $account['history'][2]['description'] );
check( 'кэш записан', is_array( get_transient( 'vlacc_crm_lp_' . (int) get_option( 'vlacc_crm_cache_gen', 1 ) . '_1' ) ) );

echo "\n== 6. Источник баланса и фильтры бонусов ==\n";
$loyalty_ui = VL_Account_RetailCRM_Loyalty::instance();
check( 'источник crm', 'crm' === VL_Account_RetailCRM_Loyalty::source( 1 ) );
update_user_meta( 1, VL_Account_Bonus::META_BALANCE, 999 );
check( 'баланс из CRM', 320.5 === VL_Account_Bonus::get_balance( 1 ), var_export( VL_Account_Bonus::get_balance( 1 ), true ) );
check( 'история из CRM', 3 === count( VL_Account_Bonus::get_history( 1 ) ) );

VL_Account_Settings::update( array( 'crm_bonus_source' => 'local' ) );
check( 'источник local', 'local' === VL_Account_RetailCRM_Loyalty::source( 1 ) );
check( 'локальный баланс', 999.0 === VL_Account_Bonus::get_balance( 1 ), var_export( VL_Account_Bonus::get_balance( 1 ), true ) );
VL_Account_Settings::update( array( 'crm_bonus_source' => 'auto' ) );

echo "\n== 7. Состояние раздела «Бонусы» ==\n";
$state = VL_Account_RetailCRM_Loyalty::state( 1 );
check( 'crm=true', true === $state['crm'] );
check( 'status=active', 'active' === $state['status'] );
check( 'телефон подставлен из CRM', '79001234567' === $state['phone'], $state['phone'] );
check( 'тексты согласий', 'Условия' === $state['terms'] && 'Персданные' === $state['privacy'] );

$rules = VL_Account_RetailCRM_Loyalty::level_rules( $state['account']['level'], 'RUB' );
check( 'правила уровня', 2 === count( $rules ) && false !== strpos( $rules[0], '5' ), print_r( $rules, true ) );
check( 'не скидочный уровень', ! VL_Account_RetailCRM_Loyalty::is_discount_level( $state['account'] ) );
check( 'скидочный уровень определяется', VL_Account_RetailCRM_Loyalty::is_discount_level( array( 'level' => array( 'type' => 'discount' ) ) ) );

echo "\n== 8. Промокоды ==\n";
$promo = VL_Account_RetailCRM_Promo::instance();
check( 'loyalty123 — служебный', VL_Account_RetailCRM_Promo::is_loyalty_coupon( 'loyalty123' ) );
check( 'LOYALTY99 — служебный', VL_Account_RetailCRM_Promo::is_loyalty_coupon( 'LOYALTY99' ) );
check( 'HELLO12345 — обычный', ! VL_Account_RetailCRM_Promo::is_loyalty_coupon( 'HELLO12345' ) );
check( 'loyaltyclub — обычный', ! VL_Account_RetailCRM_Promo::is_loyalty_coupon( 'loyaltyclub' ) );

$codes = apply_filters(
	'vlacc_user_promo_codes',
	array(
		array( 'code' => 'HELLO123', 'valid' => true ),
		array( 'code' => 'loyalty4242', 'valid' => true ),
	),
	1
);
check( 'служебный купон скрыт', 1 === count( $codes ) && 'HELLO123' === $codes[0]['code'] );
check( 'individual_use снят', false === apply_filters( 'vlacc_promo_individual_use', true, 1 ) );

echo "\n== 9. Данные покупателя для CRM ==\n";
$customer_sync = VL_Account_RetailCRM_Customer::instance();
update_user_meta( 2, VL_Account_User::META_PHONE, '79261234567' );
update_user_meta( 2, VL_Account_User::META_CONSENTS, array( 'marketing' => array( 'value' => true ) ) );

class WC_Customer {
	private $id;
	public function __construct( $id = 0 ) { $this->id = $id; }
	public function get_id() { return $this->id; }
}

$data = apply_filters(
	'retailcrm_process_customer',
	array(
		'firstName' => 'Иван',
		'email'     => '79261234567@phone.example.test',
	),
	new WC_Customer( 2 )
);
check( 'телефон добавлен', isset( $data['phones'][0]['number'] ) && '+79261234567' === $data['phones'][0]['number'], print_r( $data, true ) );
check( 'технический e-mail убран', ! isset( $data['email'] ) );
check( 'подписка проставлена', isset( $data['subscribed'] ) && true === $data['subscribed'] );

$data2 = apply_filters(
	'retailcrm_process_customer',
	array( 'email' => 'real@example.com', 'phones' => array( array( 'number' => '8 (926) 123-45-67' ) ) ),
	new WC_Customer( 2 )
);
check( 'настоящий e-mail сохранён', 'real@example.com' === $data2['email'] );

// Дата регистрации: WordPress хранит её в UTC, в CRM должна уехать местная.
$GLOBALS['users'][2] = new WP_User(
	array(
		'ID'              => 2,
		'user_email'      => 'real@example.com',
		'user_registered' => '2026-08-23 19:00:00',
	)
);

$data3 = apply_filters(
	'retailcrm_process_customer',
	array( 'createdAt' => '2026-08-23 19:00:00' ),
	new WC_Customer( 2 )
);

check( 'дата регистрации переведена в местное время', '2026-08-23 22:00:00' === $data3['createdAt'], print_r( $data3, true ) );
check( 'дубль телефона не добавлен', 1 === count( $data2['phones'] ), print_r( $data2['phones'], true ) );

echo "\n== 10. Приоритет обработки заказа ==\n";
check( 'приоритет 5 при активной интеграции', 5 === apply_filters( 'vlacc_checkout_hook_priority', 20 ) );
VL_Account_Settings::update( array( 'crm_order_priority' => 0 ) );
check( 'приоритет 20 при выключенной настройке', 20 === apply_filters( 'vlacc_checkout_hook_priority', 20 ) );
VL_Account_Settings::update( array( 'crm_order_priority' => 1 ) );

echo "\n== 11. Интеграция выключена ==\n";
VL_Account_Settings::update( array( 'crm_enabled' => 0 ) );
VL_Account_RetailCRM::flush_all();
check( 'enabled=false', ! VL_Account_RetailCRM::enabled() );
check( 'account=off', 'off' === VL_Account_RetailCRM::account( 1, true )['status'] );
check( 'источник local', 'local' === VL_Account_RetailCRM_Loyalty::source( 1 ) );
check( 'баланс локальный', 999.0 === VL_Account_Bonus::get_balance( 1 ) );
VL_Account_Settings::update( array( 'crm_enabled' => 1 ) );

echo "\n== 12. Вступление в программу ==\n";
VL_Account_RetailCRM::flush_all();
WC_Retailcrm_Proxy::$responses['credentials'] = new WC_Retailcrm_Response( 200, '{"sitesAvailable":["shop"]}' );
WC_Retailcrm_Proxy::$responses['getSingleSiteForKey'] = 'shop';
$result = VL_Account_RetailCRM::register_account( 1, '8 926 123-45-67' );
check( 'регистрация прошла', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
$last = end( $GLOBALS['log'] );
$registered = false;
foreach ( $GLOBALS['log'] as $row ) {
	if ( 'lp_register' === ( $row[0] ?? '' ) && '+79261234567' === ( $row[1] ?? '' ) ) { $registered = true; }
}
check( 'телефон нормализован в +79261234567', $registered, print_r( $GLOBALS['log'], true ) );

$bad = VL_Account_RetailCRM::register_account( 1, '123' );
check( 'кривой телефон отклонён', is_wp_error( $bad ) && 'vlacc_crm_phone' === $bad->get_error_code() );

echo "\n== ИТОГО: $pass ок, $fail ошибок ==\n";
exit( $fail > 0 ? 1 : 0 );
