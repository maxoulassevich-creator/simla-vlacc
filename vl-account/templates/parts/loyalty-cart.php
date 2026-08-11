<?php
/**
 * Списание баллов программы лояльности в корзине.
 *
 * @package VL_Account
 *
 * @var array $lp Данные: balance, max, used, credit, discount, currency, level.
 */

defined( 'ABSPATH' ) || exit;

$lp = isset( $lp ) && is_array( $lp ) ? $lp : array();

if ( ! $lp ) {
	return;
}

$vl_max  = (int) floor( $lp['max'] );
$vl_used = (int) round( $lp['used'] );
?>
<div class="vl-loyalty-cart" data-vl-form="loyalty-cart">
	<div class="vl-form__messages" data-vl-messages></div>

	<?php if ( $lp['discount'] > 0 ) : ?>

		<p class="vl-loyalty-cart__note">
			<?php
			printf(
				/* translators: 1: сумма скидки, 2: название уровня. */
				esc_html__( 'Скидка по программе лояльности %1$s уже учтена (уровень «%2$s»).', 'vl-account' ),
				esc_html( wp_strip_all_tags( wc_price( $lp['discount'] ) ) ),
				esc_html( $lp['level']['name'] )
			);
			?>
		</p>

	<?php elseif ( $vl_used > 0 ) : ?>

		<p class="vl-loyalty-cart__note">
			<?php
			printf(
				/* translators: %s — количество баллов. */
				esc_html__( 'Списываем %s баллов.', 'vl-account' ),
				'<strong>' . esc_html( number_format_i18n( $vl_used ) ) . '</strong>'
			);
			?>
			<button type="button" class="vl-link vl-link--muted" data-vl-action="loyalty-charge-cancel">
				<?php esc_html_e( 'отменить', 'vl-account' ); ?>
			</button>
		</p>

	<?php elseif ( $vl_max > 0 ) : ?>

		<div class="vl-loyalty-cart__row">
			<label class="vl-label" for="vl-loyalty-charge">
				<?php
				printf(
					/* translators: 1: баланс баллов, 2: максимум к списанию. */
					esc_html__( 'У вас %1$s баллов, в этом заказе можно списать до %2$s', 'vl-account' ),
					esc_html( number_format_i18n( $lp['balance'] ) ),
					esc_html( number_format_i18n( $vl_max ) )
				);
				?>
			</label>

			<div class="vl-loyalty-cart__controls">
				<input type="number" id="vl-loyalty-charge" name="amount" class="vl-input" min="1" step="1"
					max="<?php echo esc_attr( $vl_max ); ?>" value="<?php echo esc_attr( $vl_max ); ?>" />

				<button type="button" class="vl-btn vl-btn--dark" data-vl-action="loyalty-charge">
					<?php esc_html_e( 'списать баллы', 'vl-account' ); ?>
				</button>
			</div>
		</div>

	<?php endif; ?>

	<?php if ( $lp['credit'] > 0 ) : ?>
		<p class="vl-loyalty-cart__credit">
			<?php
			printf(
				/* translators: %s — количество баллов. */
				esc_html__( 'После получения заказа начислим %s баллов.', 'vl-account' ),
				'<strong>' . esc_html( number_format_i18n( $lp['credit'] ) ) . '</strong>'
			);
			?>
		</p>
	<?php endif; ?>

	<?php
	$vl_terms_page = (int) VL_Account_Settings::get( 'loyalty_page', 0 );

	if ( $vl_terms_page && get_post_status( $vl_terms_page ) === 'publish' ) :
		?>
		<p class="vl-loyalty-cart__terms">
			<a class="vl-link" href="<?php echo esc_url( get_permalink( $vl_terms_page ) ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Условия программы лояльности', 'vl-account' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
