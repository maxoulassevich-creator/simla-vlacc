<?php
/**
 * Кабинет: заказы покупателя из CRM, которых нет на сайте.
 *
 * Оформленные офлайн, по телефону или перенесённые в CRM из другой системы.
 * Список только для чтения: на сайте таких заказов не существует.
 *
 * @package VL_Account
 *
 * @var array $orders Заказы: number, date, status, total, items.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $orders ) ) {
	return;
}

$vl_statuses = class_exists( 'VL_Account_RetailCRM' ) ? VL_Account_RetailCRM::order_statuses() : array();
?>

<h3 class="vl-subtitle"><?php esc_html_e( 'Заказы в магазине', 'vl-account' ); ?></h3>

<p class="vl-note"><?php esc_html_e( 'Покупки, оформленные не на сайте: в магазине, по телефону или через менеджера.', 'vl-account' ); ?></p>

<table class="vl-table vl-table--orders">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Заказ', 'vl-account' ); ?></th>
			<th><?php esc_html_e( 'Дата', 'vl-account' ); ?></th>
			<th><?php esc_html_e( 'Статус', 'vl-account' ); ?></th>
			<th><?php esc_html_e( 'Товаров', 'vl-account' ); ?></th>
			<th><?php esc_html_e( 'Сумма', 'vl-account' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $orders as $vl_order ) : ?>
			<?php
			$vl_status = isset( $vl_statuses[ $vl_order['status'] ] )
				? $vl_statuses[ $vl_order['status'] ]
				: $vl_order['status'];

			$vl_date = $vl_order['date'] ? mysql2date( 'd.m.Y', $vl_order['date'] ) : '';
			?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Заказ', 'vl-account' ); ?>">№ <?php echo esc_html( $vl_order['number'] ); ?></td>
				<td data-label="<?php esc_attr_e( 'Дата', 'vl-account' ); ?>"><?php echo esc_html( $vl_date ); ?></td>
				<td data-label="<?php esc_attr_e( 'Статус', 'vl-account' ); ?>"><?php echo esc_html( $vl_status ); ?></td>
				<td data-label="<?php esc_attr_e( 'Товаров', 'vl-account' ); ?>"><?php echo esc_html( $vl_order['items'] ); ?></td>
				<td data-label="<?php esc_attr_e( 'Сумма', 'vl-account' ); ?>"><?php echo wp_kses_post( wc_price( $vl_order['total'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
