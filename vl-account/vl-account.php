<?php
/**
 * Plugin Name: VL Account — вход, регистрация по SMS и личный кабинет
 * Plugin URI:  https://example.com/
 * Description: Вход и регистрация по номеру телефона через SMS.RU (код в SMS или звонком), восстановление пароля, личный кабинет WooCommerce (заказы, избранное, промокод, бонусы, согласия), автосоздание кабинета при заказе, привязка гостевых заказов, адреса доставки и оплаты в кабинете, объединение старых аккаунтов с входом по SMS, отчёт по брошенным корзинам, автозаполнение форм. Интеграция с плагинами Simla.com (RetailCRM), WishSuite (избранное) и Back In Stock Notifier (подписка на размер). Всё выводится шорткодами.
 * Version:     2.8.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author:      —
 * Text Domain: vl-account
 * Domain Path: /languages
 * WC requires at least: 6.0
 * WC tested up to: 9.9
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

define( 'VLACC_VERSION', '2.8.0' );
define( 'VLACC_FILE', __FILE__ );
define( 'VLACC_PATH', plugin_dir_path( __FILE__ ) );
define( 'VLACC_URL', plugin_dir_url( __FILE__ ) );
define( 'VLACC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Подключение файлов плагина.
 */
require_once VLACC_PATH . 'includes/functions.php';
require_once VLACC_PATH . 'includes/class-vl-settings.php';
require_once VLACC_PATH . 'includes/class-vl-phone.php';
require_once VLACC_PATH . 'includes/class-vl-smsru.php';
require_once VLACC_PATH . 'includes/class-vl-otp.php';
require_once VLACC_PATH . 'includes/class-vl-errors.php';
require_once VLACC_PATH . 'includes/class-vl-user.php';
require_once VLACC_PATH . 'includes/class-vl-auth.php';
require_once VLACC_PATH . 'includes/class-vl-ajax.php';
require_once VLACC_PATH . 'includes/class-vl-router.php';
require_once VLACC_PATH . 'includes/class-vl-myaccount.php';
require_once VLACC_PATH . 'includes/class-vl-orders.php';
require_once VLACC_PATH . 'includes/class-vl-wishlist.php';
require_once VLACC_PATH . 'includes/class-vl-promo.php';
require_once VLACC_PATH . 'includes/class-vl-bonus.php';
require_once VLACC_PATH . 'includes/class-vl-emails.php';
require_once VLACC_PATH . 'includes/class-vl-email-confirm.php';
require_once VLACC_PATH . 'includes/class-vl-cache.php';
require_once VLACC_PATH . 'includes/class-vl-gate.php';
require_once VLACC_PATH . 'includes/class-vl-carts.php';
require_once VLACC_PATH . 'includes/class-vl-autofill.php';
require_once VLACC_PATH . 'includes/class-vl-address.php';
require_once VLACC_PATH . 'includes/class-vl-identity.php';
require_once VLACC_PATH . 'includes/class-vl-shortcodes.php';
require_once VLACC_PATH . 'includes/class-vl-admin.php';
require_once VLACC_PATH . 'includes/class-vl-identity-admin.php';

// Интеграция с плагином «Simla.com» (woo-retailcrm).
require_once VLACC_PATH . 'includes/integrations/class-vl-crm-client.php';
require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm.php';
require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-customer.php';
require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-loyalty.php';
require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-promo.php';
require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-cart.php';
require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-directory.php';

// Интеграция с плагином избранного «WishSuite».
require_once VLACC_PATH . 'includes/integrations/class-vl-wishsuite.php';

// Интеграция с плагином подписок на поступление «Back In Stock Notifier».
require_once VLACC_PATH . 'includes/integrations/class-vl-stock-notifier.php';

/**
 * Главный класс плагина.
 */
final class VL_Account_Plugin {

	/**
	 * Единственный экземпляр.
	 *
	 * @var VL_Account_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Plugin
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
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compat' ) );
	}

	/**
	 * Локализация.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'vl-account', false, dirname( VLACC_BASENAME ) . '/languages' );
	}

	/**
	 * Инициализация подсистем.
	 */
	public function init() {
		VL_Account_Settings::instance();
		VL_Account_Errors::instance();

		// Интеграция с RetailCRM / Simla подключается, только если тот плагин активен.
		// Поднимаем её до остальных подсистем: она меняет приоритеты их хуков.
		if ( VL_Account_RetailCRM::plugin_active() ) {
			VL_Account_RetailCRM::instance();
			VL_Account_RetailCRM_Customer::instance();
			VL_Account_RetailCRM_Loyalty::instance();
			VL_Account_RetailCRM_Promo::instance();
			VL_Account_RetailCRM_Cart::instance();
			VL_Account_RetailCRM_Directory::instance();
		}

		// Избранное WishSuite и кнопки размеров: классы сами проверяют,
		// установлены ли сторонние плагины, — без них просто ничего не делают.
		VL_Account_WishSuite::instance();
		VL_Account_Stock_Notifier::instance();

		VL_Account_Auth::instance();
		VL_Account_Ajax::instance();
		VL_Account_Router::instance();
		VL_Account_MyAccount::instance();
		VL_Account_Orders::instance();
		VL_Account_Wishlist::instance();
		VL_Account_Promo::instance();
		VL_Account_Bonus::instance();
		VL_Account_Emails::instance();
		VL_Account_Email_Confirm::instance();
		VL_Account_Cache::instance();
		VL_Account_Shortcodes::instance();
		VL_Account_Gate::instance();
		VL_Account_Carts::instance();
		VL_Account_Autofill::instance();
		VL_Account_Address::instance();
		VL_Account_Identity::instance();

		if ( is_admin() ) {
			VL_Account_Admin::instance();
			VL_Account_Identity_Admin::instance();
		}
	}

	/**
	 * Совместимость с HPOS (хранение заказов в отдельных таблицах).
	 */
	public function declare_hpos_compat() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VLACC_FILE, true );
		}
	}

	/**
	 * Версия файла ресурсов.
	 *
	 * @param string $relative Путь относительно папки плагина.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$path = VLACC_PATH . $relative;

		if ( file_exists( $path ) ) {
			return VLACC_VERSION . '.' . filemtime( $path );
		}

		return VLACC_VERSION;
	}

	/**
	 * Стили и скрипты фронтенда.
	 */
	public function assets() {
		// Версия по времени изменения файла: браузеры и плагины оптимизации
		// подхватывают новую версию сразу после обновления плагина.
		wp_register_style( 'vl-account', VLACC_URL . 'assets/css/vl-account.css', array(), self::asset_version( 'assets/css/vl-account.css' ) );
		wp_register_script( 'vl-account', VLACC_URL . 'assets/js/vl-account.js', array(), self::asset_version( 'assets/js/vl-account.js' ), true );

		wp_localize_script(
			'vl-account',
			'VLACC',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'vl-account' ),
				'resend_wait'  => (int) VL_Account_Settings::get( 'resend_timeout', 60 ),
				'code_length'  => (int) VL_Account_Settings::get( 'code_length', 4 ),
				'phone_mask'   => VL_Account_Settings::get( 'phone_mask', '+7 (___) ___-__-__' ),
				'is_logged_in' => is_user_logged_in(),
				'auth_url'     => VL_Account_Settings::auth_url(),
				'gate'         => array(
					'enabled'      => VL_Account_Gate::should_block(),
					'mode'         => VL_Account_Gate::mode(),
					'selectors'    => VL_Account_Gate::selectors(),
					'message'      => VL_Account_Gate::message(),
					'checkout_url' => VL_Account_Gate::checkout_url(),
				),
				'i18n'         => array(
					'sending'      => __( 'Отправляем…', 'vl-account' ),
					'wait'         => __( 'Повторная отправка через %d сек.', 'vl-account' ),
					'resend'       => __( 'Отправить код повторно', 'vl-account' ),
					'bad_phone'    => __( 'Введите корректный номер телефона', 'vl-account' ),
					'bad_code'     => __( 'Введите код из %d цифр', 'vl-account' ),
					'need_name'    => __( 'Напишите, как к вам обращаться', 'vl-account' ),
					'network'      => __( 'Не удалось связаться с сервером. Попробуйте ещё раз.', 'vl-account' ),
					'copied'       => __( 'Скопировано', 'vl-account' ),
					'confirm_exit' => __( 'Выйти из личного кабинета?', 'vl-account' ),
					'gate_hint'    => __( 'После входа мы сразу продолжим — товар добавится в корзину.', 'vl-account' ),
					'loyalty_confirm' => __( 'Отметьте согласие с участием в программе лояльности.', 'vl-account' ),
				),
			)
		);

		// Стили подключаем всегда: формы часто выводятся в хедере/попапе через шорткод.
		wp_enqueue_style( 'vl-account' );
		wp_enqueue_script( 'vl-account' );

		$accent = VL_Account_Settings::get( 'accent_color', '#d40000' );
		$dark   = VL_Account_Settings::get( 'button_color', '#2f2f2f' );
		$radius = (int) VL_Account_Settings::get( 'radius', 0 );

		$inline = sprintf(
			':root{--vl-accent:%1$s;--vl-dark:%2$s;--vl-radius:%3$dpx;}',
			esc_attr( $accent ),
			esc_attr( $dark ),
			$radius
		);
		wp_add_inline_style( 'vl-account', $inline );
	}
}

VL_Account_Plugin::instance();

/**
 * Активация: создаём эндпоинты и сбрасываем правила ЧПУ.
 */
function vlacc_activate() {
	if ( class_exists( 'VL_Account_MyAccount' ) ) {
		VL_Account_MyAccount::register_endpoints();
	}
	flush_rewrite_rules();
	if ( false === get_option( 'vlacc_settings' ) ) {
		add_option( 'vlacc_settings', VL_Account_Settings::defaults() );
	}

	if ( class_exists( 'VL_Account_Carts' ) ) {
		VL_Account_Carts::install();
	}

	if ( class_exists( 'VL_Account_Identity' ) ) {
		VL_Account_Identity::install();
	}

	if ( class_exists( 'VL_Account_RetailCRM_Directory' ) ) {
		VL_Account_RetailCRM_Directory::install();
	}
	set_transient( 'vlacc_activated', 1, 60 );
}
register_activation_hook( __FILE__, 'vlacc_activate' );

/**
 * Деактивация.
 */
function vlacc_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'vlacc_carts_cleanup' );
	wp_clear_scheduled_hook( 'vlacc_crm_sync_batch' );
	wp_clear_scheduled_hook( 'vlacc_crm_sync_daily' );
	wp_clear_scheduled_hook( 'vlacc_loyalty_autojoin' );
}
register_deactivation_hook( __FILE__, 'vlacc_deactivate' );
