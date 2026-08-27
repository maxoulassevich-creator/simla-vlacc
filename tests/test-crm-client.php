<?php
/**
 * Смоук-тест своего клиента API RetailCRM.
 *
 * Клиент умеет только читать: адреса запросов, постраничка и разбор ответа
 * должны совпадать с тем, что делал транспорт Simla, а любая попытка записи —
 * блокироваться, не доходя до сети.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушка HTTP: запоминаем запросы, отдаём подготовленные ответы ---- */

$GLOBALS['http']      = array();
$GLOBALS['http_next'] = array( 'code' => 200, 'body' => '{"success":true}' );

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['http'][] = array( 'url' => $url, 'args' => $args );

	if ( ! empty( $GLOBALS['http_next']['wp_error'] ) ) {
		return new WP_Error( 'http', 'нет связи' );
	}

	return array(
		'response' => array( 'code' => $GLOBALS['http_next']['code'] ),
		'body'     => $GLOBALS['http_next']['body'],
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? $response['body'] : '';
}

require_once VLACC_PATH . 'includes/integrations/class-vl-crm-client.php';

/**
 * Последний запрос.
 *
 * @return array
 */
function last_request() {
	$all = $GLOBALS['http'];

	return $all ? end( $all ) : array( 'url' => '' );
}

$client = new VL_Account_CRM_Client( 'https://lovefirst.retailcrm.ru/', 'SECRET', 'lovefirst' );

echo "\n== 1. Адреса запросов ==\n";

$client->customersGet( 42 );
$url = last_request()['url'];

check( 'версия API в адресе', false !== strpos( $url, '/api/v5/customers/42' ), $url );
check( 'ключ уходит параметром', false !== strpos( $url, 'apiKey=SECRET' ), $url );
check( 'по умолчанию ищем по externalId', false !== strpos( $url, 'by=externalId' ), $url );
check( 'магазин подставлен', false !== strpos( $url, 'site=lovefirst' ), $url );

$client->customersGet( 8698, 'id' );
check( 'поиск по id', false !== strpos( last_request()['url'], 'by=id' ), last_request()['url'] );

$client->customersList( array( 'name' => '+79047767897' ), 2, 50 );
$url = last_request()['url'];

check( 'фильтр в формате CRM', false !== strpos( rawurldecode( $url ), 'filter[name]=+79047767897' ), rawurldecode( $url ) );
check( 'страница и лимит', false !== strpos( $url, 'page=2' ) && false !== strpos( $url, 'limit=50' ), $url );

$client->getLoyaltyAccountList( array( 'phoneNumber' => '+79047767897' ), 20, 1 );
check( 'участия программы лояльности', false !== strpos( last_request()['url'], '/loyalty/accounts' ), last_request()['url'] );

$client->ordersList( array( 'customerId' => 9638 ), 1, 20 );
check( 'заказы', false !== strpos( last_request()['url'], '/api/v5/orders' ), last_request()['url'] );

$client->statusesList();
$url = last_request()['url'];

check( 'справочник статусов', false !== strpos( $url, '/reference/statuses' ), $url );
check( 'к справочнику магазин не приклеивается', false === strpos( $url, 'site=' ), $url );

echo "\n== 2. Разбор ответа ==\n";

$GLOBALS['http_next'] = array( 'code' => 200, 'body' => '{"success":true,"customer":{"id":9638,"firstName":"Александр"}}' );
$response             = $client->customersGet( 9638, 'id' );

check( 'ответ успешен', $response->isSuccessful() );
check( 'ключ на месте', $response->offsetExists( 'customer' ) );
check( 'данные читаются', 'Александр' === $response['customer']['firstName'], print_r( $response['customer'], true ) );
check( 'чужого ключа нет', ! $response->offsetExists( 'orders' ) );
check( 'отсутствующий ключ не роняет', null === $response['orders'] );

$GLOBALS['http_next'] = array( 'code' => 403, 'body' => '{"success":false,"errorMsg":"Access denied"}' );
$response             = $client->customersList();

check( 'ошибка распознана', ! $response->isSuccessful() );
check( 'код ответа виден', 403 === $response->getStatusCode() );
check( 'текст ошибки виден', 'Access denied' === $response->getErrorString() );

$GLOBALS['http_next'] = array( 'wp_error' => true );
$response             = $client->customersList();

check( 'обрыв связи — не успех', ! $response->isSuccessful() );
check( 'причина сохранена', 'нет связи' === $response->getErrorString() );

echo "\n== 3. Запись заблокирована ==\n";

$GLOBALS['http']      = array();
$GLOBALS['http_next'] = array( 'code' => 200, 'body' => '{"success":true}' );

$response = $client->customersEdit( array( 'id' => 1, 'externalId' => 2 ), 'id' );

check( 'запись не выполнена', ! $response->isSuccessful() );
check( 'в сеть не ходили', array() === $GLOBALS['http'], print_r( $GLOBALS['http'], true ) );
check( 'сказано почему', false !== mb_strpos( $response->getErrorString(), 'только на чтение' ), $response->getErrorString() );

$client->customersFixExternalIds( array() );
$client->createLoyaltyAccount( array() );

check( 'ни один пишущий метод не прошёл', array() === $GLOBALS['http'] );

$blocked = 0;

foreach ( VL_Account_CRM_Client::journal() as $row ) {
	if ( 'BLOCKED' === $row['method'] ) { ++$blocked; }
}

check( 'попытки записи в журнале', 3 === $blocked, (string) $blocked );

echo "\n== 4. Код магазина по правам ключа ==\n";

$GLOBALS['http_next'] = array( 'code' => 200, 'body' => '{"success":true,"sitesAvailable":["lovefirst"]}' );

$one = new VL_Account_CRM_Client( 'https://lovefirst.retailcrm.ru', 'SECRET' );
check( 'магазин определён по ключу', 'lovefirst' === $one->getSingleSiteForKey() );

$GLOBALS['http_next'] = array( 'code' => 200, 'body' => '{"success":true,"sitesAvailable":["one","two"]}' );

$two = new VL_Account_CRM_Client( 'https://lovefirst.retailcrm.ru', 'SECRET' );
check( 'при двух магазинах не гадаем', '' === $two->getSingleSiteForKey() );

$fixed = new VL_Account_CRM_Client( 'https://lovefirst.retailcrm.ru', 'SECRET', 'lovefirst' );
check( 'заданный магазин важнее', 'lovefirst' === $fixed->getSingleSiteForKey() );

echo "\n== 5. Без ключа в сеть не ходим ==\n";

$GLOBALS['http'] = array();
$empty           = new VL_Account_CRM_Client( 'https://lovefirst.retailcrm.ru', '' );
$response        = $empty->customersList();

check( 'запрос не ушёл', array() === $GLOBALS['http'] );
check( 'ответ — ошибка', ! $response->isSuccessful() );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
