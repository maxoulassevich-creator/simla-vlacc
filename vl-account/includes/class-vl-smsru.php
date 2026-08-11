<?php
/**
 * Клиент API SMS.RU.
 *
 * Документация: https://sms.ru/api/send , https://sms.ru/api/code_call
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Отправка SMS и звонков-кодов через SMS.RU.
 */
class VL_Account_SmsRu {

	const API_BASE = 'https://sms.ru/';

	/**
	 * Расшифровка кодов ответа SMS.RU на русском.
	 *
	 * @param int $code Код.
	 * @return string
	 */
	public static function status_message( $code ) {
		$map = array(
			100 => 'Запрос выполнен успешно',
			101 => 'Сообщение передаётся оператору',
			102 => 'Сообщение отправлено (в пути)',
			103 => 'Сообщение доставлено',
			104 => 'Не может быть доставлено: время жизни истекло',
			105 => 'Не может быть доставлено: удалено оператором',
			106 => 'Не может быть доставлено: сбой в телефоне',
			107 => 'Не может быть доставлено: неизвестная причина',
			108 => 'Не может быть доставлено: отклонено',
			110 => 'Сообщение прочитано',
			150 => 'Не может быть доставлено: номер в чёрном списке',
			200 => 'Неправильный api_id',
			201 => 'Недостаточно средств на лицевом счёте SMS.RU',
			202 => 'Неправильно указан номер получателя',
			203 => 'Нет текста сообщения',
			204 => 'Имя отправителя не согласовано с администрацией SMS.RU',
			205 => 'Сообщение слишком длинное',
			206 => 'Превышен дневной лимит отправки сообщений',
			207 => 'На этот номер нельзя отправлять сообщения',
			208 => 'Параметр time указан неверно',
			209 => 'Номер получателя в стоп-листе',
			210 => 'Используется GET, где необходим POST',
			211 => 'Метод не найден',
			212 => 'Текст сообщения необходимо передать в кодировке UTF-8',
			220 => 'Сервис временно недоступен, попробуйте чуть позже',
			230 => 'Превышен общий лимит сообщений на этот номер за сутки',
			231 => 'Превышен лимит одинаковых сообщений на этот номер в минуту',
			232 => 'Превышен лимит одинаковых сообщений на этот номер в день',
			233 => 'Превышен лимит отправки на этот номер: код уже отправлен',
			300 => 'Неправильный token (возможно, истёк срок действия)',
			301 => 'Неправильный пароль либо пользователь не найден',
			302 => 'Пользователь авторизован, но аккаунт не подтверждён',
			303 => 'Код подтверждения неверен',
			304 => 'Отправлено слишком много кодов подтверждения. Повторите позже',
			305 => 'Слишком много неверных вводов кода. Повторите позже',
			500 => 'Ошибка сервера SMS.RU',
			901 => 'Callback: URL неверный',
			902 => 'Callback: обработчик не найден',
		);

		$code = (int) $code;

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		/* translators: %d — код ответа SMS.RU. */
		return sprintf( __( 'Неизвестный ответ шлюза (код %d)', 'vl-account' ), $code );
	}

	/**
	 * Сообщение, которое можно показать посетителю (без технических подробностей).
	 *
	 * @param int $code Код ответа.
	 * @return string
	 */
	public static function public_message( $code ) {
		$code = (int) $code;

		$public = array(
			202 => __( 'Проверьте, правильно ли указан номер телефона.', 'vl-account' ),
			207 => __( 'На этот номер отправка сообщений недоступна.', 'vl-account' ),
			209 => __( 'Этот номер добавлен в стоп-лист. Напишите нам, и мы поможем.', 'vl-account' ),
			230 => __( 'На сегодня лимит кодов для этого номера исчерпан. Попробуйте завтра или войдите по паролю.', 'vl-account' ),
			231 => __( 'Код уже отправлен. Подождите минуту и попробуйте снова.', 'vl-account' ),
			232 => __( 'На сегодня лимит кодов для этого номера исчерпан. Попробуйте завтра.', 'vl-account' ),
			233 => __( 'Код уже отправлен. Подождите минуту и попробуйте снова.', 'vl-account' ),
			304 => __( 'Отправлено слишком много кодов. Попробуйте позже.', 'vl-account' ),
			220 => __( 'Сервис отправки временно перегружен. Попробуйте через минуту.', 'vl-account' ),
		);

		if ( isset( $public[ $code ] ) ) {
			return $public[ $code ];
		}

		// Ошибки настройки (200, 201, 204 и т.п.) посетителю знать не нужно.
		return __( 'Не удалось отправить код. Попробуйте ещё раз или свяжитесь с нами.', 'vl-account' );
	}

	/**
	 * Базовый запрос к API.
	 *
	 * @param string $endpoint Например 'sms/send'.
	 * @param array  $params   Параметры без api_id.
	 * @return array|WP_Error Ответ в виде массива.
	 */
	protected static function request( $endpoint, $params = array() ) {
		$api_id = trim( (string) VL_Account_Settings::get( 'api_id', '' ) );

		if ( '' === $api_id ) {
			return new WP_Error( 'vlacc_no_api_id', __( 'Не указан api_id SMS.RU в настройках плагина.', 'vl-account' ) );
		}

		$params['api_id'] = $api_id;
		$params['json']   = 1;

		$url = self::API_BASE . ltrim( $endpoint, '/' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => $params,
				'headers'   => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			vlacc_log(
				'Ошибка соединения с SMS.RU',
				array(
					'endpoint' => $endpoint,
					'error'    => $response->get_error_message(),
				)
			);
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			vlacc_log(
				'Некорректный ответ SMS.RU',
				array(
					'endpoint' => $endpoint,
					'body'     => mb_substr( (string) $body, 0, 500 ),
				)
			);
			return new WP_Error( 'vlacc_bad_response', __( 'Шлюз вернул неожиданный ответ.', 'vl-account' ) );
		}

		return $data;
	}

	/**
	 * Отправить SMS с кодом.
	 *
	 * @param string $phone Нормализованный номер (79261234567).
	 * @param string $text  Текст сообщения.
	 * @return array {
	 *     @type bool   $success
	 *     @type int    $status_code
	 *     @type string $message      Техническое описание.
	 *     @type string $public       Сообщение для посетителя.
	 *     @type array  $raw
	 * }
	 */
	public static function send_sms( $phone, $text ) {
		$params = array(
			'to'  => $phone,
			'msg' => $text,
		);

		$from = trim( (string) VL_Account_Settings::get( 'sms_from', '' ) );
		if ( '' !== $from ) {
			$params['from'] = $from;
		}

		if ( VL_Account_Settings::get( 'test_mode', 0 ) ) {
			$params['test'] = 1;
		}

		$data = self::request( 'sms/send', $params );

		if ( is_wp_error( $data ) ) {
			return self::error_result( $data );
		}

		$status_code = isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;

		// Общий статус запроса.
		if ( isset( $data['status'] ) && 'OK' === $data['status'] ) {
			// Проверяем статус конкретного номера.
			if ( ! empty( $data['sms'][ $phone ]['status_code'] ) ) {
				$status_code = (int) $data['sms'][ $phone ]['status_code'];
			}

			if ( in_array( $status_code, array( 100, 101, 102, 103 ), true ) ) {
				vlacc_log(
					'SMS отправлено',
					array(
						'phone'   => vlacc_mask_phone( $phone ),
						'balance' => isset( $data['balance'] ) ? $data['balance'] : null,
					)
				);

				return array(
					'success'     => true,
					'status_code' => $status_code,
					'message'     => self::status_message( $status_code ),
					'public'      => '',
					'raw'         => $data,
				);
			}
		}

		vlacc_log(
			'Ошибка отправки SMS',
			array(
				'phone'  => vlacc_mask_phone( $phone ),
				'status' => $status_code,
				'text'   => self::status_message( $status_code ),
			)
		);

		return array(
			'success'     => false,
			'status_code' => $status_code,
			'message'     => self::status_message( $status_code ),
			'public'      => self::public_message( $status_code ),
			'raw'         => $data,
		);
	}

	/**
	 * Авторизация звонком: SMS.RU звонит на номер, код — последние 4 цифры номера звонящего.
	 *
	 * @param string $phone Нормализованный номер.
	 * @return array Как send_sms(), плюс ключ 'code' с кодом подтверждения.
	 */
	public static function call_code( $phone ) {
		$data = self::request(
			'code/call',
			array(
				'phone' => $phone,
				'ip'    => vlacc_client_ip(),
			)
		);

		if ( is_wp_error( $data ) ) {
			return self::error_result( $data );
		}

		$status_code = isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;

		if ( isset( $data['status'] ) && 'OK' === $data['status'] && ! empty( $data['code'] ) ) {
			vlacc_log(
				'Заказан звонок с кодом',
				array(
					'phone'   => vlacc_mask_phone( $phone ),
					'call_id' => isset( $data['call_id'] ) ? $data['call_id'] : null,
				)
			);

			return array(
				'success'     => true,
				'status_code' => $status_code ? $status_code : 100,
				'code'        => (string) $data['code'],
				'call_id'     => isset( $data['call_id'] ) ? $data['call_id'] : '',
				'message'     => self::status_message( 100 ),
				'public'      => '',
				'raw'         => $data,
			);
		}

		vlacc_log(
			'Ошибка звонка с кодом',
			array(
				'phone'  => vlacc_mask_phone( $phone ),
				'status' => $status_code,
				'text'   => self::status_message( $status_code ),
			)
		);

		return array(
			'success'     => false,
			'status_code' => $status_code,
			'message'     => self::status_message( $status_code ),
			'public'      => self::public_message( $status_code ),
			'raw'         => $data,
		);
	}

	/**
	 * Баланс лицевого счёта.
	 *
	 * @return float|WP_Error
	 */
	public static function balance() {
		$data = self::request( 'my/balance' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['status'] ) && 'OK' === $data['status'] ) {
			return isset( $data['balance'] ) ? (float) $data['balance'] : 0.0;
		}

		$status_code = isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;

		return new WP_Error( 'vlacc_balance', self::status_message( $status_code ) );
	}

	/**
	 * Список согласованных имён отправителей.
	 *
	 * @return array|WP_Error
	 */
	public static function senders() {
		$data = self::request( 'my/senders' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['status'] ) && 'OK' === $data['status'] && ! empty( $data['senders'] ) ) {
			return (array) $data['senders'];
		}

		return array();
	}

	/**
	 * Преобразование WP_Error в стандартный результат.
	 *
	 * @param WP_Error $error Ошибка.
	 * @return array
	 */
	protected static function error_result( $error ) {
		return array(
			'success'     => false,
			'status_code' => 0,
			'message'     => $error->get_error_message(),
			'public'      => __( 'Не удалось отправить код. Попробуйте ещё раз или свяжитесь с нами.', 'vl-account' ),
			'raw'         => array(),
		);
	}
}
