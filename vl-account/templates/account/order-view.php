<?php
/**
 * Кабинет: просмотр заказа.
 *
 * @package VL_Account
 *
 * @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

if ( ! vlacc_is_woo() ) {
	return;
}

$vl_order_id = VL_Account_Router::current_order_id();
$vl_order    = $vl_order_id ? wc_get_order( $vl_order_id ) : false;

if ( ! $vl_order || ! VL_Account_Orders::can_view( $vl_order, $user_id ) ) {
	echo '<div class="vl-empty"><p>' . esc_html__( 'Заказ не найден.', 'vl-account' ) . '</p></div>';
	return;
}
?>
<div class="vl-order">
	<p class="vl-links">
		<a class="vl-link vl-link--muted" href="<?php echo esc_url( VL_Account_Router::url( 'orders' ) ); ?>">&larr; <?php esc_html_e( 'ко всем заказам', 'vl-account' ); ?></a>
	</p>

	<div class="vl-order__head">
		<h3 class="vl-subtitle">
			<?php
			printf(
				/* translators: %s — номер заказа. */
				esc_html__( 'Заказ №%s', 'vl-account' ),
				esc_html( $vl_order->get_order_number() )
			);
			?>
		</h3>
		<span class="vl-status vl-status--<?php echo esc_attr( $vl_order->get_status() ); ?>">
			<?php echo esc_html( wc_get_order_status_name( $vl_order->get_status() ) ); ?>
		</span>
		<span class="vl-order__date">
			<?php echo esc_html( $vl_order->get_date_created() ? $vl_order->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '' ); ?>
		</span>
	</div>

	<div class="vl-table-wrap">
		<table class="vl-table vl-table--items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Товар', 'vl-account' ); ?></th>
					<th><?php esc_html_e( 'Кол-во', 'vl-account' ); ?></th>
					<th><?php esc_html_e( 'Сумма', 'vl-account' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $vl_order->get_items() as $vl_item ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Товар', 'vl-account' ); ?>">
							<?php
							$vl_product = $vl_item->get_product();

							if ( $vl_product && $vl_product->is_visible() ) {
								printf(
									'<a href="%s">%s</a>',
									esc_url( $vl_product->get_permalink() ),
									esc_html( $vl_item->get_name() )
								);
							} else {
								echo esc_html( $vl_item->get_name() );
							}

							$vl_meta = wc_display_item_meta( $vl_item, array( 'echo' => false ) );

							if ( $vl_meta ) {
								echo wp_kses_post( $vl_meta );
							}
							?>
						</td>
						<td data-label="<?php esc_attr_e( 'Кол-во', 'vl-account' ); ?>"><?php echo esc_html( $vl_item->get_quantity() ); ?></td>
						<td data-label="<?php esc_attr_e( 'Сумма', 'vl-account' ); ?>"><?php echo wp_kses_post( $vl_order->get_formatted_line_subtotal( $vl_item ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<?php foreach ( $vl_order->get_order_item_totals() as $vl_total ) : ?>
					<tr>
						<th colspan="2"><?php echo esc_html( $vl_total['label'] ); ?></th>
						<td><?php echo wp_kses_post( $vl_total['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tfoot>
		</table>
	</div>

	<div class="vl-order__addresses">
		<div class="vl-order__address">
			<h4><?php esc_html_e( 'Получатель', 'vl-account' ); ?></h4>
			<p><?php echo wp_kses_post( $vl_order->get_formatted_billing_address( '—' ) ); ?></p>
			<?php if ( $vl_order->get_billing_phone() ) : ?>
				<p><?php echo esc_html( $vl_order->get_billing_phone() ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $vl_order->get_formatted_shipping_address() ) : ?>
			<div class="vl-order__address">
				<h4><?php esc_html_e( 'Доставка', 'vl-account' ); ?></h4>
				<p><?php echo wp_kses_post( $vl_order->get_formatted_shipping_address() ); ?></p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $vl_order->needs_payment() ) : ?>
		<p>
			<a class="vl-btn vl-btn--primary" href="<?php echo esc_url( $vl_order->get_checkout_payment_url() ); ?>">
				<?php esc_html_e( 'оплатить заказ', 'vl-account' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
