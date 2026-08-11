<?php
/**
 * Меню личного кабинета.
 *
 * @package VL_Account
 *
 * @var string $class   Дополнительный класс.
 * @var bool   $icons   Показывать иконки.
 * @var string $current Текущий раздел.
 */

defined( 'ABSPATH' ) || exit;

$vl_items   = VL_Account_Router::tabs();
$vl_current = isset( $current ) ? $current : VL_Account_Router::current_tab();
$vl_icons   = isset( $icons ) ? (bool) $icons : true;
$vl_class   = isset( $class ) ? $class : '';

$vl_counts = array(
	'wishlist' => VL_Account_Wishlist::count(),
);
?>
<nav class="vl-menu <?php echo esc_attr( $vl_class ); ?>">
	<ul class="vl-menu__list">
		<?php
		foreach ( $vl_items as $vl_slug => $vl_item ) :
			if ( ! empty( $vl_item['hidden'] ) ) {
				continue;
			}

			$vl_active = ( $vl_slug === $vl_current ) || ( 'orders' === $vl_slug && 'order-view' === $vl_current );
			?>
			<li class="vl-menu__item<?php echo $vl_active ? ' is-active' : ''; ?> vl-menu__item--<?php echo esc_attr( $vl_slug ); ?>">
				<a class="vl-menu__link" href="<?php echo esc_url( VL_Account_Router::url( $vl_slug ) ); ?>"
					<?php echo 'logout' === $vl_slug ? ' data-vl-confirm-logout="1"' : ''; ?>>
					<?php if ( $vl_icons && ! empty( $vl_item['icon'] ) ) : ?>
						<span class="vl-menu__icon"><?php echo vlacc_icon( $vl_item['icon'], 17 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php endif; ?>
					<span class="vl-menu__text"><?php echo esc_html( $vl_item['title'] ); ?></span>
					<?php if ( ! empty( $vl_counts[ $vl_slug ] ) ) : ?>
						<span class="vl-menu__count"><?php echo esc_html( $vl_counts[ $vl_slug ] ); ?></span>
					<?php endif; ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
