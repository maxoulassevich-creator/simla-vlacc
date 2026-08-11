<?php
/**
 * Кабинет: подписки (на размеры и рассылки).
 *
 * Список подписок на размер берётся фильтром vlacc_user_subscriptions —
 * так его можно связать с формой «сообщить о поступлении» или с RetailCRM,
 * не меняя вёрстку кабинета.
 *
 * @package VL_Account
 *
 * @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ожидаемый формат элемента:
 * array( 'product_id' => 123, 'title' => 'Платье', 'size' => 'M', 'date' => '2026-07-01' )
 */
$vl_subs = apply_filters( 'vlacc_user_subscriptions', array(), $user_id );
?>
<div class="vl-subs">

	<h3 class="vl-subtitle"><?php esc_html_e( 'Подписка на размеры', 'vl-account' ); ?></h3>

	<?php if ( $vl_subs ) : ?>
		<div class="vl-table-wrap">
			<table class="vl-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Товар', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Размер', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Дата', 'vl-account' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $vl_subs as $vl_sub ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Товар', 'vl-account' ); ?>">
								<?php
								$vl_title = isset( $vl_sub['title'] ) ? $vl_sub['title'] : '';
								$vl_pid   = isset( $vl_sub['product_id'] ) ? (int) $vl_sub['product_id'] : 0;

								if ( $vl_pid && get_permalink( $vl_pid ) ) {
									printf( '<a href="%s">%s</a>', esc_url( get_permalink( $vl_pid ) ), esc_html( $vl_title ? $vl_title : get_the_title( $vl_pid ) ) );
								} else {
									echo esc_html( $vl_title );
								}
								?>
							</td>
							<td data-label="<?php esc_attr_e( 'Размер', 'vl-account' ); ?>"><?php echo esc_html( isset( $vl_sub['size'] ) ? $vl_sub['size'] : '' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Дата', 'vl-account' ); ?>"><?php echo esc_html( isset( $vl_sub['date'] ) ? date_i18n( 'd.m.Y', strtotime( $vl_sub['date'] ) ) : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php else : ?>
		<p class="vl-note"><?php esc_html_e( 'Вы пока не подписаны на появление размеров. Подписаться можно в карточке товара — там, где размер отмечен как отсутствующий.', 'vl-account' ); ?></p>
	<?php endif; ?>

	<h3 class="vl-subtitle"><?php esc_html_e( 'Письма и рассылки', 'vl-account' ); ?></h3>

	<form class="vl-form vl-form--consents" data-vl-form="consents" method="post">
		<div class="vl-form__messages" data-vl-messages></div>

		<?php vlacc_template( 'parts/consents.php', array( 'user_id' => $user_id ) ); ?>

		<button type="submit" class="vl-btn vl-btn--primary" data-vl-action="save-consents">
			<?php esc_html_e( 'сохранить', 'vl-account' ); ?>
		</button>

		<input type="text" name="vlacc_hp" class="vl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
	</form>
</div>
