<?php
/**
 * Избранное: хранение у пользователя и у гостя, вывод в кабинете.
 *
 * Закрывает задачи «положить в избранное из карточки товара» и
 * «попадание товаров из избранного в личный кабинет клиента».
 *
 * Если на сайте уже работает сторонний плагин избранного, его список можно
 * подставить фильтром vlacc_wishlist_items и отключить наше хранилище.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Избранное.
 */
class VL_Account_Wishlist {

	const META   = 'vlacc_wishlist';
	const COOKIE = 'vlacc_wishlist';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Wishlist|null
	 */
	private static $instance = null;

	/**
	 * Кэш проверенных списков в рамках запроса.
	 *
	 * @var array
	 */
	private static $valid_cache = array();

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Wishlist
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		if ( VL_Account_Settings::get( 'wishlist_on_product', 0 ) && vlacc_is_woo() ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'product_button' ), 35 );
		}
	}

	/**
	 * Кнопка на странице товара.
	 */
	public function product_button() {
		global $product;

		if ( ! $product ) {
			return;
		}

		echo self::button( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- разметка формируется в button().
	}

	/**
	 * Список ID товаров в избранном.
	 *
	 * @param int $user_id Пользователь (0 — текущий/гость).
	 * @return array
	 */
	public static function get_items( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		return apply_filters( 'vlacc_wishlist_items', self::raw_items( $user_id ), $user_id );
	}

	/**
	 * Список только из нашего хранилища, без сторонних плагинов.
	 *
	 * Сохранять нужно именно его: если записать объединённый список,
	 * товары стороннего плагина осядут у нас копией и не исчезнут из кабинета,
	 * когда покупатель уберёт их там.
	 *
	 * @param int $user_id Пользователь (0 — текущий/гость).
	 * @return array
	 */
	public static function raw_items( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( $user_id ) {
			$items = get_user_meta( $user_id, self::META, true );
		} else {
			$items = isset( $_COOKIE[ self::COOKIE ] ) ? explode( ',', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) : array();
		}

		return is_array( $items ) ? array_values( array_unique( array_filter( array_map( 'absint', $items ) ) ) ) : array();
	}

	/**
	 * Сохранить список.
	 *
	 * @param array $items   ID товаров.
	 * @param int   $user_id Пользователь.
	 */
	protected static function save( $items, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$items   = array_values( array_unique( array_filter( array_map( 'absint', (array) $items ) ) ) );

		self::$valid_cache = array();

		if ( $user_id ) {
			update_user_meta( $user_id, self::META, $items );
			return;
		}

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				implode( ',', $items ),
				time() + 30 * DAY_IN_SECONDS,
				COOKIEPATH ? COOKIEPATH : '/',
				COOKIE_DOMAIN,
				is_ssl(),
				false
			);
		}

		$_COOKIE[ self::COOKIE ] = implode( ',', $items );
	}

	/**
	 * Список только из тех товаров, которые действительно можно показать.
	 *
	 * В избранном оседают ID удалённых товаров и товаров, отправленных в корзину
	 * или черновики. Раньше такой ID считался счётчиком, но карточка не выводилась:
	 * в кабинете «3 в избранном», а раздел пустой. Теперь список и счётчик
	 * считают одно и то же, а мёртвые ID вычищаются из нашего хранилища.
	 *
	 * @param int $user_id Пользователь (0 — текущий/гость).
	 * @return array
	 */
	public static function get_valid_items( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$items   = self::get_items( $user_id );

		if ( ! $items || ! vlacc_is_woo() ) {
			return array();
		}

		$key = $user_id . ':' . implode( ',', $items );

		if ( isset( self::$valid_cache[ $key ] ) ) {
			return self::$valid_cache[ $key ];
		}

		// Один запрос на все ID: что из этого опубликовано и доступно.
		$published = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post__in'               => $items,
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => count( $items ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$published = array_map( 'absint', (array) $published );

		// Порядок избранного сохраняем: get_posts возвращает свой.
		$valid = array_values( array_intersect( $items, $published ) );

		// Второй запрос: какие ID вообще существуют (черновик и корзина — существуют).
		$existing = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post__in'               => $items,
				'post_status'            => 'any',
				'posts_per_page'         => count( $items ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$gone = array_diff( $items, array_map( 'absint', (array) $existing ) );

		if ( $gone ) {
			self::forget( $gone, $user_id );
		}

		self::$valid_cache[ $key ] = $valid;

		return $valid;
	}

	/**
	 * Забыть товары, которых больше нет в каталоге.
	 *
	 * Чистим только своё хранилище; сторонние плагины подчищают своё
	 * по событию vlacc_wishlist_forget.
	 *
	 * @param array $ids     ID товаров.
	 * @param int   $user_id Пользователь.
	 */
	protected static function forget( $ids, $user_id = 0 ) {
		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $ids ) {
			return;
		}

		$raw   = self::raw_items( $user_id );
		$clean = array_values( array_diff( $raw, $ids ) );

		if ( count( $clean ) !== count( $raw ) ) {
			self::save( $clean, $user_id );
		}

		do_action( 'vlacc_wishlist_forget', $ids, $user_id );
	}

	/**
	 * Товар в избранном?
	 *
	 * @param int $product_id Товар.
	 * @param int $user_id    Пользователь.
	 * @return bool
	 */
	public static function has( $product_id, $user_id = 0 ) {
		return in_array( (int) $product_id, self::get_items( $user_id ), true );
	}

	/**
	 * Переключить.
	 *
	 * @param int $product_id Товар.
	 * @return bool Итоговое состояние: true — в избранном.
	 */
	public static function toggle( $product_id ) {
		$id = (int) $product_id;

		// Решение принимаем по общему списку (с учётом стороннего плагина),
		// а сохраняем только своё хранилище.
		$items = self::raw_items();

		if ( self::has( $id ) ) {
			$items = array_diff( $items, array( $id ) );
			$state = false;
		} else {
			$items[] = $id;
			$state   = true;
		}

		self::save( $items );

		do_action( 'vlacc_wishlist_updated', $id, $state, get_current_user_id() );

		return $state;
	}

	/**
	 * Убрать товар.
	 *
	 * @param int $product_id Товар.
	 */
	public static function remove( $product_id ) {
		$items = array_diff( self::raw_items(), array( (int) $product_id ) );
		self::save( $items );

		do_action( 'vlacc_wishlist_updated', (int) $product_id, false, get_current_user_id() );
	}

	/**
	 * Сколько товаров в избранном.
	 *
	 * Считаем только то, что реально показывается в кабинете, — иначе счётчик
	 * расходится с разделом.
	 *
	 * @return int
	 */
	public static function count() {
		return vlacc_is_woo() ? count( self::get_valid_items() ) : count( self::get_items() );
	}

	/**
	 * Перенос избранного гостя в аккаунт после входа.
	 *
	 * @param int $user_id Пользователь.
	 */
	public static function merge_guest_wishlist( $user_id ) {
		$guest = empty( $_COOKIE[ self::COOKIE ] )
			? array()
			: array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) ) );

		if ( $guest ) {
			$user_items = get_user_meta( $user_id, self::META, true );
			$user_items = is_array( $user_items ) ? $user_items : array();

			update_user_meta( $user_id, self::META, array_values( array_unique( array_merge( $user_items, $guest ) ) ) );

			if ( ! headers_sent() ) {
				setcookie( self::COOKIE, '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
			}

			unset( $_COOKIE[ self::COOKIE ] );
		}

		// Сторонние плагины избранного переносят список гостя на этом же событии.
		do_action( 'vlacc_wishlist_merged', $user_id, $guest );
	}

	/**
	 * Разметка кнопки «в избранное».
	 *
	 * @param int   $product_id Товар.
	 * @param array $args       Аргументы.
	 * @return string
	 */
	public static function button( $product_id, $args = array() ) {
		$product_id = (int) $product_id;

		if ( ! $product_id ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'class' => '',
				'label' => __( 'В избранное', 'vl-account' ),
				'added' => __( 'В избранном', 'vl-account' ),
				'text'  => true,
			)
		);

		$active = self::has( $product_id );

		return sprintf(
			'<button type="button" class="vl-wishlist-btn %1$s%2$s" data-vl-wishlist="%3$d" aria-pressed="%4$s"><span class="vl-wishlist-btn__icon">%5$s</span>%6$s</button>',
			esc_attr( $args['class'] ),
			$active ? ' is-active' : '',
			$product_id,
			$active ? 'true' : 'false',
			vlacc_icon( 'heart', 18 ),
			$args['text'] ? '<span class="vl-wishlist-btn__label" data-label-off="' . esc_attr( $args['label'] ) . '" data-label-on="' . esc_attr( $args['added'] ) . '">' . esc_html( $active ? $args['added'] : $args['label'] ) . '</span>' : ''
		);
	}
}
