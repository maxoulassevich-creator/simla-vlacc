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
		<div class="vl-form__messages" data-vl-messages></div>

		<div class="vl-table-wrap" data-vl-form="subscriptions">
			<table class="vl-table vl-table--subs">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Товар', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Размер', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Дата', 'vl-account' ); ?></th>
						<th><?php esc_html_e( 'Статус', 'vl-account' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $vl_subs as $vl_sub ) : ?>
						<?php
						$vl_title  = isset( $vl_sub['title'] ) ? $vl_sub['title'] : '';
						$vl_pid    = isset( $vl_sub['product_id'] ) ? (int) $vl_sub['product_id'] : 0;
						$vl_sub_id = isset( $vl_sub['id'] ) ? (int) $vl_sub['id'] : 0;
						$vl_stock  = ! empty( $vl_sub['in_stock'] );
						?>
						<tr<?php echo $vl_sub_id ? ' data-vl-subscription="' . esc_attr( $vl_sub_id ) . '"' : ''; ?>>
							<td class="vl-table__product" data-label="<?php esc_attr_e( 'Товар', 'vl-account' ); ?>">
								<?php
								$vl_link = $vl_pid ? (string) get_permalink( $vl_pid ) : '';
								$vl_name = $vl_pid && ! $vl_title ? get_the_title( $vl_pid ) : $vl_title;
								?>
								<div class="vl-item">
									<?php
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- разметка собрана и очищена в vlacc_item_thumb().
									echo vlacc_item_thumb( $vl_pid, $vl_link );
									?>

									<div class="vl-item__body">
										<?php
										if ( '' !== $vl_link ) {
											printf( '<a class="vl-item__title" href="%s">%s</a>', esc_url( $vl_link ), esc_html( $vl_name ) );
										} else {
											printf( '<span class="vl-item__title">%s</span>', esc_html( $vl_name ) );
										}
										?>
									</div>
								</div>
							</td>
							<td data-label="<?php esc_attr_e( 'Размер', 'vl-account' ); ?>"><?php echo esc_html( isset( $vl_sub['size'] ) ? $vl_sub['size'] : '' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Дата', 'vl-account' ); ?>"><?php echo esc_html( isset( $vl_sub['date'] ) ? date_i18n( 'd.m.Y', strtotime( $vl_sub['date'] ) ) : '' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Статус', 'vl-account' ); ?>">
								<?php if ( $vl_stock ) : ?>
									<span class="vl-positive"><?php esc_html_e( 'снова в наличии', 'vl-account' ); ?></span>
								<?php elseif ( ! empty( $vl_sub['status_label'] ) ) : ?>
									<span class="vl-subs__status"><?php echo esc_html( $vl_sub['status_label'] ); ?></span>
								<?php endif; ?>

								<?php if ( $vl_sub_id ) : ?>
									<button type="button" class="vl-link vl-link--muted" data-vl-action="subscription-remove" data-id="<?php echo esc_attr( $vl_sub_id ); ?>">
										<?php esc_html_e( 'отписаться', 'vl-account' ); ?>
									</button>
								<?php endif; ?>
							</td>
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
