<?php
/**
 * Смоук-тест привязки почты из оформления заказа.
 *
 * Аккаунт, заведённый входом по SMS, живёт с техническим адресом вида
 * 79001234567@phone.site. Адрес, который покупатель вводит в своём же
 * оформлении заказа, должен записываться сразу: подтверждать нечего, он
 * вошёл по коду и ввёл адрес сам. А вот уже привязанный настоящий адрес
 * так подменить нельзя — только по ссылке из письма.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/** Заказы: важно только то, что подтягивание гостевых заказов вызвано. */
class Fake_Orders {
	public static $attached = 0;

	public static function attach_guest_orders( $user_id ) {
		++self::$attached;

		return 0;
	}
}

class_alias( 'Fake_Orders', 'VL_Account_Orders' );

class WC_Order {
	public $id;
	public $customer_id;
	public $email;
	public $phone;
	public function __construct( $id, $customer_id, $email, $phone ) {
		$this->id          = $id;
		$this->customer_id = $customer_id;
		$this->email       = $email;
		$this->phone       = $phone;
	}
	public function get_id() { return $this->id; }
	public function get_customer_id() { return $this->customer_id; }
	public function get_billing_email() { return $this->email; }
	public function get_billing_phone() { return $this->phone; }
}

$GLOBALS['orders'] = array();

function wc_get_orders( $args = array() ) {
	$found = array();

	foreach ( $GLOBALS['orders'] as $order ) {
		if ( isset( $args['billing_email'] ) && strtolower( $order->get_billing_email() ) !== strtolower( $args['billing_email'] ) ) {
			continue;
		}

		$found[] = $order;
	}

	return $found;
}

/** Снимок базы CRM: нужен только поиск по адресу. */
class Fake_Directory {
	public static $rows = array();

	public static function rows_by_email( $email ) {
		$found = array();

		foreach ( self::$rows as $row ) {
			if ( strtolower( $row['email'] ) === strtolower( $email ) ) { $found[] = $row; }
		}

		return $found;
	}
}

class_alias( 'Fake_Directory', 'VL_Account_RetailCRM_Directory' );

require_once VLACC_PATH . 'includes/class-vl-email-confirm.php';

$GLOBALS['users']    = array();
$GLOBALS['usermeta'] = array();
$GLOBALS['log']      = array();
$GLOBALS['fired']    = array();

// Этот же хук слушает мост CRM: по нему покупатель уезжает в CRM с адресом.
add_action(
	'vlacc_email_confirmed',
	static function ( $user_id, $email ) {
		$GLOBALS['fired'][] = array( $user_id, $email );
	},
	10,
	2
);

/**
 * Аккаунт для теста.
 *
 * @param int    $id    ID.
 * @param string $email Почта.
 * @param string $phone Подтверждённый номер.
 */
function user( $id, $email, $phone = '' ) {
	$GLOBALS['users'][ $id ] = new WP_User(
		array(
			'ID'         => $id,
			'user_email' => $email,
			'user_login' => 'user' . $id,
		)
	);

	if ( '' !== $phone ) {
		update_user_meta( $id, VL_Account_User::META_PHONE, $phone );
	}
}

echo "\n== 1. Технический адрес меняется сразу ==\n";

user( 10, '79001234567@phone.site' );

$result = VL_Account_Email_Confirm::attach_now( 10, 'buyer@example.com' );

check( 'адрес принят', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'почта аккаунта заменена', 'buyer@example.com' === $GLOBALS['users'][10]->user_email );
check( 'billing_email заполнен', 'buyer@example.com' === (string) get_user_meta( 10, 'billing_email', true ) );
check( 'адрес помечен подтверждённым', '' !== (string) get_user_meta( 10, 'vlacc_email_verified', true ) );
check( 'прошлые заказы подтянуты', 1 === Fake_Orders::$attached );
check( 'событие для CRM отправлено', array( array( 10, 'buyer@example.com' ) ) === $GLOBALS['fired'], print_r( $GLOBALS['fired'], true ) );

echo "\n== 2. Настоящий адрес так не подменить ==\n";

user( 11, 'real@example.com' );

$result = VL_Account_Email_Confirm::attach_now( 11, 'other@example.com' );

check( 'подмена отклонена', is_wp_error( $result ) && 'vlacc_has_email' === $result->get_error_code() );
check( 'почта осталась прежней', 'real@example.com' === $GLOBALS['users'][11]->user_email );

echo "\n== 3. Чужой адрес не забираем ==\n";

user( 12, '79007654321@phone.site' );

$result = VL_Account_Email_Confirm::attach_now( 12, 'real@example.com' );

check( 'занятый адрес отклонён', is_wp_error( $result ) && 'vlacc_email_taken' === $result->get_error_code() );
check( 'технический адрес на месте', '79007654321@phone.site' === $GLOBALS['users'][12]->user_email );

$result = VL_Account_Email_Confirm::attach_now( 12, 'не-почта' );

check( 'мусор вместо адреса отклонён', is_wp_error( $result ) && 'vlacc_bad_email' === $result->get_error_code() );
check( 'несуществующий аккаунт отклонён', is_wp_error( VL_Account_Email_Confirm::attach_now( 999, 'x@example.com' ) ) );

echo "\n== 4. За адресом стоят чужие данные ==\n";

// Гостевой заказ с этим адресом оформлен с другого номера — адрес не свой.
user( 20, '79001111111@phone.site', '79001111111' );
$GLOBALS['orders'][] = new WC_Order( 601, 0, 'guest@example.com', '+7 (999) 222-33-44' );

$result = VL_Account_Email_Confirm::attach_now( 20, 'guest@example.com' );

check( 'адрес с чужими гостевыми заказами отклонён', is_wp_error( $result ), is_wp_error( $result ) ? '' : 'записан!' );
check( 'заказы остались у гостя', 0 === (int) $GLOBALS['orders'][0]->get_customer_id() );
check( 'почта аккаунта не тронута', '79001111111@phone.site' === $GLOBALS['users'][20]->user_email );

// Тот же адрес, но заказ оформлен с того же номера — это тот же человек.
$GLOBALS['orders'] = array( new WC_Order( 602, 0, 'same@example.com', '+7 (900) 111-11-11' ) );

check( 'свой же гостевой заказ не мешает', true === VL_Account_Email_Confirm::attach_now( 20, 'same@example.com' ) );

// Заказ другого зарегистрированного покупателя.
user( 21, '79002222222@phone.site', '79002222222' );
$GLOBALS['orders'] = array( new WC_Order( 603, 777, 'client@example.com', '+7 (900) 222-22-22' ) );

check( 'адрес из заказа другого аккаунта отклонён', is_wp_error( VL_Account_Email_Confirm::attach_now( 21, 'client@example.com' ) ) );

// Карточка CRM с этим адресом и другим номером.
user( 22, '79003333333@phone.site', '79003333333' );
$GLOBALS['orders']    = array();
Fake_Directory::$rows = array(
	array( 'crm_id' => 500, 'email' => 'crmclient@example.com', 'phone' => '79998887766', 'external_id' => 0 ),
);

check( 'адрес чужой карточки CRM отклонён', is_wp_error( VL_Account_Email_Confirm::attach_now( 22, 'crmclient@example.com' ) ) );

// Карточка CRM с этой почтой без телефона и без externalId — тоже чужая:
// её завёл менеджер, и за ней стоит история другого человека.
user( 23, '79004444444@phone.site', '79004444444' );
Fake_Directory::$rows = array(
	array( 'crm_id' => 502, 'email' => 'manual@example.com', 'phone' => '', 'external_id' => 0 ),
);

check( 'карточка CRM без телефона тоже блокирует', is_wp_error( VL_Account_Email_Confirm::attach_now( 23, 'manual@example.com' ) ) );

// Карточка CRM с этим же номером — это он сам.
Fake_Directory::$rows = array(
	array( 'crm_id' => 501, 'email' => 'mycard@example.com', 'phone' => '79003333333', 'external_id' => 0 ),
);

check( 'своя карточка CRM не мешает', true === VL_Account_Email_Confirm::attach_now( 22, 'mycard@example.com' ) );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
