<?php
/**
 * Передача данных покупателя из кабинета в RetailCRM.
 *
 * Плагин Simla выгружает покупателя на хуке user_register — то есть в тот момент,
 * когда VL Account ещё не успел записать телефон, имя и согласия. Из-за этого в CRM
 * появлялся покупатель без телефона, а вход по SMS и программа лояльности
 * привязаны именно к номеру. Этот класс досылает данные после регистрации
 * и держит их в актуальном состоянии.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Синхронизация покупателя с CRM.
 */
class VL_Account_RetailCRM_Customer {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_RetailCRM_Customer|null
	 */
	private static $instance = null;

	/**
	 * Пользователи, которых уже отправили в этом запросе.
	 *
	 * @var array
	 */
	private static $pushed = array();

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_RetailCRM_Customer
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
		// Раньше Simla: у неё создание клиента висит на user_register с
		// приоритетом 10, а нам нужно успеть отдать новому аккаунту карточку,
		// которая уже есть в CRM на этом номере. Следом возвращаем её хук на
		// место: пропуск действует ровно на один аккаунт.
		add_action( 'user_register', array( $this, 'claim_existing_card' ), 1 );
		add_action( 'user_register', array( $this, 'restore_simla_create' ), 11 );

		// Досылаем покупателя после того, как кабинет заполнил телефон и согласия.
		add_action( 'vlacc_user_registered', array( $this, 'on_registered' ), 20, 2 );
		add_action( 'vlacc_email_confirmed', array( $this, 'on_email_confirmed' ), 20, 2 );
		add_action( 'vlacc_consents_saved', array( $this, 'on_consents_saved' ), 20, 2 );
		add_action( 'vlacc_account_created_from_order', array( $this, 'on_account_from_order' ), 20, 2 );
		add_action( 'vlacc_wishlist_updated', array( $this, 'on_wishlist_updated' ), 20, 3 );

		// Данные, которые плагин Simla сам не собирает.
		add_filter( 'retailcrm_process_customer', array( $this, 'filter_customer' ), 10, 2 );

		// Заказ должен уходить в CRM уже привязанным к покупателю.
		add_filter( 'vlacc_checkout_hook_priority', array( $this, 'checkout_priority' ) );
	}

	/* ------------------------------------------------------------------
	 * Хуки кабинета
	 * ------------------------------------------------------------------ */

	/**
	 * Интеграция и синхронизация покупателя включены.
	 *
	 * @return bool
	 */
	public static function sync_enabled() {
		return VL_Account_RetailCRM::enabled() && VL_Account_Settings::get( 'crm_sync_customer', 1 );
	}

	/**
	 * Номер, с которым прямо сейчас заводится аккаунт.
	 *
	 * В момент user_register телефона в метаполях ещё нет: кабинет пишет его
	 * сразу после wp_insert_user. Поэтому номер передаём напрямую.
	 *
	 * @var string
	 */
	protected static $registering = '';

	/**
	 * Запомнить номер перед созданием аккаунта.
	 *
	 * @param string $phone Нормализованный номер.
	 */
	public static function expect_phone( $phone ) {
		self::$registering = (string) $phone;
	}

	/**
	 * Отдать новому аккаунту карточку, которая уже есть в CRM на этом номере.
	 *
	 * Simla ищет клиента по почте, а технический адрес вида @phone.сайт мы
	 * в CRM не отправляем — поэтому на второй регистрации того же телефона
	 * она заводит вторую карточку. Мы успеваем раньше: проставляем ей
	 * externalId и снимаем создание Simla на этот запрос, а данные дошлём
	 * её же методом updateCustomer.
	 *
	 * @param int $user_id Новый пользователь.
	 */
	public function claim_existing_card( $user_id ) {
		$phone = self::$registering;

		self::$registering = '';

		if ( '' === $phone || ! self::sync_enabled() ) {
			return;
		}

		if ( ! VL_Account_RetailCRM::adopt_card_for_new_user( $user_id, $phone ) ) {
			return;
		}

		self::skip_simla_create();
	}

	/**
	 * Снятые хуки Simla: что вернуть после регистрации.
	 *
	 * @var array
	 */
	protected static $removed = array();

	/**
	 * Снять создание клиента у Simla для текущего аккаунта.
	 *
	 * Карточка в CRM уже есть и уже привязана к аккаунту — второй быть
	 * не должно. Обновит её наш же вызов updateCustomer у Simla.
	 */
	protected static function skip_simla_create() {
		global $wp_filter;

		self::$removed = array();

		if ( empty( $wp_filter['user_register'] ) ) {
			return;
		}

		foreach ( $wp_filter['user_register']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_array( $callback['function'] ) || ! is_object( $callback['function'][0] ) ) {
					continue;
				}

				if ( ! $callback['function'][0] instanceof WC_Retailcrm_Base || 'create_customer' !== $callback['function'][1] ) {
					continue;
				}

				remove_action( 'user_register', $callback['function'], $priority );

				self::$removed[] = array(
					'function'      => $callback['function'],
					'priority'      => (int) $priority,
					'accepted_args' => isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : 1,
				);

				VL_Account_RetailCRM::log( 'Создание карточки у Simla пропущено: карточка уже есть' );
			}
		}
	}

	/**
	 * Вернуть снятые хуки Simla.
	 *
	 * Пропуск нужен ровно на тот аккаунт, которому мы отдали готовую карточку.
	 * Если в одном запросе заводится ещё один пользователь (импорт, админка),
	 * Simla должна отработать по-своему.
	 */
	public function restore_simla_create() {
		if ( ! self::$removed ) {
			return;
		}

		foreach ( self::$removed as $hook ) {
			add_action( 'user_register', $hook['function'], $hook['priority'], $hook['accepted_args'] );
		}

		self::$removed = array();
	}

	/**
	 * После регистрации в кабинете.
	 *
	 * @param int   $user_id Пользователь.
	 * @param array $data    Данные регистрации.
	 */
	public function on_registered( $user_id, $data = array() ) {
		if ( ! self::sync_enabled() ) {
			return;
		}

		self::push( $user_id );
	}

	/**
	 * После подтверждения e-mail: в CRM должен уехать настоящий адрес.
	 *
	 * @param int    $user_id Пользователь.
	 * @param string $email   Адрес.
	 */
	public function on_email_confirmed( $user_id, $email = '' ) {
		if ( ! self::sync_enabled() ) {
			return;
		}

		self::push( $user_id, true );
	}

	/**
	 * После изменения согласий: подписка на рассылку живёт в CRM.
	 *
	 * @param int   $user_id  Пользователь.
	 * @param array $consents Что именно изменилось.
	 */
	public function on_consents_saved( $user_id, $consents = array() ) {
		if ( ! self::sync_enabled() || ! VL_Account_Settings::get( 'crm_sync_consents', 1 ) ) {
			return;
		}

		// Гоняем данные только из-за подписки: остальные согласия CRM не хранит.
		if ( is_array( $consents ) && $consents && ! array_key_exists( 'marketing', $consents ) ) {
			return;
		}

		self::push( $user_id, true );
	}

	/**
	 * Кабинет создан по заказу.
	 *
	 * @param int      $user_id Пользователь.
	 * @param WC_Order $order   Заказ.
	 */
	public function on_account_from_order( $user_id, $order = null ) {
		if ( ! self::sync_enabled() ) {
			return;
		}

		self::push( $user_id );
	}

	/**
	 * Избранное — в пользовательское поле покупателя в CRM.
	 *
	 * @param int  $product_id Товар.
	 * @param bool $state      Добавлен или удалён.
	 * @param int  $user_id    Пользователь.
	 */
	public function on_wishlist_updated( $product_id, $state = false, $user_id = 0 ) {
		if ( ! self::sync_enabled() || ! self::wishlist_field() ) {
			return;
		}

		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return;
		}

		self::push( $user_id, true );
	}

	/* ------------------------------------------------------------------
	 * Отправка данных
	 * ------------------------------------------------------------------ */

	/**
	 * Отправить покупателя в CRM (создать или обновить).
	 *
	 * @param int  $user_id Пользователь.
	 * @param bool $force   Отправить, даже если в этом запросе уже отправляли.
	 * @return bool
	 */
	public static function push( $user_id, $force = false ) {
		$user_id = (int) $user_id;

		if ( ! $user_id || ! VL_Account_RetailCRM::enabled() ) {
			return false;
		}

		if ( ! $force && isset( self::$pushed[ $user_id ] ) ) {
			return true;
		}

		// Во время разбора истории CRM своими же изменениями данные не гоняем.
		if ( class_exists( 'WC_Retailcrm_Plugin' ) && true === WC_Retailcrm_Plugin::history_running() ) {
			return false;
		}

		$customers = VL_Account_RetailCRM::customers();

		if ( ! $customers ) {
			return false;
		}

		self::$pushed[ $user_id ] = true;

		// Телефон кабинета должен быть в биллинге: из него плагин Simla берёт номер.
		self::ensure_billing_phone( $user_id );

		try {
			if ( VL_Account_RetailCRM::crm_customer_id( $user_id ) ) {
				$customers->updateCustomer( $user_id );
			} else {
				$customers->isSubscribed = VL_Account_User::has_consent( $user_id, 'marketing' );
				$customers->registerCustomer( $user_id );
			}
		} catch ( Throwable $e ) {
			VL_Account_RetailCRM::log(
				'Ошибка синхронизации покупателя',
				array(
					'user_id' => $user_id,
					'error'   => $e->getMessage(),
				)
			);

			return false;
		}

		VL_Account_RetailCRM::flush( $user_id );

		return true;
	}

	/**
	 * Записать телефон кабинета в billing_phone, если там пусто.
	 *
	 * @param int $user_id Пользователь.
	 */
	protected static function ensure_billing_phone( $user_id ) {
		$phone = VL_Account_User::get_phone( $user_id );

		if ( '' === $phone ) {
			return;
		}

		$billing = VL_Account_Phone::normalize( get_user_meta( $user_id, 'billing_phone', true ) );

		if ( $billing === $phone ) {
			return;
		}

		if ( '' === $billing ) {
			update_user_meta( $user_id, 'billing_phone', VL_Account_Phone::format( $phone ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Данные покупателя для CRM
	 * ------------------------------------------------------------------ */

	/**
	 * Дополняем массив покупателя перед отправкой в CRM.
	 *
	 * @param array       $data     Данные покупателя.
	 * @param WC_Customer $customer Покупатель WooCommerce.
	 * @return array
	 */
	public function filter_customer( $data, $customer = null ) {
		if ( ! VL_Account_RetailCRM::enabled() || ! is_array( $data ) ) {
			return $data;
		}

		$user_id = $customer instanceof WC_Customer ? (int) $customer->get_id() : 0;

		if ( ! $user_id ) {
			return $data;
		}

		// 1. Телефон кабинета (подтверждён кодом из SMS) — главный идентификатор покупателя.
		$phone = VL_Account_User::get_phone( $user_id );

		if ( $phone ) {
			$data['phones'] = self::merge_phone(
				isset( $data['phones'] ) ? (array) $data['phones'] : array(),
				$phone
			);
		}

		// 2. Технический адрес вида 79001234567@phone.site в CRM не нужен.
		if ( VL_Account_Settings::get( 'crm_skip_tech_email', 1 ) && ! empty( $data['email'] ) ) {
			if ( preg_match( '/@phone\./', $data['email'] ) && ! empty( $data['phones'] ) ) {
				unset( $data['email'] );
			}
		}

		// 3. Согласие на рассылку.
		if ( VL_Account_Settings::get( 'crm_sync_consents', 1 ) ) {
			$consents = get_user_meta( $user_id, VL_Account_User::META_CONSENTS, true );

			if ( is_array( $consents ) && isset( $consents['marketing'] ) ) {
				$data['subscribed'] = ! empty( $consents['marketing']['value'] );
			}
		}

		// 4. Дата регистрации по местному времени магазина.
		self::fix_created_at( $data, $user_id );

		// 5. Избранное в пользовательское поле покупателя.
		$field = self::wishlist_field();

		if ( $field ) {
			$data['customFields'] = isset( $data['customFields'] ) ? (array) $data['customFields'] : array();

			$data['customFields'][ $field ] = self::wishlist_value( $user_id );
		}

		return apply_filters( 'vlacc_crm_customer_data', $data, $user_id, $customer );
	}

	/**
	 * Дата регистрации покупателя — по времени магазина.
	 *
	 * WordPress хранит дату регистрации в UTC. Плагин Simla берёт её через
	 * WC_Customer, и если часовой пояс сайта задан не городом, а смещением
	 * («UTC+3»), WooCommerce отдаёт время без поправки — в CRM карточка
	 * создаётся на три часа раньше. Пересчитываем сами.
	 *
	 * @param array $data    Данные покупателя (по ссылке).
	 * @param int   $user_id Пользователь.
	 */
	protected static function fix_created_at( &$data, $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || empty( $user->user_registered ) ) {
			return;
		}

		$local = get_date_from_gmt( $user->user_registered, 'Y-m-d H:i:s' );

		if ( $local ) {
			$data['createdAt'] = $local;
		}
	}

	/**
	 * Добавить телефон в список, если его там ещё нет.
	 *
	 * @param array  $phones Телефоны CRM.
	 * @param string $phone  Нормализованный номер.
	 * @return array
	 */
	protected static function merge_phone( $phones, $phone ) {
		$normalized = VL_Account_Phone::normalize( $phone );

		if ( '' === $normalized ) {
			return $phones;
		}

		foreach ( $phones as $row ) {
			$number = is_array( $row ) && isset( $row['number'] ) ? $row['number'] : '';

			if ( VL_Account_Phone::normalize( $number ) === $normalized ) {
				return $phones;
			}
		}

		$phones[] = array( 'number' => '+' . $normalized );

		return $phones;
	}

	/**
	 * Код пользовательского поля CRM для избранного.
	 *
	 * @return string
	 */
	public static function wishlist_field() {
		return trim( (string) VL_Account_Settings::get( 'crm_wishlist_field', '' ) );
	}

	/**
	 * Значение поля «избранное»: названия товаров и ссылки.
	 *
	 * @param int $user_id Пользователь.
	 * @return string
	 */
	public static function wishlist_value( $user_id ) {
		if ( ! vlacc_is_woo() ) {
			return '';
		}

		$items = VL_Account_Wishlist::get_items( $user_id );
		$rows  = array();

		foreach ( (array) $items as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$rows[] = sprintf( '%s (%s)', $product->get_name(), get_permalink( $product_id ) );
		}

		return implode( "\n", $rows );
	}

	/* ------------------------------------------------------------------
	 * Заказы
	 * ------------------------------------------------------------------ */

	/**
	 * Приоритет обработки оформленного заказа кабинетом.
	 *
	 * Плагин Simla выгружает заказ на приоритете 10. Кабинет должен успеть
	 * привязать заказ к покупателю раньше — иначе в CRM уедет заказ без клиента.
	 *
	 * @param int $priority Текущий приоритет.
	 * @return int
	 */
	public function checkout_priority( $priority ) {
		if ( ! VL_Account_RetailCRM::enabled() || ! VL_Account_Settings::get( 'crm_order_priority', 1 ) ) {
			return $priority;
		}

		return 5;
	}
}
