<?php
/**
 * Смоук-тест связки с плагином избранного WishSuite.
 */

namespace WishSuite {

	/** Заглушка хранилища WishSuite: таблица wishsuite_list в памяти. */
	class Manage_Data {

		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function read( $args = array() ) {
			$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
			$rows    = array();

			foreach ( $GLOBALS['ws_db'][ $user_id ] ?? array() as $product_id ) {
				$rows[] = array(
					'user_id'    => $user_id,
					'product_id' => $product_id,
				);
			}

			return $rows;
		}

		public function create( $args = array() ) {
			$user_id    = (int) $args['user_id'];
			$product_id = (int) $args['product_id'];

			if ( ! isset( $GLOBALS['ws_db'][ $user_id ] ) ) {
				$GLOBALS['ws_db'][ $user_id ] = array();
			}

			if ( ! in_array( $product_id, $GLOBALS['ws_db'][ $user_id ], true ) ) {
				$GLOBALS['ws_db'][ $user_id ][] = $product_id;
			}

			return true;
		}

		public function delete( $user_id, $product_id ) {
			$user_id = (int) $user_id;

			if ( empty( $GLOBALS['ws_db'][ $user_id ] ) ) {
				return false;
			}

			$GLOBALS['ws_db'][ $user_id ] = array_values(
				array_diff( $GLOBALS['ws_db'][ $user_id ], array( (int) $product_id ) )
			);

			return true;
		}
	}
}

namespace WishSuite\Frontend {

	/** Заглушка фронтенда WishSuite. */
	class Manage_Wishlist {

		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function get_cookie_name() {
			return 'wishsuite_item_list';
		}

		public function add_product( $id ) {
			$user_id = get_current_user_id();

			return \WishSuite\Manage_Data::instance()->create(
				array(
					'user_id'    => $user_id,
					'product_id' => $id,
				)
			);
		}

		public function remove_product( $id ) {
			return \WishSuite\Manage_Data::instance()->delete( get_current_user_id(), $id );
		}
	}
}

namespace {

	require __DIR__ . '/bootstrap.php';

	require_once VLACC_PATH . 'includes/class-vl-wishlist.php';
	require_once VLACC_PATH . 'includes/integrations/class-vl-wishsuite.php';

	function wc_get_page_permalink( $p ) { return 'https://example.test/shop/'; }
	function is_multisite() { return false; }
	function get_current_blog_id() { return 1; }

	$GLOBALS['ws_db'] = array();

	// Все товары теста существуют и опубликованы (проверка живых ID).
	foreach ( array( 101, 102, 103, 105, 200, 201, 300, 401, 402, 403 ) as $vl_pid ) {
		$GLOBALS['products'][ $vl_pid ] = (object) array( 'id' => $vl_pid );
	}

	$pass = 0;
	$fail = 0;

	function check( $name, $condition, $extra = '' ) {
		global $pass, $fail;
		if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
	}

	VL_Account_WishSuite::instance();

	echo "\n== 1. Плагин найден ==\n";
	check( 'plugin_active', VL_Account_WishSuite::plugin_active() );
	check( 'enabled', VL_Account_WishSuite::enabled() );

	echo "\n== 2. Списки объединяются ==\n";
	$GLOBALS['ws_db'][1] = array( 101, 102 );
	update_user_meta( 1, VL_Account_Wishlist::META, array( 102, 103 ) );

	$items = VL_Account_Wishlist::get_items( 1 );
	sort( $items );
	check( 'три товара без дублей', array( 101, 102, 103 ) === $items, print_r( $items, true ) );
	check( 'счётчик', 3 === VL_Account_Wishlist::count() );
	check( 'товар WishSuite виден кабинету', VL_Account_Wishlist::has( 101, 1 ) );

	echo "\n== 3. Режим «только WishSuite» ==\n";
	VL_Account_Settings::update( array( 'ws_source' => 'wishsuite' ) );
	$items = VL_Account_Wishlist::get_items( 1 );
	sort( $items );
	check( 'только список WishSuite', array( 101, 102 ) === $items, print_r( $items, true ) );

	echo "\n== 4. Режим «только кабинет» ==\n";
	VL_Account_Settings::update( array( 'ws_source' => 'vlacc' ) );
	$items = VL_Account_Wishlist::get_items( 1 );
	sort( $items );
	check( 'только наш список', array( 102, 103 ) === $items, print_r( $items, true ) );
	VL_Account_Settings::update( array( 'ws_source' => 'merge' ) );

	echo "\n== 5. Изменения из кабинета уходят в WishSuite ==\n";
	VL_Account_Wishlist::toggle( 200 );
	check( 'добавленный товар попал в WishSuite', in_array( 200, $GLOBALS['ws_db'][1], true ), print_r( $GLOBALS['ws_db'][1], true ) );

	VL_Account_Wishlist::remove( 101 );
	check( 'убранный товар исчез из WishSuite', ! in_array( 101, $GLOBALS['ws_db'][1], true ), print_r( $GLOBALS['ws_db'][1], true ) );
	check( 'в кабинете его тоже нет', ! VL_Account_Wishlist::has( 101, 1 ) );

	echo "\n== 5.1. Чужие товары не оседают в нашем хранилище ==\n";

	// Товар добавлен только сердечком WishSuite (в обход кабинета — как в жизни).
	$GLOBALS['ws_db'][1][] = 105;
	VL_Account_WishSuite::flush();
	check( 'товар WishSuite виден в кабинете', VL_Account_Wishlist::has( 105, 1 ) );

	// Любое действие в кабинете не должно копировать чужие товары к нам.
	VL_Account_Wishlist::toggle( 201 );
	$own = get_user_meta( 1, VL_Account_Wishlist::META, true );
	check( 'чужой товар не скопирован в наше хранилище', ! in_array( 105, $own, true ), print_r( $own, true ) );

	// Покупатель убрал товар сердечком в WishSuite — из кабинета он тоже пропадает.
	$GLOBALS['ws_db'][1] = array_values( array_diff( $GLOBALS['ws_db'][1], array( 105 ) ) );
	VL_Account_WishSuite::flush();
	check( 'убранный в WishSuite исчез из кабинета', ! VL_Account_Wishlist::has( 105, 1 ), print_r( VL_Account_Wishlist::get_items( 1 ), true ) );

	echo "\n== 6. Двусторонняя синхронизация выключается ==\n";
	VL_Account_Settings::update( array( 'ws_two_way' => 0 ) );
	VL_Account_Wishlist::toggle( 300 );
	check( 'в WishSuite не записалось', ! in_array( 300, $GLOBALS['ws_db'][1], true ) );
	check( 'в кабинете записалось', VL_Account_Wishlist::has( 300, 1 ) );
	VL_Account_Settings::update( array( 'ws_two_way' => 1 ) );

	echo "\n== 7. Избранное гостя переносится в аккаунт ==\n";
	$GLOBALS['ws_db'][5] = array();
	$GLOBALS['ws_db'][1700000000] = array( 401, 402 );
	$_COOKIE['wishsuite_item_list']    = wp_json_encode( array( 401, 403 ) );
	$_COOKIE['wishsuite_temp_user_id'] = '1700000000';

	VL_Account_Wishlist::merge_guest_wishlist( 5 );

	$moved = $GLOBALS['ws_db'][5];
	sort( $moved );
	check( 'товары гостя у пользователя', array( 401, 402, 403 ) === $moved, print_r( $moved, true ) );
	check( 'временные записи очищены', array() === $GLOBALS['ws_db'][1700000000], print_r( $GLOBALS['ws_db'][1700000000], true ) );
	check( 'кука очищена', ! isset( $_COOKIE['wishsuite_item_list'] ) );

	echo "\n== 8. Интеграция выключена ==\n";
	VL_Account_Settings::update( array( 'ws_enabled' => 0 ) );
	$items = VL_Account_Wishlist::get_items( 1 );
	sort( $items );
	check( 'показывается только наш список', array( 102, 103, 200, 201, 300 ) === $items, print_r( $items, true ) );
	check( 'items() пустой', array() === VL_Account_WishSuite::items( 1 ) );
	VL_Account_Settings::update( array( 'ws_enabled' => 1 ) );

	echo "\n== 8.1. Удалённые и снятые с публикации товары ==\n";
	update_user_meta( 9, VL_Account_Wishlist::META, array( 101, 103, 777 ) );
	$GLOBALS['ws_db'][9] = array( 105 );
	$GLOBALS['product_status'][103] = 'draft';  // снят с публикации
	VL_Account_WishSuite::flush();

	$visible = VL_Account_Wishlist::get_valid_items( 9 );
	sort( $visible );
	check( 'показываются только живые товары', array( 101, 105 ) === $visible, print_r( $visible, true ) );

	$own = get_user_meta( 9, VL_Account_Wishlist::META, true );
	sort( $own );
	check( 'удалённый товар вычищен из хранилища', array( 101, 103 ) === $own, print_r( $own, true ) );
	check( 'черновик остался в хранилище', in_array( 103, $own, true ) );

	$GLOBALS['product_status'][103] = 'publish';
	VL_Account_WishSuite::flush();

	echo "\n== 9. Кнопка кабинета не дублирует сердечко ==\n";
	check( 'наша кнопка выключена', 0 === (int) apply_filters( 'vlacc_setting_wishlist_on_product', 1 ) );
	VL_Account_Settings::update( array( 'ws_hide_our_button' => 0 ) );
	check( 'настройкой возвращается', 1 === (int) apply_filters( 'vlacc_setting_wishlist_on_product', 1 ) );
	VL_Account_Settings::update( array( 'ws_hide_our_button' => 1 ) );

	echo "\n== 10. Атрибуты для кнопок размеров ==\n";
	check( 'по умолчанию pa_razmer', array( 'pa_razmer' ) === VL_Account_WishSuite::size_attributes(), print_r( VL_Account_WishSuite::size_attributes(), true ) );
	VL_Account_Settings::update( array( 'ws_size_attributes' => '' ) );
	check( 'пусто — все атрибуты', array() === VL_Account_WishSuite::size_attributes() );
	VL_Account_Settings::update( array( 'ws_size_attributes' => 'pa_razmer, pa_size' ) );
	check( 'список разбирается', array( 'pa_razmer', 'pa_size' ) === VL_Account_WishSuite::size_attributes(), print_r( VL_Account_WishSuite::size_attributes(), true ) );
	echo "\n== ИТОГО: $pass ок, $fail ошибок ==\n";
	exit( $fail > 0 ? 1 : 0 );
}
