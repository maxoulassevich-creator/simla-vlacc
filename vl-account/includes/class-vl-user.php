<?php
/**
 * Работа с пользователями: поиск по телефону, создание, метаполя, согласия.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Пользователи.
 */
class VL_Account_User {

	const META_PHONE     = 'vlacc_phone';
	const META_VERIFIED  = 'vlacc_phone_verified';
	const META_TELEGRAM  = 'vlacc_telegram';
	const META_NOPASS    = 'vlacc_passwordless';
	const META_CONSENTS  = 'vlacc_consents';
	const META_MAGIC     = 'vlacc_magic_key';

	/**
	 * Найти пользователя по номеру телефона.
	 *
	 * Ищем и по нашему полю, и по billing_phone WooCommerce во всех вариантах записи.
	 *
	 * @param string $phone Номер.
	 * @return WP_User|false
	 */
	public static function get_by_phone( $phone ) {
		$normalized = VL_Account_Phone::normalize( $phone );

		if ( '' === $normalized ) {
			return false;
		}

		$found = false;

		// 1. Наше нормализованное поле — самый быстрый путь.
		$users = get_users(
			array(
				'meta_key'   => self::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $normalized,      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'all',
			)
		);

		if ( ! empty( $users[0] ) ) {
			$found = $users[0];
		}

		// 2. Телефон из биллинга WooCommerce в любом формате записи.
		$variants = $found ? array() : VL_Account_Phone::variants( $normalized );

		if ( $variants ) {
			$meta_query = array( 'relation' => 'OR' );

			foreach ( array( 'billing_phone', 'shipping_phone' ) as $meta_key ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => $variants,
					'compare' => 'IN',
				);
			}

			$users = get_users(
				array(
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'number'     => 1,
					'fields'     => 'all',
				)
			);

			if ( ! empty( $users[0] ) ) {
				// Подтягиваем номер в нормализованное поле, чтобы дальше искать быстро.
				update_user_meta( $users[0]->ID, self::META_PHONE, $normalized );
				$found = $users[0];
			}
		}

		// Поиск достраивается снаружи: заказы сайта и база покупателей CRM
		// знают телефон даже тогда, когда в профиле его нет (VL_Account_Identity).
		$found = apply_filters( 'vlacc_user_by_phone', $found, $normalized );

		return $found instanceof WP_User ? $found : false;
	}

	/**
	 * Найти пользователя по логину / e-mail / телефону.
	 *
	 * @param string $login Строка входа.
	 * @return WP_User|false
	 */
	public static function get_by_login( $login ) {
		$login = trim( (string) $login );

		if ( '' === $login ) {
			return false;
		}

		if ( is_email( $login ) ) {
			$user = get_user_by( 'email', $login );
			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', $login );
		if ( $user ) {
			return $user;
		}

		return self::get_by_phone( $login );
	}

	/**
	 * Свободный логин на основе телефона.
	 *
	 * @param string $phone Номер.
	 * @param string $email E-mail (запасной вариант).
	 * @return string
	 */
	protected static function build_username( $phone, $email = '' ) {
		$base = $phone ? $phone : ( $email ? sanitize_user( current( explode( '@', $email ) ), true ) : 'user' );
		$base = sanitize_user( $base, true );

		if ( '' === $base ) {
			$base = 'user';
		}

		$login = $base;
		$i     = 1;

		while ( username_exists( $login ) ) {
			$login = $base . '-' . $i;
			++$i;

			if ( $i > 100 ) {
				$login = $base . '-' . wp_generate_password( 5, false );
				break;
			}
		}

		return $login;
	}

	/**
	 * Создать пользователя.
	 *
	 * @param array $data {
	 *     @type string $phone       Телефон (нормализуется).
	 *     @type string $email       E-mail.
	 *     @type string $first_name  Имя.
	 *     @type string $last_name   Фамилия.
	 *     @type string $telegram    Telegram.
	 *     @type string $password    Пароль; пусто — генерируется случайный.
	 *     @type bool   $verified    Телефон подтверждён кодом.
	 *     @type array  $consents    Согласия.
	 *     @type string $source      Источник регистрации.
	 * }
	 * @return int|WP_Error ID пользователя.
	 */
	public static function create( $data ) {
		$defaults = array(
			'phone'      => '',
			'email'      => '',
			'first_name' => '',
			'last_name'  => '',
			'telegram'   => '',
			'password'   => '',
			'verified'   => false,
			'consents'   => array(),
			'source'     => 'form',
		);

		$data  = wp_parse_args( $data, $defaults );
		$phone = VL_Account_Phone::normalize( $data['phone'] );
		$email = sanitize_email( $data['email'] );

		// Имя из формы входа: телефон вместо имени не принимаем.
		$data['first_name'] = self::sanitize_name( $data['first_name'] );

		if ( '' === $phone && '' === $email ) {
			return new WP_Error( 'vlacc_no_identity', __( 'Нужен телефон или e-mail.', 'vl-account' ) );
		}

		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'vlacc_bad_email', __( 'Проверьте, правильно ли указан e-mail.', 'vl-account' ) );
		}

		if ( $email && email_exists( $email ) ) {
			return new WP_Error( 'vlacc_email_exists', __( 'Аккаунт с таким e-mail уже зарегистрирован. Войдите или восстановите пароль.', 'vl-account' ) );
		}

		if ( $phone && self::get_by_phone( $phone ) ) {
			return new WP_Error( 'vlacc_phone_exists', __( 'Аккаунт с таким номером уже зарегистрирован. Войдите по коду из SMS.', 'vl-account' ) );
		}

		// Если e-mail не обязателен и не указан — делаем технический адрес.
		if ( '' === $email ) {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			$domain = $domain ? preg_replace( '/^www\./', '', $domain ) : 'example.com';
			$email  = $phone . '@phone.' . $domain;
		}

		$password    = '' !== $data['password'] ? $data['password'] : wp_generate_password( 16, true );
		$passwordless = '' === $data['password'];

		$role = get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' );

		$display = trim( sanitize_text_field( $data['first_name'] ) . ' ' . sanitize_text_field( $data['last_name'] ) );

		if ( '' === $display ) {
			$display = $phone ? VL_Account_Phone::format( $phone ) : $email;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::build_username( $phone, $email ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => sanitize_text_field( $data['first_name'] ),
				'last_name'    => sanitize_text_field( $data['last_name'] ),
				'display_name' => $display,
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( $phone ) {
			update_user_meta( $user_id, self::META_PHONE, $phone );
			update_user_meta( $user_id, 'billing_phone', VL_Account_Phone::format( $phone ) );

			if ( $data['verified'] ) {
				update_user_meta( $user_id, self::META_VERIFIED, current_time( 'mysql' ) );
			}
		}

		if ( $data['telegram'] ) {
			update_user_meta( $user_id, self::META_TELEGRAM, self::sanitize_telegram( $data['telegram'] ) );
		}

		if ( $data['first_name'] ) {
			update_user_meta( $user_id, 'billing_first_name', sanitize_text_field( $data['first_name'] ) );
		}

		if ( $data['last_name'] ) {
			update_user_meta( $user_id, 'billing_last_name', sanitize_text_field( $data['last_name'] ) );
		}

		if ( ! preg_match( '/@phone\./', $email ) ) {
			update_user_meta( $user_id, 'billing_email', $email );
		}

		if ( $passwordless ) {
			update_user_meta( $user_id, self::META_NOPASS, 1 );
		}

		self::save_consents( $user_id, $data['consents'] );

		update_user_meta( $user_id, 'vlacc_source', sanitize_text_field( $data['source'] ) );

		do_action( 'vlacc_user_registered', $user_id, $data );

		vlacc_log(
			'Регистрация пользователя',
			array(
				'user_id' => $user_id,
				'phone'   => vlacc_mask_phone( $phone ),
				'source'  => $data['source'],
			)
		);

		return $user_id;
	}

	/**
	 * Имя покупателя из формы входа.
	 *
	 * Правило простое: своё имя покупателя не трогаем. Заполняем только там,
	 * где имени нет, — в том числе у аккаунтов, где вместо имени стоял номер
	 * телефона (так было до появления поля «Имя» в форме входа).
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $name    Имя из формы.
	 * @return bool Записали ли имя.
	 */
	public static function maybe_set_name( $user_id, $name ) {
		$user_id = (int) $user_id;
		$name    = self::sanitize_name( $name );

		if ( ! $user_id || '' === $name ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return false;
		}

		// Имя уже есть — это данные покупателя, перезаписывать их нельзя.
		if ( '' !== trim( (string) get_user_meta( $user_id, 'first_name', true ) ) ) {
			return false;
		}

		update_user_meta( $user_id, 'first_name', $name );

		if ( '' === trim( (string) get_user_meta( $user_id, 'billing_first_name', true ) ) ) {
			update_user_meta( $user_id, 'billing_first_name', $name );
		}

		// Отображаемое имя: до сих пор там стоял телефон или логин.
		if ( self::display_name_is_technical( $user ) ) {
			$last    = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
			$display = trim( $name . ' ' . $last );

			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $display,
					'nickname'     => $name,
				)
			);
		}

		vlacc_log(
			'Имя покупателя записано из формы входа',
			array(
				'user_id' => $user_id,
			)
		);

		// В CRM имя должно уехать сразу, иначе там останется номер телефона.
		if ( class_exists( 'VL_Account_RetailCRM_Customer' ) && class_exists( 'VL_Account_RetailCRM' ) && VL_Account_RetailCRM::enabled() ) {
			VL_Account_RetailCRM_Customer::push( $user_id, true );
		}

		return true;
	}

	/**
	 * Отображаемое имя — это на самом деле телефон или логин.
	 *
	 * @param WP_User $user Пользователь.
	 * @return bool
	 */
	protected static function display_name_is_technical( $user ) {
		$display = trim( (string) $user->display_name );

		if ( '' === $display ) {
			return true;
		}

		if ( $display === $user->user_login || $display === $user->user_email ) {
			return true;
		}

		// «+7 (926) 123-45-67» или «79261234567» — это не имя.
		return '' !== VL_Account_Phone::normalize( $display ) && '' === trim( preg_replace( '/[\d\s()+\-]/u', '', $display ) );
	}

	/**
	 * Привести имя из формы к аккуратному виду.
	 *
	 * @param string $name Имя.
	 * @return string
	 */
	public static function sanitize_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );

		if ( '' === $name ) {
			return '';
		}

		// Номер телефона вместо имени — это не имя.
		if ( '' === trim( preg_replace( '/[\d\s()+\-]/u', '', $name ) ) ) {
			return '';
		}

		return mb_substr( $name, 0, 60 );
	}

	/**
	 * Сохранить согласия с датой и IP.
	 *
	 * @param int   $user_id  Пользователь.
	 * @param array $consents Массив вида ['privacy' => true, 'marketing' => false].
	 */
	public static function save_consents( $user_id, $consents ) {
		if ( ! is_array( $consents ) || ! $consents ) {
			return;
		}

		$stored = get_user_meta( $user_id, self::META_CONSENTS, true );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( $consents as $type => $value ) {
			$type = sanitize_key( $type );

			$stored[ $type ] = array(
				'value' => (bool) $value,
				'date'  => current_time( 'mysql' ),
				'ip'    => vlacc_client_ip(),
			);
		}

		update_user_meta( $user_id, self::META_CONSENTS, $stored );

		// Отдельное поле для маркетинга — удобно для выгрузок и фильтров в админке.
		if ( isset( $consents['marketing'] ) ) {
			update_user_meta( $user_id, 'vlacc_consent_marketing', $consents['marketing'] ? 1 : 0 );
		}

		do_action( 'vlacc_consents_saved', $user_id, $consents );
	}

	/**
	 * Получить согласие.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $type    Тип.
	 * @return bool
	 */
	public static function has_consent( $user_id, $type ) {
		$stored = get_user_meta( $user_id, self::META_CONSENTS, true );

		return ! empty( $stored[ $type ]['value'] );
	}

	/**
	 * Телефон пользователя.
	 *
	 * @param int $user_id Пользователь.
	 * @return string
	 */
	public static function get_phone( $user_id ) {
		$phone = get_user_meta( $user_id, self::META_PHONE, true );

		if ( ! $phone ) {
			$phone = VL_Account_Phone::normalize( get_user_meta( $user_id, 'billing_phone', true ) );
		}

		return $phone;
	}

	/**
	 * Нормализовать ник Telegram.
	 *
	 * @param string $value Значение.
	 * @return string
	 */
	public static function sanitize_telegram( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '~^https?://(t\.me|telegram\.me)/~i', '', $value );
		$value = ltrim( $value, '@' );
		$value = preg_replace( '/[^A-Za-z0-9_]/', '', $value );

		return $value ? '@' . $value : '';
	}

	/**
	 * Есть ли у пользователя настоящий (не технический) e-mail.
	 *
	 * @param WP_User $user Пользователь.
	 * @return bool
	 */
	public static function has_real_email( $user ) {
		return $user instanceof WP_User && ! preg_match( '/@phone\./', $user->user_email );
	}

	/**
	 * Установлен ли пароль (или аккаунт «беспарольный»).
	 *
	 * @param int $user_id Пользователь.
	 * @return bool
	 */
	public static function has_password( $user_id ) {
		return ! get_user_meta( $user_id, self::META_NOPASS, true );
	}
}
