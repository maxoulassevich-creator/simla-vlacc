<?php
/**
 * Личный кабинет: шапка, меню, содержимое раздела.
 *
 * @package VL_Account
 *
 * @var string  $tab  Текущий раздел.
 * @var WP_User $user Пользователь.
 */

defined( 'ABSPATH' ) || exit;

$vl_tabs  = VL_Account_Router::tabs();
$vl_title = isset( $vl_tabs[ $tab ]['title'] ) ? $vl_tabs[ $tab ]['title'] : '';
$vl_name  = $user->first_name ? $user->first_name : $user->display_name;
$vl_phone = VL_Account_User::get_phone( $user->ID );
?>
<div class="vl-account" data-vl-account>

	<div class="vl-account__head">
		<div class="vl-account__greeting">
			<span class="vl-account__hello"><?php esc_html_e( 'Здравствуйте,', 'vl-account' ); ?></span>
			<span class="vl-account__name"><?php echo esc_html( $vl_name ); ?></span>
		</div>
		<div class="vl-account__contacts">
			<?php if ( $vl_phone ) : ?>
				<span><?php echo esc_html( VL_Account_Phone::format( $vl_phone ) ); ?></span>
			<?php endif; ?>
			<?php if ( VL_Account_User::has_real_email( $user ) ) : ?>
				<span><?php echo esc_html( $user->user_email ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<button type="button" class="vl-account__toggle" data-vl-menu-toggle aria-expanded="false">
		<span><?php echo esc_html( $vl_title ? $vl_title : __( 'Разделы кабинета', 'vl-account' ) ); ?></span>
		<span class="vl-account__toggle-icon" aria-hidden="true"></span>
	</button>

	<div class="vl-account__layout">
		<aside class="vl-account__side" data-vl-menu>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- разметка формируется шаблоном меню.
			echo VL_Account_MyAccount::render_menu( array( 'current' => $tab ) );
			?>
		</aside>

		<main class="vl-account__content">
			<?php if ( $vl_title && 'dashboard' !== $tab ) : ?>
				<h2 class="vl-account__title"><?php echo esc_html( $vl_title ); ?></h2>
			<?php endif; ?>

			<?php VL_Account_MyAccount::render_tab( $tab ); ?>
		</main>
	</div>
</div>
