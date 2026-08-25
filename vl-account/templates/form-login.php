<?php
/**
 * Вход и регистрация в один шаг: телефон -> код -> кабинет.
 *
 * Если номера ещё нет в базе, аккаунт создаётся автоматически после
 * верного кода. Имя, e-mail и адрес подтянутся из первого заказа.
 *
 * @package VL_Account
 *
 * @var string $redirect Куда вести после входа.
 */

defined( 'ABSPATH' ) || exit;

$vl_both  = 'both' === VL_Account_Settings::get( 'auth_mode', 'sms' );
$vl_redir = isset( $redirect ) ? $redirect : '';

$vl_intro = (string) VL_Account_Settings::get( 'auth_intro', '' );

if ( '' === trim( $vl_intro ) ) {
	$vl_intro = __( 'Авторизуйтесь или зарегистрируйтесь по номеру телефона — мы пришлём SMS с кодом подтверждения.', 'vl-account' );
}

// Согласие в один шаг: явная галочка не нужна, но факт согласия фиксируем.
$vl_consent_note = (string) VL_Account_Settings::get( 'auth_consent_note', '' );

if ( '' === trim( $vl_consent_note ) ) {
	$vl_privacy_page = (int) VL_Account_Settings::get( 'privacy_page', 0 );
	$vl_privacy_url  = $vl_privacy_page ? get_permalink( $vl_privacy_page ) : ( function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '' );

	$vl_consent_note = $vl_privacy_url
		? sprintf(
			/* translators: %s — ссылка на политику обработки персональных данных. */
			__( 'Нажимая «получить код», вы соглашаетесь с <a href="%s" target="_blank" rel="nofollow">обработкой персональных данных</a>.', 'vl-account' ),
			esc_url( $vl_privacy_url )
		)
		: __( 'Нажимая «получить код», вы соглашаетесь с обработкой персональных данных.', 'vl-account' );
}

$vl_marketing = VL_Account_Settings::get( 'consent_marketing', 1 ) && VL_Account_Settings::get( 'auth_marketing_box', 1 );
$vl_ask_name  = (bool) VL_Account_Settings::get( 'ask_name', 1 );
?>
<form class="vl-form vl-form--login" data-vl-form="login" method="post" novalidate>

	<div class="vl-form__messages" data-vl-messages></div>

	<div class="vl-step is-active" data-vl-step="phone">

		<?php if ( $vl_intro ) : ?>
			<p class="vl-form__intro"><?php echo wp_kses_post( $vl_intro ); ?></p>
		<?php endif; ?>

		<div class="vl-field">
			<label class="vl-label" for="vl-login-phone">
				<?php echo vlacc_icon( 'phone', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Телефон', 'vl-account' ); ?> <span class="vl-req">*</span>
			</label>
			<input type="tel" id="vl-login-phone" name="phone" class="vl-input" autocomplete="tel"
				placeholder="<?php echo esc_attr( VL_Account_Settings::get( 'phone_mask', '+7 (___) ___-__-__' ) ); ?>"
				data-vl-phone required />
			<span class="vl-field__error" data-vl-error="phone"></span>
		</div>

		<?php if ( $vl_ask_name ) : ?>
			<div class="vl-field">
				<label class="vl-label" for="vl-login-name">
					<?php echo vlacc_icon( 'user', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Имя', 'vl-account' ); ?> <span class="vl-req">*</span>
				</label>
				<input type="text" id="vl-login-name" name="name" class="vl-input" autocomplete="given-name"
					placeholder="<?php esc_attr_e( 'Как к вам обращаться', 'vl-account' ); ?>" required />
				<span class="vl-field__error" data-vl-error="name"></span>
			</div>
		<?php endif; ?>

		<?php if ( $vl_marketing ) : ?>
			<div class="vl-consents vl-consents--compact">
				<label class="vl-check">
					<input type="checkbox" name="consent_marketing" value="1" />
					<span class="vl-check__box"></span>
					<span class="vl-check__text"><?php echo wp_kses_post( VL_Account_Settings::get( 'consent_marketing_text', '' ) ); ?></span>
				</label>
			</div>
		<?php endif; ?>

		<button type="submit" class="vl-btn vl-btn--primary vl-btn--block" data-vl-action="send-code" data-purpose="login">
			<?php esc_html_e( 'получить код', 'vl-account' ); ?>
		</button>

		<?php if ( $vl_consent_note ) : ?>
			<p class="vl-form__consent"><?php echo wp_kses_post( $vl_consent_note ); ?></p>
		<?php endif; ?>

		<?php if ( $vl_both ) : ?>
			<p class="vl-links">
				<button type="button" class="vl-link" data-vl-action="show-password-login"><?php esc_html_e( 'Войти по паролю', 'vl-account' ); ?></button>
			</p>
		<?php endif; ?>
	</div>

	<div class="vl-step" data-vl-step="code">
		<p class="vl-step__hint" data-vl-code-hint></p>

		<div class="vl-field">
			<label class="vl-label" for="vl-login-code"><?php esc_html_e( 'Код из SMS', 'vl-account' ); ?></label>
			<input type="text" id="vl-login-code" name="code" class="vl-input vl-input--code" inputmode="numeric"
				autocomplete="one-time-code" maxlength="8" data-vl-code />
			<span class="vl-field__error" data-vl-error="code"></span>
		</div>

		<button type="submit" class="vl-btn vl-btn--primary vl-btn--block" data-vl-action="verify-code" data-purpose="login">
			<?php esc_html_e( 'подтвердить', 'vl-account' ); ?>
		</button>

		<p class="vl-links">
			<button type="button" class="vl-link" data-vl-action="resend" data-purpose="login"><?php esc_html_e( 'Отправить код повторно', 'vl-account' ); ?></button>
			<button type="button" class="vl-link vl-link--muted" data-vl-action="change-phone"><?php esc_html_e( 'Изменить номер', 'vl-account' ); ?></button>
		</p>
	</div>

	<?php if ( $vl_both ) : ?>
		<div class="vl-step" data-vl-step="password">
			<div class="vl-field">
				<label class="vl-label" for="vl-login-user"><?php esc_html_e( 'E-mail или телефон', 'vl-account' ); ?></label>
				<input type="text" id="vl-login-user" name="login" class="vl-input" autocomplete="username" />
			</div>

			<div class="vl-field">
				<label class="vl-label" for="vl-login-pass"><?php esc_html_e( 'Пароль', 'vl-account' ); ?></label>
				<input type="password" id="vl-login-pass" name="password" class="vl-input" autocomplete="current-password" />
				<button type="button" class="vl-eye" data-vl-toggle-password aria-label="<?php esc_attr_e( 'Показать пароль', 'vl-account' ); ?>"></button>
			</div>

			<button type="button" class="vl-btn vl-btn--dark vl-btn--block" data-vl-action="login-password">
				<?php esc_html_e( 'войти', 'vl-account' ); ?>
			</button>

			<p class="vl-links">
				<button type="button" class="vl-link" data-vl-action="show-sms-login"><?php esc_html_e( 'Войти по коду из SMS', 'vl-account' ); ?></button>
				<button type="button" class="vl-link vl-link--muted" data-vl-action="show-lost"><?php esc_html_e( 'Забыли пароль?', 'vl-account' ); ?></button>
			</p>
		</div>
	<?php endif; ?>

	<input type="hidden" name="redirect_to" value="<?php echo esc_url( $vl_redir ); ?>" />
	<input type="text" name="vlacc_hp" class="vl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
</form>
