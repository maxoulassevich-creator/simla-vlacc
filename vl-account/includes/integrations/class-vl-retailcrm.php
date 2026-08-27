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
	 * Клиент API для записи (только Simla).
	 *
	 * @var WC_Retailcrm_Proxy|false|null
	 */
	private static $writer = null;

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
	 * Ключ API заполнен: свой или из настроек Simla.
	 *
	 * @return bool
	 */
	public static function api_ready() {
		return self::own_key_ready() || self::simla_key_ready();
	}

	/**
	 * Ключ API есть в настройках плагина Simla.
	 *
	 * @return bool
	 */
	public static function simla_key_ready() {
		return '' !== trim( (string) self::crm_setting( 'api_url' ) )
			&& '' !== trim( (string) self::crm_setting( 'api_key' ) );
	}

	/**
	 * Заполнен свой ключ API — тот, которым кабинет читает CRM сам.
	 *
	 * @return bool
	 */
	public static function own_key_ready() {
		return '' !== trim( (string) VL_Account_Settings::get( 'crm_api_url', '' ) )
			&& '' !== trim( (string) VL_Account_Settings::get( 'crm_api_key', '' ) );
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

		// Со своим ключом кабинет читает CRM сам, без плагина Simla.
		if ( self::own_key_ready() ) {
			return true;
		}

		return self::plugin_active() && self::simla_key_ready();
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
	 * Клиент API для чтения.
	 *
	 * Если в настройках кабинета заполнен свой ключ — читаем им, своим
	 * клиентом: он не зависит от плагина Simla, пишет журнал запросов и
	 * физически не умеет менять данные в CRM. Иначе работаем, как раньше,
	 * транспортом Simla.
	 *
	 * @return WC_Retailcrm_Proxy|VL_Account_CRM_Client|false
	 */
	public static function api() {
		if ( null !== self::$api ) {
			return self::$api;
		}

		self::$api = false;

		if ( ! self::enabled() ) {
			return self::$api;
		}

		if ( self::own_key_ready() ) {
			self::$api = new VL_Account_CRM_Client(
				(string) VL_Account_Settings::get( 'crm_api_url', '' ),
				(string) VL_Account_Settings::get( 'crm_api_key', '' ),
				(string) VL_Account_Settings::get( 'crm_api_site', '' )
			);

			return self::$api;
		}

		self::$api = self::writer();

		return self::$api;
	}

	/**
	 * Клиент API для записи — только транспорт Simla.
	 *
	 * Писать в CRM должен кто-то один, иначе покупатели и заказы начнут
	 * спорить друг с другом. Этот кто-то — плагин Simla; наш ключ работает
	 * только на чтение. Здесь остались две операции: простановка externalId
	 * существующей карточке и вступление в программу лояльности.
	 *
	 * @return WC_Retailcrm_Proxy|false
	 */
	public static function writer() {
		if ( null !== self::$writer ) {
			return self::$writer;
		}

		self::$writer = false;

		if ( ! VL_Account_Settings::get( 'crm_enabled', 1 ) || ! self::plugin_active() || ! self::simla_key_ready() ) {
			return self::$writer;
		}

		try {
			self::$writer = new WC_Retailcrm_Proxy(
				self::crm_setting( 'api_url' ),
				self::crm_setting( 'api_key' ),
				'yes' === self::crm_setting( 'corporate_enabled', 'no' )
			);
		} catch ( Throwable $e ) {
			self::log( 'Не удалось создать клиент API', array( 'error' => $e->getMessage() ) );
			self::$writer = false;
		}

		return self::$writer;
	}

	/**
	 * Ответ CRM успешен.
	 *
	 * Ответы приходят от двух разных клиентов — своего и Simla, — поэтому
	 * проверку держим в одном месте.
	 *
	 * @param mixed $response Ответ.
	 * @return bool
	 */
	public static function ok( $response ) {
		if ( $response instanceof VL_Account_CRM_Response ) {
			return $response->isSuccessful();
		}

		if ( class_exists( 'WC_Retailcrm_Response' ) && $response instanceof WC_Retailcrm_Response ) {
			return $response->isSuccessful();
		}

		return false;
	}

	/**
	 * В успешном ответе есть такой ключ.
	 *
	 * @param mixed  $response Ответ.
	 * @param string $key      Ключ.
	 * @return bool
	 */
	public static function has( $response, $key ) {
		return self::ok( $response ) && $response->offsetExists( $key );
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

		// Объект Simla не только читает: он регистрирует участие, активирует
		// его и подтверждает код. Значит ему нужен пишущий транспорт Simla —
		// наш ключ работает только на чтение и все эти вызовы отклонит.
		$api = self::writer();

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
		// Выгрузка покупателя в CRM — запись, транспорт только от Simla.
		$api = self::writer();

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

		if ( VL_Account_RetailCRM::ok( $response ) && ! empty( $response['customer']['id'] ) ) {
			self::$crm_ids[ $user_id ] = (int) $response['customer']['id'];

			return self::$crm_ids[ $user_id ];
		}

		// В CRM карточку могли завести руками — тогда externalId у неё нет,
		// и по ID пользователя сайта она не находится. Ищем по телефону
		// и привязываем карточку к аккаунту, чтобы связь была постоянной.
		self::$crm_ids[ $user_id ] = self::link_by_phone( $user_id );

		return self::$crm_ids[ $user_id ];
	}

	/**
	 * Счета программы лояльности по телефону покупателя.
	 *
	 * Работает даже тогда, когда карточка клиента в CRM ещё не связана
	 * с аккаунтом сайта: номер у счёта программы свой. Найденную карточку
	 * заодно привязываем, чтобы дальше всё шло штатным путём.
	 *
	 * @param int $user_id Пользователь.
	 * @return array Записи об участии (может быть несколько при дублях карточек).
	 */
	public static function loyalty_by_phone( $user_id ) {
		if ( ! class_exists( 'VL_Account_RetailCRM_Directory' ) || ! VL_Account_Settings::get( 'crm_link_by_phone', 1 ) ) {
			return array();
		}

		$api   = self::api();
		$phone = VL_Account_User::get_phone( $user_id );

		if ( ! $api || '' === $phone ) {
			return array();
		}

		$accounts = VL_Account_RetailCRM_Directory::loyalty_accounts_by_phone( $api, $phone );
		$accounts = self::own_loyalty_accounts( $api, $accounts, $phone, $user_id );

		if ( ! $accounts ) {
			return array();
		}

		$crm_id = VL_Account_RetailCRM_Directory::customer_id_from_account( self::pick_loyalty( $accounts ) );

		if ( $crm_id && empty( self::$crm_ids[ $user_id ] ) ) {
			// Карточку CRM привязываем к аккаунту, но только свободную.
			$customer = VL_Account_RetailCRM_Directory::fetch_customer( $api, $crm_id );
			$external = ( is_array( $customer ) && isset( $customer['externalId'] ) ) ? (int) $customer['externalId'] : 0;

			if ( ! $external ) {
				self::assign_external_id( $crm_id, $user_id );
				self::$crm_ids[ $user_id ] = $crm_id;
			} elseif ( $external === (int) $user_id ) {
				self::$crm_ids[ $user_id ] = $crm_id;
			}

			if ( is_array( $customer ) ) {
				VL_Account_RetailCRM_Directory::store( $customer );
			}
		}

		self::log(
			'Участие в программе лояльности найдено по телефону',
			array(
				'user_id' => $user_id,
				'crm_id'  => $crm_id,
				'счетов'  => count( $accounts ),
			)
		);

		return $accounts;
	}

	/**
	 * Отсеять счета программы, заведённые на чужую карточку.
	 *
	 * Номер у счёта программы свой и с карточкой клиента может не совпадать:
	 * счёт заводится по номеру, а карточка — по externalId. Если эти двое
	 * разошлись, по номеру находится участие постороннего человека, и кабинет
	 * показывает чужие баллы. Поэтому счёт принимаем, только когда карточка
	 * его владельца подтверждает номер или уже принадлежит этому аккаунту.
	 *
	 * @param object $api      Клиент API.
	 * @param array  $accounts Счета из CRM.
	 * @param string $phone    Нормализованный номер.
	 * @param int    $user_id  Пользователь сайта.
	 * @return array
	 */
	public static function own_loyalty_accounts( $api, $accounts, $phone, $user_id = 0 ) {
		$own = array();

		foreach ( (array) $accounts as $account ) {
			$crm_id = VL_Account_RetailCRM_Directory::customer_id_from_account( $account );

			if ( ! $crm_id ) {
				continue;
			}

			$customer = VL_Account_RetailCRM_Directory::fetch_customer( $api, $crm_id );

			// Карточку прочитать не удалось (нет связи, карточки уже нет) —
			// доказательств чужого владельца тоже нет, счёт оставляем.
			if ( ! is_array( $customer ) ) {
				$own[] = $account;

				continue;
			}

			$external = isset( $customer['externalId'] ) ? (int) $customer['externalId'] : 0;
			$mine     = $user_id && $external === (int) $user_id;

			if ( ! $mine && ! VL_Account_RetailCRM_Directory::has_phone( $customer, $phone ) ) {
				self::log(
					'Счёт программы лояльности заведён на чужую карточку — пропускаем',
					array(
						'user_id'    => $user_id,
						'crm_id'     => $crm_id,
						'externalId' => $external,
						'счёт'       => isset( $account['id'] ) ? (int) $account['id'] : 0,
					)
				);

				continue;
			}

			$own[] = $account;
		}

		return $own;
	}

	/**
	 * Размер приветственных баллов.
	 *
	 * Нужен, чтобы отличить счёт с настоящими баллами покупателя от счёта,
	 * на котором лежит только приветственное начисление.
	 *
	 * @return float
	 */
	public static function welcome_amount() {
		return (float) apply_filters( 'vlacc_crm_welcome_bonus', (float) VL_Account_Settings::get( 'crm_welcome_bonus', 1000 ) );
	}

	/**
	 * Выбрать счёт программы лояльности, если их несколько.
	 *
	 * При дублях карточек в CRM у человека оказывается два счёта: на одном —
	 * приветственная тысяча, на другом — настоящий баланс, начисленный
	 * менеджером или заказами. Показывать нужно настоящий, в любую сторону
	 * от приветственной суммы. Порядок предпочтений:
	 *
	 *   1. счёт не пустой (есть баллы или заказы);
	 *   2. сумма отличается от приветственной;
	 *   3. участие активно;
	 *   4. больше сумма заказов, затем больше баллов.
	 *
	 * @param array $accounts Счета из CRM.
	 * @return array|false
	 */
	public static function pick_loyalty( $accounts ) {
		$accounts = array_values( array_filter( (array) $accounts, 'is_array' ) );

		if ( ! $accounts ) {
			return false;
		}

		$welcome = self::welcome_amount();
		$best    = false;
		$rank    = null;

		foreach ( $accounts as $account ) {
			$amount     = isset( $account['amount'] ) ? (float) $account['amount'] : 0.0;
			$orders_sum = isset( $account['ordersSum'] ) ? (float) $account['ordersSum'] : 0.0;

			// Счёт считается «приветственным» только если сумма совпадает с
			// начислением за регистрацию И покупок по нему не было: у живого
			// покупателя баланс может случайно совпасть с круглой тысячей.
			$welcome_only = $welcome > 0 && abs( $amount - $welcome ) < 0.01 && $orders_sum <= 0;

			$weight = array(
				( $amount > 0 || $orders_sum > 0 ) ? 1 : 0,
				$welcome_only ? 0 : 1,
				empty( $account['active'] ) ? 0 : 1,
				$orders_sum,
				$amount,
			);

			if ( false === $best || self::weight_beats( $weight, $rank ) ) {
				$best = $account;
				$rank = $weight;
			}
		}

		return $best;
	}

	/**
	 * Сравнить веса счетов по порядку значимости.
	 *
	 * @param array $weight  Кандидат.
	 * @param array $current Текущий лучший.
	 * @return bool
	 */
	protected static function weight_beats( $weight, $current ) {
		foreach ( $weight as $index => $value ) {
			$other = isset( $current[ $index ] ) ? $current[ $index ] : 0;

			if ( $value === $other ) {
				continue;
			}

			return $value > $other;
		}

		return false;
	}

	/**
	 * Найти карточку CRM по телефону покупателя и привязать её к аккаунту.
	 *
	 * @param int $user_id Пользователь.
	 * @return int ID покупателя в CRM или 0.
	 */
	public static function link_by_phone( $user_id ) {
		if ( ! class_exists( 'VL_Account_RetailCRM_Directory' ) || ! VL_Account_Settings::get( 'crm_link_by_phone', 1 ) ) {
			return 0;
		}

		$phone = VL_Account_User::get_phone( $user_id );

		if ( '' === $phone ) {
			return 0;
		}

		// Карточка со счётом программы лояльности важнее всех: на ней лежат
		// баллы, и списание на оформлении заказа Simla делает именно по её
		// externalId. Если он указывает не на тот аккаунт, покупатель видит
		// баллы, но потратить их не может.
		$card = VL_Account_RetailCRM_Directory::card_by_loyalty( self::api(), $phone );
		$row  = false;

		if ( is_array( $card ) && ! empty( $card['id'] ) ) {
			$row = VL_Account_RetailCRM_Directory::row_from_customer( $card, $phone );
		}

		if ( ! $row ) {
			$found = VL_Account_RetailCRM_Directory::find_by_phone( $phone );

			// На номере карточки разных людей — привязывать нечего и опасно.
			if ( $found && '' !== VL_Account_RetailCRM_Directory::conflict_reason( $found ) ) {
				return 0;
			}

			$row = VL_Account_RetailCRM_Directory::row_for_user( $phone, $user_id );

			if ( ! $row ) {
				$row = $found;
			}
		}

		if ( ! $row || empty( $row['crm_id'] ) ) {
			return 0;
		}

		$external = ! empty( $row['external_id'] ) ? (int) $row['external_id'] : 0;

		// Карточка привязана к другому аккаунту сайта. Если тот аккаунт ещё
		// существует — не перехватываем: это чужая связь. Если его удалили
		// (или он был объединён с нашим), связь осиротела — забираем себе,
		// иначе баллы этой карточки останутся недоступными для списания.
		if ( $external && $external !== (int) $user_id ) {
			$owner  = get_user_by( 'id', $external );
			$merged = ( $owner && class_exists( 'VL_Account_Identity' ) )
				? (int) get_user_meta( $external, VL_Account_Identity::META_MERGED, true )
				: 0;

			if ( $owner && $merged !== (int) $user_id ) {
				self::log(
					'Карточка CRM привязана к другому аккаунту — списание баллов работать не будет',
					array(
						'user_id'    => $user_id,
						'crm_id'     => (int) $row['crm_id'],
						'externalId' => $external,
					)
				);

				return 0;
			}
		}

		$crm_id = (int) $row['crm_id'];

		if ( $external !== (int) $user_id ) {
			self::assign_external_id( $crm_id, $user_id );
		}

		self::log(
			'Карточка CRM привязана к аккаунту по телефону',
			array(
				'user_id' => $user_id,
				'crm_id'  => $crm_id,
			)
		);

		return $crm_id;
	}

	/**
	 * Передать карточку CRM объединённого аккаунта выжившему.
	 *
	 * После слияния дублей аккаунт-источник удаляется, и карточка CRM с его
	 * externalId остаётся ничьей — вместе с заказами и баллами. Если у
	 * выжившего аккаунта своей карточки нет, отдаём осиротевшую ему.
	 *
	 * @param int $from_id Погашенный аккаунт.
	 * @param int $into_id Выживший аккаунт.
	 * @return bool
	 */
	public static function rebind_card( $from_id, $into_id ) {
		if ( ! class_exists( 'VL_Account_RetailCRM_Directory' ) || ! self::enabled() ) {
			return false;
		}

		if ( ! VL_Account_Settings::get( 'crm_link_by_phone', 1 ) ) {
			return false;
		}

		$phone = VL_Account_User::get_phone( $into_id );

		if ( '' === $phone ) {
			$phone = VL_Account_User::get_phone( $from_id );
		}

		if ( '' === $phone ) {
			return false;
		}

		$rows = VL_Account_RetailCRM_Directory::rows_by_phone( $phone );

		// На номере карточки разных людей — чужую карточку не передаём.
		if ( '' !== VL_Account_RetailCRM_Directory::conflict_reason( VL_Account_RetailCRM_Directory::combine( $rows ) ) ) {
			return false;
		}

		$orphan = 0;

		foreach ( $rows as $row ) {
			$external = (int) $row['external_id'];

			// У выжившего своя карточка — вторую к нему не привязать.
			if ( $external === (int) $into_id ) {
				return false;
			}

			if ( $external === (int) $from_id && ! $orphan ) {
				$orphan = (int) $row['crm_id'];
			}
		}

		if ( ! $orphan ) {
			return false;
		}

		return self::assign_external_id( $orphan, $into_id );
	}

	/**
	 * Отдать новому аккаунту карточку, которая уже есть в CRM на этом номере.
	 *
	 * Simla заводит клиента на хуке user_register и ищет его **по почте**
	 * (customersList['email']). Технический адрес вида 79001234567@phone.сайт
	 * мы в CRM не отправляем, поэтому у существующей карточки его нет — и Simla
	 * создаёт вторую карточку на того же человека. Так у покупателя, который
	 * заводит аккаунт заново, в CRM появляются дубли, а баллы остаются на
	 * старой карточке и не тратятся.
	 *
	 * Здесь мы успеваем раньше: находим карточку по номеру и проставляем ей
	 * externalId нового аккаунта. Дальше данные в неё дошлёт наш же вызов
	 * updateCustomer у Simla — карточка одна.
	 *
	 * Забираем только свободную или осиротевшую карточку: занятую живым
	 * аккаунтом не трогаем, номер с карточками разных людей пропускаем.
	 *
	 * @param int    $user_id Новый аккаунт.
	 * @param string $phone   Нормализованный номер.
	 * @return int ID карточки CRM или 0.
	 */
	public static function adopt_card_for_new_user( $user_id, $phone ) {
		$phone = VL_Account_Phone::normalize( $phone );

		if ( ! $user_id || '' === $phone || ! self::enabled() ) {
			return 0;
		}

		if ( ! VL_Account_Settings::get( 'crm_link_by_phone', 1 ) || ! class_exists( 'VL_Account_RetailCRM_Directory' ) ) {
			return 0;
		}

		if ( ! self::writer() ) {
			return 0;
		}

		// Карточка счёта программы лояльности — самая надёжная; если счёта нет,
		// берём единственную карточку номера.
		$card = VL_Account_RetailCRM_Directory::card_by_loyalty( self::api(), $phone );
		$row  = is_array( $card ) && ! empty( $card['id'] )
			? VL_Account_RetailCRM_Directory::row_from_customer( $card, $phone )
			: false;

		if ( ! $row ) {
			$found = VL_Account_RetailCRM_Directory::find_by_phone( $phone );

			if ( ! $found || '' !== VL_Account_RetailCRM_Directory::conflict_reason( $found ) ) {
				return 0;
			}

			$row = $found;
		}

		if ( empty( $row['crm_id'] ) ) {
			return 0;
		}

		$external = ! empty( $row['external_id'] ) ? (int) $row['external_id'] : 0;

		// Карточка занята живым аккаунтом — не перехватываем.
		if ( $external && get_user_by( 'id', $external ) ) {
			return 0;
		}

		if ( ! self::assign_external_id( (int) $row['crm_id'], $user_id ) ) {
			return 0;
		}

		self::$crm_ids[ (int) $user_id ] = (int) $row['crm_id'];

		self::log(
			'Новый аккаунт получил существующую карточку CRM — дубль не заводим',
			array(
				'user_id' => $user_id,
				'crm_id'  => (int) $row['crm_id'],
				'было'    => $external,
			)
		);

		return (int) $row['crm_id'];
	}

	/**
	 * Проставить карточке CRM externalId — ID пользователя сайта.
	 *
	 * После этого покупателя находят и наш мост, и сам плагин Simla.
	 *
	 * @param int $crm_id  Покупатель в CRM.
	 * @param int $user_id Пользователь сайта.
	 * @return bool
	 */
	public static function assign_external_id( $crm_id, $user_id ) {
		// Запись в CRM идёт только транспортом Simla: свой ключ читает.
		$api = self::writer();

		if ( ! $api || ! $crm_id || ! $user_id ) {
			if ( ! $api ) {
				self::log(
					'Записать externalId некому: плагин Simla выключен или не настроен',
					array(
						'user_id' => $user_id,
						'crm_id'  => $crm_id,
					)
				);
			}

			return false;
		}

		$response = null;

		// Штатный метод CRM для простановки externalId существующим клиентам.
		if ( is_callable( array( $api, 'customersFixExternalIds' ) ) ) {
			$response = $api->customersFixExternalIds(
				array(
					array(
						'id'         => (int) $crm_id,
						'externalId' => (int) $user_id,
					),
				)
			);
		}

		if ( ! VL_Account_RetailCRM::ok( $response ) ) {
			$response = $api->customersEdit(
				array(
					'id'         => (int) $crm_id,
					'externalId' => (int) $user_id,
				),
				'id'
			);
		}

		$ok = VL_Account_RetailCRM::ok( $response );

		if ( ! $ok ) {
			self::log(
				'Не удалось привязать карточку CRM к аккаунту',
				array(
					'user_id' => $user_id,
					'crm_id'  => $crm_id,
				)
			);

			return false;
		}

		// В снимке справочника связь тоже обновляем.
		if ( class_exists( 'VL_Account_RetailCRM_Directory' ) ) {
			VL_Account_RetailCRM_Directory::set_external_id( $crm_id, $user_id );
		}

		return true;
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

		$crm_id   = self::crm_customer_id( $user_id );
		$accounts = array();

		if ( $crm_id ) {
			$response = $api->getLoyaltyAccountList( array( 'customerId' => $crm_id ) );

			if ( ! VL_Account_RetailCRM::ok( $response ) ) {
				self::log( 'Не удалось получить участие в программе лояльности', array( 'user_id' => $user_id ) );

				return self::empty_account( 'error' );
			}

			$accounts = $response->offsetExists( 'loyaltyAccounts' ) ? (array) $response['loyaltyAccounts'] : array();
		}

		// Ищем участие ещё и по телефону, если карточки с покупателем найти не
		// удалось или их у него в CRM несколько: баллы живут на счёте программы,
		// и на дубле карточки может лежать как раз настоящий баланс.
		if ( ! $accounts || self::has_duplicate_cards( $user_id ) ) {
			$accounts = self::merge_accounts( $accounts, self::loyalty_by_phone( $user_id ) );
		}

		$raw = self::pick_loyalty( $accounts );

		if ( ! $raw ) {
			return self::empty_account( 'none' );
		}

		// Баллы лежат на одной карточке, а аккаунт привязан к другой. Показать
		// баланс мы можем (счёт нашли по телефону), а списать — нет: Simla
		// ищет покупателя по externalId. Чинится это только объединением
		// карточек в самой CRM, поэтому случай уходит в отчёт.
		$raw_card = class_exists( 'VL_Account_RetailCRM_Directory' )
			? VL_Account_RetailCRM_Directory::customer_id_from_account( $raw )
			: 0;

		if ( $crm_id && $raw_card && $raw_card !== $crm_id && class_exists( 'VL_Account_Identity' ) ) {
			VL_Account_Identity::log(
				'conflict',
				array(
					'phone'   => VL_Account_User::get_phone( $user_id ),
					'user_id' => $user_id,
					'source'  => 'loyalty',
					'note'    => 'баллы на карточке ' . $raw_card . ', аккаунт привязан к карточке ' . $crm_id
						. ' — списание не сработает, объедините карточки в CRM',
				)
			);
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
			$burning = $loyalty->getBonusDetails( $account['id'], 'burn_soon' );
			$waiting = $loyalty->getBonusDetails( $account['id'], 'waiting_activation' );

			$account['history']        = self::normalize_history( $loyalty->getLoyaltyHistory( $account['id'] ) );
			$account['burn']           = self::first_bonus( $burning );
			$account['activation']     = self::first_bonus( $waiting );
			$account['burn_sum']       = self::sum_bonus( $burning );
			$account['activation_sum'] = self::sum_bonus( $waiting );
		}

		return $account;
	}

	/**
	 * У покупателя в CRM больше одной карточки с его телефоном.
	 *
	 * @param int $user_id Пользователь.
	 * @return bool
	 */
	protected static function has_duplicate_cards( $user_id ) {
		if ( ! class_exists( 'VL_Account_RetailCRM_Directory' ) ) {
			return false;
		}

		$phone = VL_Account_User::get_phone( $user_id );

		if ( '' === $phone ) {
			return false;
		}

		// Ровно одна карточка в снимке — дублей нет, спрашивать нечего.
		// Ноль означает не «дублей нет», а «мы не знаем»: снимок могли не
		// делать, выключить или он устарел, а вторая карточка с настоящими
		// баллами в CRM уже есть. В таком случае спрашиваем по телефону.
		return 1 !== count( VL_Account_RetailCRM_Directory::crm_ids_by_phone( $phone ) );
	}

	/**
	 * Соединить списки счетов, отбрасывая повторы.
	 *
	 * @param array $first  Первый список.
	 * @param array $second Второй список.
	 * @return array
	 */
	protected static function merge_accounts( $first, $second ) {
		$all = array();

		foreach ( array_merge( (array) $first, (array) $second ) as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}

			$key         = isset( $account['id'] ) ? 'id-' . (int) $account['id'] : 'n-' . count( $all );
			$all[ $key ] = $account;
		}

		return array_values( $all );
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
			'burn'           => null,
			'activation'     => null,
			'burn_sum'       => 0.0,
			'activation_sum' => 0.0,
			'history'        => array(),
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
	 * Сколько всего баллов в списке.
	 *
	 * CRM отдаёт ожидание активации и сгорание отдельными партиями с разными
	 * датами. Для строки «ждут активации» нужна вся сумма, а не ближайшая.
	 *
	 * @param mixed $bonuses Ответ CRM.
	 * @return float
	 */
	protected static function sum_bonus( $bonuses ) {
		if ( ! is_array( $bonuses ) ) {
			return 0.0;
		}

		$sum = 0.0;

		foreach ( $bonuses as $bonus ) {
			if ( is_array( $bonus ) && isset( $bonus['amount'] ) ) {
				$sum += (float) $bonus['amount'];
			}
		}

		return $sum;
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

		if ( ! VL_Account_RetailCRM::ok( $response ) ) {
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
	 * Заказы покупателя в CRM
	 * ------------------------------------------------------------------ */

	/**
	 * История заказов покупателя из CRM.
	 *
	 * Нужна для заказов, которых нет на сайте: оформленных офлайн, по телефону
	 * или перенесённых в CRM из другой системы. Заказы сайта отдаёт сам
	 * WooCommerce — они узнаются по externalId и в список не попадают.
	 *
	 * @param int $user_id Пользователь.
	 * @param int $limit   Сколько заказов.
	 * @return array Список заказов: number, date, status, total, items.
	 */
	public static function orders( $user_id, $limit = 20 ) {
		$user_id = (int) $user_id;

		if ( ! $user_id || ! self::enabled() || ! VL_Account_Settings::get( 'crm_orders_history', 1 ) ) {
			return array();
		}

		$key    = 'vlacc_crm_orders_' . (int) get_option( self::GENERATION_OPTION, 1 ) . '_' . $user_id;
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api    = self::api();
		$crm_id = self::crm_customer_id( $user_id );

		if ( ! $api || ! $crm_id || ! is_callable( array( $api, 'ordersList' ) ) ) {
			return array();
		}

		$response = $api->ordersList( array( 'customerId' => $crm_id ), 1, min( 100, (int) $limit ) );

		if ( ! VL_Account_RetailCRM::ok( $response ) ) {
			self::log( 'Не удалось получить заказы покупателя из CRM', array( 'user_id' => $user_id ) );

			return array();
		}

		$raw    = $response->offsetExists( 'orders' ) ? (array) $response['orders'] : array();
		$orders = array();

		foreach ( $raw as $order ) {
			// Заказ сайта — его и так показывает WooCommerce.
			if ( ! empty( $order['externalId'] ) ) {
				continue;
			}

			$items = 0;

			foreach ( (array) ( isset( $order['items'] ) ? $order['items'] : array() ) as $item ) {
				$items += isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			}

			$orders[] = array(
				'number' => isset( $order['number'] ) ? (string) $order['number'] : (string) ( isset( $order['id'] ) ? $order['id'] : '' ),
				'date'   => isset( $order['createdAt'] ) ? (string) $order['createdAt'] : '',
				'status' => isset( $order['status'] ) ? (string) $order['status'] : '',
				'total'  => isset( $order['totalSumm'] ) ? (float) $order['totalSumm'] : 0.0,
				'items'  => $items,
			);
		}

		$orders = apply_filters( 'vlacc_crm_orders', $orders, $user_id );

		if ( self::cache_ttl() > 0 ) {
			set_transient( $key, $orders, self::cache_ttl() );
		}

		return $orders;
	}

	/**
	 * Человеческие названия статусов заказов CRM.
	 *
	 * @return array Код => название.
	 */
	public static function order_statuses() {
		$cached = get_transient( 'vlacc_crm_statuses' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api = self::api();

		if ( ! $api || ! is_callable( array( $api, 'statusesList' ) ) ) {
			return array();
		}

		$response = $api->statusesList();
		$statuses = array();

		if ( VL_Account_RetailCRM::has( $response, 'statuses' ) ) {
			foreach ( (array) $response['statuses'] as $code => $status ) {
				$statuses[ $code ] = isset( $status['name'] ) ? (string) $status['name'] : (string) $code;
			}
		}

		set_transient( 'vlacc_crm_statuses', $statuses, DAY_IN_SECONDS );

		return $statuses;
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
	 * Что о баллах лежит в кэше прямо сейчас.
	 *
	 * Кабинет показывает покупателю именно это значение, пока не истёк кэш.
	 * В диагностике важно видеть его рядом со свежим ответом CRM.
	 *
	 * @param int $user_id Пользователь.
	 * @return array|null
	 */
	public static function cached( $user_id ) {
		$cached = get_transient( self::cache_key( (int) $user_id ) );

		return is_array( $cached ) ? $cached : null;
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
		self::$writer   = null;
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
	 * Человеческая причина отказа из ответа CRM.
	 *
	 * Без неё «CRM не ответила» ничего не объясняет: не видно, отказ это по
	 * правам ключа, опечатка в адресе или вообще нет связи.
	 *
	 * @param mixed $response Ответ.
	 * @return string
	 */
	public static function response_reason( $response ) {
		$code  = is_object( $response ) && is_callable( array( $response, 'getStatusCode' ) ) ? (int) $response->getStatusCode() : 0;
		$error = is_object( $response ) && is_callable( array( $response, 'getErrorString' ) ) ? (string) $response->getErrorString() : '';

		$known = array(
			0   => __( 'сайт не смог связаться с CRM — проверьте адрес и доступ в интернет с хостинга', 'vl-account' ),
			400 => __( 'CRM не приняла запрос', 'vl-account' ),
			403 => __( 'ключ не подошёл или ему не хватает прав', 'vl-account' ),
			404 => __( 'такого адреса в CRM нет — проверьте адрес магазина', 'vl-account' ),
		);

		$parts = array();

		if ( isset( $known[ $code ] ) ) {
			$parts[] = $known[ $code ];
		} elseif ( $code ) {
			/* translators: %d — код ответа HTTP. */
			$parts[] = sprintf( __( 'ответ CRM: %d', 'vl-account' ), $code );
		}

		if ( '' !== $error ) {
			$parts[] = $error;
		}

		return $parts ? '(' . implode( '; ', $parts ) . ')' : '—';
	}

	/**
	 * Проверка связи с CRM для диагностики.
	 *
	 * @return array ['ok' => bool, 'message' => string]
	 */
	public static function ping() {
		if ( ! self::plugin_active() && ! self::own_key_ready() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Плагин Simla.com не установлен или не активирован, свой ключ API тоже не задан.', 'vl-account' ),
			);
		}

		if ( ! self::api_ready() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Не заполнены адрес и ключ API — ни свои, ни в настройках Simla.com.', 'vl-account' ),
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

		if ( ! VL_Account_RetailCRM::ok( $response ) ) {
			$reason = self::response_reason( $response );

			return array(
				'ok'      => false,
				'message' => self::own_key_ready()
					? sprintf(
						/* translators: %s — причина отказа. */
						__( 'CRM не ответила на запрос прав своего ключа. Проверьте адрес и ключ API в настройках кабинета. %s', 'vl-account' ),
						$reason
					)
					: sprintf(
						/* translators: %s — причина отказа. */
						__( 'CRM не ответила на запрос прав ключа. Проверьте адрес и ключ API. %s', 'vl-account' ),
						$reason
					),
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
