<?php
/**
 * Мост к плагину «Back In Stock Notifier for WooCommerce» (cwginstocknotifier).
 *
 * Плагин хранит подписки на поступление в записях типа cwginstocknotifier:
 * заголовок записи — e-mail подписчика, товар и размер лежат в метаполях
 * cwginstock_product_id / cwginstock_variation_id, автор подписки —
 * в cwginstock_user_id. Кабинет ничего этого не знал, поэтому раздел
 * «Подписки» оставался пустым даже после подписки на отсутствующий размер.
 *
 * Здесь подписки читаются и показываются в кабинете, а отписаться можно
 * прямо оттуда. Плагин подписок не изменяется.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Подписки на поступление товара.
 */
class VL_Account_Stock_Notifier {

	/**
	 * Тип записи плагина подписок.
	 */
	const POST_TYPE = 'cwginstocknotifier';

	/**
	 * Метаполя плагина подписок.
	 */
	const META_PRODUCT   = 'cwginstock_product_id';
	const META_VARIATION = 'cwginstock_variation_id';
	const META_EMAIL     = 'cwginstock_subscriber_email';
	const META_USER      = 'cwginstock_user_id';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Stock_Notifier|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Stock_Notifier
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
		add_filter( 'vlacc_user_subscriptions', array( $this, 'subscriptions' ), 10, 2 );

		add_action( 'wp_ajax_vlacc_subscription_remove', array( $this, 'ajax_remove' ) );
	}

	/* ------------------------------------------------------------------
	 * Доступность
	 * ------------------------------------------------------------------ */

	/**
	 * Плагин подписок установлен.
	 *
	 * @return bool
	 */
	public static function plugin_active() {
		return class_exists( 'CWG_Instock_Notifier' ) || post_type_exists( self::POST_TYPE );
	}

	/**
	 * Интеграция включена.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return self::plugin_active() && VL_Account_Settings::get( 'sn_enabled', 1 );
	}

	/**
	 * Статусы подписок, которые показываем в кабинете.
	 *
	 * @return array
	 */
	public static function statuses() {
		$statuses = array( 'cwg_subscribed' );

		if ( VL_Account_Settings::get( 'sn_show_sent', 1 ) ) {
			// Письмо о поступлении уже ушло — подписка всё ещё интересна покупателю.
			$statuses[] = 'cwg_mailsent';
			$statuses[] = 'cwg_mailnotsent';
		}

		return apply_filters( 'vlacc_stock_notifier_statuses', $statuses );
	}

	/**
	 * Человеческое название статуса.
	 *
	 * @param string $status Статус записи.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'cwg_subscribed'   => __( 'ждём поступления', 'vl-account' ),
			'cwg_mailsent'     => __( 'уведомление отправлено', 'vl-account' ),
			'cwg_mailnotsent'  => __( 'уведомление не доставлено', 'vl-account' ),
			'cwg_unsubscribed' => __( 'отписка', 'vl-account' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : '';
	}

	/* ------------------------------------------------------------------
	 * Чтение подписок
	 * ------------------------------------------------------------------ */

	/**
	 * Адреса, по которым ищем подписки пользователя.
	 *
	 * Подписаться можно и до регистрации — тогда подписка привязана только
	 * к e-mail. Технический адрес вида 79001234567@phone.site тоже учитываем:
	 * форма подписки могла подставить его из аккаунта.
	 *
	 * @param int $user_id Пользователь.
	 * @return array
	 */
	protected static function emails( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return array();
		}

		$emails = array( $user->user_email );

		$billing = get_user_meta( $user_id, 'billing_email', true );

		if ( $billing ) {
			$emails[] = $billing;
		}

		$emails = array_filter( array_map( 'strtolower', array_map( 'trim', $emails ) ) );

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Подписки пользователя для раздела «Подписки».
	 *
	 * @param array $items   Текущий список.
	 * @param int   $user_id Пользователь.
	 * @return array
	 */
	public function subscriptions( $items, $user_id = 0 ) {
		if ( ! self::enabled() ) {
			return $items;
		}

		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return $items;
		}

		$posts = self::query( $user_id );

		if ( ! $posts ) {
			return $items;
		}

		$items = is_array( $items ) ? $items : array();

		foreach ( $posts as $post ) {
			$row = self::format( $post );

			if ( $row ) {
				$items[] = $row;
			}
		}

		return $items;
	}

	/**
	 * Записи подписок пользователя.
	 *
	 * @param int $user_id Пользователь.
	 * @return WP_Post[]
	 */
	public static function query( $user_id ) {
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'   => self::META_USER,
				'value' => (int) $user_id,
			),
		);

		if ( VL_Account_Settings::get( 'sn_match_email', 1 ) ) {
			$emails = self::emails( $user_id );

			if ( $emails ) {
				$meta_query[] = array(
					'key'     => self::META_EMAIL,
					'value'   => $emails,
					'compare' => 'IN',
				);
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => self::statuses(),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		return $query->posts;
	}

	/**
	 * Запись подписки в формате раздела «Подписки».
	 *
	 * @param WP_Post $post Запись.
	 * @return array|null
	 */
	protected static function format( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$product_id   = (int) get_post_meta( $post->ID, self::META_PRODUCT, true );
		$variation_id = (int) get_post_meta( $post->ID, self::META_VARIATION, true );

		if ( ! $product_id && ! $variation_id ) {
			return null;
		}

		$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );

		if ( ! $product ) {
			return null;
		}

		$parent_id = $product_id ? $product_id : $product->get_parent_id();
		$parent    = $parent_id ? wc_get_product( $parent_id ) : $product;

		return array(
			'id'           => $post->ID,
			'product_id'   => $parent ? $parent->get_id() : $product->get_id(),
			'variation_id' => $variation_id,
			'title'        => $parent ? $parent->get_name() : $product->get_name(),
			'size'         => self::variation_label( $product, $variation_id ),
			'date'         => $post->post_date,
			'status'       => $post->post_status,
			'status_label' => self::status_label( $post->post_status ),
			'in_stock'     => $product->is_in_stock(),
			'source'       => 'cwginstock',
		);
	}

	/**
	 * Подпись варианта: «Размер: M».
	 *
	 * @param WC_Product $product      Товар или вариация.
	 * @param int        $variation_id ID вариации.
	 * @return string
	 */
	protected static function variation_label( $product, $variation_id ) {
		if ( ! $variation_id || ! $product instanceof WC_Product_Variation ) {
			return '';
		}

		$attributes = $product->get_variation_attributes();

		if ( ! $attributes ) {
			return '';
		}

		if ( function_exists( 'wc_get_formatted_variation' ) ) {
			$label = wc_get_formatted_variation( $product, true, false );

			if ( $label ) {
				return $label;
			}
		}

		return implode( ', ', array_filter( $attributes ) );
	}

	/* ------------------------------------------------------------------
	 * Отписка из кабинета
	 * ------------------------------------------------------------------ */

	/**
	 * Подписка принадлежит пользователю.
	 *
	 * @param int $post_id Запись подписки.
	 * @param int $user_id Пользователь.
	 * @return bool
	 */
	public static function belongs_to( $post_id, $user_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		if ( (int) get_post_meta( $post_id, self::META_USER, true ) === (int) $user_id ) {
			return true;
		}

		if ( ! VL_Account_Settings::get( 'sn_match_email', 1 ) ) {
			return false;
		}

		$email = strtolower( (string) get_post_meta( $post_id, self::META_EMAIL, true ) );

		return $email && in_array( $email, self::emails( $user_id ), true );
	}

	/**
	 * Отписаться от поступления.
	 */
	public function ajax_remove() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vl-account' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Страница устарела. Обновите её и попробуйте снова.', 'vl-account' ),
					'reload'  => true,
				),
				403
			);
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Сначала войдите в кабинет.', 'vl-account' ) ), 403 );
		}

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Подписки сейчас недоступны.', 'vl-account' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
		$post_id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( ! $post_id || ! self::belongs_to( $post_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Подписка не найдена.', 'vl-account' ) ) );
		}

		self::unsubscribe( $post_id );

		vlacc_log(
			'Отписка от поступления товара',
			array(
				'user_id' => $user_id,
				'post_id' => $post_id,
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Отписали от уведомлений.', 'vl-account' ),
				'id'      => $post_id,
			)
		);
	}

	/**
	 * Перевести подписку в статус «отписан».
	 *
	 * @param int $post_id Запись подписки.
	 */
	public static function unsubscribe( $post_id ) {
		// У плагина есть свой метод — он корректнее на случай доработок.
		if ( class_exists( 'CWG_Instock_API' ) ) {
			try {
				$api = new CWG_Instock_API();

				if ( method_exists( $api, 'subscriber_unsubscribed' ) ) {
					$api->subscriber_unsubscribed( $post_id );

					return;
				}
			} catch ( Throwable $e ) {
				// Ниже сделаем то же самое напрямую.
			}
		}

		wp_update_post(
			array(
				'ID'          => (int) $post_id,
				'post_type'   => self::POST_TYPE,
				'post_status' => 'cwg_unsubscribed',
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Диагностика
	 * ------------------------------------------------------------------ */

	/**
	 * Проверки связки для админки.
	 *
	 * @return array
	 */
	public static function checks() {
		$active = self::plugin_active();

		$checks = array(
			array(
				'title'  => __( 'Плагин подписок на поступление', 'vl-account' ),
				'status' => $active ? 'ok' : 'warn',
				'text'   => $active
					? __( 'Back In Stock Notifier активен — подписки показываются в разделе «Подписки».', 'vl-account' )
					: __( 'Не найден. Раздел «Подписки» показывает только настройки рассылок.', 'vl-account' ),
			),
		);

		if ( ! $active ) {
			return $checks;
		}

		$counts = wp_count_posts( self::POST_TYPE );
		$total  = 0;

		foreach ( self::statuses() as $status ) {
			$total += isset( $counts->$status ) ? (int) $counts->$status : 0;
		}

		$checks[] = array(
			'title'  => __( 'Активные подписки', 'vl-account' ),
			'status' => 'ok',
			'text'   => sprintf(
				/* translators: %d — количество подписок. */
				esc_html__( 'Всего по магазину: %d.', 'vl-account' ),
				$total
			),
		);

		$user_id = get_current_user_id();

		if ( $user_id ) {
			$checks[] = array(
				'title'  => __( 'Подписки текущего пользователя', 'vl-account' ),
				'status' => 'ok',
				'text'   => sprintf(
					/* translators: %d — количество подписок. */
					esc_html__( 'Найдено: %d. Ищем по ID пользователя и по его e-mail.', 'vl-account' ),
					count( self::query( $user_id ) )
				),
			);
		}

		return $checks;
	}
}
