<?php
/**
 * Кабинет: обзор.
 *
 * @package VL_Account
 *
 * @var WP_User $user
 * @var int     $user_id
 */

defined( 'ABSPATH' ) || exit;

$vl_orders   = VL_Account_Orders::get_user_orders( $user_id, 3 );
$vl_all      = VL_Account_Orders::get_user_orders( $user_id, 100 );
$vl_wishlist = VL_Account_Wishlist::count();
$vl_bonus    = VL_Account_Bonus::get_balance( $user_id );
$vl_promo    = VL_Account_Promo::get_user_codes( $user_id );

// Уровень программы лояльности может давать не баллы, а постоянную скидку.
$vl_lp_discount = 0;

// Баллы за заказ становятся доступны не сразу: до этого CRM держит их
// «в ожидании активации». На плитке видно только доступные, поэтому сумму
// ожидания показываем отдельной строкой — иначе кажется, что баллы пропали.
$vl_lp_waiting = 0;

if ( class_exists( 'VL_Account_RetailCRM_Loyalty' ) && VL_Account_RetailCRM::loyalty_active()
	&& 'crm' === VL_Account_RetailCRM_Loyalty::source( $user_id ) ) {
	$vl_lp = VL_Account_RetailCRM::account( $user_id );

	if ( 'active' === $vl_lp['status'] && VL_Account_RetailCRM_Loyalty::is_discount_level( $vl_lp ) ) {
		$vl_lp_discount = (float) $vl_lp['level']['size'];
	}

	if ( 'active' === $vl_lp['status'] && ! empty( $vl_lp['activation_sum'] ) ) {
		$vl_lp_waiting = (float) $vl_lp['activation_sum'];
	}
}
?>
<div class="vl-dashboard">

	<div class="vl-cards">
		<a class="vl-card" href="<?php echo esc_url( VL_Account_Router::url( 'orders' ) ); ?>">
			<span class="vl-card__value"><?php echo esc_html( count( $vl_all ) ); ?></span>
			<span class="vl-card__label"><?php esc_html_e( 'заказов', 'vl-account' ); ?></span>
		</a>

		<a class="vl-card" href="<?php echo esc_url( VL_Account_Router::url( 'wishlist' ) ); ?>">
			<span class="vl-card__value"><?php echo esc_html( $vl_wishlist ); ?></span>
			<span class="vl-card__label"><?php esc_html_e( 'в избранном', 'vl-account' ); ?></span>
		</a>

		<a class="vl-card" href="<?php echo esc_url( VL_Account_Router::url( 'bonus' ) ); ?>">
			<?php if ( $vl_lp_discount > 0 ) : ?>
				<span class="vl-card__value"><?php echo esc_html( number_format_i18n( $vl_lp_discount, 0 ) ); ?>%</span>
				<span class="vl-card__label"><?php esc_html_e( 'скидка', 'vl-account' ); ?></span>
			<?php else : ?>
				<span class="vl-card__value"><?php echo esc_html( number_format_i18n( $vl_bonus ) ); ?></span>
				<span class="vl-card__label"><?php esc_html_e( 'баллов', 'vl-account' ); ?></span>

				<?php if ( $vl_lp_waiting > 0 ) : ?>
					<span class="vl-card__note">
						<?php
						printf(
							/* translators: %s — сколько баллов ждут активации. */
							esc_html__( '+%s ждут активации', 'vl-account' ),
							esc_html( number_format_i18n( $vl_lp_waiting ) )
						);
						?>
					</span>
				<?php endif; ?>
			<?php endif; ?>
		</a>

		<a class="vl-card" href="<?php echo esc_url( VL_Account_Router::url( 'promo' ) ); ?>">
			<span class="vl-card__value"><?php echo esc_html( count( $vl_promo ) ); ?></span>
			<span class="vl-card__label"><?php esc_html_e( 'промокодов', 'vl-account' ); ?></span>
		</a>
	</div>

	<?php if ( $vl_promo ) : ?>
		<?php $vl_first_promo = reset( $vl_promo ); ?>
		<?php if ( ! empty( $vl_first_promo['valid'] ) ) : ?>
			<div class="vl-promo-banner">
				<div>
					<span class="vl-promo-banner__label"><?php esc_html_e( 'Ваш промокод', 'vl-account' ); ?></span>
					<span class="vl-promo-banner__code" data-vl-copy="<?php echo esc_attr( $vl_first_promo['code'] ); ?>"><?php echo esc_html( $vl_first_promo['code'] ); ?></span>
				</div>
				<?php if ( ! empty( $vl_first_promo['description'] ) ) : ?>
					<span class="vl-promo-banner__desc"><?php echo esc_html( $vl_first_promo['description'] ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	// Куда везём — самое частое, что покупатель проверяет перед заказом.
	$vl_address = '';

	if ( class_exists( 'VL_Account_Address' ) && VL_Account_Address::enabled() ) {
		$vl_address = VL_Account_Address::formatted( $user_id, VL_Account_Address::shipping_enabled() ? 'shipping' : 'billing' );
	}
	?>

	<?php if ( $vl_address ) : ?>
		<div class="vl-dash-address">
			<span class="vl-dash-address__label"><?php esc_html_e( 'Адрес доставки', 'vl-account' ); ?></span>
			<span class="vl-dash-address__value"><?php echo esc_html( $vl_address ); ?></span>
			<a class="vl-link" href="<?php echo esc_url( VL_Account_Router::url( 'profile' ) ); ?>"><?php esc_html_e( 'изменить', 'vl-account' ); ?></a>
		</div>
	<?php endif; ?>

	<h3 class="vl-subtitle"><?php esc_html_e( 'Последние заказы', 'vl-account' ); ?></h3>

	<?php if ( $vl_orders ) : ?>
		<?php vlacc_template( 'parts/orders-table.php', array( 'orders' => $vl_orders ) ); ?>
		<p class="vl-links">
			<a class="vl-link" href="<?php echo esc_url( VL_Account_Router::url( 'orders' ) ); ?>"><?php esc_html_e( 'Все заказы', 'vl-account' ); ?></a>
		</p>
	<?php else : ?>
		<div class="vl-empty">
			<p><?php esc_html_e( 'Заказов пока нет.', 'vl-account' ); ?></p>
			<?php if ( vlacc_is_woo() ) : ?>
				<a class="vl-btn vl-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'перейти в каталог', 'vl-account' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
