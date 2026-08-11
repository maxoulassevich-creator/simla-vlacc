<?php
/**
 * Русские тексты ошибок входа и регистрации.
 *
 * Задача из ТЗ: «Если есть ошибки при регистрации — они должны отображаться
 * на русском языке» (сейчас часть сообщений WordPress/WooCommerce выводится
 * по-английски или слишком технично).
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ошибки.
 */
class VL_Account_Errors {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Errors|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Errors
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
		add_filter( 'wp_login_errors', array( $this, 'filter_wp_errors' ), 20, 2 );
		add_filter( 'registration_errors', array( $this, 'filter_errors' ), 20 );
		add_filter( 'woocommerce_registration_errors', array( $this, 'filter_errors' ), 20 );
		add_filter( 'woocommerce_process_login_errors', array( $this, 'filter_errors' ), 20 );
		add_filter( 'shake_error_codes', array( $this, 'shake_codes' ) );
		add_filter( 'login_errors', array( $this, 'filter_login_html' ), 20 );
		add_filter( 'gettext', array( $this, 'translate_strings' ), 20, 3 );
	}

	/**
	 * Словарь: код ошибки -> русский текст.
	 *
	 * @return array
	 */
	public static function map() {
		return apply_filters(
			'vlacc_error_messages',
			array(
				'empty_username'          => __( 'Укажите e-mail или номер телефона.', 'vl-account' ),
				'empty_password'          => __( 'Введите пароль.', 'vl-account' ),
				'empty_email'             => __( 'Укажите e-mail.', 'vl-account' ),
				'invalid_username'        => __( 'Пользователь с таким логином не найден. Проверьте данные или зарегистрируйтесь.', 'vl-account' ),
				'invalid_email'           => __( 'Проверьте, правильно ли указан e-mail.', 'vl-account' ),
				'incorrect_password'      => __( 'Неверный пароль. Попробуйте ещё раз или восстановите доступ.', 'vl-account' ),
				'invalidcombo'            => __( 'Не нашли такой аккаунт. Проверьте e-mail или телефон.', 'vl-account' ),
				'username_exists'         => __( 'Такой логин уже занят.', 'vl-account' ),
				'email_exists'            => __( 'Аккаунт с таким e-mail уже зарегистрирован. Войдите или восстановите пароль.', 'vl-account' ),
				'registration-error-email-exists' => __( 'Аккаунт с таким e-mail уже зарегистрирован. Войдите или восстановите пароль.', 'vl-account' ),
				'existing_user_email'     => __( 'Аккаунт с таким e-mail уже зарегистрирован. Войдите или восстановите пароль.', 'vl-account' ),
				'existing_user_login'     => __( 'Такой логин уже занят.', 'vl-account' ),
				'user_login'              => __( 'Логин указан неверно.', 'vl-account' ),
				'password_reset_mismatch' => __( 'Пароли не совпадают.', 'vl-account' ),
				'password_reset_empty'    => __( 'Введите новый пароль.', 'vl-account' ),
				'expired_key'             => __( 'Срок действия ссылки истёк. Запросите восстановление пароля заново.', 'vl-account' ),
				'invalid_key'             => __( 'Ссылка недействительна. Запросите восстановление пароля заново.', 'vl-account' ),
				'too_many_retries'        => __( 'Слишком много попыток. Попробуйте позже.', 'vl-account' ),
				'authentication_failed'   => __( 'Неверный логин или пароль.', 'vl-account' ),
			)
		);
	}

	/**
	 * Текст по коду.
	 *
	 * @param string $code     Код ошибки.
	 * @param string $fallback Запасной текст.
	 * @return string
	 */
	public static function message( $code, $fallback = '' ) {
		$map = self::map();

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		return $fallback ? $fallback : __( 'Не получилось. Проверьте данные и попробуйте ещё раз.', 'vl-account' );
	}

	/**
	 * Заменяем тексты в объекте WP_Error.
	 *
	 * @param WP_Error $errors Ошибки.
	 * @return WP_Error
	 */
	public function filter_errors( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}

		$map = self::map();
		$new = new WP_Error();

		foreach ( $errors->get_error_codes() as $code ) {
			foreach ( $errors->get_error_messages( $code ) as $message ) {
				$new->add( $code, isset( $map[ $code ] ) ? $map[ $code ] : $this->clean_message( $message ) );
			}
		}

		return $new->has_errors() ? $new : $errors;
	}

	/**
	 * То же самое для формы входа WordPress.
	 *
	 * @param WP_Error $errors   Ошибки.
	 * @param string   $redirect Редирект.
	 * @return WP_Error
	 */
	public function filter_wp_errors( $errors, $redirect = '' ) {
		return $this->filter_errors( $errors );
	}

	/**
	 * Чистим служебные ссылки и HTML из сообщений WordPress.
	 *
	 * @param string $message Сообщение.
	 * @return string
	 */
	protected function clean_message( $message ) {
		$message = wp_strip_all_tags( (string) $message );
		$message = str_replace( array( 'ОШИБКА:', 'Error:', 'ERROR:' ), '', $message );

		return trim( $message );
	}

	/**
	 * HTML сообщений на wp-login.php.
	 *
	 * @param string $html Разметка.
	 * @return string
	 */
	public function filter_login_html( $html ) {
		$replacements = array(
			'The password you entered for the' => __( 'Неверный пароль.', 'vl-account' ),
			'Unknown username'                 => __( 'Пользователь не найден.', 'vl-account' ),
			'Invalid username'                 => __( 'Пользователь не найден.', 'vl-account' ),
		);

		foreach ( $replacements as $needle => $ru ) {
			if ( false !== strpos( $html, $needle ) ) {
				return $ru;
			}
		}

		return $html;
	}

	/**
	 * Коды, при которых форма «дрожит».
	 *
	 * @param array $codes Коды.
	 * @return array
	 */
	public function shake_codes( $codes ) {
		$codes[] = 'vlacc_wrong_code';
		$codes[] = 'vlacc_bad_phone';

		return $codes;
	}

	/**
	 * Точечный перевод самых частых английских строк WooCommerce/WordPress,
	 * которые остаются непереведёнными на боевых сайтах.
	 *
	 * @param string $translated Переведённая строка.
	 * @param string $original   Исходная строка.
	 * @param string $domain     Домен перевода.
	 * @return string
	 */
	public function translate_strings( $translated, $original, $domain ) {
		if ( is_admin() ) {
			return $translated;
		}

		static $dict = null;

		if ( null === $dict ) {
			$dict = array(
				'Password'                                                  => 'Пароль',
				'Confirm password'                                          => 'Подтвердить пароль',
				'Passwords do not match.'                                   => 'Пароли не совпадают.',
				'Please enter an account password.'                         => 'Введите пароль.',
				'Please provide a valid email address.'                     => 'Проверьте, правильно ли указан e-mail.',
				'Invalid email address.'                                    => 'Проверьте, правильно ли указан e-mail.',
				'An account is already registered with your email address.' => 'Аккаунт с таким e-mail уже зарегистрирован. Войдите или восстановите пароль.',
				'Error: Password is required.'                              => 'Введите пароль.',
				'Weak'                                                      => 'Слабый пароль',
				'Medium'                                                    => 'Средний пароль',
				'Strong'                                                    => 'Надёжный пароль',
				'Very weak'                                                 => 'Очень слабый пароль',
				'Hide password'                                             => 'Скрыть пароль',
				'Show password'                                             => 'Показать пароль',
				'The password should be at least twelve characters long. To make it stronger, use upper and lower case letters, numbers, and symbols like ! " ? $ % ^ &amp; ).' => 'Пароль должен быть не короче 8 символов. Используйте буквы разного регистра, цифры и символы.',
			);

			$dict = apply_filters( 'vlacc_gettext_dictionary', $dict );
		}

		if ( isset( $dict[ $original ] ) ) {
			return $dict[ $original ];
		}

		return $translated;
	}
}
