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
 */
function user( $id, $email ) {
	$GLOBALS['users'][ $id ] = new WP_User(
		array(
			'ID'         => $id,
			'user_email' => $email,
			'user_login' => 'user' . $id,
		)
	);
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

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
