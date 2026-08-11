<?php
/**
 * Кабинет: промокоды.
 *
 * @package VL_Account
 *
 * @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

$vl_codes = VL_Account_Promo::get_user_codes( $user_id );

if ( ! $vl_codes ) :
	?>
	<div class="vl-empty">
		<p><?php esc_html_e( 'Персональных промокодов пока нет. Мы пришлём код на почту, как только он появится.', 'vl-account' ); ?></p>
	</div>
	<?php
	return;
endif;
?>
<div class="vl-promos">
	<?php foreach ( $vl_codes as $vl_code ) : ?>
		<div class="vl-promo<?php echo empty( $vl_code['valid'] ) ? ' is-expired' : ''; ?>">
			<div class="vl-promo__main">
				<span class="vl-promo__code" data-vl-copy="<?php echo esc_attr( $vl_code['code'] ); ?>">
					<?php echo esc_html( $vl_code['code'] ); ?>
					<?php echo vlacc_icon( 'copy', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>

				<?php if ( ! empty( $vl_code['description'] ) ) : ?>
					<span class="vl-promo__desc"><?php echo esc_html( $vl_code['description'] ); ?></span>
				<?php endif; ?>
			</div>

			<div class="vl-promo__meta">
				<?php if ( ! empty( $vl_code['used'] ) ) : ?>
					<span class="vl-promo__status"><?php esc_html_e( 'уже использован', 'vl-account' ); ?></span>
				<?php elseif ( empty( $vl_code['valid'] ) ) : ?>
					<span class="vl-promo__status"><?php esc_html_e( 'срок действия истёк', 'vl-account' ); ?></span>
				<?php elseif ( ! empty( $vl_code['expires'] ) ) : ?>
					<span class="vl-promo__status">
						<?php
						printf(
							/* translators: %s — дата. */
							esc_html__( 'действует до %s', 'vl-account' ),
							esc_html( $vl_code['expires'] )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<p class="vl-note"><?php esc_html_e( 'Промокод вводится в корзине в поле «Промокод» — скидка применится к заказу.', 'vl-account' ); ?></p>
