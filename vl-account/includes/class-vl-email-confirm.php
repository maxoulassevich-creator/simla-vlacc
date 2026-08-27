<?php
/**
 * Подтверждение e-mail.
 *
 * При входе по SMS у аккаунта нет почты — она появляется, когда покупатель
 * оформляет заказ или вписывает её в кабинете. Чужой адрес указать нельзя:
 * пока по ссылке из письма не подтвердили владение, e-mail к аккаунту
 * не привязывается, а лежит «в ожидании».
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Подтверждение почты.
 */
class VL_Account_Email_Confirm {

	const META_EMAIL = 'vlacc_pending_email';
	const META_HASH  = 'vlacc_pending_email_key';
	const META_SENT  = 'vlacc_pending_email_sent';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Email_Confirm|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Email_Confirm
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
		add_action( 'wp_loaded', array( $this, 'handle_link' ), 3 );

		if ( ! vlacc_is_woo() ) {
			return;
		}

		// Письмо-подтверждение уходит после стандартного письма о заказе.
		add_action( 'woocommerce_thankyou', array( $this, 'after_thankyou' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'after_status_change' ), 20, 4 );
	}

	/**
	 * Включено ли подтверждение почты.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) VL_Account_Settings::get( 'email_confirm', 1 );
	}

	/**
	 * Ожидающий подтверждения адрес.
	 *
	 * @param int $user_id Пользователь.
	 * @return string
	 */
	public static function pending( $user_id ) {
		$email = get_user_meta( $user_id, self::META_EMAIL, true );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Запомнить адрес и подготовить ссылку подтверждения.
	 *
	 * Само письмо отправляется отдельно (send), чтобы оно приходило
	 * после стандартного письма о заказе.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $email   Адрес.
	 * @return true|WP_Error
	 */
	public static function request( $user_id, $email ) {
		$email = sanitize_email( $email );
		$user  = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'vlacc_no_user', __( 'Пользователь не найден.', 'vl-account' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'vlacc_bad_email', __( 'Проверьте, правильно ли указан e-mail.', 'vl-account' ) );
		}

		// Уже привязан к этому же аккаунту — подтверждать нечего.
		if ( strtolower( $user->user_email ) === strtolower( $email ) ) {
			return new WP_Error( 'vlacc_same_email', __( 'Этот адрес уже привязан к вашему аккаунту.', 'vl-account' ) );
		}

		$owner = email_exists( $email );

		if ( $owner && (int) $owner !== (int) $user_id ) {
			return new WP_Error( 'vlacc_email_taken', __( 'Этот e-mail уже используется другим аккаунтом.', 'vl-account' ) );
		}

		$key = wp_generate_password( 32, false );

		update_user_meta( $user_id, self::META_EMAIL, $email );
		update_user_meta( $user_id, self::META_HASH, self::hash( $key, $user_id, $email ) );
		delete_user_meta( $user_id, self::META_SENT );

		// Ключ живёт в метаполе только до отправки письма.
		set_transient( 'vlacc_email_key_' . $user_id, $key, DAY_IN_SECONDS );

		return true;
	}

	/**
	 * Отправить письмо с подтверждением, если есть что подтверждать.
	 *
	 * @param int  $user_id Пользователь.
	 * @param bool $force   Отправить повторно, даже если уже отправляли.
	 * @return bool
	 */
	public static function send( $user_id, $force = false ) {
		$email = self::pending( $user_id );

		if ( ! $email || ! self::enabled() ) {
			return false;
		}

		$sent = get_user_meta( $user_id, self::META_SENT, true );

		if ( $sent && ! $force ) {
			return false;
		}

		// Ключ мог истечь (например, письмо отправляем спустя сутки) — выпускаем новый.
		$key = get_transient( 'vlacc_email_key_' . $user_id );

		if ( ! $key ) {
			$key = wp_generate_password( 32, false );
			update_user_meta( $user_id, self::META_HASH, self::hash( $key, $user_id, $email ) );
		}

		delete_transient( 'vlacc_email_key_' . $user_id );

		$link = add_query_arg(
			array(
				'vlacc_confirm_email' => $key,
				'vlacc_uid'           => (int) $user_id,
			),
			VL_Account_Settings::account_url()
		);

		$user = get_user_by( 'id', $user_id );
		$name = $user && $user->first_name ? $user->first_name : __( 'Здравствуйте', 'vl-account' );

		$content  = '<p>' . sprintf(
			/* translators: %s — имя покупателя. */
			esc_html__( '%s, подтвердите, пожалуйста, свой e-mail.', 'vl-account' ),
			esc_html( $name )
		) . '</p>';
		$content .= '<p>' . esc_html__( 'Этот адрес вы указали при оформлении заказа. Как только подтвердите — он привяжется к личному кабинету: туда будут приходить статусы заказов, и по нему можно будет восстановить доступ.', 'vl-account' ) . '</p>';

		$color = VL_Account_Settings::get( 'accent_color', '#d40000' );

		$content .= sprintf(
			'<p style="margin:28px 0"><a href="%1$s" style="background:%2$s;color:#fff;text-decoration:none;display:inline-block;padding:14px 28px;font-size:13px;letter-spacing:.08em;text-transform:uppercase">%3$s</a></p>',
			esc_url( $link ),
			esc_attr( $color ),
			esc_html__( 'Подтвердить e-mail', 'vl-account' )
		);

		$content .= '<p style="color:#888;font-size:12px">' . esc_html__( 'Если кнопка не работает, скопируйте ссылку в адресную строку браузера:', 'vl-account' ) . '<br>' . esc_html( $link ) . '</p>';
		$content .= '<p style="color:#888;font-size:12px">' . esc_html__( 'Если вы не оформляли заказ на нашем сайте, просто удалите это письмо — без подтверждения адрес никуда не привяжется.', 'vl-account' ) . '</p>';

		$sent = VL_Account_Emails::instance()->send(
			$email,
			sprintf(
				/* translators: %s — название сайта. */
				__( 'Подтвердите e-mail на сайте %s', 'vl-account' ),
				get_bloginfo( 'name' )
			),
			$content
		);

		if ( ! $sent ) {
			// Отметку об отправке не ставим: письмо можно будет отправить заново.
			vlacc_log(
				'Письмо с подтверждением e-mail не ушло',
				array(
					'user_id' => $user_id,
					'email'   => vlacc_mask_email( $email ),
					'error'   => VL_Account_Emails::instance()->last_error(),
				)
			);

			return false;
		}

		update_user_meta( $user_id, self::META_SENT, time() );

		vlacc_log(
			'Отправлено письмо с подтверждением e-mail',
			array(
				'user_id' => $user_id,
				'email'   => vlacc_mask_email( $email ),
			)
		);

		return true;
	}

	/**
	 * Запросить подтверждение и сразу отправить письмо.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $email   Адрес.
	 * @return true|WP_Error
	 */
	public static function request_and_send( $user_id, $email ) {
		$result = self::request( $user_id, $email );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! self::send( $user_id, true ) ) {
			return new WP_Error(
				'vlacc_mail_failed',
				__( 'Адрес сохранён, но письмо с подтверждением отправить не удалось — почта сайта не работает. Напишите нам, мы привяжем адрес вручную.', 'vl-account' )
			);
		}

		return true;
	}

	/**
	 * Отправка после страницы «спасибо за заказ».
	 *
	 * @param int $order_id Заказ.
	 */
	public function after_thankyou( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order && $order->get_customer_id() ) {
			self::send( $order->get_customer_id() );
		}
	}

	/**
	 * Отправка при смене статуса — страховка для платёжных систем
	 * с переходом на сторонний сайт, когда «спасибо» не открывается.
	 *
	 * @param int      $order_id Заказ.
	 * @param string   $from     Старый статус.
	 * @param string   $to       Новый статус.
	 * @param WC_Order $order    Заказ.
	 */
	public function after_status_change( $order_id, $from = '', $to = '', $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( $order && $order->get_customer_id() ) {
			self::send( $order->get_customer_id() );
		}
	}

	/**
	 * Переход по ссылке из письма.
	 */
	public function handle_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['vlacc_confirm_email'] ) || empty( $_GET['vlacc_uid'] ) ) {
			return;
		}

		$key     = sanitize_text_field( wp_unslash( $_GET['vlacc_confirm_email'] ) );
		$user_id = absint( $_GET['vlacc_uid'] );
		// phpcs:enable

		$redirect = remove_query_arg( array( 'vlacc_confirm_email', 'vlacc_uid' ) );
		$redirect = $redirect ? $redirect : VL_Account_Settings::account_url();

		$result = self::confirm( $user_id, $key );

		wp_safe_redirect(
			add_query_arg(
				'vlacc_notice',
				is_wp_error( $result ) ? 'email_failed' : 'email_confirmed',
				$redirect
			)
		);
		exit;
	}

	/**
	 * Подтвердить адрес.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $key     Ключ из ссылки.
	 * @return true|WP_Error
	 */
	public static function confirm( $user_id, $key ) {
		$email  = self::pending( $user_id );
		$stored = get_user_meta( $user_id, self::META_HASH, true );

		if ( ! $email || ! $stored ) {
			return new WP_Error( 'vlacc_nothing', __( 'Подтверждать нечего — ссылка устарела.', 'vl-account' ) );
		}

		$sent = (int) get_user_meta( $user_id, self::META_SENT, true );
		$ttl  = max( 1, (int) VL_Account_Settings::get( 'email_confirm_days', 7 ) ) * DAY_IN_SECONDS;

		if ( $sent && time() - $sent > $ttl ) {
			return new WP_Error( 'vlacc_expired', __( 'Срок действия ссылки истёк. Запросите письмо заново в личном кабинете.', 'vl-account' ) );
		}

		if ( ! hash_equals( (string) $stored, self::hash( $key, $user_id, $email ) ) ) {
			return new WP_Error( 'vlacc_bad_key', __( 'Ссылка недействительна.', 'vl-account' ) );
		}

		$owner = email_exists( $email );

		if ( $owner && (int) $owner !== (int) $user_id ) {
			self::forget( $user_id );
			return new WP_Error( 'vlacc_email_taken', __( 'Этот e-mail уже используется другим аккаунтом.', 'vl-account' ) );
		}

		$updated = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_user_meta( $user_id, 'billing_email', $email );
		update_user_meta( $user_id, 'vlacc_email_verified', current_time( 'mysql' ) );

		self::forget( $user_id );

		// Прошлые заказы с этим адресом подтягиваем в кабинет.
		VL_Account_Orders::attach_guest_orders( $user_id );

		do_action( 'vlacc_email_confirmed', $user_id, $email );

		vlacc_log(
			'E-mail подтверждён',
			array(
				'user_id' => $user_id,
				'email'   => vlacc_mask_email( $email ),
			)
		);

		return true;
	}

	/**
	 * За адресом стоит кто-то ещё.
	 *
	 * Проверка перед тем, как записать адрес без письма. Опасен не сам факт
	 * чужого адреса — опасно то, что к адресу привязаны чужие данные: гостевые
	 * заказы сайта подтянутся в кабинет, а карточка CRM с этим адресом отдаст
	 * чужую историю и баллы. Поэтому адрес, о котором сайту или CRM уже
	 * что-то известно, записывается только по ссылке из письма.
	 *
	 * @param string $email   Адрес.
	 * @param string $phone   Подтверждённый номер владельца аккаунта.
	 * @param int    $user_id Аккаунт.
	 * @return string Пусто, если адрес ничей; иначе причина.
	 */
	public static function email_belongs_to_someone( $email, $phone = '', $user_id = 0 ) {
		$email = strtolower( sanitize_email( $email ) );
		$phone = VL_Account_Phone::normalize( $phone );

		$owner = email_exists( $email );

		if ( $owner && (int) $owner !== (int) $user_id ) {
			return __( 'адрес занят другим аккаунтом', 'vl-account' );
		}

		// Гостевые заказы с этим адресом: если их оформлял не владелец номера,
		// адрес чужой — иначе его заказы уехали бы в чужой кабинет.
		if ( vlacc_is_woo() && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => 20,
					'type'          => 'shop_order',
					'return'        => 'objects',
				)
			);

			foreach ( (array) $orders as $order ) {
				if ( ! $order instanceof WC_Order || strtolower( $order->get_billing_email() ) !== $email ) {
					continue;
				}

				$customer_id = (int) $order->get_customer_id();

				if ( $customer_id && $customer_id !== (int) $user_id ) {
					return __( 'на адрес оформлены заказы другого покупателя', 'vl-account' );
				}

				if ( ! $customer_id && VL_Account_Phone::normalize( $order->get_billing_phone() ) !== $phone ) {
					// Телефона у аккаунта ещё нет — сравнивать не с чем, а
					// гостевой заказ на этот адрес уже есть: не присваиваем.
					return __( 'на адрес есть гостевые заказы с другим телефоном', 'vl-account' );
				}
			}
		}

		// Карточка CRM с этим адресом и другим номером — тоже чужая история.
		if ( class_exists( 'VL_Account_RetailCRM_Directory' ) ) {
			foreach ( VL_Account_RetailCRM_Directory::rows_by_email( $email ) as $row ) {
				$card_phone = isset( $row['phone'] ) ? (string) $row['phone'] : '';
				$external   = isset( $row['external_id'] ) ? (int) $row['external_id'] : 0;

				// Карточка этого же аккаунта или с этим же номером — своя.
				if ( ( $external && $external === (int) $user_id ) || ( '' !== $card_phone && $card_phone === $phone ) ) {
					continue;
				}

				// Всё остальное — чужая история: за адресом стоят заказы и
				// баллы другого человека, даже если телефона в карточке нет.
				return __( 'в CRM этот адрес принадлежит другому клиенту', 'vl-account' );
			}
		}

		return '';
	}

	/**
	 * Записать адрес сразу, без письма с подтверждением.
	 *
	 * Можно только тогда, когда адрес ничей: аккаунт заведён входом по SMS,
	 * своей почты у него не было, а введённый адрес сайту и CRM неизвестен.
	 * Если за адресом стоит чужая история — заказы, карточка CRM, другой
	 * аккаунт, — остаётся обычный путь с подтверждением по ссылке.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $email   Адрес.
	 * @return true|WP_Error
	 */
	public static function attach_now( $user_id, $email ) {
		$email = sanitize_email( $email );
		$user  = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'vlacc_no_user', __( 'Пользователь не найден.', 'vl-account' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'vlacc_bad_email', __( 'Проверьте, правильно ли указан e-mail.', 'vl-account' ) );
		}

		// У аккаунта уже есть настоящий адрес — такой меняют только по ссылке.
		if ( VL_Account_User::has_real_email( $user ) ) {
			return new WP_Error( 'vlacc_has_email', __( 'У аккаунта уже есть подтверждённый адрес.', 'vl-account' ) );
		}

		$taken = self::email_belongs_to_someone( $email, VL_Account_User::get_phone( $user_id ), $user_id );

		if ( '' !== $taken ) {
			vlacc_log(
				'Адрес из заказа отправлен на подтверждение по ссылке',
				array(
					'user_id' => $user_id,
					'email'   => vlacc_mask_email( $email ),
					'причина' => $taken,
				)
			);

			return new WP_Error( 'vlacc_email_taken', $taken );
		}

		$updated = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_user_meta( $user_id, 'billing_email', $email );
		update_user_meta( $user_id, 'vlacc_email_verified', current_time( 'mysql' ) );

		self::forget( $user_id );

		VL_Account_Orders::attach_guest_orders( $user_id );

		do_action( 'vlacc_email_confirmed', $user_id, $email );

		vlacc_log(
			'E-mail из оформления заказа записан без подтверждения',
			array(
				'user_id' => $user_id,
				'email'   => vlacc_mask_email( $email ),
			)
		);

		return true;
	}

	/**
	 * Подтвердить адрес вручную, без письма.
	 *
	 * Нужно администратору: например, письма с сайта не доходят,
	 * а покупателю надо привязать почту к кабинету.
	 *
	 * @param int $user_id Пользователь.
	 * @return true|WP_Error
	 */
	public static function force_confirm( $user_id ) {
		$email = self::pending( $user_id );

		if ( ! $email ) {
			return new WP_Error( 'vlacc_nothing', __( 'У этого пользователя нет адреса, ожидающего подтверждения.', 'vl-account' ) );
		}

		$owner = email_exists( $email );

		if ( $owner && (int) $owner !== (int) $user_id ) {
			return new WP_Error( 'vlacc_email_taken', __( 'Этот e-mail уже используется другим аккаунтом.', 'vl-account' ) );
		}

		$updated = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_user_meta( $user_id, 'billing_email', $email );
		update_user_meta( $user_id, 'vlacc_email_verified', current_time( 'mysql' ) );

		self::forget( $user_id );

		VL_Account_Orders::attach_guest_orders( $user_id );

		do_action( 'vlacc_email_confirmed', $user_id, $email );

		vlacc_log(
			'E-mail подтверждён вручную администратором',
			array(
				'user_id' => $user_id,
				'email'   => vlacc_mask_email( $email ),
				'admin'   => get_current_user_id(),
			)
		);

		return true;
	}

	/**
	 * Забыть ожидающий адрес.
	 *
	 * @param int $user_id Пользователь.
	 */
	public static function forget( $user_id ) {
		delete_user_meta( $user_id, self::META_EMAIL );
		delete_user_meta( $user_id, self::META_HASH );
		delete_user_meta( $user_id, self::META_SENT );
		delete_transient( 'vlacc_email_key_' . $user_id );
	}

	/**
	 * Хэш ключа подтверждения.
	 *
	 * @param string $key     Ключ.
	 * @param int    $user_id Пользователь.
	 * @param string $email   Адрес.
	 * @return string
	 */
	protected static function hash( $key, $user_id, $email ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'vl-account';

		return hash_hmac( 'sha256', $key . '|' . (int) $user_id . '|' . strtolower( $email ), $salt );
	}
}
