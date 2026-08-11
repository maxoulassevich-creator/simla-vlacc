<?php
/**
 * Кабинет: мои данные.
 *
 * @package VL_Account
 *
 * @var WP_User $user
 * @var int     $user_id
 */

defined( 'ABSPATH' ) || exit;

$vl_phone    = VL_Account_User::get_phone( $user_id );
$vl_telegram = get_user_meta( $user_id, VL_Account_User::META_TELEGRAM, true );
$vl_email    = VL_Account_User::has_real_email( $user ) ? $user->user_email : '';
$vl_pending  = VL_Account_Email_Confirm::pending( $user_id );

// Пока адрес не подтверждён, в поле показываем именно его — так понятнее.
if ( $vl_pending && ! $vl_email ) {
	$vl_email = $vl_pending;
}
?>

<?php if ( $vl_pending ) : ?>
	<div class="vl-message vl-message--info vl-pending-email">
		<span>
			<?php
			printf(
				/* translators: %s — адрес электронной почты. */
				esc_html__( 'Адрес %s ждёт подтверждения. Мы отправили на него письмо со ссылкой — после перехода по ней почта привяжется к кабинету.', 'vl-account' ),
				'<strong>' . esc_html( $vl_pending ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			?>
		</span>
		<form class="vl-form vl-form--resend" data-vl-form="resend" method="post">
			<div class="vl-form__messages" data-vl-messages></div>
			<button type="submit" class="vl-link" data-vl-action="resend-email"><?php esc_html_e( 'Отправить письмо ещё раз', 'vl-account' ); ?></button>
			<input type="text" name="vlacc_hp" class="vl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
		</form>
	</div>
<?php elseif ( ! VL_Account_User::has_real_email( $user ) ) : ?>
	<div class="vl-message vl-message--info">
		<?php esc_html_e( 'Добавьте e-mail — на него будут приходить статусы заказов, и по нему можно восстановить доступ. Мы отправим на указанный адрес письмо для подтверждения.', 'vl-account' ); ?>
	</div>
<?php endif; ?>
<form class="vl-form vl-form--profile" data-vl-form="profile" method="post" novalidate>

	<div class="vl-form__messages" data-vl-messages></div>

	<div class="vl-field">
		<label class="vl-label" for="vl-profile-first"><?php esc_html_e( 'Имя', 'vl-account' ); ?></label>
		<input type="text" id="vl-profile-first" name="first_name" class="vl-input" value="<?php echo esc_attr( $user->first_name ); ?>" autocomplete="given-name" />
	</div>

	<div class="vl-field">
		<label class="vl-label" for="vl-profile-last"><?php esc_html_e( 'Фамилия', 'vl-account' ); ?></label>
		<input type="text" id="vl-profile-last" name="last_name" class="vl-input" value="<?php echo esc_attr( $user->last_name ); ?>" autocomplete="family-name" />
	</div>

	<?php if ( VL_Account_Settings::get( 'show_telegram', 1 ) ) : ?>
		<div class="vl-field">
			<label class="vl-label" for="vl-profile-telegram">
				<?php echo vlacc_icon( 'telegram', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Telegram', 'vl-account' ); ?>
			</label>
			<input type="text" id="vl-profile-telegram" name="telegram" class="vl-input" value="<?php echo esc_attr( $vl_telegram ); ?>" placeholder="@username" />
		</div>
	<?php endif; ?>

	<div class="vl-field">
		<label class="vl-label" for="vl-profile-email"><?php esc_html_e( 'Email адрес', 'vl-account' ); ?></label>
		<input type="email" id="vl-profile-email" name="email" class="vl-input" value="<?php echo esc_attr( $vl_email ); ?>" autocomplete="email" />
		<span class="vl-field__hint">
			<?php esc_html_e( 'На него приходят письма о заказах. Новый адрес привяжется только после перехода по ссылке из письма.', 'vl-account' ); ?>
		</span>
	</div>

	<div class="vl-field">
		<label class="vl-label"><?php esc_html_e( 'Телефон', 'vl-account' ); ?></label>
		<input type="text" class="vl-input" value="<?php echo esc_attr( VL_Account_Phone::format( $vl_phone ) ); ?>" readonly />
		<span class="vl-field__hint"><?php esc_html_e( 'По этому номеру вы входите в кабинет. Чтобы изменить его, напишите нам — поможем.', 'vl-account' ); ?></span>
	</div>

	<button type="submit" class="vl-btn vl-btn--primary" data-vl-action="save-profile">
		<?php esc_html_e( 'сохранить', 'vl-account' ); ?>
	</button>

	<input type="text" name="vlacc_hp" class="vl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
</form>

<?php
if ( vlacc_is_woo() ) :
	$vl_addresses = wc_get_account_menu_items();

	if ( isset( $vl_addresses['edit-address'] ) ) :
		?>
		<p class="vl-links">
			<a class="vl-link" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', '', wc_get_page_permalink( 'myaccount' ) ) ); ?>">
				<?php esc_html_e( 'Адреса доставки и оплаты', 'vl-account' ); ?>
			</a>
		</p>
		<?php
	endif;
endif;
