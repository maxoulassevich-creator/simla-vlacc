<?php
/**
 * Кабинет: избранное.
 *
 * @package VL_Account
 *
 * @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

// Берём только те товары, которые действительно можно показать: удалённые,
// черновики и товары из корзины сюда не попадают — иначе раздел выглядел
// пустым, а счётчик показывал старое число.
$vl_ids      = vlacc_is_woo() ? VL_Account_Wishlist::get_valid_items( $user_id ) : array();
$vl_products = array();

foreach ( $vl_ids as $vl_id ) {
	$vl_product = wc_get_product( $vl_id );

	// Товар мог исчезнуть между проверкой и выводом — тогда просто пропускаем,
	// но пустую сетку вместо надписи «в избранном пусто» не показываем.
	if ( $vl_product && in_array( $vl_product->get_status(), array( 'publish', 'private' ), true ) ) {
		$vl_products[ $vl_id ] = $vl_product;
	}
}

if ( ! $vl_products || ! vlacc_is_woo() ) :
	?>
	<div class="vl-empty">
		<p><?php esc_html_e( 'В избранном пока пусто. Нажмите на сердечко у товара — он появится здесь.', 'vl-account' ); ?></p>
		<?php if ( vlacc_is_woo() ) : ?>
			<a class="vl-btn vl-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'перейти в каталог', 'vl-account' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;
?>
<div class="vl-products" data-vl-wishlist-grid>
	<?php
	foreach ( $vl_products as $vl_id => $vl_product ) :
		?>
		<div class="vl-product" data-vl-wishlist-item="<?php echo esc_attr( $vl_id ); ?>">
			<a class="vl-product__image" href="<?php echo esc_url( $vl_product->get_permalink() ); ?>">
				<?php echo wp_kses_post( $vl_product->get_image( 'woocommerce_thumbnail' ) ); ?>
			</a>

			<div class="vl-product__body">
				<a class="vl-product__title" href="<?php echo esc_url( $vl_product->get_permalink() ); ?>">
					<?php echo esc_html( $vl_product->get_name() ); ?>
				</a>

				<div class="vl-product__price"><?php echo wp_kses_post( $vl_product->get_price_html() ); ?></div>

				<div class="vl-product__actions">
					<?php if ( $vl_product->is_in_stock() ) : ?>
						<a class="vl-btn vl-btn--primary vl-btn--sm" href="<?php echo esc_url( $vl_product->get_permalink() ); ?>">
							<?php esc_html_e( 'в корзину', 'vl-account' ); ?>
						</a>
					<?php else : ?>
						<span class="vl-product__stock"><?php esc_html_e( 'нет в наличии', 'vl-account' ); ?></span>
					<?php endif; ?>

					<button type="button" class="vl-link vl-link--muted" data-vl-wishlist-remove="<?php echo esc_attr( $vl_id ); ?>">
						<?php esc_html_e( 'убрать', 'vl-account' ); ?>
					</button>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</div>
