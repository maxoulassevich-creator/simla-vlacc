<?php
/**
 * Бонусы программы лояльности в кабинете.
 *
 * Плагин показывает баланс и историю, но не считает баллы сам: источник данных
 * подключается фильтрами. Так раздел сразу работает с ручным балансом, а позже
 * его можно переключить на RetailCRM или другой плагин лояльности, не меняя вёрстку.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Бонусы.
 */
class VL_Account_Bonus {

	const META_BALANCE = 'vlacc_bonus_balance';
	const META_HISTORY = 'vlacc_bonus_history';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Bonus|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Bonus
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
		add_action( 'show_user_profile', array( $this, 'admin_field' ) );
		add_action( 'edit_user_profile', array( $this, 'admin_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_admin_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_admin_field' ) );
	}

	/**
	 * Баланс баллов.
	 *
	 * @param int $user_id Пользователь.
	 * @return float
	 */
	public static function get_balance( $user_id ) {
		$balance = (float) get_user_meta( $user_id, self::META_BALANCE, true );

		return (float) apply_filters( 'vlacc_bonus_balance', $balance, $user_id );
	}

	/**
	 * История начислений/списаний.
	 *
	 * @param int $user_id Пользователь.
	 * @return array Массив ['date' => ..., 'amount' => ..., 'description' => ...].
	 */
	public static function get_history( $user_id ) {
		$history = get_user_meta( $user_id, self::META_HISTORY, true );
		$history = is_array( $history ) ? $history : array();

		return (array) apply_filters( 'vlacc_bonus_history', $history, $user_id );
	}

	/**
	 * Изменить баланс.
	 *
	 * @param int    $user_id     Пользователь.
	 * @param float  $amount      Сколько (может быть отрицательным).
	 * @param string $description Комментарий.
	 * @return float Новый баланс.
	 */
	public static function adjust( $user_id, $amount, $description = '' ) {
		$balance = (float) get_user_meta( $user_id, self::META_BALANCE, true );
		$balance = round( $balance + (float) $amount, 2 );

		update_user_meta( $user_id, self::META_BALANCE, $balance );

		$history = get_user_meta( $user_id, self::META_HISTORY, true );
		$history = is_array( $history ) ? $history : array();

		array_unshift(
			$history,
			array(
				'date'        => current_time( 'mysql' ),
				'amount'      => (float) $amount,
				'description' => sanitize_text_field( $description ),
				'balance'     => $balance,
			)
		);

		update_user_meta( $user_id, self::META_HISTORY, array_slice( $history, 0, 100 ) );

		do_action( 'vlacc_bonus_adjusted', $user_id, $amount, $balance );

		return $balance;
	}

	/**
	 * Поле баланса в профиле пользователя в админке.
	 *
	 * @param WP_User $user Пользователь.
	 */
	public function admin_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Личный кабинет (VL Account)', 'vl-account' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="vlacc_bonus_balance"><?php esc_html_e( 'Баланс баллов', 'vl-account' ); ?></label></th>
				<td>
					<input type="number" step="0.01" name="vlacc_bonus_balance" id="vlacc_bonus_balance"
						value="<?php echo esc_attr( get_user_meta( $user->ID, self::META_BALANCE, true ) ); ?>" class="regular-text" />
					<?php if ( class_exists( 'VL_Account_RetailCRM_Loyalty' ) && 'crm' === VL_Account_RetailCRM_Loyalty::source( $user->ID ) ) : ?>
						<p class="description">
							<?php esc_html_e( 'Сейчас в кабинете показывается баланс из программы лояльности RetailCRM — это поле не влияет на него. Начисляйте и списывайте баллы в CRM.', 'vl-account' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="vlacc_phone_field"><?php esc_html_e( 'Телефон (нормализованный)', 'vl-account' ); ?></label></th>
				<td>
					<input type="text" name="vlacc_phone_field" id="vlacc_phone_field"
						value="<?php echo esc_attr( get_user_meta( $user->ID, VL_Account_User::META_PHONE, true ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'В формате 79261234567 — по нему работает вход по SMS.', 'vl-account' ); ?></p>
				</td>
			</tr>
			<?php $vl_pending = VL_Account_Email_Confirm::pending( $user->ID ); ?>
			<?php if ( $vl_pending ) : ?>
				<tr>
					<th><?php esc_html_e( 'E-mail ждёт подтверждения', 'vl-account' ); ?></th>
					<td>
						<code><?php echo esc_html( $vl_pending ); ?></code>
						<p>
							<label>
								<input type="checkbox" name="vlacc_force_confirm_email" value="1" />
								<?php esc_html_e( 'Подтвердить этот адрес вручную и привязать к аккаунту', 'vl-account' ); ?>
							</label>
						</p>
						<p class="description">
							<?php esc_html_e( 'Пригодится, если письма с сайта не доходят: адрес станет почтой аккаунта, к кабинету подтянутся заказы с этим адресом.', 'vl-account' ); ?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Согласия', 'vl-account' ); ?></th>
				<td>
					<?php
					$consents = get_user_meta( $user->ID, VL_Account_User::META_CONSENTS, true );

					if ( is_array( $consents ) && $consents ) {
						echo '<ul style="margin:0">';
						foreach ( $consents as $type => $row ) {
							printf(
								'<li>%s — <strong>%s</strong> (%s, IP %s)</li>',
								esc_html( $type ),
								! empty( $row['value'] ) ? esc_html__( 'да', 'vl-account' ) : esc_html__( 'нет', 'vl-account' ),
								esc_html( isset( $row['date'] ) ? $row['date'] : '' ),
								esc_html( isset( $row['ip'] ) ? $row['ip'] : '' )
							);
						}
						echo '</ul>';
					} else {
						esc_html_e( 'Нет данных', 'vl-account' );
					}
					?>
				</td>
			</tr>
		</table>
		<?php
		wp_nonce_field( 'vlacc_user_fields', 'vlacc_user_fields_nonce' );
	}

	/**
	 * Сохранение полей профиля.
	 *
	 * @param int $user_id Пользователь.
	 */
	public function save_admin_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['vlacc_user_fields_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vlacc_user_fields_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vlacc_user_fields' ) ) {
			return;
		}

		if ( isset( $_POST['vlacc_bonus_balance'] ) ) {
			update_user_meta( $user_id, self::META_BALANCE, (float) wp_unslash( $_POST['vlacc_bonus_balance'] ) );
		}

		if ( isset( $_POST['vlacc_phone_field'] ) ) {
			$phone = VL_Account_Phone::normalize( sanitize_text_field( wp_unslash( $_POST['vlacc_phone_field'] ) ) );
			update_user_meta( $user_id, VL_Account_User::META_PHONE, $phone );
		}

		if ( ! empty( $_POST['vlacc_force_confirm_email'] ) ) {
			VL_Account_Email_Confirm::force_confirm( $user_id );
		}
	}
}
