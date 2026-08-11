<?php
/**
 * Блок входа и регистрации.
 *
 * Вход и регистрация — одно и то же действие: посетитель вводит телефон,
 * получает код, попадает в кабинет. Незнакомый номер регистрируется
 * автоматически, отдельной формы регистрации нет.
 *
 * Переопределяется темой: wp-content/themes/ваша-тема/vl-account/auth.php
 *
 * @package VL_Account
 *
 * @var string $redirect Куда вести после входа.
 * @var string $notice   Сообщение над формой.
 * @var string $title    Заголовок.
 */

defined( 'ABSPATH' ) || exit;

$vl_notice = isset( $notice ) ? $notice : '';
$vl_title  = isset( $title ) ? $title : '';
$vl_redir  = isset( $redirect ) ? $redirect : '';

// Сообщения из ссылок в письмах.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! $vl_notice && ! empty( $_GET['vlacc_notice'] ) ) {
	$vl_notices = array(
		'link_expired'    => __( 'Ссылка для входа больше не действует. Войдите по коду из SMS.', 'vl-account' ),
		'email_failed'    => __( 'Не получилось подтвердить e-mail: ссылка устарела или уже использована.', 'vl-account' ),
		'email_confirmed' => __( 'E-mail подтверждён.', 'vl-account' ),
	);

	$vl_key = sanitize_key( wp_unslash( $_GET['vlacc_notice'] ) );

	if ( isset( $vl_notices[ $vl_key ] ) ) {
		$vl_notice = $vl_notices[ $vl_key ];
	}
}
// phpcs:enable

vlacc_print_form_critical_css();
?>
<div class="vl-auth vl-auth--single" data-vl-auth data-redirect="<?php echo esc_url( $vl_redir ); ?>">

	<?php if ( $vl_title ) : ?>
		<h2 class="vl-auth__title"><?php echo esc_html( $vl_title ); ?></h2>
	<?php endif; ?>

	<?php if ( $vl_notice ) : ?>
		<div class="vl-message vl-message--info"><?php echo esc_html( $vl_notice ); ?></div>
	<?php endif; ?>

	<div class="vl-auth__panes">
		<div class="vl-auth__pane is-active" data-vl-pane="login">
			<?php vlacc_template( 'form-login.php', array( 'redirect' => $vl_redir ) ); ?>
		</div>

		<div class="vl-auth__pane" data-vl-pane="lost">
			<?php vlacc_template( 'form-lost-password.php', array( 'title' => '' ) ); ?>
		</div>
	</div>
</div>
