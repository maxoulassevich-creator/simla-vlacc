<?php
/**
 * Таблица заказов.
 *
 * @package VL_Account
 *
 * @var WC_Order[] $orders
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $orders ) ) {
	return;
}
?>
<div class="vl-table-wrap">
	<table class="vl-table vl-table--orders">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Заказ', 'vl-account' ); ?></th>
				<th><?php esc_html_e( 'Дата', 'vl-account' ); ?></th>
				<th><?php esc_html_e( 'Статус', 'vl-account' ); ?></th>
				<th><?php esc_html_e( 'Сумма', 'vl-account' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $orders as $vl_order ) : ?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Заказ', 'vl-account' ); ?>">
						<a href="<?php echo esc_url( VL_Account_Router::url( 'order-view', array( 'order' => $vl_order->get_id() ) ) ); ?>">
							№<?php echo esc_html( $vl_order->get_order_number() ); ?>
						</a>
					</td>
					<td data-label="<?php esc_attr_e( 'Дата', 'vl-account' ); ?>">
						<?php echo esc_html( $vl_order->get_date_created() ? $vl_order->get_date_created()->date_i18n( 'd.m.Y' ) : '' ); ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Статус', 'vl-account' ); ?>">
						<span class="vl-status vl-status--<?php echo esc_attr( $vl_order->get_status() ); ?>">
							<?php echo esc_html( wc_get_order_status_name( $vl_order->get_status() ) ); ?>
						</span>
					</td>
					<td data-label="<?php esc_attr_e( 'Сумма', 'vl-account' ); ?>">
						<?php echo wp_kses_post( $vl_order->get_formatted_order_total() ); ?>
					</td>
					<td class="vl-table__actions">
						<a class="vl-link" href="<?php echo esc_url( VL_Account_Router::url( 'order-view', array( 'order' => $vl_order->get_id() ) ) ); ?>">
							<?php esc_html_e( 'подробнее', 'vl-account' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
