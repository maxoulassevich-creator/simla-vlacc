<?php
/**
 * Письма плагина.
 *
 * Закрывает задачи «после регистрации не приходит письмо с подтверждением»
 * и «письмо клиенту с доступом в новый ЛК при автосоздании по заказу».
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Письма.
 */
class VL_Account_Emails {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Emails|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Emails
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
		add_action( 'vlacc_user_registered', array( $this, 'on_register' ), 20, 2 );
		add_action( 'vlacc_account_created_from_order', array( $this, 'on_order_account' ), 20, 2 );

		// Любая ошибка отправки почты на сайте попадает в журнал плагина —
		// без этого «письмо не пришло» невозможно отличить от «письмо не ушло».
		add_action( 'wp_mail_failed', array( $this, 'log_mail_error' ) );
	}

	/**
	 * Последняя ошибка отправки почты.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * Записать ошибку отправки в журнал.
	 *
	 * @param WP_Error $error Ошибка от PHPMailer.
	 */
	public function log_mail_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$this->last_error = $error->get_error_message();

		$data = $error->get_error_data();

		vlacc_log(
			'Ошибка отправки письма',
			array(
				'error' => $this->last_error,
				'to'    => isset( $data['to'] ) ? (array) $data['to'] : array(),
			)
		);
	}

	/**
	 * Текст последней ошибки отправки.
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}

	/**
	 * Письмо после регистрации.
	 *
	 * @param int   $user_id Пользователь.
	 * @param array $data    Данные регистрации.
	 */
	public function on_register( $user_id, $data = array() ) {
		if ( ! VL_Account_Settings::get( 'email_on_register', 1 ) ) {
			return;
		}

		// Регистрация из чекаута обрабатывается отдельным письмом.
		if ( isset( $data['source'] ) && 'checkout' === $data['source'] ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! VL_Account_User::has_real_email( $user ) ) {
			return;
		}

		$name  = $user->first_name ? $user->first_name : __( 'Здравствуйте', 'vl-account' );
		$promo = VL_Account_Promo::get_user_codes( $user_id );

		$content  = '<p>' . sprintf(
			/* translators: %s — имя покупателя. */
			esc_html__( '%s, спасибо за регистрацию!', 'vl-account' ),
			esc_html( $name )
		) . '</p>';
		$content .= '<p>' . esc_html__( 'Личный кабинет уже создан. В нём — история заказов, избранное, промокоды и бонусы.', 'vl-account' ) . '</p>';

		if ( $promo ) {
			$first    = reset( $promo );
			$content .= '<p style="font-size:16px">' . esc_html__( 'Ваш промокод:', 'vl-account' ) . ' <strong style="letter-spacing:1px">' . esc_html( $first['code'] ) . '</strong>';

			if ( ! empty( $first['description'] ) ) {
				$content .= '<br><span style="color:#777">' . esc_html( $first['description'] ) . '</span>';
			}

			$content .= '</p>';
		}

		if ( ! VL_Account_User::has_password( $user_id ) ) {
			$content .= '<p>' . esc_html__( 'Пароль не нужен: вход в кабинет — по коду из SMS на ваш номер. Если удобнее с паролем, задайте его в разделе «Пароль и вход».', 'vl-account' ) . '</p>';
		}

		$link     = VL_Account_Auth::create_magic_link( $user_id, 7 * DAY_IN_SECONDS );
		$content .= self::button( $link, __( 'Перейти в личный кабинет', 'vl-account' ) );

		$this->send(
			$user->user_email,
			sprintf(
				/* translators: %s — название сайта. */
				__( 'Личный кабинет на сайте %s создан', 'vl-account' ),
				get_bloginfo( 'name' )
			),
			$content
		);
	}

	/**
	 * Письмо о кабинете, созданном автоматически при заказе.
	 *
	 * @param int      $user_id Пользователь.
	 * @param WC_Order $order   Заказ.
	 */
	public function on_order_account( $user_id, $order ) {
		if ( ! VL_Account_Settings::get( 'email_on_autocreate', 1 ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! VL_Account_User::has_real_email( $user ) ) {
			return;
		}

		$name = $user->first_name ? $user->first_name : __( 'Здравствуйте', 'vl-account' );

		$content  = '<p>' . sprintf(
			/* translators: %s — имя покупателя. */
			esc_html__( '%s, спасибо за заказ!', 'vl-account' ),
			esc_html( $name )
		) . '</p>';
		$content .= '<p>' . sprintf(
			/* translators: %s — номер заказа. */
			esc_html__( 'Мы создали для вас личный кабинет — заказ №%s уже там.', 'vl-account' ),
			esc_html( $order instanceof WC_Order ? $order->get_order_number() : '' )
		) . '</p>';
		$content .= '<p>' . esc_html__( 'Входить можно по коду из SMS на номер, который вы указали при оформлении, — пароль запоминать не нужно.', 'vl-account' ) . '</p>';

		$link     = VL_Account_Auth::create_magic_link( $user_id, 14 * DAY_IN_SECONDS );
		$content .= self::button( $link, __( 'Открыть личный кабинет', 'vl-account' ) );

		$this->send(
			$user->user_email,
			sprintf(
				/* translators: %s — название сайта. */
				__( 'Ваш личный кабинет на сайте %s', 'vl-account' ),
				get_bloginfo( 'name' )
			),
			$content
		);
	}

	/**
	 * Кнопка в письме.
	 *
	 * @param string $url   Ссылка.
	 * @param string $label Текст.
	 * @return string
	 */
	protected static function button( $url, $label ) {
		$color = VL_Account_Settings::get( 'accent_color', '#d40000' );

		return sprintf(
			'<p style="margin:28px 0"><a href="%1$s" style="background:%2$s;color:#fff;text-decoration:none;display:inline-block;padding:14px 28px;font-size:13px;letter-spacing:.08em;text-transform:uppercase">%3$s</a></p>',
			esc_url( $url ),
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	/**
	 * Отправка письма (в оформлении WooCommerce, если он активен).
	 *
	 * @param string $to      Получатель.
	 * @param string $subject Тема.
	 * @param string $content HTML письма.
	 * @return bool
	 */
	public function send( $to, $subject, $content ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$this->last_error = '';

		if ( vlacc_is_woo() && function_exists( 'WC' ) && WC()->mailer() ) {
			$message = WC()->mailer()->wrap_message( $subject, $content );
			$sent    = (bool) WC()->mailer()->send( $to, $subject, $message, $headers );
		} else {
			$message = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#333;max-width:600px;margin:0 auto">' . $content . '</div>';
			$sent    = (bool) wp_mail( $to, $subject, $message, $headers );
		}

		if ( ! $sent ) {
			vlacc_log(
				'Письмо не отправлено',
				array(
					'to'      => vlacc_mask_email( $to ),
					'subject' => $subject,
					'error'   => $this->last_error ? $this->last_error : 'wp_mail вернул false без описания ошибки',
				)
			);
		}

		return $sent;
	}
}
