<?php
/**
 * Смоук-тест автоматического вступления в программу лояльности.
 *
 * Приветственные баллы должны появиться у нового покупателя сами: номер он уже
 * подтвердил кодом при регистрации, и просить подтверждать его второй раз
 * в разделе «Бонусы» незачем.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

update_option(
	'woocommerce_integration-retailcrm_settings',
	array(
		'api_url' => 'https://demo.simla.com',
		'api_key' => 'key',
		'loyalty' => 'yes',
	)
);

WC_Retailcrm_Proxy::$responses = array(
	'customersGet'        => new WC_Retailcrm_Response( 200, '{"customer":{"id":77}}' ),
	'getSingleSiteForKey' => 'shop',
);

$loyalty = VL_Account_RetailCRM_Loyalty::instance();

/**
 * Ответ CRM об участии в программе.
 */
function lp_accounts( $status ) {
	$map = array(
		'none'     => '{"loyaltyAccounts":[]}',
		'inactive' => '{"loyaltyAccounts":[{"id":12,"active":false,"amount":0,"ordersSum":0,"nextLevelSum":0,
		               "level":{"name":"First Love","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":5},
		               "loyalty":{"currency":"RUB"}}]}',
		'active'   => '{"loyaltyAccounts":[{"id":12,"active":true,"amount":1000,"ordersSum":0,"nextLevelSum":30000,
		               "level":{"name":"First Love","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":5},
		               "loyalty":{"currency":"RUB"}}]}',
	);

	WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response( 200, $map[ $status ] );
}

/**
 * Ответы CRM по шагам: сначала участия нет, после регистрации — неактивно.
 */
function lp_sequence( array $steps ) {
	$GLOBALS['lp_steps'] = $steps;

	WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = function () {
		$status = count( $GLOBALS['lp_steps'] ) > 1 ? array_shift( $GLOBALS['lp_steps'] ) : $GLOBALS['lp_steps'][0];

		$map = array(
			'none'     => '{"loyaltyAccounts":[]}',
			'inactive' => '{"loyaltyAccounts":[{"id":12,"active":false,"amount":0,"ordersSum":0,"nextLevelSum":0,
			               "level":{"name":"First Love","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":5},
			               "loyalty":{"currency":"RUB"}}]}',
			'active'   => '{"loyaltyAccounts":[{"id":12,"active":true,"amount":1000,"ordersSum":0,"nextLevelSum":30000,
			               "level":{"name":"First Love","type":"bonus_percent","privilegeSize":5,"privilegeSizePromo":5},
			               "loyalty":{"currency":"RUB"}}]}',
		);

		return new WC_Retailcrm_Response( 200, $map[ $status ] );
	};
}

/**
 * Сброс состояния между сценариями.
 */
function lp_reset( $user_id = 5 ) {
	$GLOBALS['users'][ $user_id ]    = new WP_User(
		array(
			'ID'         => $user_id,
			'user_email' => '79261234567@phone.example.test',
			'user_login' => 'client',
		)
	);
	$GLOBALS['usermeta'][ $user_id ] = array();
	$GLOBALS['log']                  = array();
	$GLOBALS['transients']           = array();
	$GLOBALS['scheduled']            = array();

	update_user_meta( $user_id, VL_Account_User::META_PHONE, '79261234567' );
	delete_option( VL_Account_RetailCRM_Loyalty::OPTION_VERIFY );

	WC_Retailcrm_Loyalty::$register_ok = true;
	WC_Retailcrm_Loyalty::$activate    = null;

	VL_Account_RetailCRM::flush_all();
	VL_Account_RetailCRM::flush( $user_id );
}

VL_Account_Settings::update( array( 'crm_loyalty_auto' => 1, 'crm_loyalty_ui' => 1 ) );

echo "\n== 1. Регистрация ставит вступление в очередь ==\n";
lp_reset();

VL_Account_RetailCRM_Loyalty::on_registered( 5, array( 'phone' => '+7 (926) 123-45-67' ) );

check( 'вступление помечено как незаконченное', VL_Account_RetailCRM_Loyalty::pending( 5 ) );
check( 'задача поставлена в планировщик', ! empty( $GLOBALS['scheduled'] ), print_r( $GLOBALS['scheduled'], true ) );
check( 'задача та самая', VL_Account_RetailCRM_Loyalty::CRON === ( $GLOBALS['scheduled'][0][1] ?? '' ) );

lp_reset();
VL_Account_Settings::update( array( 'crm_loyalty_auto' => 0 ) );
VL_Account_RetailCRM_Loyalty::on_registered( 5, array( 'phone' => '+79261234567' ) );
check( 'выключенная настройка ничего не планирует', ! VL_Account_RetailCRM_Loyalty::pending( 5 ) && empty( $GLOBALS['scheduled'] ) );
VL_Account_Settings::update( array( 'crm_loyalty_auto' => 1 ) );

lp_reset();
delete_user_meta( 5, VL_Account_User::META_PHONE );
VL_Account_RetailCRM_Loyalty::on_registered( 5, array( 'phone' => '' ) );
check( 'без телефона вступать не с чем', ! VL_Account_RetailCRM_Loyalty::pending( 5 ) );

echo "\n== 2. Полное вступление без участия покупателя ==\n";
lp_reset();

// CRM подтверждения участия не требует — активация проходит сразу.
WC_Retailcrm_Loyalty::$activate = new WC_Retailcrm_Response( 200, '{"loyaltyAccount":{"id":12,"active":true}}' );
lp_sequence( array( 'none', 'inactive', 'active' ) );

update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_PENDING, 1 );
$result = VL_Account_RetailCRM_Loyalty::auto_join( 5 );

check( 'участие оформлено и активно', 'active' === $result, $result );

$calls = array();

foreach ( $GLOBALS['log'] as $entry ) {
	$calls[] = $entry[0];
}

check( 'покупатель зарегистрирован в программе', in_array( 'lp_register', $calls, true ), print_r( $calls, true ) );
check( 'участие активировано', in_array( 'lp_activate', $calls, true ) );
check( 'метка «незакончено» снята', ! VL_Account_RetailCRM_Loyalty::pending( 5 ) );
check( 'проставлена отметка об автовступлении', '' !== get_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_AUTO, true ) );
check( 'кода подтверждения не осталось', '' === get_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_CHECK, true ) );
check( 'CRM не требует подтверждения', ! VL_Account_RetailCRM_Loyalty::verification_required() );

$consents = get_user_meta( 5, VL_Account_User::META_CONSENTS, true );
check( 'согласие на участие записано', ! empty( $consents['loyalty']['value'] ), print_r( $consents, true ) );

echo "\n== 3. Уже участвует — ничего не трогаем ==\n";
lp_reset();

lp_accounts( 'active' );
update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_PENDING, 1 );

$result = VL_Account_RetailCRM_Loyalty::auto_join( 5 );
$calls  = array();

foreach ( $GLOBALS['log'] as $entry ) {
	$calls[] = $entry[0];
}

check( 'итог — участие активно', 'active' === $result );
check( 'повторной регистрации не было', ! in_array( 'lp_register', $calls, true ), print_r( $calls, true ) );
check( 'активацию не дёргали', ! in_array( 'lp_activate', $calls, true ) );

echo "\n== 4. CRM просит свой код подтверждения ==\n";
lp_reset();

WC_Retailcrm_Loyalty::$activate = new WC_Retailcrm_Response( 200, '{"verification":{"checkId":"check-777"}}' );
lp_accounts( 'inactive' );
update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_PENDING, 1 );

$result = VL_Account_RetailCRM_Loyalty::auto_join( 5 );

check( 'итог — нужен код', 'sms' === $result, $result );
check( 'checkId сохранён для кабинета', 'check-777' === get_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_CHECK, true ) );
check( 'требование CRM зафиксировано', VL_Account_RetailCRM_Loyalty::verification_required() );
check( 'повторно вступать не пытаемся', ! VL_Account_RetailCRM_Loyalty::pending( 5 ) );

$GLOBALS['logged_in']       = true;
$GLOBALS['current_user_id'] = 5;

$state = VL_Account_RetailCRM_Loyalty::state( 5 );
check( 'кабинет получает checkId и сразу просит код', 'check-777' === $state['check_id'], print_r( $state, true ) );
check( 'статус участия — не активно', 'inactive' === $state['status'] );

echo "\n== 5. Ошибки CRM ==\n";
lp_reset();

WC_Retailcrm_Loyalty::$register_ok = false;
lp_accounts( 'none' );
update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_PENDING, 1 );

$result = VL_Account_RetailCRM_Loyalty::auto_join( 5 );

check( 'регистрация не удалась', 'error' === $result, $result );
check( 'метка осталась — попробуем позже', VL_Account_RetailCRM_Loyalty::pending( 5 ) );

lp_reset();
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response( 503, '{}' );
update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_PENDING, 1 );

check( 'CRM недоступна — тихо выходим', 'error' === VL_Account_RetailCRM_Loyalty::auto_join( 5 ) );
check( 'метка сохранена', VL_Account_RetailCRM_Loyalty::pending( 5 ) );

echo "\n== 6. Защита от повторов ==\n";
lp_reset();

WC_Retailcrm_Loyalty::$activate = new WC_Retailcrm_Response( 200, '{"loyaltyAccount":{"id":12,"active":true}}' );
lp_sequence( array( 'none', 'inactive', 'active' ) );
update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_PENDING, 1 );

VL_Account_RetailCRM_Loyalty::auto_join( 5 );

$before = count( $GLOBALS['log'] );
$again  = VL_Account_RetailCRM_Loyalty::auto_join( 5 );

check( 'второй заход подряд пропускается', 'skip' === $again, $again );
check( 'лишних запросов в CRM нет', $before === count( $GLOBALS['log'] ) );

echo "\n== 7. Старый аккаунт: участие оформляется при открытии «Бонусов» ==\n";
lp_reset();

// Метки «незакончено» нет — аккаунт заведён до появления автовступления.
WC_Retailcrm_Loyalty::$activate = new WC_Retailcrm_Response( 200, '{"loyaltyAccount":{"id":12,"active":true}}' );
lp_sequence( array( 'none', 'none', 'inactive', 'active' ) );

$GLOBALS['logged_in']       = true;
$GLOBALS['current_user_id'] = 5;

$state = VL_Account_RetailCRM_Loyalty::state( 5 );
$calls = array();

foreach ( $GLOBALS['log'] as $entry ) {
	$calls[] = $entry[0];
}

check( 'вступление оформлено без кнопки', in_array( 'lp_register', $calls, true ), print_r( $calls, true ) );
check( 'раздел показывает активное участие', 'active' === $state['status'], $state['status'] );

echo "\n== 8. Вход ставит вступление в очередь ==\n";
lp_reset();

lp_accounts( 'none' );
VL_Account_RetailCRM_Loyalty::ensure_membership( 5 );

check( 'задача поставлена', ! empty( $GLOBALS['scheduled'] ) );
check( 'метка выставлена', VL_Account_RetailCRM_Loyalty::pending( 5 ) );

lp_reset();
lp_accounts( 'active' );
VL_Account_RetailCRM_Loyalty::ensure_membership( 5 );

check( 'участнику ничего не планируем', empty( $GLOBALS['scheduled'] ) );

lp_reset();
delete_user_meta( 5, VL_Account_User::META_PHONE );
lp_accounts( 'none' );
VL_Account_RetailCRM_Loyalty::ensure_membership( 5 );

check( 'без телефона очередь не трогаем', empty( $GLOBALS['scheduled'] ) );

echo "\n== 9. Повторы после неудачи ==\n";
lp_reset();

update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_TRIED, time() );

check( 'сразу после попытки не повторяем', ! VL_Account_RetailCRM_Loyalty::may_retry( 5 ) );

update_user_meta( 5, VL_Account_RetailCRM_Loyalty::META_TRIED, time() - 2 * DAY_IN_SECONDS );
check( 'через сутки пробуем снова', VL_Account_RetailCRM_Loyalty::may_retry( 5 ) );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
