<?php
/**
 * Смоук-тест миниатюр товара в таблицах кабинета.
 *
 * Файл намеренно не подключает bootstrap.php: тот подменяет помощники плагина
 * своими заглушками, а проверять надо настоящий vlacc_item_thumb(). Поэтому
 * здесь минимальный набор заглушек WordPress и WooCommerce — ровно те, что
 * функция вызывает.
 */

define( 'ABSPATH', '/tmp/wp/' );

$pass = 0;
$fail = 0;

/**
 * Проверка.
 *
 * @param string $name      Название.
 * @param bool   $condition Условие.
 * @param string $extra     Что показать при провале.
 */
function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки ---- */

$GLOBALS['products'] = array();

function esc_url( $u ) { return $u; }
function wp_kses_post( $html ) { return $html; }
function wc_get_product( $id ) { return $GLOBALS['products'][ (int) $id ] ?? false; }
function wc_placeholder_img( $size = '' ) { return '<img class="placeholder" src="ph.png" alt="" />'; }

/** Товар WooCommerce: нужен только get_image(). */
class WC_Product {
	public $image = '';
	public $args  = array();

	public function __construct( $image = '<img src="dress.jpg" alt="" />' ) {
		$this->image = $image;
	}

	public function get_image( $size = '', $attr = array(), $placeholder = true ) {
		$this->args = array( $size, $attr );

		return $this->image;
	}
}

require_once __DIR__ . '/../vl-account/includes/functions.php';

echo "\n== 0. Без WooCommerce ==\n";

// Проверяем первой: vlacc_is_woo() смотрит на class_exists('WooCommerce'),
// а объявленный класс уже не убрать.
check( 'без магазина картинок нет', '' === vlacc_item_thumb( 7, '' ) );

// Объявление в блоке, а не на верхнем уровне: иначе PHP поднял бы класс
// в начало файла и проверка выше стала бы бессмысленной.
if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

echo "\n== 1. Товар с картинкой ==\n";

$product                = new WC_Product();
$GLOBALS['products'][7] = $product;

$html = vlacc_item_thumb( $product, 'https://example.test/dress' );

check( 'миниатюра в ссылке', false !== strpos( $html, '<a class="vl-item__thumb" href="https://example.test/dress"' ), $html );
check( 'картинка внутри', false !== strpos( $html, 'dress.jpg' ), $html );
check( 'размер каталога', 'woocommerce_thumbnail' === $product->args[0], print_r( $product->args, true ) );
check( 'картинка грузится лениво', array( 'loading' => 'lazy' ) === $product->args[1], print_r( $product->args, true ) );

// Название товара рядом ведёт туда же — второй ссылки для чтения с экрана
// быть не должно.
check( 'ссылка-картинка скрыта от скринридера', false !== strpos( $html, 'aria-hidden="true"' ), $html );
check( 'и не ловит табуляцию', false !== strpos( $html, 'tabindex="-1"' ), $html );

echo "\n== 2. Товар по ID ==\n";

check( 'ID разворачивается в товар', false !== strpos( vlacc_item_thumb( 7, '' ), 'dress.jpg' ) );
check( 'без ссылки — просто span', false !== strpos( vlacc_item_thumb( 7, '' ), '<span class="vl-item__thumb">' ), vlacc_item_thumb( 7, '' ) );

echo "\n== 3. Товара нет ==\n";

// Товар удалили из каталога, а в заказе он остался: строки таблицы не должны
// прыгать по высоте, поэтому ставим заглушку WooCommerce.
$html = vlacc_item_thumb( 999, '' );

check( 'вместо удалённого товара заглушка', false !== strpos( $html, 'placeholder' ), $html );
check( 'заглушка тоже в обёртке', false !== strpos( $html, 'vl-item__thumb' ), $html );
check( 'нулевой ID не ломает', false !== strpos( vlacc_item_thumb( 0, '' ), 'placeholder' ) );

echo "\n== ИТОГО: $pass ок, $fail ошибок ==\n";

exit( $fail > 0 ? 1 : 0 );
