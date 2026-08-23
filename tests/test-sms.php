<?php
/**
 * Смоук-тест клиента SMS.RU: что именно уходит в запросе и как читается ответ.
 *
 * Главный вопрос, ради которого написан набор: точно ли плагин передаёт
 * согласованное имя отправителя (параметр from) и видно ли из ответа,
 * что имя аккаунтом не согласовано.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушка HTTP: запоминаем запрос, отдаём заготовленный ответ ---- */

$GLOBALS['http_calls']    = array();
$GLOBALS['http_response'] = array();

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['http_calls'][] = array( 'url' => $url, 'body' => $args['body'] );

	$endpoint = str_replace( 'https://sms.ru/', '', $url );

	return array( 'body' => $GLOBALS['http_response'][ $endpoint ] ?? '{}' );
}
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? $response['body'] : ''; }

require_once VLACC_PATH . 'includes/class-vl-smsru.php';

/**
 * Последний запрос к API.
 */
function last_request() {
	$calls = $GLOBALS['http_calls'];

	return end( $calls );
}

VL_Account_Settings::update(
	array(
		'api_id'    => 'KEY-1',
		'sms_from'  => 'LovefirstRU',
		'test_mode' => 0,
	)
);

$GLOBALS['http_response']['sms/send'] = '{"status":"OK","status_code":100,"sms":{"79261234567":{"status":"OK","status_code":100,"sms_id":"000000-10000000"}},"balance":150.5}';

echo "\n== 1. Имя отправителя уходит в каждом запросе ==\n";

$result = VL_Account_SmsRu::send_sms( '79261234567', 'Код подтверждения: 1234' );
$sent   = last_request();

check( 'отправка успешна', ! empty( $result['success'] ), print_r( $result, true ) );
check( 'запрос ушёл на sms/send', 'https://sms.ru/sms/send' === $sent['url'], $sent['url'] );
check( 'from передан', isset( $sent['body']['from'] ) && 'LovefirstRU' === $sent['body']['from'], print_r( $sent['body'], true ) );
check( 'api_id передан', 'KEY-1' === $sent['body']['api_id'] );
check( 'ответ разбирается в json', 1 === (int) $sent['body']['json'] );
check( 'тестовый режим выключен', ! isset( $sent['body']['test'] ) );

$log = end( $GLOBALS['log'] );
check( 'имя отправителя попало в лог', isset( $log[1]['from'] ) && 'LovefirstRU' === $log[1]['from'], print_r( $log, true ) );
check( 'id сообщения попал в лог', isset( $log[1]['sms_id'] ) && '000000-10000000' === $log[1]['sms_id'] );

echo "\n== 2. Пустое имя — параметр не отправляем ==\n";

VL_Account_Settings::update( array( 'sms_from' => '' ) );
VL_Account_SmsRu::send_sms( '79261234567', 'Код: 1234' );
$sent = last_request();

check( 'from не передан', ! isset( $sent['body']['from'] ) );

VL_Account_Settings::update( array( 'sms_from' => '  LovefirstRU  ' ) );
VL_Account_SmsRu::send_sms( '79261234567', 'Код: 1234' );
$sent = last_request();

check( 'пробелы вокруг имени срезаются', 'LovefirstRU' === $sent['body']['from'], var_export( $sent['body']['from'], true ) );

VL_Account_Settings::update( array( 'sms_from' => 'LovefirstRU', 'test_mode' => 1 ) );
VL_Account_SmsRu::send_sms( '79261234567', 'Код: 1234' );
$sent = last_request();

check( 'тестовый режим добавляет test=1', isset( $sent['body']['test'] ) && 1 === $sent['body']['test'] );
VL_Account_Settings::update( array( 'test_mode' => 0 ) );

echo "\n== 3. Несогласованное имя видно по ответу ==\n";

$GLOBALS['http_response']['sms/send'] = '{"status":"ERROR","status_code":204,"status_text":"sender name is not approved"}';

$result = VL_Account_SmsRu::send_sms( '79261234567', 'Код: 1234' );

check( 'отправка провалена', empty( $result['success'] ) );
check( 'код 204 расшифрован', false !== mb_strpos( $result['message'], 'не согласовано' ), $result['message'] );
check( 'посетителю техническая причина не показывается', false === mb_strpos( $result['public'], 'согласовано' ), $result['public'] );

$GLOBALS['http_response']['sms/send'] = '{"status":"OK","status_code":100,"sms":{"79261234567":{"status":"OK","status_code":100}}}';

echo "\n== 4. Список согласованных имён аккаунта ==\n";

$GLOBALS['http_response']['my/senders'] = '{"status":"OK","status_code":100,"senders":["LovefirstRU","LOVEFIRST"]}';

$senders = VL_Account_SmsRu::senders( true );
check( 'список читается', array( 'LovefirstRU', 'LOVEFIRST' ) === $senders, print_r( $senders, true ) );

$before = count( $GLOBALS['http_calls'] );
VL_Account_SmsRu::senders();
check( 'второй раз берётся из кэша', $before === count( $GLOBALS['http_calls'] ) );

$GLOBALS['http_response']['my/senders'] = '{"status":"OK","status_code":100,"senders":[{"name":"LovefirstRU"}]}';
check( 'формат с объектами тоже понимаем', array( 'LovefirstRU' ) === VL_Account_SmsRu::senders( true ) );

$GLOBALS['http_response']['my/senders'] = '{"status":"ERROR","status_code":200}';
$error = VL_Account_SmsRu::senders( true );
check( 'ошибка аккаунта возвращается как WP_Error', $error instanceof WP_Error, print_r( $error, true ) );

echo "\n== 5. Проверка имени для диагностики ==\n";

$GLOBALS['http_response']['my/senders'] = '{"status":"OK","status_code":100,"senders":["LovefirstRU"]}';

VL_Account_Settings::update( array( 'sms_from' => 'LovefirstRU' ) );
$status = VL_Account_SmsRu::sender_status();
check( 'имя согласовано', true === $status['approved'], print_r( $status, true ) );
check( 'список отдаётся в диагностику', array( 'LovefirstRU' ) === $status['list'] );

VL_Account_Settings::update( array( 'sms_from' => 'lovefirstru' ) );
check( 'регистр не важен', true === VL_Account_SmsRu::sender_status()['approved'] );

// Кириллические «о» и «е» в латинском имени — частая причина «имя не то».
VL_Account_Settings::update( array( 'sms_from' => 'LоvefirstRU' ) );
$status = VL_Account_SmsRu::sender_status();
check( 'буква-двойник ловится', false === $status['approved'], print_r( $status, true ) );

VL_Account_Settings::update( array( 'sms_from' => 'ДругоеИмя' ) );
check( 'чужое имя не согласовано', false === VL_Account_SmsRu::sender_status()['approved'] );

VL_Account_Settings::update( array( 'sms_from' => '' ) );
$status = VL_Account_SmsRu::sender_status();
check( 'без имени проверять нечего', null === $status['approved'] );

VL_Account_Settings::update( array( 'sms_from' => 'LovefirstRU', 'api_id' => '' ) );
$status = VL_Account_SmsRu::sender_status();
check( 'без ключа честно говорим об ошибке', null === $status['approved'] && '' !== $status['error'], print_r( $status, true ) );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
