<?php
/**
 * Кабинет: заказы.
 *
 * @package VL_Account
 *
 * @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

if ( ! vlacc_is_woo() ) {
	echo '<div class="vl-empty"><p>' . esc_html__( 'Раздел заказов доступен при активном WooCommerce.', 'vl-account' ) . '</p></div>';
	return;
}

$vl_orders = VL_Account_Orders::get_user_orders( $user_id, 50 );

// Заказы, которых на сайте нет: офлайн, по телефону, перенесённые в CRM.
$vl_crm_orders = class_exists( 'VL_Account_RetailCRM' ) ? VL_Account_RetailCRM::orders( $user_id, 50 ) : array();

if ( ! $vl_orders && ! $vl_crm_orders ) :
	?>
	<div class="vl-empty">
		<p><?php esc_html_e( 'Здесь появятся ваши заказы — и те, что оформлены без входа в кабинет, на этот же телефон или e-mail.', 'vl-account' ); ?></p>
		<a class="vl-btn vl-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'перейти в каталог', 'vl-account' ); ?></a>
	</div>
	<?php
	return;
endif;

if ( $vl_orders ) {
	vlacc_template( 'parts/orders-table.php', array( 'orders' => $vl_orders ) );
}

if ( $vl_crm_orders ) {
	vlacc_template( 'parts/orders-crm.php', array( 'orders' => $vl_crm_orders ) );
}
