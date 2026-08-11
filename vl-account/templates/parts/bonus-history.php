<?php
/**
 * Таблица истории начислений и списаний баллов.
 *
 * @package VL_Account
 *
 * @var array $history Операции: date, amount, description, order_id.
 */

defined( 'ABSPATH' ) || exit;

$history = isset( $history ) && is_array( $history ) ? $history : array();

if ( ! $history ) :
	?>
	<p class="vl-note"><?php esc_html_e( 'История операций появится после первых начислений.', 'vl-account' ); ?></p>
	<?php
	return;
endif;
?>
<div class="vl-table-wrap">
	<table class="vl-table vl-table--bonus">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Дата', 'vl-account' ); ?></th>
				<th><?php esc_html_e( 'Операция', 'vl-account' ); ?></th>
				<th><?php esc_html_e( 'Баллы', 'vl-account' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $history as $vl_row ) : ?>
				<?php
				$vl_amount = isset( $vl_row['amount'] ) ? (float) $vl_row['amount'] : 0;
				$vl_date   = isset( $vl_row['date'] ) ? strtotime( $vl_row['date'] ) : 0;
				$vl_order  = isset( $vl_row['order_id'] ) ? (int) $vl_row['order_id'] : 0;
				?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Дата', 'vl-account' ); ?>">
						<?php echo esc_html( $vl_date ? date_i18n( 'd.m.Y', $vl_date ) : '' ); ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Операция', 'vl-account' ); ?>">
						<?php if ( $vl_order && vlacc_is_woo() && VL_Account_Orders::can_view( wc_get_order( $vl_order ), get_current_user_id() ) ) : ?>
							<a class="vl-link" href="<?php echo esc_url( VL_Account_Router::url( 'order-view', array( 'order' => $vl_order ) ) ); ?>">
								<?php echo esc_html( isset( $vl_row['description'] ) ? $vl_row['description'] : '' ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( isset( $vl_row['description'] ) ? $vl_row['description'] : '' ); ?>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Баллы', 'vl-account' ); ?>" class="<?php echo $vl_amount < 0 ? 'vl-negative' : 'vl-positive'; ?>">
						<?php echo esc_html( ( $vl_amount > 0 ? '+' : '' ) . number_format_i18n( $vl_amount ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
