<?php
/**
 * Галочки согласий.
 *
 * @package VL_Account
 *
 * @var int $user_id Пользователь (для кабинета), необязательно.
 */

defined( 'ABSPATH' ) || exit;

$vl_user_id = isset( $user_id ) ? (int) $user_id : 0;

$vl_privacy   = (bool) VL_Account_Settings::get( 'consent_privacy', 1 );
$vl_marketing = (bool) VL_Account_Settings::get( 'consent_marketing', 1 );

if ( ! $vl_privacy && ! $vl_marketing ) {
	return;
}

$vl_privacy_page = (int) VL_Account_Settings::get( 'privacy_page', 0 );
$vl_privacy_url  = $vl_privacy_page ? get_permalink( $vl_privacy_page ) : ( function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '' );

$vl_privacy_text = (string) VL_Account_Settings::get( 'consent_privacy_text', '' );

if ( $vl_privacy_url && false !== strpos( $vl_privacy_text, '%s' ) ) {
	$vl_privacy_text = sprintf( $vl_privacy_text, esc_url( $vl_privacy_url ) );
} else {
	// Ссылки нет — убираем разметку ссылки и незаполненный плейсхолдер.
	$vl_privacy_text = wp_strip_all_tags( str_replace( '%s', '', $vl_privacy_text ) );
}
?>
<div class="vl-consents">
	<?php if ( $vl_privacy ) : ?>
		<label class="vl-check">
			<?php
			// Согласие — активное действие: в форме регистрации галочка по умолчанию снята.
			// Значение по умолчанию можно изменить фильтром vlacc_consent_default.
			$vl_privacy_checked = $vl_user_id
				? VL_Account_User::has_consent( $vl_user_id, 'privacy' )
				: (bool) apply_filters( 'vlacc_consent_default', false, 'privacy' );
			?>
			<input type="checkbox" name="consent_privacy" value="1" <?php checked( $vl_privacy_checked ); ?> />
			<span class="vl-check__box"></span>
			<span class="vl-check__text"><?php echo wp_kses_post( $vl_privacy_text ); ?> <span class="vl-req">*</span></span>
		</label>
		<span class="vl-field__error" data-vl-error="consent_privacy"></span>
	<?php endif; ?>

	<?php if ( $vl_marketing ) : ?>
		<label class="vl-check">
			<?php
			$vl_marketing_checked = $vl_user_id
				? VL_Account_User::has_consent( $vl_user_id, 'marketing' )
				: (bool) apply_filters( 'vlacc_consent_default', false, 'marketing' );
			?>
			<input type="checkbox" name="consent_marketing" value="1" <?php checked( $vl_marketing_checked ); ?> />
			<span class="vl-check__box"></span>
			<span class="vl-check__text"><?php echo wp_kses_post( VL_Account_Settings::get( 'consent_marketing_text', '' ) ); ?></span>
		</label>
	<?php endif; ?>
</div>
