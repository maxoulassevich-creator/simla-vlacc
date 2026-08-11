<?php
/**
 * Восстановление доступа: письмо на e-mail или код в SMS.
 *
 * @package VL_Account
 *
 * @var string $title Заголовок.
 */

defined( 'ABSPATH' ) || exit;

$vl_title = isset( $title ) ? $title : '';
?>
<form class="vl-form vl-form--lost" data-vl-form="lost" method="post" novalidate>

	<?php if ( $vl_title ) : ?>
		<h2 class="vl-form__title"><?php echo esc_html( $vl_title ); ?></h2>
	<?php endif; ?>

	<div class="vl-form__messages" data-vl-messages></div>

	<div class="vl-step is-active" data-vl-step="request">
		<p class="vl-note">
			<?php esc_html_e( 'Укажите телефон — пришлём код и сразу пустим в кабинет. Или укажите e-mail — отправим ссылку для смены пароля.', 'vl-account' ); ?>
		</p>

		<div class="vl-field">
			<label class="vl-label" for="vl-lost-login"><?php esc_html_e( 'Телефон или e-mail', 'vl-account' ); ?> <span class="vl-req">*</span></label>
			<input type="text" id="vl-lost-login" name="login" class="vl-input" autocomplete="username" required />
			<span class="vl-field__error" data-vl-error="login"></span>
		</div>

		<button type="submit" class="vl-btn vl-btn--primary vl-btn--block" data-vl-action="lost-password">
			<?php esc_html_e( 'восстановить доступ', 'vl-account' ); ?>
		</button>

		<p class="vl-links">
			<button type="button" class="vl-link vl-link--muted" data-vl-action="show-login"><?php esc_html_e( 'Вернуться ко входу', 'vl-account' ); ?></button>
		</p>
	</div>

	<div class="vl-step" data-vl-step="code">
		<p class="vl-step__hint" data-vl-code-hint></p>

		<div class="vl-field">
			<label class="vl-label" for="vl-lost-code"><?php esc_html_e( 'Код из SMS', 'vl-account' ); ?></label>
			<input type="text" id="vl-lost-code" name="code" class="vl-input vl-input--code" inputmode="numeric"
				autocomplete="one-time-code" maxlength="8" data-vl-code />
			<span class="vl-field__error" data-vl-error="code"></span>
		</div>

		<div class="vl-field">
			<label class="vl-label" for="vl-lost-pass"><?php esc_html_e( 'Новый пароль', 'vl-account' ); ?></label>
			<input type="password" id="vl-lost-pass" name="password" class="vl-input" autocomplete="new-password" />
			<button type="button" class="vl-eye" data-vl-toggle-password aria-label="<?php esc_attr_e( 'Показать пароль', 'vl-account' ); ?>"></button>
			<span class="vl-field__hint"><?php esc_html_e( 'Не короче 8 символов.', 'vl-account' ); ?></span>
		</div>

		<div class="vl-field">
			<label class="vl-label" for="vl-lost-pass2"><?php esc_html_e( 'Повторите пароль', 'vl-account' ); ?></label>
			<input type="password" id="vl-lost-pass2" name="password2" class="vl-input" autocomplete="new-password" />
		</div>

		<button type="button" class="vl-btn vl-btn--primary vl-btn--block" data-vl-action="reset-password">
			<?php esc_html_e( 'сохранить пароль и войти', 'vl-account' ); ?>
		</button>

		<p class="vl-links">
			<button type="button" class="vl-link" data-vl-action="resend" data-purpose="reset"><?php esc_html_e( 'Отправить код повторно', 'vl-account' ); ?></button>
		</p>
	</div>

	<input type="text" name="vlacc_hp" class="vl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
</form>
