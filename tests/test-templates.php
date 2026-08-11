<?php
/**
 * Рендер новых шаблонов во всех состояниях программы лояльности.
 */

require __DIR__ . '/bootstrap.php';

function esc_html_e( $t, $d = '' ) { echo $t; }
function esc_attr_e( $t, $d = '' ) { echo $t; }
function wc_price( $v ) { return '<span>' . number_format( (float) $v, 2, ',', ' ' ) . ' ₽</span>'; }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function wc_get_page_permalink( $p ) { return 'https://example.test/my-account/'; }
function wc_get_endpoint_url( $e, $v = '', $u = '' ) { return $u . $e . '/' . ( $v ? $v . '/' : '' ); }
function add_query_arg( ...$a ) { return 'https://example.test/?x=1'; }
function wc_get_order_statuses() { return array( 'wc-completed' => 'Completed' ); }

require_once VLACC_PATH . 'includes/class-vl-router.php';
require_once VLACC_PATH . 'includes/class-vl-orders.php';
require_once VLACC_PATH . 'includes/class-vl-wishlist.php';

/** Настоящая загрузка шаблонов вместо заглушки. */
function render_template( $template, $args = array() ) {
	extract( $args, EXTR_SKIP ); // phpcs:ignore
	ob_start();
	include VLACC_PATH . 'templates/' . $template;
	return ob_get_clean();
}

update_option(
	'woocommerce_integration-retailcrm_settings',
	array(
		'api_url'          => 'https://demo.simla.com',
		'api_key'          => 'key',
		'loyalty'          => 'yes',
		'loyalty_terms'    => 'Условия программы',
		'loyalty_personal' => 'Согласие на обработку',
	)
);

WC_Retailcrm_Proxy::$responses = array(
	'customersGet'        => new WC_Retailcrm_Response( 200, '{"customer":{"id":77}}' ),
	'getSingleSiteForKey' => 'shop',
);

$scenes = array(
	'участия нет'          => '{"loyaltyAccounts":[]}',
	'не активировано'      => '{"loyaltyAccounts":[{"id":12,"active":false,"amount":0,"ordersSum":0,"nextLevelSum":0,
	                             "level":{"name":"Базовый","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":2},
	                             "loyalty":{"currency":"RUB"}}]}',
	'активно, баллы'       => '{"loyaltyAccounts":[{"id":12,"active":true,"amount":320,"ordersSum":12000,"nextLevelSum":20000,
	                             "level":{"name":"Серебро","type":"bonus_converting","privilegeSize":100,"privilegeSizePromo":200},
	                             "loyalty":{"currency":"RUB"}}]}',
	'активно, скидка'      => '{"loyaltyAccounts":[{"id":12,"active":true,"amount":0,"ordersSum":50000,"nextLevelSum":0,
	                             "level":{"name":"Золото","type":"discount","privilegeSize":10,"privilegeSizePromo":5},
	                             "loyalty":{"currency":"RUB"}}]}',
);

VL_Account_RetailCRM_Loyalty::instance(); // фильтры баланса и истории

$fail = 0;

foreach ( $scenes as $name => $json ) {
	WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response( 200, $json );
	VL_Account_RetailCRM::flush_all();
	WC_Retailcrm_Loyalty::$history = array(
		array( 'type' => 'credit_for_order', 'amount' => 100, 'createdAt' => '2026-01-05 10:00:00', 'order' => array( 'externalId' => 501 ) ),
	);

	$html = render_template( 'account/bonus.php', array( 'user_id' => 1, 'user' => null ) );

	$ok = '' !== trim( $html ) && false === strpos( $html, 'Fatal' );
	echo ( $ok ? '  ok  ' : 'FAIL  ' ) . "bonus.php — $name (" . strlen( $html ) . " байт)\n";

	if ( ! $ok ) {
		++$fail;
	}
}

// Ошибка CRM.
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response( 500, '{}' );
VL_Account_RetailCRM::flush_all();
$html = render_template( 'account/bonus.php', array( 'user_id' => 1, 'user' => null ) );
$ok   = false !== strpos( $html, 'Не удалось получить данные' );
echo ( $ok ? '  ok  ' : 'FAIL  ' ) . "bonus.php — ошибка CRM показывает предупреждение\n";
$fail += $ok ? 0 : 1;

// Интеграция выключена — старое поведение.
VL_Account_Settings::update( array( 'crm_enabled' => 0 ) );
VL_Account_RetailCRM::flush_all();
update_user_meta( 1, VL_Account_Bonus::META_BALANCE, 250 );
$html = render_template( 'account/bonus.php', array( 'user_id' => 1, 'user' => null ) );
$ok   = false !== strpos( $html, '250' ) && false === strpos( $html, 'вступить в программу' );
echo ( $ok ? '  ok  ' : 'FAIL  ' ) . "bonus.php — без интеграции показывает локальный баланс\n";
$fail += $ok ? 0 : 1;
VL_Account_Settings::update( array( 'crm_enabled' => 1 ) );

// Виджет корзины.
foreach (
	array(
		'можно списать' => array( 'balance' => 500.0, 'max' => 300.0, 'used' => 0.0, 'credit' => 42.0, 'discount' => 0.0, 'currency' => 'RUB', 'level' => array( 'name' => 'Серебро', 'type' => 'bonus_percent' ) ),
		'уже списано'   => array( 'balance' => 500.0, 'max' => 300.0, 'used' => 100.0, 'credit' => 20.0, 'discount' => 0.0, 'currency' => 'RUB', 'level' => array( 'name' => 'Серебро', 'type' => 'bonus_percent' ) ),
		'скидка уровня' => array( 'balance' => 0.0, 'max' => 0.0, 'used' => 0.0, 'credit' => 0.0, 'discount' => 150.0, 'currency' => 'RUB', 'level' => array( 'name' => 'Золото', 'type' => 'discount' ) ),
	) as $name => $lp
) {
	$html = render_template( 'parts/loyalty-cart.php', array( 'lp' => $lp ) );
	$ok   = false !== strpos( $html, 'vl-loyalty-cart' );
	echo ( $ok ? '  ok  ' : 'FAIL  ' ) . "loyalty-cart.php — $name (" . strlen( $html ) . " байт)\n";
	$fail += $ok ? 0 : 1;
}

echo "\n== Ошибок: $fail ==\n";
exit( $fail > 0 ? 1 : 0 );
