<?php
/**
 * Мост к плагину «Simla.com» (woo-retailcrm): доступ к API, программе лояльности и кэш.
 *
 * Плагин Simla не трогаем — всё общение идёт через его публичные классы
 * (WC_Retailcrm_Proxy, WC_Retailcrm_Loyalty, WC_Retailcrm_Customers) и хуки
 * WordPress. Если плагин выключен или API не настроен, все методы возвращают
 * пустые значения, а кабинет продолжает работать на локальных данных.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ядро интеграции с RetailCRM / Simla.
 */
class VL_Account_RetailCRM {

	/**
	 * Опция настроек плагина Simla (WooCommerce integration).
	 */
	const CRM_OPTION = 'woocommerce_integration-retailcrm_settings';

	/**
	 * Префикс транзиентов с данными программы лояльности.
	 */
	const CACHE_PREFIX = 'vlacc_crm_lp_';

	/**
	 * Транзиент с кодом магазина (site) для ключа API.
	 */
	const SITE_CACHE = 'vlacc_crm_site';

	/**
	 * Опция с поколением кэша: увеличивается при полном сбросе.
	 */
	const GENERATION_OPTION = 'vlacc_crm_cache_gen';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_RetailCRM|null
	 */
	private static $instance = null;

	/**
	 * Клиент API: null — ещё не создавали, false — создать нельзя.
	 *
	 * @var WC_Retailcrm_Proxy|false|null
	 */
	private static $api = null;

	/**
	 * Объект программы лояльности плагина Simla.
	 *
	 * @var WC_Retailcrm_Loyalty|false|null
	 */
	private static $loyalty = null;

	/**
	 * Кэш аккаунтов лояльности в рамках запроса.
	 *
	 * @var array
	 */
	private static $accounts = array();

	/**
	 * Кэш ID покупателей в CRM в рамках запроса.
	 *
	 * @var array
	 */
	private static $crm_ids = array();

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_RetailCRM
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
		// Баланс и уровень меняются после заказов — сбрасываем кэш.
		add_action( 'woocommerce_order_status_changed', array( $this, 'flush_by_order' ), 10, 4 );
		add_action( 'vlacc_settings_saved', array( __CLASS__, 'flush_all' ) );
	}

	/* ------------------------------------------------------------------
	 * Доступность
	 * ------------------------------------------------------------------ */

	/**
	 * Плагин Simla установлен и загружен.
	 *
	 * @return bool
	 */
	public static function plugin_active() {
		return class_exists( 'WC_Retailcrm_Base' ) && class_exists( 'WC_Retailcrm_Proxy' );
	}

	/**
	 * Настройки плагина Simla.
	 *
	 * @return array
	 */
	public static function crm_settings() {
		$settings = get_option( self::CRM_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Одна настройка плагина Simla.
	 *
	 * @param string $key     Ключ.
	 * @param mixed  $default Значение по умолчанию.
	 * @return mixed
	 */
	public static function crm_setting( $key, $default = '' ) {
		$settings = self::crm_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Ключ API заполнен.
	 *
	 * @return bool
	 */
	public static function api_ready() {
		return '' !== trim( (string) self::crm_setting( 'api_url' ) )
			&& '' !== trim( (string) self::crm_setting( 'api_key' ) );
	}

	/**
	 * Программа лояльности включена в настройках Simla.
	 *
	 * @return bool
	 */
	public static function loyalty_enabled() {
		return 'yes' === self::crm_setting( 'loyalty', 'no' );
	}

	/**
	 * Интеграция включена: плагин активен, API настроен, галочка в наших настройках стоит.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! VL_Account_Settings::get( 'crm_enabled', 1 ) ) {
			return false;
		}

		return self::plugin_active() && self::api_ready();
	}

	/**
	 * Можно работать с программой лояльности.
	 *
	 * @return bool
	 */
	public static function loyalty_active() {
		return self::enabled() && self::loyalty_enabled() && class_exists( 'WC_Retailcrm_Loyalty' );
	}

	/* ------------------------------------------------------------------
	 * Клиенты API
	 * ------------------------------------------------------------------ */

	/**
	 * Клиент API Simla.
	 *
	 * @return WC_Retailcrm_Proxy|false
	 */
	public static function api() {
		if ( null !== self::$api ) {
			return self::$api;
		}

		self::$api = false;

		if ( ! self::enabled() ) {
			return self::$api;
		}

		try {
			self::$api = new WC_Retailcrm_Proxy(
				self::crm_setting( 'api_url' ),
				self::crm_setting( 'api_key' ),
				'yes' === self::crm_setting( 'corporate_enabled', 'no' )
			);
		} catch ( Throwable $e ) {
			self::log( 'Не удалось создать клиент API', array( 'error' => $e->getMessage() ) );
			self::$api = false;
		}

		return self::$api;
	}

	/**
	 * Объект программы лояльности плагина Simla.
	 *
	 * @return WC_Retailcrm_Loyalty|false
	 */
	public static function loyalty() {
		if ( null !== self::$loyalty ) {
			return self::$loyalty;
		}

		self::$loyalty = false;

		$api = self::api();

		if ( ! $api || ! self::loyalty_active() ) {
			return self::$loyalty;
		}

		try {
			self::$loyalty = new WC_Retailcrm_Loyalty( $api, self::crm_settings() );
		} catch ( Throwable $e ) {
			self::log( 'Не удалось создать объект лояльности', array( 'error' => $e->getMessage() ) );
			self::$loyalty = false;
		}

		return self::$loyalty;
	}

	/**
	 * Объект синхронизации покупателей плагина Simla.
	 *
	 * @return WC_Retailcrm_Customers|false
	 */
	public static function customers() {
		$api = self::api();

		if ( ! $api || ! class_exists( 'WC_Retailcrm_Customers' ) || ! class_exists( 'WC_Retailcrm_Customer_Address' ) ) {
			return false;
		}

		try {
			return new WC_Retailcrm_Customers( $api, self::crm_settings(), new WC_Retailcrm_Customer_Address() );
		} catch ( Throwable $e ) {
			self::log( 'Не удалось создать объект покупателей', array( 'error' => $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * Код магазина (site) для ключа API.
	 *
	 * @return string
	 */
	public static function site() {
		$cached = get_transient( self::SITE_CACHE );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$api = self::api();

		if ( ! $api ) {
			return '';
		}

		$site = $api->getSingleSiteForKey();
		$site = is_string( $site ) ? $site : '';

		if ( '' !== $site ) {
			set_transient( self::SITE_CACHE, $site, DAY_IN_SECONDS );
		}

		return $site;
	}

	/* ------------------------------------------------------------------
	 * Покупатель и программа лояльности
	 * ------------------------------------------------------------------ */

	/**
	 * ID покупателя в CRM по ID пользователя WordPress.
	 *
	 * @param int $user_id Пользователь.
	 * @return int 0, если покупателя в CRM нет.
	 */
	public static function crm_customer_id( $user_id ) {
		$user_id = (int) $user_id;

		if ( isset( self::$crm_ids[ $user_id ] ) ) {
			return self::$crm_ids[ $user_id ];
		}

		self::$crm_ids[ $user_id ] = 0;

		$api = self::api();

		if ( ! $api || ! $user_id ) {
			return 0;
		}

		$response = $api->customersGet( $user_id );

		if ( $response instanceof WC_Retailcrm_Response && $response->isSuccessful() && ! empty( $response['customer']['id'] ) ) {
			self::$crm_ids[ $user_id ] = (int) $response['customer']['id'];
		}

		return self::$crm_ids[ $user_id ];
	}

	/**
	 * Время жизни кэша данных лояльности.
	 *
	 * @return int Секунды.
	 */
	public static function cache_ttl() {
		$ttl = (int) VL_Account_Settings::get( 'crm_cache_ttl', 300 );

		return max( 0, min( $ttl, HOUR_IN_SECONDS ) );
	}

	/**
	 * Данные участия в программе лояльности в едином формате.
	 *
	 * Возвращаемый массив:
	 *  status       — off | error | none | inactive | active
	 *  id           — ID участия в ПЛ
	 *  amount       — баллов на счету
	 *  currency     — валюта программы
	 *  level        — [name, type, size, size_promo]
	 *  orders_sum   — сумма заказов
	 *  next_level   — сумма до следующего уровня (0 — уровень последний)
	 *  burn         — [amount, date] ближайшее сгорание или null
	 *  activation   — [amount, date] ближайшая активация или null
	 *  history      — массив операций
	 *
	 * @param int  $user_id Пользователь.
	 * @param bool $force   Игнорировать кэш.
	 * @return array
	 */
	public static function account( $user_id, $force = false ) {
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return self::empty_account( 'off' );
		}

		if ( ! $force && isset( self::$accounts[ $user_id ] ) ) {
			return self::$accounts[ $user_id ];
		}

		// Принудительное обновление сбрасывает и найденного покупателя: он мог
		// появиться в CRM только что — например, сразу после вступления в программу.
		if ( $force ) {
			unset( self::$crm_ids[ $user_id ] );
		}

		if ( ! self::loyalty_active() ) {
			self::$accounts[ $user_id ] = self::empty_account( 'off' );

			return self::$accounts[ $user_id ];
		}

		$key = self::cache_key( $user_id );

		if ( ! $force ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				self::$accounts[ $user_id ] = $cached;

				return $cached;
			}
		}

		$account = self::fetch_account( $user_id );

		self::$accounts[ $user_id ] = $account;

		// Ошибки связи не кэшируем: следующий заход должен попробовать снова.
		if ( 'error' !== $account['status'] && self::cache_ttl() > 0 ) {
			set_transient( $key, $account, self::cache_ttl() );
		}

		return $account;
	}

	/**
	 * Запрос данных лояльности в CRM.
	 *
	 * @param int $user_id Пользователь.
	 * @return array
	 */
	protected static function fetch_account( $user_id ) {
		$api = self::api();

		if ( ! $api ) {
			return self::empty_account( 'off' );
		}

		$crm_id = self::crm_customer_id( $user_id );

		if ( ! $crm_id ) {
			// Покупателя ещё нет в CRM — участвовать в ПЛ пока не в чем.
			return self::empty_account( 'none' );
		}

		$response = $api->getLoyaltyAccountList( array( 'customerId' => $crm_id ) );

		if ( ! $response instanceof WC_Retailcrm_Response || ! $response->isSuccessful() ) {
			self::log( 'Не удалось получить участие в программе лояльности', array( 'user_id' => $user_id ) );

			return self::empty_account( 'error' );
		}

		$accounts = $response->offsetExists( 'loyaltyAccounts' ) ? (array) $response['loyaltyAccounts'] : array();

		if ( ! $accounts ) {
			return self::empty_account( 'none' );
		}

		// Активное участие приоритетнее: у покупателя может быть несколько записей.
		$raw = null;

		foreach ( $accounts as $candidate ) {
			if ( ! empty( $candidate['active'] ) ) {
				$raw = $candidate;
				break;
			}

			if ( null === $raw ) {
				$raw = $candidate;
			}
		}

		if ( ! $raw ) {
			return self::empty_account( 'none' );
		}

		$active  = ! empty( $raw['active'] );
		$account = self::empty_account( $active ? 'active' : 'inactive' );

		$account['id']         = isset( $raw['id'] ) ? (int) $raw['id'] : 0;
		$account['amount']     = isset( $raw['amount'] ) ? (float) $raw['amount'] : 0.0;
		$account['orders_sum'] = isset( $raw['ordersSum'] ) ? (float) $raw['ordersSum'] : 0.0;
		$account['next_level'] = isset( $raw['nextLevelSum'] ) ? (float) $raw['nextLevelSum'] : 0.0;
		$account['currency']   = isset( $raw['loyalty']['currency'] ) ? (string) $raw['loyalty']['currency'] : '';
		$account['phone']      = isset( $raw['phoneNumber'] ) ? (string) $raw['phoneNumber'] : '';

		if ( isset( $raw['level'] ) && is_array( $raw['level'] ) ) {
			$account['level'] = array(
				'name'       => isset( $raw['level']['name'] ) ? (string) $raw['level']['name'] : '',
				'type'       => isset( $raw['level']['type'] ) ? (string) $raw['level']['type'] : '',
				'size'       => isset( $raw['level']['privilegeSize'] ) ? (float) $raw['level']['privilegeSize'] : 0.0,
				'size_promo' => isset( $raw['level']['privilegeSizePromo'] ) ? (float) $raw['level']['privilegeSizePromo'] : 0.0,
			);
		}

		if ( ! $active || ! $account['id'] ) {
			return $account;
		}

		$loyalty = self::loyalty();

		if ( $loyalty ) {
			$account['history']    = self::normalize_history( $loyalty->getLoyaltyHistory( $account['id'] ) );
			$account['burn']       = self::first_bonus( $loyalty->getBonusDetails( $account['id'], 'burn_soon' ) );
			$account['activation'] = self::first_bonus( $loyalty->getBonusDetails( $account['id'], 'waiting_activation' ) );
		}

		return $account;
	}

	/**
	 * Пустая структура данных лояльности.
	 *
	 * @param string $status Статус.
	 * @return array
	 */
	public static function empty_account( $status = 'off' ) {
		return array(
			'status'     => $status,
			'id'         => 0,
			'amount'     => 0.0,
			'currency'   => '',
			'orders_sum' => 0.0,
			'next_level' => 0.0,
			'phone'      => '',
			'level'      => array(
				'name'       => '',
				'type'       => '',
				'size'       => 0.0,
				'size_promo' => 0.0,
			),
			'burn'       => null,
			'activation' => null,
			'history'    => array(),
		);
	}

	/**
	 * Первая запись из списка бонусов (ближайшее сгорание/активация).
	 *
	 * @param mixed $bonuses Ответ CRM.
	 * @return array|null
	 */
	protected static function first_bonus( $bonuses ) {
		if ( ! is_array( $bonuses ) || ! $bonuses ) {
			return null;
		}

		$first = reset( $bonuses );

		if ( ! is_array( $first ) ) {
			return null;
		}

		return array(
			'amount' => isset( $first['amount'] ) ? (float) $first['amount'] : 0.0,
			'date'   => isset( $first['date'] ) ? (string) $first['date'] : '',
		);
	}

	/**
	 * История операций CRM в формате раздела «Бонусы».
	 *
	 * @param mixed $operations Ответ CRM.
	 * @return array
	 */
	public static function normalize_history( $operations ) {
		if ( ! is_array( $operations ) ) {
			return array();
		}

		$labels = self::operation_labels();
		$rows   = array();

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$type        = isset( $operation['type'] ) ? (string) $operation['type'] : '';
			$description = isset( $labels[ $type ] ) ? $labels[ $type ] : __( 'Операция по счёту', 'vl-account' );
			$order_id    = 0;

			if ( ! empty( $operation['order']['externalId'] ) ) {
				$order_id     = (int) $operation['order']['externalId'];
				$description .= ' ' . sprintf(
					/* translators: %s — номер заказа. */
					__( '№%s', 'vl-account' ),
					$order_id
				);
			}

			$rows[] = array(
				'date'        => isset( $operation['createdAt'] ) ? (string) $operation['createdAt'] : '',
				'amount'      => isset( $operation['amount'] ) ? (float) $operation['amount'] : 0.0,
				'description' => $description,
				'order_id'    => $order_id,
				'source'      => 'crm',
			);
		}

		return $rows;
	}

	/**
	 * Названия операций программы лояльности.
	 *
	 * @return array
	 */
	public static function operation_labels() {
		return array(
			'credit_manual'    => __( 'Начислено менеджером', 'vl-account' ),
			'charge_manual'    => __( 'Списано менеджером', 'vl-account' ),
			'credit_for_order' => __( 'Начислено за заказ', 'vl-account' ),
			'charge_for_order' => __( 'Списано за заказ', 'vl-account' ),
			'credit_for_event' => __( 'Начислено по акции', 'vl-account' ),
			'burn'             => __( 'Сгорание баллов', 'vl-account' ),
			'cancel_of_charge' => __( 'Возврат списанных баллов по заказу', 'vl-account' ),
			'cancel_of_credit' => __( 'Отмена начисления по заказу', 'vl-account' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Действия покупателя
	 * ------------------------------------------------------------------ */

	/**
	 * Зарегистрировать участие в программе лояльности.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $phone   Телефон в любом формате.
	 * @return true|WP_Error
	 */
	public static function register_account( $user_id, $phone ) {
		$loyalty = self::loyalty();

		if ( ! $loyalty ) {
			return new WP_Error( 'vlacc_crm_off', __( 'Программа лояльности сейчас недоступна.', 'vl-account' ) );
		}

		$normalized = VL_Account_Phone::normalize( $phone );

		if ( '' === $normalized ) {
			return new WP_Error( 'vlacc_crm_phone', __( 'Проверьте, правильно ли указан номер телефона.', 'vl-account' ) );
		}

		$site = self::site();

		if ( '' === $site ) {
			return new WP_Error( 'vlacc_crm_site', __( 'Не удалось определить магазин в CRM. Проверьте настройки интеграции.', 'vl-account' ) );
		}

		// В CRM покупатель должен существовать до регистрации в ПЛ.
		if ( ! self::crm_customer_id( $user_id ) ) {
			VL_Account_RetailCRM_Customer::push( $user_id );
			self::$crm_ids = array();
		}

		$ok = $loyalty->registerCustomer( $user_id, '+' . $normalized, $site );

		self::flush( $user_id );

		if ( ! $ok ) {
			return new WP_Error(
				'vlacc_crm_register',
				__( 'Не удалось зарегистрировать участие в программе лояльности. Попробуйте позже.', 'vl-account' )
			);
		}

		vlacc_log(
			'Регистрация в программе лояльности CRM',
			array(
				'user_id' => $user_id,
				'phone'   => vlacc_mask_phone( $normalized ),
			)
		);

		return true;
	}

	/**
	 * Активировать участие.
	 *
	 * @param int $user_id    Пользователь.
	 * @param int $loyalty_id ID участия.
	 * @return array|WP_Error ['check_id' => string] — требуется подтверждение по SMS.
	 */
	public static function activate_account( $user_id, $loyalty_id ) {
		$loyalty = self::loyalty();

		if ( ! $loyalty ) {
			return new WP_Error( 'vlacc_crm_off', __( 'Программа лояльности сейчас недоступна.', 'vl-account' ) );
		}

		$loyalty_id = (int) $loyalty_id;

		if ( ! $loyalty_id ) {
			return new WP_Error( 'vlacc_crm_id', __( 'Не найдено участие в программе лояльности.', 'vl-account' ) );
		}

		// Активировать можно только своё участие.
		$account = self::account( $user_id, true );

		if ( (int) $account['id'] !== $loyalty_id ) {
			return new WP_Error( 'vlacc_crm_id', __( 'Не найдено участие в программе лояльности.', 'vl-account' ) );
		}

		$response = $loyalty->activateLoyaltyCustomer( $loyalty_id );

		self::flush( $user_id );

		if ( ! $response instanceof WC_Retailcrm_Response || ! $response->isSuccessful() ) {
			return new WP_Error(
				'vlacc_crm_activate',
				__( 'Не удалось активировать участие. Попробуйте позже.', 'vl-account' )
			);
		}

		$check_id = '';

		if ( $response->offsetExists( 'verification' ) && ! empty( $response['verification']['checkId'] ) ) {
			$check_id = (string) $response['verification']['checkId'];
		}

		return array( 'check_id' => $check_id );
	}

	/**
	 * Подтвердить активацию кодом из SMS (код присылает CRM).
	 *
	 * @param int    $user_id  Пользователь.
	 * @param string $code     Код.
	 * @param string $check_id Идентификатор проверки.
	 * @return true|WP_Error
	 */
	public static function confirm_activation( $user_id, $code, $check_id ) {
		$loyalty = self::loyalty();

		if ( ! $loyalty ) {
			return new WP_Error( 'vlacc_crm_off', __( 'Программа лояльности сейчас недоступна.', 'vl-account' ) );
		}

		if ( '' === trim( $code ) || '' === trim( $check_id ) ) {
			return new WP_Error( 'vlacc_crm_code', __( 'Введите код из SMS.', 'vl-account' ) );
		}

		$ok = $loyalty->confirmSmsVerification( $code, $check_id );

		self::flush( $user_id );

		if ( ! $ok ) {
			return new WP_Error( 'vlacc_crm_code', __( 'Неверный код из SMS. Попробуйте ещё раз.', 'vl-account' ) );
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Кэш
	 * ------------------------------------------------------------------ */

	/**
	 * Ключ кэша данных лояльности.
	 *
	 * В ключ входит поколение кэша: так один сброс обесценивает данные всех
	 * покупателей сразу и при этом не нужен перебор транзиентов в базе.
	 *
	 * @param int $user_id Пользователь.
	 * @return string
	 */
	protected static function cache_key( $user_id ) {
		return self::CACHE_PREFIX . (int) get_option( self::GENERATION_OPTION, 1 ) . '_' . (int) $user_id;
	}

	/**
	 * Сбросить кэш лояльности пользователя.
	 *
	 * @param int $user_id Пользователь.
	 */
	public static function flush( $user_id ) {
		$user_id = (int) $user_id;

		unset( self::$accounts[ $user_id ], self::$crm_ids[ $user_id ] );

		delete_transient( self::cache_key( $user_id ) );
	}

	/**
	 * Сбросить весь кэш интеграции.
	 */
	public static function flush_all() {
		self::$accounts = array();
		self::$crm_ids  = array();
		self::$api      = null;
		self::$loyalty  = null;

		delete_transient( self::SITE_CACHE );

		update_option( self::GENERATION_OPTION, (int) get_option( self::GENERATION_OPTION, 1 ) + 1 );
	}

	/**
	 * Сброс кэша при смене статуса заказа: баллы начисляются и списываются именно там.
	 *
	 * @param int      $order_id   Заказ.
	 * @param string   $old_status Старый статус.
	 * @param string   $new_status Новый статус.
	 * @param WC_Order $order      Заказ.
	 */
	public function flush_by_order( $order_id, $old_status = '', $new_status = '', $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( $order && $order->get_customer_id() ) {
			self::flush( $order->get_customer_id() );
		}
	}

	/* ------------------------------------------------------------------
	 * Служебное
	 * ------------------------------------------------------------------ */

	/**
	 * Запись в журнал плагина.
	 *
	 * @param string $message Сообщение.
	 * @param array  $context Контекст.
	 */
	public static function log( $message, $context = array() ) {
		if ( function_exists( 'vlacc_log' ) ) {
			vlacc_log( '[Simla] ' . $message, $context );
		}
	}

	/**
	 * Проверка связи с CRM для диагностики.
	 *
	 * @return array ['ok' => bool, 'message' => string]
	 */
	public static function ping() {
		if ( ! self::plugin_active() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Плагин Simla.com не установлен или не активирован.', 'vl-account' ),
			);
		}

		if ( ! self::api_ready() ) {
			return array(
				'ok'      => false,
				'message' => __( 'В настройках Simla.com не заполнены адрес и ключ API.', 'vl-account' ),
			);
		}

		$api = self::api();

		if ( ! $api ) {
			return array(
				'ok'      => false,
				'message' => __( 'Интеграция выключена в настройках плагина.', 'vl-account' ),
			);
		}

		$response = $api->credentials();

		if ( ! $response instanceof WC_Retailcrm_Response || ! $response->isSuccessful() ) {
			return array(
				'ok'      => false,
				'message' => __( 'CRM не ответила на запрос прав ключа. Проверьте адрес и ключ API.', 'vl-account' ),
			);
		}

		$site = self::site();

		return array(
			'ok'      => true,
			'message' => $site
				? sprintf(
					/* translators: %s — символьный код магазина в CRM. */
					__( 'Связь есть, магазин: %s', 'vl-account' ),
					$site
				)
				: __( 'Связь есть, но у ключа не один магазин — укажите магазин в настройках Simla.', 'vl-account' ),
		);
	}
}
