<?php
/**
 * Смоук-тест гейта «вход перед покупкой»: режимы «корзина» и «оформление».
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки WooCommerce ---- */

function wc_get_cart_url() { return 'https://example.test/korzina/'; }
function wc_get_checkout_url() { return 'https://example.test/oformlenie/'; }

require_once VLACC_PATH . 'includes/class-vl-shortcodes.php';
require_once VLACC_PATH . 'includes/class-vl-gate.php';

$gate = VL_Account_Gate::instance();

echo "\n== 1. Гейт выключен ==\n";
VL_Account_Settings::update( array( 'gate_cart' => 0 ) );
check( 'enabled = false', ! VL_Account_Gate::enabled() );
check( 'корзина не блокируется', ! VL_Account_Gate::blocks_cart() );
check( 'оформление не блокируется', ! VL_Account_Gate::blocks_checkout() );

echo "\n== 2. По умолчанию вход спрашиваем на оформлении ==\n";
VL_Account_Settings::update( array( 'gate_cart' => 1 ) );
check( 'режим checkout', 'checkout' === VL_Account_Gate::mode(), VL_Account_Gate::mode() );
check( 'добавление в корзину открыто', ! VL_Account_Gate::blocks_cart() );
check( 'оформление закрыто', VL_Account_Gate::blocks_checkout() );

$selectors = VL_Account_Gate::selectors();
check( 'ловим кнопку корзины WooCommerce', in_array( '.checkout-button', $selectors, true ), print_r( $selectors, true ) );
check( 'ловим блочную корзину', in_array( '.wc-block-cart__submit-button', $selectors, true ) );
check( 'ловим боковую корзину Elementor', in_array( '.elementor-button--checkout', $selectors, true ) );
check( 'кнопку «в корзину» не трогаем', ! in_array( '.single_add_to_cart_button', $selectors, true ) );
check( 'адрес оформления передаётся в скрипт', 'https://example.test/oformlenie/' === VL_Account_Gate::checkout_url() );
check( 'текст про оформление', false !== mb_strpos( VL_Account_Gate::message(), 'оформить заказ' ), VL_Account_Gate::message() );

echo "\n== 3. Старое поведение — вход до корзины ==\n";
VL_Account_Settings::update( array( 'gate_mode' => 'cart' ) );
check( 'режим cart', 'cart' === VL_Account_Gate::mode() );
check( 'корзина закрыта', VL_Account_Gate::blocks_cart() );
check( 'оформление отдельно не сторожим', ! VL_Account_Gate::blocks_checkout() );

$selectors = VL_Account_Gate::selectors();
check( 'ловим кнопку «в корзину»', in_array( '.single_add_to_cart_button', $selectors, true ) );
check( 'кнопку оформления не трогаем', ! in_array( '.checkout-button', $selectors, true ) );
check( 'адрес оформления скрипту не нужен', '' === VL_Account_Gate::checkout_url() );
check( 'текст про корзину', false !== mb_strpos( VL_Account_Gate::message(), 'в корзину' ), VL_Account_Gate::message() );

echo "\n== 4. Свои селекторы добавляются к любому режиму ==\n";
VL_Account_Settings::update( array( 'gate_mode' => 'checkout', 'gate_selectors' => '.my-checkout, .buy-now' ) );
$selectors = VL_Account_Gate::selectors();
check( 'свой селектор в списке', in_array( '.my-checkout', $selectors, true ) && in_array( '.buy-now', $selectors, true ), print_r( $selectors, true ) );
VL_Account_Settings::update( array( 'gate_selectors' => '' ) );

echo "\n== 5. Свой текст перекрывает стандартный ==\n";
VL_Account_Settings::update( array( 'gate_message' => 'Войдите, пожалуйста' ) );
check( 'текст из настроек', 'Войдите, пожалуйста' === VL_Account_Gate::message() );
VL_Account_Settings::update( array( 'gate_message' => '' ) );

echo "\n== 6. Серверный запрет гостевого оформления ==\n";
$GLOBALS['logged_in'] = false; // смотрим глазами гостя
$gate_obj = VL_Account_Gate::instance();
check( 'гостя останавливаем', VL_Account_Gate::should_block() );
check( 'регистрация обязательна для гостя', true === $gate_obj->force_registration_required( false ) );
check( 'форма регистрации на оформлении выключена', false === $gate_obj->force_registration_disabled( true ) );

check( 'авторизованного не трогаем', ( function () {
	$GLOBALS['logged_in'] = true;
	$result = VL_Account_Gate::should_block();
	$GLOBALS['logged_in'] = false;
	return ! $result;
} )() );

echo "\n== 7. Режим переключается фильтром ==\n";
add_filter( 'vlacc_gate_mode', function () { return 'cart'; } );
check( 'фильтр перекрывает настройку', 'cart' === VL_Account_Gate::mode() );

echo "\n== ИТОГО: $pass ок, $fail ошибок ==\n";
exit( $fail > 0 ? 1 : 0 );
