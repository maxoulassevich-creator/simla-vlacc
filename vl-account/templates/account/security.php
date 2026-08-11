<?php
/**
 * Кабинет: пароль и вход.
 *
 * @package VL_Account
 *
 * @var WP_User $user
 * @var int     $user_id
 */

defined( 'ABSPATH' ) || exit;

$vl_has_password = VL_Account_User::has_password( $user_id );
$vl_phone        = VL_Account_User::get_phone( $user_id );
$vl_verified     = get_user_meta( $user_id, VL_Account_User::META_VERIFIED, true );
?>
<div class="vl-security">

	<div class="vl-info-list">
		<div class="vl-info-list__row">
			<span class="vl-info-list__label"><?php esc_html_e( 'Вход по коду из SMS', 'vl-account' ); ?></span>
			<span class="vl-info-list__value">
				<?php if ( $vl_phone ) : ?>
					<?php echo esc_html( VL_Account_Phone::format( $vl_phone ) ); ?>
					<?php if ( $vl_verified ) : ?>
						<span class="vl-verified"><?php echo vlacc_icon( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'подтверждён', 'vl-account' ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'номер не привязан', 'vl-account' ); ?>
				<?php endif; ?>
			</span>
		</div>

		<div class="vl-info-list__row">
			<span class="vl-info-list__label"><?php esc_html_e( 'Вход по паролю', 'vl-account' ); ?></span>
			<span class="vl-info-list__value">
				<?php echo $vl_has_password ? esc_html__( 'настроен', 'vl-account' ) : esc_html__( 'не настроен', 'vl-account' ); ?>
			</span>
		</div>
	</div>

	<h3 class="vl-subtitle">
		<?php echo $vl_has_password ? esc_html__( 'Смена пароля', 'vl-account' ) : esc_html__( 'Задать пароль', 'vl-account' ); ?>
	</h3>

	<?php if ( ! $vl_has_password ) : ?>
		<p class="vl-note"><?php esc_html_e( 'Пароль не обязателен — входить можно по коду из SMS. Но с паролем удобно, если под рукой нет телефона.', 'vl-account' ); ?></p>
	<?php endif; ?>

	<form class="vl-form vl-form--password" data-vl-form="password" method="post" novalidate>
		<div class="vl-form__messages" data-vl-messages></div>

		<?php if ( $vl_has_password ) : ?>
			<div class="vl-field">
				<label class="vl-label" for="vl-sec-current"><?php esc_html_e( 'Текущий пароль', 'vl-account' ); ?></label>
				<input type="password" id="vl-sec-current" name="current_password" class="vl-input" autocomplete="current-password" />
				<button type="button" class="vl-eye" data-vl-toggle-password aria-label="<?php esc_attr_e( 'Показать пароль', 'vl-account' ); ?>"></button>
			</div>
		<?php endif; ?>

		<div class="vl-field">
			<label class="vl-label" for="vl-sec-pass"><?php esc_html_e( 'Новый пароль', 'vl-account' ); ?></label>
			<input type="password" id="vl-sec-pass" name="password" class="vl-input" autocomplete="new-password" />
			<button type="button" class="vl-eye" data-vl-toggle-password aria-label="<?php esc_attr_e( 'Показать пароль', 'vl-account' ); ?>"></button>
			<span class="vl-field__hint"><?php esc_html_e( 'Не короче 8 символов.', 'vl-account' ); ?></span>
		</div>

		<div class="vl-field">
			<label class="vl-label" for="vl-sec-pass2"><?php esc_html_e( 'Повторите пароль', 'vl-account' ); ?></label>
			<input type="password" id="vl-sec-pass2" name="password2" class="vl-input" autocomplete="new-password" />
		</div>

		<button type="submit" class="vl-btn vl-btn--primary" data-vl-action="save-password">
			<?php esc_html_e( 'сохранить пароль', 'vl-account' ); ?>
		</button>

		<input type="text" name="vlacc_hp" class="vl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
	</form>

	<p class="vl-links">
		<a class="vl-link vl-link--muted" href="<?php echo esc_url( VL_Account_Auth::logout_url( home_url( '/' ) ) ); ?>" data-vl-confirm-logout="1">
			<?php esc_html_e( 'Выйти из кабинета', 'vl-account' ); ?>
		</a>
	</p>
</div>
