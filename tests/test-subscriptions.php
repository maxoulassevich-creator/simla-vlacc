<?php
/**
 * Смоук-тест подписок на поступление (Back In Stock Notifier).
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки товаров WooCommerce ---- */

class WC_Product {
	protected $id;
	protected $name;
	protected $parent = 0;
	protected $stock  = false;
	public function __construct( $id, $name, $parent = 0, $stock = false ) {
		$this->id     = $id;
		$this->name   = $name;
		$this->parent = $parent;
		$this->stock  = $stock;
	}
	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function get_parent_id() { return $this->parent; }
	public function is_in_stock() { return $this->stock; }
}

class WC_Product_Variation extends WC_Product {
	public $attributes = array();
	public function get_variation_attributes() { return $this->attributes; }
}

function wc_get_formatted_variation( $variation, $flat = false, $include_names = true ) {
	return implode( ', ', $variation->get_variation_attributes() );
}

require_once VLACC_PATH . 'includes/integrations/class-vl-stock-notifier.php';

/* ---- Данные ---- */

$GLOBALS['products'][10] = new WC_Product( 10, 'Платье миди' );
$variation               = new WC_Product_Variation( 11, 'Платье миди — M', 10 );
$variation->attributes   = array( 'attribute_pa_razmer' => 'Размер: M' );
$GLOBALS['products'][11] = $variation;

$GLOBALS['products'][20]   = new WC_Product( 20, 'Джемпер' );
$back                      = new WC_Product_Variation( 21, 'Джемпер — L', 20, true );
$back->attributes          = array( 'attribute_pa_razmer' => 'Размер: L' );
$GLOBALS['products'][21]   = $back;

// Подписка пользователя 1 по user_id.
$GLOBALS['posts'][100] = new WP_Post(
	array(
		'ID'          => 100,
		'post_type'   => 'cwginstocknotifier',
		'post_status' => 'cwg_subscribed',
		'post_title'  => 'user@example.com',
		'post_date'   => '2026-07-01 10:00:00',
	)
);
$GLOBALS['postmeta'][100] = array(
	'cwginstock_product_id'       => 10,
	'cwginstock_variation_id'     => 11,
	'cwginstock_user_id'          => 1,
	'cwginstock_subscriber_email' => 'user@example.com',
);

// Подписка того же покупателя, оформленная гостем — только по e-mail.
$GLOBALS['posts'][101] = new WP_Post(
	array(
		'ID'          => 101,
		'post_type'   => 'cwginstocknotifier',
		'post_status' => 'cwg_mailsent',
		'post_title'  => 'user@example.com',
		'post_date'   => '2026-06-01 10:00:00',
	)
);
$GLOBALS['postmeta'][101] = array(
	'cwginstock_product_id'       => 20,
	'cwginstock_variation_id'     => 21,
	'cwginstock_user_id'          => 0,
	'cwginstock_subscriber_email' => 'user@example.com',
);

// Чужая подписка.
$GLOBALS['posts'][102] = new WP_Post(
	array(
		'ID'          => 102,
		'post_type'   => 'cwginstocknotifier',
		'post_status' => 'cwg_subscribed',
		'post_title'  => 'other@example.com',
		'post_date'   => '2026-06-15 10:00:00',
	)
);
$GLOBALS['postmeta'][102] = array(
	'cwginstock_product_id'       => 10,
	'cwginstock_variation_id'     => 11,
	'cwginstock_user_id'          => 7,
	'cwginstock_subscriber_email' => 'other@example.com',
);

$GLOBALS['users'][1] = (object) array(
	'ID'         => 1,
	'user_email' => 'user@example.com',
);

VL_Account_Stock_Notifier::instance();

echo "\n== 1. Плагин найден ==\n";
check( 'plugin_active', VL_Account_Stock_Notifier::plugin_active() );
check( 'enabled', VL_Account_Stock_Notifier::enabled() );

echo "\n== 2. Подписки пользователя ==\n";
$subs = apply_filters( 'vlacc_user_subscriptions', array(), 1 );

check( 'найдены две подписки', 2 === count( $subs ), print_r( array_column( $subs, 'id' ), true ) );

$ids = array_column( $subs, 'id' );
sort( $ids );
check( 'чужая подписка не попала', array( 100, 101 ) === $ids, print_r( $ids, true ) );

$first = $subs[0];
check( 'товар — родительский', 10 === $first['product_id'] || 20 === $first['product_id'], (string) $first['product_id'] );
check( 'название товара', in_array( $first['title'], array( 'Платье миди', 'Джемпер' ), true ), $first['title'] );
check( 'размер подставлен', false !== strpos( $first['size'], 'Размер' ), $first['size'] );
check( 'статус переведён', '' !== $first['status_label'], $first['status_label'] );

$stocked = null;
foreach ( $subs as $sub ) {
	if ( 20 === $sub['product_id'] ) { $stocked = $sub; }
}
check( 'снова в наличии отмечено', $stocked && true === $stocked['in_stock'], print_r( $stocked, true ) );

echo "\n== 3. Только активные подписки ==\n";
VL_Account_Settings::update( array( 'sn_show_sent' => 0 ) );
$subs = apply_filters( 'vlacc_user_subscriptions', array(), 1 );
check( 'отработавшая скрыта', 1 === count( $subs ) && 100 === $subs[0]['id'], print_r( array_column( $subs, 'id' ), true ) );
VL_Account_Settings::update( array( 'sn_show_sent' => 1 ) );

echo "\n== 4. Поиск по e-mail выключается ==\n";
VL_Account_Settings::update( array( 'sn_match_email' => 0 ) );
$subs = apply_filters( 'vlacc_user_subscriptions', array(), 1 );
check( 'осталась только привязанная по ID', 1 === count( $subs ) && 100 === $subs[0]['id'], print_r( array_column( $subs, 'id' ), true ) );
VL_Account_Settings::update( array( 'sn_match_email' => 1 ) );

echo "\n== 5. Проверка владельца подписки ==\n";
check( 'своя подписка', VL_Account_Stock_Notifier::belongs_to( 100, 1 ) );
check( 'подписка по e-mail', VL_Account_Stock_Notifier::belongs_to( 101, 1 ) );
check( 'чужая подписка', ! VL_Account_Stock_Notifier::belongs_to( 102, 1 ) );
check( 'несуществующая запись', ! VL_Account_Stock_Notifier::belongs_to( 999, 1 ) );

echo "\n== 6. Отписка ==\n";
VL_Account_Stock_Notifier::unsubscribe( 100 );
check( 'статус изменён', 'cwg_unsubscribed' === get_post( 100 )->post_status, get_post( 100 )->post_status );
$subs = apply_filters( 'vlacc_user_subscriptions', array(), 1 );
check( 'из списка пропала', ! in_array( 100, array_column( $subs, 'id' ), true ), print_r( array_column( $subs, 'id' ), true ) );

echo "\n== 7. Интеграция выключена ==\n";
VL_Account_Settings::update( array( 'sn_enabled' => 0 ) );
check( 'список не подменяется', array() === apply_filters( 'vlacc_user_subscriptions', array(), 1 ) );
VL_Account_Settings::update( array( 'sn_enabled' => 1 ) );

echo "\n== 8. Диагностика не падает ==\n";
$checks = VL_Account_Stock_Notifier::checks();
check( 'проверки возвращаются', is_array( $checks ) && count( $checks ) >= 2, print_r( $checks, true ) );

echo "\n== ИТОГО: $pass ок, $fail ошибок ==\n";
exit( $fail > 0 ? 1 : 0 );
