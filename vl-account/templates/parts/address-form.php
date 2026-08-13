<?php
/**
 * Кабинет: форма адреса (оплата или доставка).
 *
 * Поля берутся у WooCommerce, поэтому выглядят и проверяются ровно так же,
 * как на оформлении заказа.
 *
 * @package VL_Account
 *
 * @var string $section billing | shipping.
 * @var string $title   Заголовок блока.
 * @var string $hint    Пояснение под заголовком.
 * @var int    $user_id Пользователь.
 */

defined( 'ABSPATH' ) || exit;

$vl_section = 'shipping' === $section ? 'shipping' : 'billing';
$vl_fields  = VL_Account_Address::fields( $vl_section, $user_id );

if ( ! $vl_fields ) {
	return;
}

VL_Account_Address::enqueue_assets();

$vl_summary = VL_Account_Address::formatted( $user_id, $vl_section );
?>

<div class="vl-address woocommerce-address-fields" data-vl-address="<?php echo esc_attr( $vl_section ); ?>">

	<h3 class="vl-address__title"><?php echo esc_html( $title ); ?></h3>

	<?php if ( ! empty( $hint ) ) : ?>
		<p class="vl-address__hint"><?php echo esc_html( $hint ); ?></p>
	<?php endif; ?>

	<p class="vl-address__summary" data-vl-address-summary>
		<?php
		echo $vl_summary
			? esc_html( $vl_summary )
			: esc_html__( 'Адрес пока не заполнен.', 'vl-account' );
		?>
	</p>

	<form class="vl-form vl-form--address" data-vl-form="address" data-vl-section="<?php echo esc_attr( $vl_section ); ?>" method="post" novalidate>

		<div class="vl-form__messages" data-vl-messages></div>

		<div class="vl-address__grid woocommerce-address-fields__field-wrapper">
			<?php
			foreach ( $vl_fields as $vl_key => $vl_field ) {
				$vl_value = isset( $vl_field['value'] ) ? $vl_field['value'] : '';
				unset( $vl_field['value'] );

				woocommerce_form_field( $vl_key, $vl_field, $vl_value );
			}
			?>
		</div>

		<button type="submit" class="vl-btn vl-btn--primary" data-vl-action="save-address">
			<?php esc_html_e( 'сохранить', 'vl-account' ); ?>
		</button>

		<input type="text" name="vlacc_hp" class="vl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
	</form>
</div>
