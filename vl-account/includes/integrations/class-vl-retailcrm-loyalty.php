<?php
/**
 * Программа лояльности RetailCRM в разделе «Бонусы» личного кабинета.
 *
 * Раздел работает по состоянию участия покупателя:
 *  — участия нет            → форма вступления (телефон уже подтверждён при входе);
 *  — участие не активировано → кнопка активации и, если CRM просит, код из SMS;
 *  — участие активно         → баланс, уровень, сгорание, история операций.
 *
 * Если интеграция выключена или CRM недоступна, раздел показывает локальный
 * баланс из метаполя — кабинет не ломается.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Бонусы и программа лояльности из CRM.
 */
class VL_Account_RetailCRM_Loyalty {

	/**
	 * Хук фонового вступления в программу.
	 */
	const CRON = 'vlacc_loyalty_autojoin';

	/**
	 * Метка: участие оформлено автоматически.
	 */
	const META_AUTO = 'vlacc_lp_auto';

	/**
	 * Метка: вступление ещё не доведено до конца.
	 */
	const META_PENDING = 'vlacc_lp_pending';

	/**
	 * Метка: CRM ждёт код подтверждения (checkId).
	 */
	const META_CHECK = 'vlacc_lp_check';

	/**
	 * Опция: CRM требует подтверждать участие по SMS.
	 */
	const OPTION_VERIFY = 'vlacc_crm_lp_verification';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_RetailCRM_Loyalty|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_RetailCRM_Loyalty
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
		// Источник баланса и истории для раздела «Бонусы» и сводки кабинета.
		add_filter( 'vlacc_bonus_balance', array( $this, 'balance' ), 10, 2 );
		add_filter( 'vlacc_bonus_history', array( $this, 'history' ), 10, 2 );

		// AJAX личного кабинета.
		add_action( 'wp_ajax_vlacc_loyalty_register', array( $this, 'ajax_register' ) );
		add_action( 'wp_ajax_vlacc_loyalty_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_vlacc_loyalty_confirm', array( $this, 'ajax_confirm' ) );
		add_action( 'wp_ajax_vlacc_loyalty_refresh', array( $this, 'ajax_refresh' ) );

		// Убираем дубль стандартного раздела Simla в меню WooCommerce.
		add_filter( 'woocommerce_account_menu_items', array( $this, 'remove_crm_menu_item' ), 99 );
		add_action( 'template_redirect', array( $this, 'redirect_crm_endpoint' ) );

		// Кэш обновляем после списания баллов в корзине.
		add_action( 'vlacc_loyalty_charged', array( __CLASS__, 'flush_current' ) );

		// Новый покупатель вступает в программу сам, без лишних шагов в кабинете.
		add_action( 'vlacc_user_registered', array( __CLASS__, 'on_registered' ), 30, 2 );
		add_action( self::CRON, array( __CLASS__, 'auto_join' ) );
	}

	/* ------------------------------------------------------------------
	 * Автоматическое вступление в программу
	 * ------------------------------------------------------------------ */

	/**
	 * Автоматическое вступление включено.
	 *
	 * @return bool
	 */
	public static function auto_enabled() {
		return (bool) VL_Account_Settings::get( 'crm_loyalty_auto', 1 ) && VL_Account_RetailCRM::loyalty_active();
	}

	/**
	 * Новый аккаунт: ставим вступление в очередь.
	 *
	 * Приветственные баллы должны появиться сами. Покупатель уже подтвердил
	 * номер кодом из SMS при регистрации — просить его подтверждать тот же
	 * номер второй раз в разделе «Бонусы» незачем.
	 *
	 * @param int   $user_id Пользователь.
	 * @param array $data    Данные регистрации.
	 */
	public static function on_registered( $user_id, $data = array() ) {
		if ( ! self::auto_enabled() ) {
			return;
		}

		$phone = isset( $data['phone'] ) ? VL_Account_Phone::normalize( $data['phone'] ) : '';

		if ( '' === $phone ) {
			$phone = VL_Account_User::get_phone( $user_id );
		}

		// Программа лояльности CRM живёт на телефоне — без него вступать не с чем.
		if ( '' === $phone ) {
			return;
		}

		update_user_meta( $user_id, self::META_PENDING, 1 );

		// В фон: регистрация в CRM — это несколько запросов, и покупатель
		// не должен ждать их на экране входа.
		if ( ! wp_next_scheduled( self::CRON, array( (int) $user_id ) ) ) {
			wp_schedule_single_event( time() + 1, self::CRON, array( (int) $user_id ) );
		}
	}

	/**
	 * Вступление ещё не доведено до конца.
	 *
	 * @param int $user_id Пользователь.
	 * @return bool
	 */
	public static function pending( $user_id ) {
		return '' !== (string) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/**
	 * Оформить и активировать участие без участия покупателя.
	 *
	 * @param int $user_id Пользователь.
	 * @return string Итог: active | sms | error | skip.
	 */
	public static function auto_join( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id || ! self::auto_enabled() ) {
			return 'skip';
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return 'skip';
		}

		// Замок от повторных заходов: планировщик и открытие раздела «Бонусы»
		// могут прийти одновременно.
		$lock = 'vlacc_lp_join_' . $user_id;

		if ( get_transient( $lock ) ) {
			return 'skip';
		}

		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		$phone = VL_Account_User::get_phone( $user_id );

		if ( '' === $phone ) {
			delete_user_meta( $user_id, self::META_PENDING );

			return 'skip';
		}

		$account = VL_Account_RetailCRM::account( $user_id, true );

		// Уже участвует — ничего не делаем.
		if ( 'active' === $account['status'] ) {
			self::finish( $user_id );

			return 'active';
		}

		if ( 'error' === $account['status'] ) {
			return 'error';
		}

		if ( 'none' === $account['status'] ) {
			$registered = VL_Account_RetailCRM::register_account( $user_id, $phone );

			if ( is_wp_error( $registered ) ) {
				vlacc_log(
					'Автовступление в программу лояльности не удалось',
					array(
						'user_id' => $user_id,
						'error'   => $registered->get_error_message(),
					)
				);

				return 'error';
			}

			$account = VL_Account_RetailCRM::account( $user_id, true );
		}

		if ( ! $account['id'] ) {
			return 'error';
		}

		if ( 'active' === $account['status'] ) {
			self::finish( $user_id );

			return 'active';
		}

		$activated = VL_Account_RetailCRM::activate_account( $user_id, $account['id'] );

		if ( is_wp_error( $activated ) ) {
			vlacc_log(
				'Автоактивация участия не удалась',
				array(
					'user_id' => $user_id,
					'error'   => $activated->get_error_message(),
				)
			);

			return 'error';
		}

		// CRM просит свой код подтверждения — обойти это со стороны сайта
		// нельзя, подтверждение участия включено в настройках программы.
		if ( '' !== $activated['check_id'] ) {
			update_user_meta( $user_id, self::META_CHECK, $activated['check_id'] );
			update_option( self::OPTION_VERIFY, 1, false );
			delete_user_meta( $user_id, self::META_PENDING );

			vlacc_log(
				'Программа лояльности требует подтверждения участия по SMS',
				array( 'user_id' => $user_id )
			);

			return 'sms';
		}

		self::finish( $user_id );

		vlacc_log(
			'Участие в программе лояльности оформлено автоматически',
			array(
				'user_id' => $user_id,
				'phone'   => vlacc_mask_phone( $phone ),
			)
		);

		return 'active';
	}

	/**
	 * Завершить вступление: пометки и свежие данные в кабинете.
	 *
	 * @param int $user_id Пользователь.
	 */
	protected static function finish( $user_id ) {
		delete_user_meta( $user_id, self::META_PENDING );
		delete_user_meta( $user_id, self::META_CHECK );
		update_user_meta( $user_id, self::META_AUTO, current_time( 'mysql' ) );
		update_option( self::OPTION_VERIFY, 0, false );

		VL_Account_User::save_consents( $user_id, array( 'loyalty' => true ) );
		VL_Account_RetailCRM::flush( $user_id );
	}

	/**
	 * CRM требует подтверждать участие по SMS.
	 *
	 * @return bool
	 */
	public static function verification_required() {
		return (bool) get_option( self::OPTION_VERIFY, 0 );
	}

	/* ------------------------------------------------------------------
	 * Источник данных для раздела «Бонусы»
	 * ------------------------------------------------------------------ */

	/**
	 * Откуда брать баланс: crm | local.
	 *
	 * @param int $user_id Пользователь.
	 * @return string
	 */
	public static function source( $user_id = 0 ) {
		$mode = VL_Account_Settings::get( 'crm_bonus_source', 'auto' );

		if ( 'local' === $mode ) {
			return 'local';
		}

		if ( 'crm' === $mode ) {
			return 'crm';
		}

		// auto: CRM, если участие оформлено и активно.
		if ( ! VL_Account_RetailCRM::loyalty_active() ) {
			return 'local';
		}

		$account = VL_Account_RetailCRM::account( $user_id );

		return 'active' === $account['status'] ? 'crm' : 'local';
	}

	/**
	 * Баланс баллов.
	 *
	 * @param float $balance Локальный баланс.
	 * @param int   $user_id Пользователь.
	 * @return float
	 */
	public function balance( $balance, $user_id ) {
		if ( ! VL_Account_RetailCRM::loyalty_active() || 'crm' !== self::source( $user_id ) ) {
			return $balance;
		}

		$account = VL_Account_RetailCRM::account( $user_id );

		if ( 'active' !== $account['status'] ) {
			return $balance;
		}

		return (float) $account['amount'];
	}

	/**
	 * История начислений и списаний.
	 *
	 * @param array $history Локальная история.
	 * @param int   $user_id Пользователь.
	 * @return array
	 */
	public function history( $history, $user_id ) {
		if ( ! VL_Account_RetailCRM::loyalty_active() || 'crm' !== self::source( $user_id ) ) {
			return $history;
		}

		$account = VL_Account_RetailCRM::account( $user_id );

		if ( 'active' !== $account['status'] ) {
			return $history;
		}

		return $account['history'];
	}

	/* ------------------------------------------------------------------
	 * Данные для шаблона
	 * ------------------------------------------------------------------ */

	/**
	 * Состояние раздела «Бонусы» для шаблона.
	 *
	 * @param int $user_id Пользователь.
	 * @return array
	 */
	public static function state( $user_id ) {
		$state = array(
			'crm'          => false,
			'status'       => 'off',
			'account'      => VL_Account_RetailCRM::empty_account( 'off' ),
			'phone'        => VL_Account_User::get_phone( $user_id ),
			'phone_masked' => '',
			'terms'        => self::terms_text(),
			'privacy'      => self::privacy_text(),
			'can_join'     => false,
			'check_id'     => '',
		);

		$state['phone_masked'] = $state['phone'] ? VL_Account_Phone::format( $state['phone'] ) : '';

		$state['check_id'] = (string) get_user_meta( $user_id, self::META_CHECK, true );

		if ( ! VL_Account_RetailCRM::loyalty_active() || ! VL_Account_Settings::get( 'crm_loyalty_ui', 1 ) ) {
			return $state;
		}

		// Запасной путь: если планировщик не отработал (у части хостингов
		// WP-Cron выключен), доводим вступление здесь — покупатель как раз
		// смотрит на свои баллы.
		if ( self::pending( $user_id ) && $user_id === get_current_user_id() ) {
			self::auto_join( $user_id );

			$state['check_id'] = (string) get_user_meta( $user_id, self::META_CHECK, true );
		}

		$account = VL_Account_RetailCRM::account( $user_id );

		$state['crm']      = true;
		$state['status']   = $account['status'];
		$state['account']  = $account;
		$state['can_join'] = in_array( $account['status'], array( 'none' ), true );

		if ( '' === $state['phone'] && ! empty( $account['phone'] ) ) {
			$state['phone']        = VL_Account_Phone::normalize( $account['phone'] );
			$state['phone_masked'] = VL_Account_Phone::format( $state['phone'] );
		}

		return $state;
	}

	/**
	 * Текст условий программы лояльности (из настроек Simla).
	 *
	 * @return string
	 */
	public static function terms_text() {
		return (string) VL_Account_RetailCRM::crm_setting( 'loyalty_terms', '' );
	}

	/**
	 * Текст согласия на обработку данных (из настроек Simla).
	 *
	 * @return string
	 */
	public static function privacy_text() {
		return (string) VL_Account_RetailCRM::crm_setting( 'loyalty_personal', '' );
	}

	/**
	 * Человеческое описание правил уровня.
	 *
	 * @param array $level Уровень из account()['level'].
	 * @param string $currency Валюта программы.
	 * @return array Строки с правилами.
	 */
	public static function level_rules( $level, $currency = '' ) {
		$rules = array();

		if ( empty( $level['type'] ) ) {
			return $rules;
		}

		$size  = $level['size'];
		$promo = $level['size_promo'];

		switch ( $level['type'] ) {
			case 'bonus_converting':
				$rules[] = sprintf(
					/* translators: 1: сумма покупки, 2: валюта. */
					__( 'Обычные товары: 1 балл за каждые %1$s %2$s', 'vl-account' ),
					number_format_i18n( $size, 0 ),
					$currency
				);
				$rules[] = sprintf(
					/* translators: 1: сумма покупки, 2: валюта. */
					__( 'Акционные товары: 1 балл за каждые %1$s %2$s', 'vl-account' ),
					number_format_i18n( $promo, 0 ),
					$currency
				);
				break;

			case 'bonus_percent':
				$rules[] = sprintf(
					/* translators: %s — процент. */
					__( 'Обычные товары: начисляем %s%% от суммы покупки баллами', 'vl-account' ),
					number_format_i18n( $size, 0 )
				);
				$rules[] = sprintf(
					/* translators: %s — процент. */
					__( 'Акционные товары: начисляем %s%% от суммы покупки баллами', 'vl-account' ),
					number_format_i18n( $promo, 0 )
				);
				break;

			case 'discount':
				$rules[] = sprintf(
					/* translators: %s — процент. */
					__( 'Обычные товары: скидка %s%%', 'vl-account' ),
					number_format_i18n( $size, 0 )
				);
				$rules[] = sprintf(
					/* translators: %s — процент. */
					__( 'Акционные товары: скидка %s%%', 'vl-account' ),
					number_format_i18n( $promo, 0 )
				);
				break;
		}

		return $rules;
	}

	/**
	 * Уровень даёт скидку, а не баллы.
	 *
	 * @param array $account Данные участия.
	 * @return bool
	 */
	public static function is_discount_level( $account ) {
		return isset( $account['level']['type'] ) && 'discount' === $account['level']['type'];
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Проверка nonce и авторизации.
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

		if ( ! VL_Account_RetailCRM::loyalty_active() ) {
			wp_send_json_error( array( 'message' => __( 'Программа лояльности сейчас недоступна.', 'vl-account' ) ) );
		}

		return (int) $user_id;
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
	 * Вступление в программу лояльности.
	 */
	public function ajax_register() {
		$user_id = $this->guard();

		$phone = $this->post( 'phone' );

		if ( '' === $phone ) {
			$phone = VL_Account_User::get_phone( $user_id );
		}

		if ( ! VL_Account_Phone::is_valid( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Проверьте, правильно ли указан номер телефона.', 'vl-account' ) ) );
		}

		if ( self::terms_text() && ! $this->post( 'terms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Подтвердите согласие с условиями программы лояльности.', 'vl-account' ) ) );
		}

		if ( self::privacy_text() && ! $this->post( 'privacy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Подтвердите согласие на обработку персональных данных.', 'vl-account' ) ) );
		}

		$result = VL_Account_RetailCRM::register_account( $user_id, $phone );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Телефон, которым покупатель вступил в ПЛ, сохраняем в кабинете.
		$normalized = VL_Account_Phone::normalize( $phone );

		if ( $normalized && ! VL_Account_User::get_phone( $user_id ) ) {
			update_user_meta( $user_id, VL_Account_User::META_PHONE, $normalized );
		}

		VL_Account_User::save_consents( $user_id, array( 'loyalty' => true ) );

		wp_send_json_success(
			array(
				'message' => __( 'Готово! Участие оформлено.', 'vl-account' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Активация участия.
	 */
	public function ajax_activate() {
		$user_id = $this->guard();

		$account = VL_Account_RetailCRM::account( $user_id, true );

		if ( ! $account['id'] ) {
			wp_send_json_error( array( 'message' => __( 'Не найдено участие в программе лояльности.', 'vl-account' ) ) );
		}

		$result = VL_Account_RetailCRM::activate_account( $user_id, $account['id'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( '' !== $result['check_id'] ) {
			wp_send_json_success(
				array(
					'sms'      => true,
					'check_id' => $result['check_id'],
					'message'  => __( 'Мы отправили код подтверждения в SMS.', 'vl-account' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Участие активировано.', 'vl-account' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Подтверждение активации кодом из SMS.
	 */
	public function ajax_confirm() {
		$user_id = $this->guard();

		$result = VL_Account_RetailCRM::confirm_activation( $user_id, $this->post( 'code' ), $this->post( 'check_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Участие активировано.', 'vl-account' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Принудительно обновить данные из CRM.
	 */
	public function ajax_refresh() {
		$user_id = $this->guard();

		VL_Account_RetailCRM::flush( $user_id );
		VL_Account_RetailCRM::account( $user_id, true );

		wp_send_json_success( array( 'reload' => true ) );
	}

	/**
	 * Сбросить кэш текущего пользователя.
	 */
	public static function flush_current() {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			VL_Account_RetailCRM::flush( $user_id );
		}
	}

	/* ------------------------------------------------------------------
	 * Совместимость со стандартным разделом Simla
	 * ------------------------------------------------------------------ */

	/**
	 * Убрать пункт «Loyalty program» из меню WooCommerce — у нас свой раздел «Бонусы».
	 *
	 * @param array $items Пункты меню.
	 * @return array
	 */
	public function remove_crm_menu_item( $items ) {
		if ( ! VL_Account_Settings::get( 'crm_hide_wc_loyalty', 1 ) ) {
			return $items;
		}

		unset( $items['loyalty'] );

		return $items;
	}

	/**
	 * Перенаправить стандартный эндпоинт /loyalty в наш раздел «Бонусы».
	 */
	public function redirect_crm_endpoint() {
		if ( ! VL_Account_Settings::get( 'crm_hide_wc_loyalty', 1 ) || ! vlacc_is_woo() ) {
			return;
		}

		global $wp;

		if ( ! isset( $wp->query_vars['loyalty'] ) ) {
			return;
		}

		$tabs = VL_Account_Router::tabs();

		if ( ! isset( $tabs['bonus'] ) ) {
			return;
		}

		wp_safe_redirect( VL_Account_Router::url( 'bonus' ) );
		exit;
	}
}
