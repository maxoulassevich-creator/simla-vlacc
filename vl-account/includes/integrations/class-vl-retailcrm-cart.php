<?php
/**
 * Баллы программы лояльности в корзине и на оформлении заказа.
 *
 * Плагин Simla уже умеет списывать баллы, но выводит форму служебной вёрсткой
 * и только в классической корзине, а строку «будет начислено» — под способами
 * оплаты. Здесь то же самое собрано в оформлении кабинета: списание, отмена
 * списания и начисление рядом с итогом.
 *
 * Механика списания намеренно повторяет плагин Simla: купон с кодом loyalty<число>
 * на сумму, равную числу баллов. Именно такой купон Simla снимает при создании
 * заказа и превращает в списание баллов в CRM.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Списание и начисление баллов в корзине.
 */
class VL_Account_RetailCRM_Cart {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_RetailCRM_Cart|null
	 */
	private static $instance = null;

	/**
	 * Кэш расчётов CRM в рамках запроса.
	 *
	 * @var array
	 */
	private static $calc = array();

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_RetailCRM_Cart
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
		add_shortcode( 'vl_loyalty_cart', array( $this, 'shortcode' ) );

		add_action( 'wp_ajax_vlacc_loyalty_charge', array( $this, 'ajax_charge' ) );
		add_action( 'wp_ajax_vlacc_loyalty_charge_cancel', array( $this, 'ajax_cancel' ) );

		// Технический адрес в ограничении купона делает купон неприменимым.
		add_filter( 'woocommerce_coupon_get_email_restrictions', array( $this, 'fix_email_restrictions' ), 10, 2 );

		add_action( 'wp', array( $this, 'setup_output' ) );
	}

	/**
	 * Расстановка вывода: наш блок вместо стандартного или рядом с ним.
	 */
	public function setup_output() {
		if ( ! VL_Account_RetailCRM::loyalty_active() || ! vlacc_is_woo() ) {
			return;
		}

		$mode = VL_Account_Settings::get( 'crm_cart_widget', 'replace' );

		if ( 'off' !== $mode ) {
			if ( 'replace' === $mode ) {
				self::remove_crm_action( 'woocommerce_cart_coupon', 'coupon_info' );
			}

			add_action( 'woocommerce_cart_coupon', array( $this, 'render_widget' ), 12 );
		}

		if ( VL_Account_Settings::get( 'crm_credit_top', 1 ) ) {
			// «Информацию о начислении баллов поднять выше» — к строке итога.
			self::remove_crm_action( 'woocommerce_review_order_before_payment', 'reviewCreditBonus' );

			add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'render_credit_row' ) );
			add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_credit_row' ) );
		}
	}

	/**
	 * Снять обработчик плагина Simla с хука.
	 *
	 * Ищем по объекту-обработчику, а не по приоритету: так снятие не сломается,
	 * если Simla поменяет приоритет в следующей версии.
	 *
	 * @param string $hook   Хук.
	 * @param string $method Метод интеграции.
	 * @return bool
	 */
	protected static function remove_crm_action( $hook, $method ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return false;
		}

		$removed = false;

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_array( $callback['function'] ) || count( $callback['function'] ) !== 2 ) {
					continue;
				}

				list( $object, $callback_method ) = $callback['function'];

				if ( ! is_object( $object ) || ! $object instanceof WC_Retailcrm_Base ) {
					continue;
				}

				if ( $callback_method !== $method ) {
					continue;
				}

				remove_action( $hook, $callback['function'], $priority );

				$removed = true;
			}
		}

		return $removed;
	}

	/* ------------------------------------------------------------------
	 * Расчёт
	 * ------------------------------------------------------------------ */

	/**
	 * Расчёт скидок и баллов по текущей корзине.
	 *
	 * @param float $bonuses Сколько баллов планируем списать.
	 * @return array|null ['max' => float, 'credit' => float, 'level_discount' => float, 'charge_rate' => float]
	 */
	public static function calculate( $bonuses = 0 ) {
		if ( ! VL_Account_RetailCRM::loyalty_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		$user_id = get_current_user_id();
		$items   = WC()->cart->get_cart();

		if ( ! $user_id || ! $items ) {
			return null;
		}

		$account = VL_Account_RetailCRM::account( $user_id );

		if ( 'active' !== $account['status'] ) {
			return null;
		}

		$site = VL_Account_RetailCRM::site();

		if ( '' === $site ) {
			return null;
		}

		$signature = array();

		foreach ( $items as $cart_key => $item ) {
			$signature[] = $cart_key . ':' . ( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
		}

		$key = md5( $user_id . '|' . implode( '|', $signature ) . '|' . $bonuses );

		if ( isset( self::$calc[ $key ] ) ) {
			return self::$calc[ $key ];
		}

		$loyalty = VL_Account_RetailCRM::loyalty();

		if ( ! $loyalty ) {
			return null;
		}

		$response = $loyalty->calculateDiscountLoyalty( $items, $site, $user_id, (float) $bonuses );

		if ( ! $response instanceof WC_Retailcrm_Response || ! $response->isSuccessful() ) {
			return null;
		}

		$result = array(
			'max'            => 0.0,
			'credit'         => 0.0,
			'level_discount' => 0.0,
			'charge_rate'    => 1.0,
			'currency'       => $account['currency'],
		);

		// Автоматическая скидка уровня: баллы в таком заказе не списываются.
		if ( isset( $response['order']['items'] ) ) {
			foreach ( (array) $response['order']['items'] as $item ) {
				if ( empty( $item['discounts'] ) ) {
					continue;
				}

				foreach ( (array) $item['discounts'] as $discount ) {
					if ( isset( $discount['type'] ) && 'loyalty_level' === $discount['type'] ) {
						$result['level_discount'] += (float) $discount['amount'];
					}
				}
			}
		}

		if ( isset( $response['order']['bonusesCreditTotal'] ) ) {
			$result['credit'] = (float) $response['order']['bonusesCreditTotal'];
		}

		if ( isset( $response['loyalty']['chargeRate'] ) && $response['loyalty']['chargeRate'] > 0 ) {
			$result['charge_rate'] = (float) $response['loyalty']['chargeRate'];
		}

		if ( 0.0 === $result['level_discount'] && isset( $response['calculations'] ) ) {
			foreach ( (array) $response['calculations'] as $calculation ) {
				if ( ! isset( $calculation['privilegeType'] ) || 'loyalty_level' !== $calculation['privilegeType'] ) {
					continue;
				}

				$result['max'] = isset( $calculation['maxChargeBonuses'] ) ? (float) $calculation['maxChargeBonuses'] : 0.0;
			}
		}

		self::$calc[ $key ] = $result;

		return $result;
	}

	/**
	 * Применённый купон списания баллов.
	 *
	 * @return WC_Coupon|null
	 */
	public static function applied_coupon() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( VL_Account_RetailCRM_Promo::is_loyalty_coupon( $code ) ) {
				return new WC_Coupon( $code );
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------
	 * Вывод
	 * ------------------------------------------------------------------ */

	/**
	 * Данные для шаблона виджета.
	 *
	 * @return array|null
	 */
	public static function widget_data() {
		if ( ! is_user_logged_in() || ! VL_Account_RetailCRM::loyalty_active() ) {
			return null;
		}

		$user_id = get_current_user_id();
		$account = VL_Account_RetailCRM::account( $user_id );

		if ( 'active' !== $account['status'] ) {
			return null;
		}

		// При уровне со скидкой Simla тоже создаёт купон loyalty*, но в нём сумма скидки,
		// а не списанные баллы — за списание его принимать нельзя.
		$coupon = VL_Account_RetailCRM_Loyalty::is_discount_level( $account ) ? null : self::applied_coupon();
		$used   = $coupon ? (float) $coupon->get_amount() : 0.0;

		// Максимум считаем «с чистого листа», начисление — с учётом планируемого списания.
		$base = self::calculate( 0 );

		if ( null === $base ) {
			return null;
		}

		$calc = $used > 0 ? self::calculate( $used ) : $base;
		$calc = null === $calc ? $base : $calc;

		return array(
			'balance'  => (float) $account['amount'],
			'max'      => min( (float) $account['amount'], (float) $base['max'] ),
			'used'     => $used,
			'credit'   => (float) $calc['credit'],
			'discount' => (float) $base['level_discount'],
			'currency' => $account['currency'],
			'level'    => $account['level'],
		);
	}

	/**
	 * Шорткод [vl_loyalty_cart].
	 *
	 * @return string
	 */
	public function shortcode() {
		$data = self::widget_data();

		if ( null === $data ) {
			return '';
		}

		return vlacc_template( 'parts/loyalty-cart.php', array( 'lp' => $data ), true );
	}

	/**
	 * Вывод виджета в корзине.
	 */
	public function render_widget() {
		// В режиме замены мы сняли обработчик Simla с этого хука. Его логику всё равно
		// нужно выполнить: она сама применяет купон автоматической скидки уровня,
		// если покупатель вошёл в аккаунт уже с наполненной корзиной.
		if ( 'replace' === VL_Account_Settings::get( 'crm_cart_widget', 'replace' ) ) {
			$loyalty = VL_Account_RetailCRM::loyalty();

			if ( $loyalty ) {
				try {
					$loyalty->processingLoyaltyCoupon();
				} catch ( Throwable $e ) {
					VL_Account_RetailCRM::log( 'Ошибка обработки купона лояльности', array( 'error' => $e->getMessage() ) );
				}
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- вывод собирается в шаблоне.
		echo $this->shortcode();
	}

	/**
	 * Строка «будет начислено» рядом с итогом.
	 */
	public function render_credit_row() {
		$data = self::widget_data();

		if ( null === $data || $data['credit'] <= 0 ) {
			return;
		}

		printf(
			'<tr class="vl-loyalty-credit"><th>%s</th><td data-title="%s">%s</td></tr>',
			esc_html__( 'Начислим баллов', 'vl-account' ),
			esc_attr__( 'Начислим баллов', 'vl-account' ),
			esc_html( number_format_i18n( $data['credit'] ) )
		);
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Проверка запроса.
	 *
	 * @return int ID пользователя.
	 */
	protected function guard() {
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

		if ( ! VL_Account_RetailCRM::loyalty_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Списание баллов сейчас недоступно.', 'vl-account' ) ) );
		}

		return (int) $user_id;
	}

	/**
	 * Списать баллы: создаём купон и применяем его к корзине.
	 */
	public function ajax_charge() {
		$user_id = $this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен в guard().
		$amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
		$amount = floor( $amount );

		if ( $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Укажите, сколько баллов списать.', 'vl-account' ) ) );
		}

		// Старое списание убираем, иначе Simla удалит оба купона как дубли.
		$this->remove_coupon();

		$data = self::widget_data();

		if ( null === $data ) {
			wp_send_json_error( array( 'message' => __( 'Списание баллов сейчас недоступно.', 'vl-account' ) ) );
		}

		if ( $data['discount'] > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'В этом заказе уже действует скидка по программе лояльности — баллы списать нельзя.', 'vl-account' ),
				)
			);
		}

		$max = floor( $data['max'] );

		if ( $max <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Для этой корзины списание баллов недоступно.', 'vl-account' ) ) );
		}

		if ( $amount > $max ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s — максимум баллов. */
						__( 'Можно списать не больше %s баллов.', 'vl-account' ),
						number_format_i18n( $max )
					),
					'max'     => $max,
				)
			);
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( 'loyalty' . wp_rand() );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( 1 );
		$coupon->set_individual_use( false );
		$coupon->set_description( __( 'Списание баллов программы лояльности', 'vl-account' ) );

		$email = self::customer_email( $user_id );

		if ( '' !== $email ) {
			$coupon->set_email_restrictions( array( $email ) );
		}

		$coupon->save();

		$applied = WC()->cart->apply_coupon( $coupon->get_code() );

		if ( ! $applied ) {
			$coupon->delete( true );

			wp_send_json_error( array( 'message' => __( 'Не удалось применить баллы к корзине.', 'vl-account' ) ) );
		}

		WC()->cart->calculate_totals();

		do_action( 'vlacc_loyalty_charged', $user_id, $amount );

		vlacc_log(
			'Списание баллов в корзине',
			array(
				'user_id' => $user_id,
				'amount'  => $amount,
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s — количество баллов. */
					__( 'Списываем %s баллов.', 'vl-account' ),
					number_format_i18n( $amount )
				),
				'reload'  => true,
			)
		);
	}

	/**
	 * Отменить списание баллов.
	 */
	public function ajax_cancel() {
		$this->guard();

		$this->remove_coupon();

		wp_send_json_success(
			array(
				'message' => __( 'Списание отменено.', 'vl-account' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Убрать купон списания из корзины и удалить его.
	 */
	protected function remove_coupon() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( ! VL_Account_RetailCRM_Promo::is_loyalty_coupon( $code ) ) {
				continue;
			}

			WC()->cart->remove_coupon( $code );

			$coupon = new WC_Coupon( $code );

			if ( $coupon->get_id() ) {
				$coupon->delete( true );
			}
		}

		WC()->cart->calculate_totals();
	}

	/**
	 * E-mail покупателя для ограничения купона.
	 *
	 * Технический адрес вида 79001234567@phone.site не подходит: на оформлении
	 * покупатель укажет настоящую почту, и купон перестанет действовать.
	 *
	 * @param int $user_id Пользователь.
	 * @return string
	 */
	protected static function customer_email( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! VL_Account_User::has_real_email( $user ) ) {
			return '';
		}

		$billing = get_user_meta( $user_id, 'billing_email', true );

		if ( $billing && is_email( $billing ) ) {
			return $billing;
		}

		return $user->user_email;
	}

	/**
	 * Убрать технические адреса из ограничений купона.
	 *
	 * @param array     $emails Адреса.
	 * @param WC_Coupon $coupon Купон.
	 * @return array
	 */
	public function fix_email_restrictions( $emails, $coupon = null ) {
		if ( ! VL_Account_Settings::get( 'crm_fix_coupon_email', 1 ) || ! is_array( $emails ) || ! $emails ) {
			return $emails;
		}

		$clean = array();

		foreach ( $emails as $email ) {
			if ( is_string( $email ) && preg_match( '/@phone\./', $email ) ) {
				continue;
			}

			$clean[] = $email;
		}

		return $clean;
	}
}
