<?php
/**
 * Смоук-тест набора иконок: линии, а не заливка.
 *
 * Файл functions.php содержит только объявления функций, поэтому подключается
 * напрямую, без общего bootstrap.php (там vlacc_* уже подменены заглушками).
 */

define( 'ABSPATH', '/tmp/wp/' );

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

$GLOBALS['filters'] = array();

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['filters'][ $hook ] ) ) { return $value; }
	foreach ( $GLOBALS['filters'][ $hook ] as $cb ) { $value = call_user_func_array( $cb, array_merge( array( $value ), $args ) ); }
	return $value;
}
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return $t; }
function esc_html( $t ) { return $t; }
function wp_kses_allowed_html( $context = 'post' ) { return array( 'a' => array( 'href' => true ) ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function wp_unslash( $t ) { return $t; }
function locate_template( $t ) { return ''; }
function class_exists_stub() { return false; }

require_once __DIR__ . '/../vl-account/includes/functions.php';

$names = array( 'user', 'logout', 'orders', 'heart', 'gift', 'star', 'bell', 'settings', 'lock', 'copy', 'check', 'phone', 'telegram' );

echo "\n== 1. Каждая иконка — контур, а не силуэт ==\n";

foreach ( $names as $name ) {
	$svg = vlacc_icon( $name, 24 );

	check(
		$name . ': рисуется линией',
		false !== strpos( $svg, 'fill="none"' )
			&& false !== strpos( $svg, 'stroke="currentColor"' )
			&& false === strpos( $svg, 'fill="currentColor"' ),
		$svg
	);
}

echo "\n== 2. Разметка корректна ==\n";

$broken = array();
$thin   = array();

foreach ( $names as $name ) {
	$svg = vlacc_icon( $name, 24 );
	$xml = @simplexml_load_string( $svg );

	if ( ! $xml ) {
		$broken[] = $name;
		continue;
	}

	// Пустая или обрезанная кривая — самая частая ошибка в рукописном path.
	foreach ( $xml->path as $path ) {
		$d = trim( (string) $path['d'] );

		if ( '' === $d || ! preg_match( '/^[Mm]/', $d ) || strlen( $d ) < 6 ) {
			$broken[] = $name;
		}
	}

	$width = (float) $xml['stroke-width'];

	if ( $width <= 0 || $width > 1.8 ) {
		$thin[] = $name . '=' . $width;
	}
}

check( 'все SVG разбираются и содержат осмысленные кривые', array() === $broken, implode( ', ', $broken ) );
check( 'линия тонкая (<= 1.8)', array() === $thin, implode( ', ', $thin ) );

$svg = vlacc_icon( 'user', 22 );
check( 'размер подставляется', false !== strpos( $svg, 'width="22" height="22"' ), $svg );
check( 'вьюбокс единый', false !== strpos( $svg, 'viewBox="0 0 24 24"' ) );
check( 'класс иконки', false !== strpos( $svg, 'class="vl-icon vl-icon--user"' ) );
check( 'скрыта от скринридера', false !== strpos( $svg, 'aria-hidden="true"' ) );
check( 'неизвестная иконка — пустая строка', '' === vlacc_icon( 'нет-такой' ) );

echo "\n== 3. Толщину линии можно поменять фильтром ==\n";

add_filter( 'vlacc_icon_stroke', function ( $width, $name ) { return 'heart' === $name ? 2 : $width; }, 10, 2 );

check( 'фильтр применяется к своей иконке', false !== strpos( vlacc_icon( 'heart' ), 'stroke-width="2"' ) );
check( 'остальные не задеты', false !== strpos( vlacc_icon( 'user' ), 'stroke-width="1.4"' ), vlacc_icon( 'user' ) );

echo "\n== 4. kses не срежет обводку ==\n";

$allowed = vlacc_allowed_html();

foreach ( array( 'svg', 'path' ) as $tag ) {
	check(
		$tag . ': атрибуты обводки разрешены',
		! empty( $allowed[ $tag ]['stroke'] )
			&& ! empty( $allowed[ $tag ]['stroke-width'] )
			&& ! empty( $allowed[ $tag ]['stroke-linecap'] )
			&& ! empty( $allowed[ $tag ]['stroke-linejoin'] ),
		print_r( isset( $allowed[ $tag ] ) ? $allowed[ $tag ] : null, true )
	);
}

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
