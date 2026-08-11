<?php
/**
 * Кабинет: бонусы программы лояльности.
 *
 * @package VL_Account
 *
 * @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

$vl_balance = VL_Account_Bonus::get_balance( $user_id );
$vl_history = VL_Account_Bonus::get_history( $user_id );
$vl_page    = (int) VL_Account_Settings::get( 'loyalty_page', 0 );
?>
<div class="vl-bonus">
	<div class="vl-bonus__balance">
		<span class="vl-bonus__value"><?php echo esc_html( number_format_i18n( $vl_balance ) ); ?></span>
		<span class="vl-bonus__label"><?php esc_html_e( 'баллов на счету', 'vl-account' ); ?></span>
	</div>

	<?php if ( $vl_page && get_post_status( $vl_page ) === 'publish' ) : ?>
		<p class="vl-links">
			<a class="vl-link" href="<?php echo esc_url( get_permalink( $vl_page ) ); ?>"><?php esc_html_e( 'Условия программы лояльности', 'vl-account' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( $vl_history ) : ?>
		<h3 class="vl-subtitle"><?php esc_html_e( 'История', 'vl-account' ); ?></h3>

		<div class="vl-table-wrap">
			<table class="vl-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Дата', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Операция', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Баллы', 'vl-account' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $vl_history as $vl_row ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Дата', 'vl-account' ); ?>">
								<?php echo esc_html( isset( $vl_row['date'] ) ? date_i18n( 'd.m.Y', strtotime( $vl_row['date'] ) ) : '' ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Операция', 'vl-account' ); ?>">
								<?php echo esc_html( isset( $vl_row['description'] ) ? $vl_row['description'] : '' ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Баллы', 'vl-account' ); ?>" class="<?php echo ( isset( $vl_row['amount'] ) && $vl_row['amount'] < 0 ) ? 'vl-negative' : 'vl-positive'; ?>">
								<?php echo esc_html( ( isset( $vl_row['amount'] ) && $vl_row['amount'] > 0 ? '+' : '' ) . number_format_i18n( isset( $vl_row['amount'] ) ? $vl_row['amount'] : 0 ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php else : ?>
		<p class="vl-note"><?php esc_html_e( 'История операций появится после первых начислений.', 'vl-account' ); ?></p>
	<?php endif; ?>
</div>
