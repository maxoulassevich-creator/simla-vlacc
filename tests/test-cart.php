<?php
/**
 * Смоук-тест расчёта списания баллов в корзине.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки корзины ---- */

class Fake_Cart {
	public $items    = array();
	public $coupons  = array();
	public $totals   = 0;
	public function get_cart() { return $this->items; }
	public function get_applied_coupons() { return $this->coupons; }
	public function apply_coupon( $code ) { $this->coupons[] = $code; return true; }
	public function remove_coupon( $code ) { $this->coupons = array_values( array_diff( $this->coupons, array( $code ) ) ); return true; }
	public function calculate_totals() { ++$this->totals; }
	public function get_cart_contents_count() { return count( $this->items ); }
}

class Fake_WC {
	public $cart;
	public $integrations = null;
	public function __construct() { $this->cart = new Fake_Cart(); }
}

$GLOBALS['wc'] = new Fake_WC();
function WC() { return $GLOBALS['wc']; }

class WC_Coupon {
	public static $store = array();
	private $code   = '';
	private $amount = 0;
	private $emails = array();
	private $id     = 0;
	public function __construct( $code = '' ) {
		$this->code = $code;
		if ( $code && isset( self::$store[ $code ] ) ) {
			$this->amount = self::$store[ $code ]['amount'];
			$this->id     = self::$store[ $code ]['id'];
		}
	}
	public function set_code( $c ) { $this->code = $c; }
	public function get_code() { return $this->code; }
	public function set_amount( $a ) { $this->amount = $a; }
	public function get_amount() { return $this->amount; }
	public function set_usage_limit( $l ) {}
	public function set_individual_use( $v ) {}
	public function set_discount_type( $t ) {}
	public function set_description( $d ) {}
	public function set_email_restrictions( $e ) { $this->emails = (array) $e; }
	public function get_email_restrictions() { return $this->emails; }
	public function get_id() { return $this->id; }
	public function save() { $this->id = count( self::$store ) + 1; self::$store[ $this->code ] = array( 'amount' => $this->amount, 'id' => $this->id ); }
	public function delete( $force = false ) { unset( self::$store[ $this->code ] ); }
}

require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-cart.php';

update_option(
	'woocommerce_integration-retailcrm_settings',
	array( 'api_url' => 'https://demo.simla.com', 'api_key' => 'key', 'loyalty' => 'yes' )
);

WC_Retailcrm_Proxy::$responses = array(
	'customersGet'          => new WC_Retailcrm_Response( 200, '{"customer":{"id":77}}' ),
	'getSingleSiteForKey'   => 'shop',
	'getLoyaltyAccountList' => new WC_Retailcrm_Response(
		200,
		'{"loyaltyAccounts":[{"id":12,"active":true,"amount":500,"ordersSum":1000,"nextLevelSum":0,
		  "level":{"name":"Серебро","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":2},
		  "loyalty":{"currency":"RUB"}}]}'
	),
);

$cart = WC()->cart;
$cart->items = array( 'abc' => array( 'quantity' => 2, 'data' => null ) );

echo "\n== 1. Обычный уровень: доступно списание ==\n";
WC_Retailcrm_Proxy::$responses['calculateDiscountLoyalty'] = new WC_Retailcrm_Response(
	200,
	'{"calculations":[{"privilegeType":"loyalty_level","maxChargeBonuses":300}],
	  "loyalty":{"chargeRate":1},
	  "order":{"bonusesCreditTotal":42,"items":[{"discounts":[]}]}}'
);

$data = VL_Account_RetailCRM_Cart::widget_data();
check( 'данные получены', is_array( $data ), var_export( $data, true ) );
check( 'баланс 500', 500.0 === $data['balance'] );
check( 'максимум 300', 300.0 === $data['max'], var_export( $data['max'], true ) );
check( 'начисление 42', 42.0 === $data['credit'] );
check( 'скидки уровня нет', 0.0 === $data['discount'] );
check( 'ничего не списано', 0.0 === $data['used'] );

echo "\n== 2. Максимум ограничен балансом ==\n";
$cart->items = array( 'k2' => array( 'quantity' => 2, 'data' => null ) );
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[{"id":12,"active":true,"amount":120,"ordersSum":1000,"nextLevelSum":0,
	  "level":{"name":"Серебро","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":2},
	  "loyalty":{"currency":"RUB"}}]}'
);
VL_Account_RetailCRM::flush( 1 );
$data = VL_Account_RetailCRM_Cart::widget_data();
check( 'максимум = баланс 120', 120.0 === $data['max'], var_export( $data['max'], true ) );

echo "\n== 3. Уровень со скидкой: списание недоступно ==\n";
$cart->items = array( 'k3' => array( 'quantity' => 3, 'data' => null ) );
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[{"id":12,"active":true,"amount":0,"ordersSum":1000,"nextLevelSum":0,
	  "level":{"name":"Золото","type":"discount","privilegeSize":10,"privilegeSizePromo":5},
	  "loyalty":{"currency":"RUB"}}]}'
);
WC_Retailcrm_Proxy::$responses['calculateDiscountLoyalty'] = new WC_Retailcrm_Response(
	200,
	'{"calculations":[{"privilegeType":"loyalty_level","maxChargeBonuses":300}],
	  "loyalty":{"chargeRate":1},
	  "order":{"bonusesCreditTotal":0,"items":[{"discounts":[{"type":"loyalty_level","amount":150}]}]}}'
);
VL_Account_RetailCRM::flush( 1 );
$cart->coupons = array( 'loyalty777' );
WC_Coupon::$store['loyalty777'] = array( 'amount' => 150, 'id' => 9 );

$data = VL_Account_RetailCRM_Cart::widget_data();
check( 'скидка уровня 150', 150.0 === $data['discount'], var_export( $data['discount'], true ) );
check( 'максимум обнулён', 0.0 === $data['max'], var_export( $data['max'], true ) );
check( 'купон скидки не считается списанием', 0.0 === $data['used'], var_export( $data['used'], true ) );

echo "\n== 4. Ошибка CRM не ломает корзину ==\n";
$cart->items = array( 'k4' => array( 'quantity' => 4, 'data' => null ) );
$cart->coupons = array();
WC_Retailcrm_Proxy::$responses['calculateDiscountLoyalty'] = new WC_Retailcrm_Response( 500, '{}' );
VL_Account_RetailCRM::flush( 1 );
check( 'calculate вернул null', null === VL_Account_RetailCRM_Cart::calculate( 0 ) );
check( 'widget_data вернул null', null === VL_Account_RetailCRM_Cart::widget_data() );

echo "\n== 5. Пустая корзина ==\n";
$cart->items = array();
check( 'без товаров расчёта нет', null === VL_Account_RetailCRM_Cart::calculate( 0 ) );

echo "\n== 5.1. Почему нет поля списания ==\n";

// Ровно тот случай с боевого сайта: строка начисления есть, поля списания нет.
// Со стороны не видно, кто отказал — CRM или пустой баланс, поэтому причину
// формулируем явно.
$reason = VL_Account_RetailCRM_Cart::no_charge_reason(
	array(
		'balance'   => 1700.0,
		'max'       => 0.0,
		'crm_max'   => 0.0,
		'has_level' => true,
		'used'      => 0.0,
		'credit'    => 1400.0,
		'discount'  => 0.0,
	)
);

check( 'CRM запретила списание — причина названа', false !== mb_strpos( $reason, 'CRM разрешает списать 0' ), $reason );

$reason = VL_Account_RetailCRM_Cart::no_charge_reason(
	array(
		'balance'   => 0.0,
		'max'       => 0.0,
		'crm_max'   => 500.0,
		'has_level' => true,
		'used'      => 0.0,
		'credit'    => 10.0,
		'discount'  => 0.0,
	)
);

check( 'пустой баланс — причина другая', false !== mb_strpos( $reason, 'Доступных баллов нет' ), $reason );

$reason = VL_Account_RetailCRM_Cart::no_charge_reason(
	array(
		'balance'   => 500.0,
		'max'       => 0.0,
		'crm_max'   => 0.0,
		'has_level' => false,
		'used'      => 0.0,
		'credit'    => 0.0,
		'discount'  => 0.0,
	)
);

check( 'CRM не вернула расчёт уровня', false !== mb_strpos( $reason, 'не вернула расчёт' ), $reason );

$reason = VL_Account_RetailCRM_Cart::no_charge_reason(
	array(
		'balance'   => 500.0,
		'max'       => 0.0,
		'crm_max'   => 0.0,
		'has_level' => true,
		'used'      => 0.0,
		'credit'    => 0.0,
		'discount'  => 150.0,
	)
);

check( 'скидка уровня объясняется отдельно', false !== mb_strpos( $reason, 'скидка уровня' ), $reason );

check(
	'списание возможно — причины нет',
	'' === VL_Account_RetailCRM_Cart::no_charge_reason(
		array(
			'balance'   => 500.0,
			'max'       => 300.0,
			'crm_max'   => 300.0,
			'has_level' => true,
			'used'      => 0.0,
			'credit'    => 42.0,
			'discount'  => 0.0,
		)
	)
);

check(
	'списание уже применено — причины нет',
	'' === VL_Account_RetailCRM_Cart::no_charge_reason(
		array(
			'balance'   => 500.0,
			'max'       => 0.0,
			'crm_max'   => 300.0,
			'has_level' => true,
			'used'      => 300.0,
			'credit'    => 10.0,
			'discount'  => 0.0,
		)
	)
);

check( 'без данных виджета причина тоже есть', '' !== VL_Account_RetailCRM_Cart::no_charge_reason( null ) );

echo "\n== 5.2. Расчёт по произвольным позициям ==\n";

// Диагностика в админке считает не по живой корзине, а по одному товару.
WC_Retailcrm_Proxy::$responses['calculateDiscountLoyalty'] = new WC_Retailcrm_Response(
	200,
	'{"calculations":[{"privilegeType":"loyalty_level","maxChargeBonuses":0}],
	  "loyalty":{"chargeRate":1},
	  "order":{"bonusesCreditTotal":1400,"items":[{"discounts":[]}]}}'
);
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[{"id":12,"active":true,"amount":1700,"ordersSum":1000,"nextLevelSum":0,
	  "level":{"name":"First Love","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":2},
	  "loyalty":{"currency":"RUB"}}]}'
);
VL_Account_RetailCRM::flush( 1 );

$calc = VL_Account_RetailCRM_Cart::calculate_items( array( 'probe' => array( 'quantity' => 1, 'data' => null ) ), 1, 0 );

check( 'расчёт по одной позиции получен', is_array( $calc ), var_export( $calc, true ) );
check( 'CRM разрешила 0 баллов', 0.0 === $calc['max'], var_export( $calc['max'], true ) );
check( 'начисление при этом есть', 1400.0 === $calc['credit'] );
check( 'расчёт уровня в ответе был', true === $calc['has_level'] );
check( 'без позиций расчёта нет', null === VL_Account_RetailCRM_Cart::calculate_items( array(), 1, 0 ) );
check( 'без покупателя расчёта нет', null === VL_Account_RetailCRM_Cart::calculate_items( array( 'probe' => array( 'quantity' => 1, 'data' => null ) ), 0, 0 ) );

echo "\n== 6. Купоны с техническим адресом ==\n";
$cart_obj = VL_Account_RetailCRM_Cart::instance();
$fixed    = apply_filters( 'woocommerce_coupon_get_email_restrictions', array( '79001234567@phone.example.test' ), new WC_Coupon( 'loyalty1' ) );
check( 'технический адрес убран', array() === $fixed, print_r( $fixed, true ) );
$kept = apply_filters( 'woocommerce_coupon_get_email_restrictions', array( 'real@example.com' ), new WC_Coupon( 'HELLO' ) );
check( 'настоящий адрес сохранён', array( 'real@example.com' ) === $kept );

echo "\n== ИТОГО: $pass ок, $fail ошибок ==\n";
exit( $fail > 0 ? 1 : 0 );
