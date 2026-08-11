<?php
/**
 * Одноразовые коды подтверждения.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Генерация, отправка, проверка кодов и ограничение частоты.
 */
class VL_Account_OTP {

	const PREFIX = 'vlacc_otp_';

	/**
	 * Ключ хранения кода.
	 *
	 * @param string $phone   Нормализованный номер.
	 * @param string $purpose login|register|reset|checkout.
	 * @return string
	 */
	protected static function key( $phone, $purpose ) {
		return self::PREFIX . md5( $purpose . '|' . $phone );
	}

	/**
	 * Хэш кода (сам код в базе не храним).
	 *
	 * @param string $code  Код.
	 * @param string $phone Номер.
	 * @return string
	 */
	protected static function hash( $code, $phone ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'vl-account';
		return hash_hmac( 'sha256', $code . '|' . $phone, $salt );
	}

	/**
	 * Сгенерировать код нужной длины.
	 *
	 * @return string
	 */
	protected static function generate() {
		$length = max( 4, min( 8, (int) VL_Account_Settings::get( 'code_length', 4 ) ) );
		$min    = (int) str_pad( '1', $length, '0' );
		$max    = (int) str_pad( '9', $length, '9' );

		return (string) wp_rand( $min, $max );
	}

	/**
	 * Отправить код на номер.
	 *
	 * @param string $phone   Номер (любой формат).
	 * @param string $purpose Назначение.
	 * @return array|WP_Error
	 */
	public static function send( $phone, $purpose = 'login' ) {
		$phone = VL_Account_Phone::normalize( $phone );

		if ( ! VL_Account_Phone::is_valid( $phone ) ) {
			return new WP_Error( 'vlacc_bad_phone', __( 'Проверьте, правильно ли указан номер телефона.', 'vl-account' ) );
		}

		if ( ! VL_Account_Settings::sms_ready() ) {
			return new WP_Error( 'vlacc_not_configured', __( 'Отправка кодов временно недоступна. Попробуйте войти по паролю или напишите нам.', 'vl-account' ) );
		}

		$limit = self::check_limits( $phone );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$method   = VL_Account_Settings::get( 'delivery_method', 'sms' );
		$ttl      = (int) VL_Account_Settings::get( 'code_ttl', 300 );
		$code     = '';
		$sent_via = '';

		if ( 'call' === $method || 'sms_then_call' === $method ) {
			$result = VL_Account_SmsRu::call_code( $phone );

			if ( ! empty( $result['success'] ) ) {
				$code     = $result['code'];
				$sent_via = 'call';
			} elseif ( 'sms_then_call' === $method ) {
				// Звонок не прошёл — пробуем SMS.
				$method = 'sms';
			} else {
				return new WP_Error( 'vlacc_send_failed', $result['public'] );
			}
		}

		if ( '' === $code ) {
			$code = self::generate();
			$text = str_replace(
				array( '{code}', '{site}' ),
				array( $code, get_bloginfo( 'name' ) ),
				(string) VL_Account_Settings::get( 'sms_text', 'Код подтверждения: {code}' )
			);

			$result = VL_Account_SmsRu::send_sms( $phone, $text );

			if ( empty( $result['success'] ) ) {
				return new WP_Error( 'vlacc_send_failed', $result['public'] );
			}

			$sent_via = 'sms';
		}

		set_transient(
			self::key( $phone, $purpose ),
			array(
				'hash'     => self::hash( $code, $phone ),
				'attempts' => 0,
				'method'   => $sent_via,
				'created'  => time(),
			),
			$ttl
		);

		self::register_send( $phone );

		$response = array(
			'success' => true,
			'method'  => $sent_via,
			'wait'    => (int) VL_Account_Settings::get( 'resend_timeout', 60 ),
			'ttl'     => $ttl,
			'message' => 'call' === $sent_via
				? __( 'Сейчас поступит звонок — введите последние 4 цифры номера, с которого звонят.', 'vl-account' )
				: sprintf(
					/* translators: %s — номер телефона в маскированном виде. */
					__( 'Код отправлен по SMS на номер %s', 'vl-account' ),
					vlacc_mask_phone( $phone )
				),
		);

		// Только для отладки на тестовом сайте.
		if ( VL_Account_Settings::get( 'debug_show_code', 0 ) ) {
			$response['debug_code'] = $code;
		}

		do_action( 'vlacc_code_sent', $phone, $purpose, $sent_via );

		return $response;
	}

	/**
	 * Проверить введённый код.
	 *
	 * @param string $phone   Номер.
	 * @param string $code    Код.
	 * @param string $purpose Назначение.
	 * @return true|WP_Error
	 */
	public static function verify( $phone, $code, $purpose = 'login' ) {
		$phone = VL_Account_Phone::normalize( $phone );
		$code  = preg_replace( '/\D+/', '', (string) $code );

		if ( '' === $phone || '' === $code ) {
			return new WP_Error( 'vlacc_bad_code', __( 'Введите код из SMS.', 'vl-account' ) );
		}

		$key  = self::key( $phone, $purpose );
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['hash'] ) ) {
			return new WP_Error( 'vlacc_code_expired', __( 'Срок действия кода истёк. Запросите новый.', 'vl-account' ) );
		}

		$max = (int) VL_Account_Settings::get( 'max_attempts', 5 );

		if ( (int) $data['attempts'] >= $max ) {
			delete_transient( $key );
			return new WP_Error( 'vlacc_too_many', __( 'Слишком много попыток. Запросите новый код.', 'vl-account' ) );
		}

		if ( ! hash_equals( $data['hash'], self::hash( $code, $phone ) ) ) {
			++$data['attempts'];
			$left = max( 0, $max - (int) $data['attempts'] );
			set_transient( $key, $data, (int) VL_Account_Settings::get( 'code_ttl', 300 ) );

			if ( 0 === $left ) {
				delete_transient( $key );
				return new WP_Error( 'vlacc_too_many', __( 'Слишком много попыток. Запросите новый код.', 'vl-account' ) );
			}

			return new WP_Error(
				'vlacc_wrong_code',
				sprintf(
					/* translators: %d — количество оставшихся попыток. */
					_n( 'Неверный код. Осталась %d попытка.', 'Неверный код. Осталось попыток: %d.', $left, 'vl-account' ),
					$left
				)
			);
		}

		delete_transient( $key );

		do_action( 'vlacc_code_verified', $phone, $purpose );

		return true;
	}

	/**
	 * Ограничения по частоте отправки.
	 *
	 * @param string $phone Номер.
	 * @return true|WP_Error
	 */
	protected static function check_limits( $phone ) {
		$cooldown = (int) VL_Account_Settings::get( 'resend_timeout', 60 );
		$last     = get_transient( self::PREFIX . 'last_' . md5( $phone ) );

		if ( $last ) {
			$left = $cooldown - ( time() - (int) $last );
			if ( $left > 0 ) {
				return new WP_Error(
					'vlacc_cooldown',
					sprintf(
						/* translators: %d — секунд до повторной отправки. */
						__( 'Код уже отправлен. Повторить можно через %d сек.', 'vl-account' ),
						$left
					),
					array( 'wait' => $left )
				);
			}
		}

		$per_day = (int) VL_Account_Settings::get( 'max_per_phone_day', 10 );
		$day_key = self::PREFIX . 'day_' . md5( $phone . gmdate( 'Ymd' ) );
		if ( (int) get_transient( $day_key ) >= $per_day ) {
			return new WP_Error( 'vlacc_limit_phone', __( 'На сегодня лимит кодов для этого номера исчерпан. Попробуйте завтра или войдите по паролю.', 'vl-account' ) );
		}

		$per_hour = (int) VL_Account_Settings::get( 'max_per_ip_hour', 15 );
		$ip_key   = self::PREFIX . 'ip_' . md5( vlacc_client_ip() . gmdate( 'YmdH' ) );
		if ( (int) get_transient( $ip_key ) >= $per_hour ) {
			return new WP_Error( 'vlacc_limit_ip', __( 'Слишком много запросов. Попробуйте позже.', 'vl-account' ) );
		}

		return true;
	}

	/**
	 * Зафиксировать факт отправки для счётчиков.
	 *
	 * @param string $phone Номер.
	 */
	protected static function register_send( $phone ) {
		set_transient( self::PREFIX . 'last_' . md5( $phone ), time(), (int) VL_Account_Settings::get( 'resend_timeout', 60 ) );

		$day_key = self::PREFIX . 'day_' . md5( $phone . gmdate( 'Ymd' ) );
		set_transient( $day_key, (int) get_transient( $day_key ) + 1, DAY_IN_SECONDS );

		$ip_key = self::PREFIX . 'ip_' . md5( vlacc_client_ip() . gmdate( 'YmdH' ) );
		set_transient( $ip_key, (int) get_transient( $ip_key ) + 1, HOUR_IN_SECONDS );
	}

}
