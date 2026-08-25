<?php
/**
 * AJAX-обработчики форм.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX.
 */
class VL_Account_Ajax {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Ajax|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Ajax
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
		$public = array(
			'send_code',
			'verify_code',
			'login_password',
			'lost_password',
			'reset_password',
			'check_email',
			'wishlist_toggle',
			'wishlist_count',
		);

		$private = array(
			'profile_save',
			'resend_email',
			'password_save',
			'consents_save',
			'wishlist_remove',
		);

		foreach ( $public as $action ) {
			add_action( 'wp_ajax_nopriv_vlacc_' . $action, array( $this, 'handle_' . $action ) );
			add_action( 'wp_ajax_vlacc_' . $action, array( $this, 'handle_' . $action ) );
		}

		foreach ( $private as $action ) {
			add_action( 'wp_ajax_vlacc_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Проверка nonce и антибот-поля.
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

		// Honeypot: скрытое поле должно оставаться пустым.
		if ( ! empty( $_POST['vlacc_hp'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Не получилось отправить форму.', 'vl-account' ) ), 400 );
		}
	}

	/**
	 * Значение из POST.
	 *
	 * @param string $key     Ключ.
	 * @param string $default Значение по умолчанию.
	 * @return string
	 */
	protected function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется в guard().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/**
	 * Отправка кода подтверждения.
	 */
	public function handle_send_code() {
		$this->guard();

		$phone   = $this->post( 'phone' );
		$purpose = $this->post( 'purpose', 'login' );

		if ( ! in_array( $purpose, array( 'login', 'register', 'reset', 'checkout' ), true ) ) {
			$purpose = 'login';
		}

		if ( ! VL_Account_Phone::is_valid( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Проверьте, правильно ли указан номер телефона.', 'vl-account' ) ) );
		}

		$normalized = VL_Account_Phone::normalize( $phone );
		$user       = VL_Account_User::get_by_phone( $normalized );

		// Вход по коду для незарегистрированного номера, если самостоятельная регистрация выключена.
		if ( ! $user && 'reset' === $purpose ) {
			wp_send_json_error( array( 'message' => __( 'Аккаунт с таким номером не найден.', 'vl-account' ) ) );
		}

		if ( ! $user && 'login' === $purpose && ! VL_Account_Settings::get( 'auto_register', 1 ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Аккаунт с таким номером не найден. Зарегистрируйтесь — это займёт минуту.', 'vl-account' ),
					'register' => true,
				)
			);
		}

		$result = VL_Account_OTP::send( $normalized, $purpose );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'wait'    => isset( $data['wait'] ) ? (int) $data['wait'] : 0,
				)
			);
		}

		$result['phone_formatted'] = VL_Account_Phone::format( $normalized );
		$result['phone']           = $normalized;
		$result['exists']          = (bool) $user;

		wp_send_json_success( $result );
	}

	/**
	 * Проверка кода: вход или переход к регистрации.
	 */
	public function handle_verify_code() {
		$this->guard();

		$phone   = VL_Account_Phone::normalize( $this->post( 'phone' ) );
		$code    = $this->post( 'code' );
		$purpose = $this->post( 'purpose', 'login' );

		if ( ! in_array( $purpose, array( 'login', 'register', 'reset', 'checkout' ), true ) ) {
			$purpose = 'login';
		}

		$verified = VL_Account_OTP::verify( $phone, $code, $purpose );

		if ( is_wp_error( $verified ) ) {
			wp_send_json_error( array( 'message' => $verified->get_error_message() ) );
		}

		$user = VL_Account_User::get_by_phone( $phone );

		// Восстановление доступа по SMS: пускаем в кабинет и просим задать пароль.
		if ( 'reset' === $purpose ) {
			if ( ! $user ) {
				wp_send_json_error( array( 'message' => __( 'Аккаунт с таким номером не найден.', 'vl-account' ) ) );
			}

			VL_Account_Auth::login_user( $user->ID, true );

			wp_send_json_success(
				array(
					'logged_in' => true,
					'message'   => __( 'Готово! Задайте новый пароль в разделе «Пароль и вход».', 'vl-account' ),
					'redirect'  => VL_Account_Router::url( 'security' ),
				)
			);
		}

		// Код подтверждён — можно искать старый аккаунт этого человека
		// (по заказам сайта и базе покупателей CRM) и объединять дубли.
		$user = VL_Account_Identity::prepare_login( $user, $phone );

		// Имя из формы: заполняем только там, где имени ещё нет.
		$name = VL_Account_User::sanitize_name( $this->post( 'name' ) );

		if ( $user ) {
			VL_Account_User::maybe_set_name( $user->ID, $name );

			// Логин-номер у аккаунтов, заведённых до появления поля «Имя»,
			// меняем здесь: кука выдаётся ниже, уже с новым логином.
			VL_Account_User::maybe_rename_login( $user->ID );

			update_user_meta( $user->ID, VL_Account_User::META_VERIFIED, current_time( 'mysql' ) );
			VL_Account_Auth::login_user( $user->ID, true );

			wp_send_json_success(
				array(
					'logged_in' => true,
					'message'   => __( 'Вы вошли в личный кабинет.', 'vl-account' ),
					'redirect'  => vlacc_redirect_url(),
				)
			);
		}

		// Номера в базе нет — заводим аккаунт прямо здесь, без второго шага.
		if ( ! VL_Account_Settings::get( 'auto_register', 1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Аккаунт с таким номером не найден.', 'vl-account' ) ) );
		}

		$consents = array( 'privacy' => true );

		if ( VL_Account_Settings::get( 'consent_marketing', 1 ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен в guard().
			$consents['marketing'] = ! empty( $_POST['consent_marketing'] );
		}

		$user_id = VL_Account_User::create(
			array(
				'phone'      => $phone,
				'first_name' => $name,
				'verified'   => true,
				'consents'   => $consents,
				'source'     => 'sms_one_step',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		// Новый аккаунт сразу наполняем тем, что о покупателе знает CRM,
		// и привязываем его прежние гостевые заказы.
		VL_Account_Identity::after_register( $user_id, $phone );

		VL_Account_Auth::login_user( $user_id, true );

		wp_send_json_success(
			array(
				'logged_in' => true,
				'created'   => true,
				'message'   => __( 'Готово! Личный кабинет создан, вы вошли.', 'vl-account' ),
				'redirect'  => vlacc_redirect_url(),
			)
		);
	}


	/**
	 * Собрать согласия из формы.
	 *
	 * @return array
	 */
	protected function collect_consents() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce проверен в guard().
		$consents = array();

		if ( VL_Account_Settings::get( 'consent_privacy', 1 ) ) {
			$consents['privacy'] = ! empty( $_POST['consent_privacy'] );
		}

		if ( VL_Account_Settings::get( 'consent_marketing', 1 ) ) {
			$consents['marketing'] = ! empty( $_POST['consent_marketing'] );
		}
		// phpcs:enable

		return $consents;
	}

	/**
	 * Вход по паролю (запасной сценарий).
	 */
	public function handle_login_password() {
		$this->guard();

		$login    = $this->post( 'login' );
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $login ) {
			wp_send_json_error( array( 'message' => VL_Account_Errors::message( 'empty_username' ) ) );
		}

		if ( '' === $password ) {
			wp_send_json_error( array( 'message' => VL_Account_Errors::message( 'empty_password' ) ) );
		}

		// Телефон приводим к логину пользователя.
		$user = VL_Account_User::get_by_login( $login );

		$creds = array(
			'user_login'    => $user ? $user->user_login : $login,
			'user_password' => $password,
			'remember'      => true,
		);

		$signon = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $signon ) ) {
			wp_send_json_error( array( 'message' => VL_Account_Errors::message( $signon->get_error_code(), wp_strip_all_tags( $signon->get_error_message() ) ) ) );
		}

		wp_set_current_user( $signon->ID );

		wp_send_json_success(
			array(
				'logged_in' => true,
				'redirect'  => vlacc_redirect_url(),
			)
		);
	}

	/**
	 * Восстановление пароля: письмо на e-mail либо код в SMS.
	 */
	public function handle_lost_password() {
		$this->guard();

		$login = $this->post( 'login' );

		if ( '' === $login ) {
			wp_send_json_error( array( 'message' => __( 'Укажите e-mail или телефон.', 'vl-account' ) ) );
		}

		// Телефон — отправляем код.
		if ( ! is_email( $login ) && VL_Account_Phone::is_valid( $login ) ) {
			$phone = VL_Account_Phone::normalize( $login );
			$user  = VL_Account_User::get_by_phone( $phone );

			if ( ! $user ) {
				wp_send_json_error( array( 'message' => __( 'Аккаунт с таким номером не найден.', 'vl-account' ) ) );
			}

			$sent = VL_Account_OTP::send( $phone, 'reset' );

			if ( is_wp_error( $sent ) ) {
				wp_send_json_error( array( 'message' => $sent->get_error_message() ) );
			}

			$sent['mode']            = 'sms';
			$sent['phone']           = $phone;
			$sent['phone_formatted'] = VL_Account_Phone::format( $phone );

			wp_send_json_success( $sent );
		}

		$user = VL_Account_User::get_by_login( $login );

		if ( ! $user ) {
			// Не раскрываем, есть ли аккаунт.
			wp_send_json_success(
				array(
					'mode'    => 'email',
					'message' => __( 'Если такой аккаунт есть, письмо со ссылкой уже в пути. Проверьте почту, в том числе папку «Спам».', 'vl-account' ),
				)
			);
		}

		$result = retrieve_password( $user->user_login );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => VL_Account_Errors::message( $result->get_error_code(), wp_strip_all_tags( $result->get_error_message() ) ) ) );
		}

		wp_send_json_success(
			array(
				'mode'    => 'email',
				'message' => sprintf(
					/* translators: %s — маскированный e-mail. */
					__( 'Письмо со ссылкой отправлено на %s. Проверьте почту, в том числе папку «Спам».', 'vl-account' ),
					vlacc_mask_email( $user->user_email )
				),
			)
		);
	}

	/**
	 * Установка нового пароля после подтверждения по SMS.
	 */
	public function handle_reset_password() {
		$this->guard();

		$phone     = VL_Account_Phone::normalize( $this->post( 'phone' ) );
		$code      = $this->post( 'code' );
		$password  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password2 = isset( $_POST['password2'] ) ? (string) wp_unslash( $_POST['password2'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Пароль должен быть не короче 8 символов.', 'vl-account' ) ) );
		}

		if ( $password !== $password2 ) {
			wp_send_json_error( array( 'message' => __( 'Пароли не совпадают.', 'vl-account' ) ) );
		}

		$verified = VL_Account_OTP::verify( $phone, $code, 'reset' );

		if ( is_wp_error( $verified ) ) {
			wp_send_json_error( array( 'message' => $verified->get_error_message() ) );
		}

		$user = VL_Account_User::get_by_phone( $phone );

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Аккаунт с таким номером не найден.', 'vl-account' ) ) );
		}

		wp_set_password( $password, $user->ID );
		delete_user_meta( $user->ID, VL_Account_User::META_NOPASS );

		VL_Account_Auth::login_user( $user->ID, true );

		wp_send_json_success(
			array(
				'logged_in' => true,
				'message'   => __( 'Пароль обновлён, вы вошли в кабинет.', 'vl-account' ),
				'redirect'  => VL_Account_Settings::account_url(),
			)
		);
	}

	/**
	 * Проверка e-mail: есть ли уже аккаунт (используется формой регистрации и подписки).
	 */
	public function handle_check_email() {
		$this->guard();

		$email = sanitize_email( $this->post( 'email' ) );

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Проверьте, правильно ли указан e-mail.', 'vl-account' ) ) );
		}

		$exists = (bool) email_exists( $email );

		wp_send_json_success(
			array(
				'exists'  => $exists,
				'message' => $exists
					? __( 'Такой e-mail уже зарегистрирован. Войдите в кабинет — так удобнее.', 'vl-account' )
					: '',
			)
		);
	}

	/**
	 * Сохранение профиля в кабинете.
	 */
	public function handle_profile_save() {
		$this->guard();

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Сначала войдите в кабинет.', 'vl-account' ) ) );
		}

		$first_name = $this->post( 'first_name' );
		$last_name  = $this->post( 'last_name' );
		$telegram   = $this->post( 'telegram' );
		$email      = sanitize_email( $this->post( 'email' ) );

		if ( $email && ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Проверьте, правильно ли указан e-mail.', 'vl-account' ) ) );
		}

		if ( $email ) {
			$owner = email_exists( $email );

			if ( $owner && (int) $owner !== $user_id ) {
				wp_send_json_error( array( 'message' => __( 'Этот e-mail уже занят другим аккаунтом.', 'vl-account' ) ) );
			}
		}

		$update = array(
			'ID'           => $user_id,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
		);

		if ( '' === $update['display_name'] ) {
			unset( $update['display_name'] );
		}

		$result = wp_update_user( $update );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $result->get_error_message() ) ) );
		}

		update_user_meta( $user_id, 'billing_first_name', $first_name );
		update_user_meta( $user_id, 'billing_last_name', $last_name );
		update_user_meta( $user_id, VL_Account_User::META_TELEGRAM, VL_Account_User::sanitize_telegram( $telegram ) );

		$message = __( 'Изменения сохранены.', 'vl-account' );
		$user    = get_user_by( 'id', $user_id );

		// E-mail меняем только через подтверждение по ссылке из письма.
		if ( $email && strtolower( $user->user_email ) !== strtolower( $email ) ) {
			$requested = VL_Account_Email_Confirm::request_and_send( $user_id, $email );

			if ( is_wp_error( $requested ) ) {
				// Остальные поля уже сохранены — сообщаем именно про письмо.
				wp_send_json_error(
					array(
						'message' => $requested->get_error_message(),
						'saved'   => true,
					)
				);
			}

			$message = sprintf(
				/* translators: %s — адрес электронной почты. */
				__( 'Сохранили. Мы отправили письмо на %s — перейдите по ссылке из него, чтобы привязать адрес к кабинету.', 'vl-account' ),
				$email
			);
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Повторно отправить письмо с подтверждением e-mail.
	 */
	public function handle_resend_email() {
		$this->guard();

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Сначала войдите в кабинет.', 'vl-account' ) ) );
		}

		$email = VL_Account_Email_Confirm::pending( $user_id );

		if ( ! $email ) {
			wp_send_json_error( array( 'message' => __( 'Подтверждать нечего — укажите e-mail в поле выше и сохраните.', 'vl-account' ) ) );
		}

		if ( ! VL_Account_Email_Confirm::send( $user_id, true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Письмо отправить не удалось — почта сайта не работает. Напишите нам, мы привяжем адрес вручную.', 'vl-account' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s — адрес электронной почты. */
					__( 'Письмо отправлено на %s. Проверьте почту, в том числе папку «Спам».', 'vl-account' ),
					$email
				),
			)
		);
	}

	/**
	 * Смена пароля в кабинете.
	 */
	public function handle_password_save() {
		$this->guard();

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Сначала войдите в кабинет.', 'vl-account' ) ) );
		}

		$current   = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password2 = isset( $_POST['password2'] ) ? (string) wp_unslash( $_POST['password2'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$user = get_user_by( 'id', $user_id );

		// Если пароль уже был задан — просим ввести текущий.
		if ( VL_Account_User::has_password( $user_id ) ) {
			if ( '' === $current || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Текущий пароль указан неверно.', 'vl-account' ) ) );
			}
		}

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Пароль должен быть не короче 8 символов.', 'vl-account' ) ) );
		}

		if ( $password !== $password2 ) {
			wp_send_json_error( array( 'message' => __( 'Пароли не совпадают.', 'vl-account' ) ) );
		}

		wp_set_password( $password, $user_id );
		delete_user_meta( $user_id, VL_Account_User::META_NOPASS );

		// wp_set_password разлогинивает — восстанавливаем сессию.
		VL_Account_Auth::login_user( $user_id, true );

		wp_send_json_success( array( 'message' => __( 'Пароль обновлён.', 'vl-account' ) ) );
	}

	/**
	 * Сохранение согласий в кабинете.
	 */
	public function handle_consents_save() {
		$this->guard();

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Сначала войдите в кабинет.', 'vl-account' ) ) );
		}

		VL_Account_User::save_consents( $user_id, $this->collect_consents() );

		wp_send_json_success( array( 'message' => __( 'Настройки сохранены.', 'vl-account' ) ) );
	}

	/**
	 * Добавить/убрать товар в избранном.
	 */
	public function handle_wishlist_toggle() {
		$this->guard();

		$product_id = absint( $this->post( 'product_id' ) );

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Товар не найден.', 'vl-account' ) ) );
		}

		$state = VL_Account_Wishlist::toggle( $product_id );

		wp_send_json_success(
			array(
				'in_list' => $state,
				'count'   => VL_Account_Wishlist::count(),
				'message' => $state
					? __( 'Добавили в избранное.', 'vl-account' )
					: __( 'Убрали из избранного.', 'vl-account' ),
			)
		);
	}

	/**
	 * Сколько товаров в избранном.
	 *
	 * Нужен внешним плагинам избранного (например, WishSuite): после их
	 * собственного AJAX счётчик в шапке должен показать общий список.
	 */
	public function handle_wishlist_count() {
		$this->guard();

		wp_send_json_success( array( 'count' => VL_Account_Wishlist::count() ) );
	}

	/**
	 * Удалить товар из избранного (в кабинете).
	 */
	public function handle_wishlist_remove() {
		$this->guard();

		$product_id = absint( $this->post( 'product_id' ) );

		VL_Account_Wishlist::remove( $product_id );

		wp_send_json_success(
			array(
				'count'   => VL_Account_Wishlist::count(),
				'message' => __( 'Убрали из избранного.', 'vl-account' ),
			)
		);
	}
}
