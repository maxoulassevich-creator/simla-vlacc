<?php
/**
 * Минимальные заглушки WordPress/WooCommerce для смоук-теста логики интеграции.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['usermeta']   = array();
$GLOBALS['filters']    = array();
$GLOBALS['actions']    = array();
$GLOBALS['log']        = array();

function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $hook ][] = $cb; }
function remove_action( ...$a ) { return true; }
function add_shortcode( $t, $cb ) {}
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['filters'][ $hook ] ) ) { return $value; }
	foreach ( $GLOBALS['filters'][ $hook ] as $cb ) { $value = call_user_func_array( $cb, array_merge( array( $value ), $args ) ); }
	return $value;
}
function do_action( $hook, ...$args ) {
	if ( empty( $GLOBALS['actions'][ $hook ] ) ) { return; }
	foreach ( $GLOBALS['actions'][ $hook ] as $cb ) { call_user_func_array( $cb, $args ); }
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { return update_option( $k, $v ); }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['usermeta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['usermeta'][ $id ][ $key ] = $value; return true; }
$GLOBALS['users'] = array();
function get_user_by( $f, $v ) {
	if ( 'id' === strtolower( $f ) ) {
		return $GLOBALS['users'][ (int) $v ] ?? false;
	}
	foreach ( $GLOBALS['users'] as $user ) {
		if ( isset( $user->user_email ) && strtolower( $user->user_email ) === strtolower( (string) $v ) ) {
			return $user;
		}
	}
	return false;
}
function get_current_user_id() { return 1; }
$GLOBALS['logged_in'] = true;
function is_user_logged_in() { return ! empty( $GLOBALS['logged_in'] ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return $t; }
function esc_attr__( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_attr( $t ) { return $t; }
function esc_url( $t ) { return $t; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
function wp_unslash( $t ) { return $t; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', ' ' ); }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( 1, 999999 ); }
function absint( $v ) { return abs( (int) $v ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $v ) { return trim( (string) $v ); }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function date_i18n( $f, $t ) { return gmdate( $f, $t ); }
function get_permalink( $id ) { return 'https://example.test/?p=' . $id; }
function get_post_status( $id ) { return 'publish'; }
$GLOBALS['products'] = array();
function wc_get_product( $id ) { return $GLOBALS['products'][ (int) $id ] ?? false; }
function wc_get_order( $id ) { return false; }
function is_admin() { return false; }
function current_user_can( $c, $o = null ) { return false; }
function locate_template( $t ) { return ''; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/vl-account/'; }
function plugin_basename( $f ) { return 'vl-account/vl-account.php'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( $a ) { return 'nonce'; }
function wp_verify_nonce( $n, $a ) { return true; }
function wp_safe_redirect( $u ) { return true; }
function vlacc_is_woo() { return true; }
function vlacc_log( $m, $c = array() ) { $GLOBALS['log'][] = array( $m, $c ); }
function vlacc_mask_phone( $p ) { return substr( (string) $p, 0, 4 ) . '***'; }
function vlacc_mask_email( $e ) { return '***'; }
function vlacc_client_ip() { return '127.0.0.1'; }
function vlacc_template( $t, $a = array(), $r = false ) { return ''; }
function vlacc_icon( $n, $s = 16 ) { return ''; }

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/* ---- Заглушки плагина Simla ---- */

class WC_Retailcrm_Response implements ArrayAccess {
	protected $statusCode;
	protected $response;
	public function __construct( $code, $body = '{}' ) { $this->statusCode = (int) $code; $this->response = json_decode( $body, true ) ?: array(); }
	public function isSuccessful() { return $this->statusCode < 400; }
	public function getStatusCode() { return $this->statusCode; }
	public function getRawResponse() { return json_encode( $this->response ); }
	public function getErrorString() { return 'error'; }
	public function offsetExists( $o ): bool { return isset( $this->response[ $o ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $o ) {
		if ( ! isset( $this->response[ $o ] ) ) { throw new InvalidArgumentException( "Property '$o' not found" ); }
		return $this->response[ $o ];
	}
	public function offsetSet( $o, $v ): void { throw new BadMethodCallException( 'no' ); }
	public function offsetUnset( $o ): void { throw new BadMethodCallException( 'no' ); }
}

class WC_Retailcrm_Base {}
class WC_Retailcrm_Customer_Address {}
class WC_Retailcrm_Customers {
	public $isSubscribed = null;
	public function __construct( $a, $b, $c ) {}
	public function updateCustomer( $id ) { $GLOBALS['log'][] = array( 'updateCustomer', $id ); }
	public function registerCustomer( $id ) { $GLOBALS['log'][] = array( 'registerCustomer', $id ); }
}
class WC_Retailcrm_Plugin {
	public static function history_running() { return false; }
}

/** Заглушка клиента API: отдаёт заранее подготовленные ответы. */
class WC_Retailcrm_Proxy {
	public static $responses = array();
	public static $calls     = array();
	public function __construct( $url = '', $key = '', $corp = false ) {}
	public function __call( $method, $args ) {
		self::$calls[] = $method;
		if ( isset( self::$responses[ $method ] ) ) {
			$r = self::$responses[ $method ];
			return is_callable( $r ) ? $r( $args ) : $r;
		}
		return new WC_Retailcrm_Response( 900, '{}' );
	}
}

class WC_Retailcrm_Loyalty {
	public static $history = array();
	public static $bonuses = array();
	public function __construct( $api, $settings ) {}
	public function getLoyaltyHistory( $id ) { return self::$history; }
	public function getBonusDetails( $id, $status ) { return self::$bonuses[ $status ] ?? array(); }
	public function registerCustomer( $userId, $phone, $site ) { $GLOBALS['log'][] = array( 'lp_register', $phone ); return true; }
	public function activateLoyaltyCustomer( $id ) { return new WC_Retailcrm_Response( 200, '{"verification":{"checkId":"abc"}}' ); }
	public function confirmSmsVerification( $c, $i ) { return true; }
	public function calculateDiscountLoyalty( $items, $site, $customer, $bonuses = 0 ) { return WC_Retailcrm_Proxy::$responses['calculateDiscountLoyalty'] ?? 0; }
	public function processingLoyaltyCoupon( $refresh = false ) { return null; }
}


/* ---- Записи и запросы (для подписок на поступление) ---- */

$GLOBALS['posts']    = array();
$GLOBALS['postmeta'] = array();

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_title;
	public $post_date;
	public function __construct( $data = array() ) {
		foreach ( $data as $key => $value ) { $this->$key = $value; }
	}
}

function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }

/**
 * Заглушка get_posts: поддерживает выборку товаров по post__in и статусу.
 * Товары объявляются в $GLOBALS['products'], их статусы — в $GLOBALS['product_status'].
 */
function get_posts( $args = array() ) {
	$ids    = array_map( 'absint', (array) ( $args['post__in'] ?? array() ) );
	$status = $args['post_status'] ?? 'publish';
	$result = array();

	foreach ( $ids as $id ) {
		if ( ! isset( $GLOBALS['products'][ $id ] ) ) {
			continue;
		}

		$post_status = $GLOBALS['product_status'][ $id ] ?? 'publish';

		if ( 'any' !== $status && ! in_array( $post_status, (array) $status, true ) ) {
			continue;
		}

		$result[] = $id;
	}

	return $result;
}
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['postmeta'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['postmeta'][ (int) $id ][ $key ] = $value; return true; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function post_type_exists( $type ) { return 'cwginstocknotifier' === $type; }
function wp_count_posts( $type ) { return (object) array( 'cwg_subscribed' => 1 ); }
function wp_update_post( $args ) {
	$post = get_post( $args['ID'] );
	if ( $post ) { $post->post_status = $args['post_status']; }
	return $args['ID'];
}

/**
 * Заглушка WP_Query: фильтрует $GLOBALS['posts'] по типу, статусу и meta_query.
 */
class WP_Query {
	public $posts = array();
	public function __construct( $args = array() ) {
		$statuses = (array) ( $args['post_status'] ?? array() );
		foreach ( $GLOBALS['posts'] as $post ) {
			if ( $post->post_type !== $args['post_type'] ) { continue; }
			if ( $statuses && ! in_array( $post->post_status, $statuses, true ) ) { continue; }
			if ( ! empty( $args['meta_query'] ) && ! $this->match( $post->ID, $args['meta_query'] ) ) { continue; }
			$this->posts[] = $post;
		}
	}
	private function match( $id, $query ) {
		$relation = strtoupper( $query['relation'] ?? 'AND' );
		unset( $query['relation'] );
		$results = array();
		foreach ( $query as $clause ) {
			$value   = get_post_meta( $id, $clause['key'], true );
			$compare = $clause['compare'] ?? '=';
			if ( 'IN' === $compare ) {
				$results[] = in_array( strtolower( (string) $value ), array_map( 'strtolower', (array) $clause['value'] ), true );
			} else {
				$results[] = (string) $value === (string) $clause['value'];
			}
		}
		return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}
}

/* ---- Файлы плагина ---- */

define( 'VLACC_VERSION', 'test' );
define( 'VLACC_PATH', __DIR__ . '/../vl-account/' );

$base = VLACC_PATH . 'includes/';

require_once $base . 'class-vl-settings.php';
require_once $base . 'class-vl-phone.php';
require_once $base . 'class-vl-user.php';
require_once $base . 'class-vl-bonus.php';
require_once $base . 'class-vl-promo.php';
require_once $base . 'integrations/class-vl-retailcrm.php';
require_once $base . 'integrations/class-vl-retailcrm-customer.php';
require_once $base . 'integrations/class-vl-retailcrm-loyalty.php';
require_once $base . 'integrations/class-vl-retailcrm-promo.php';
