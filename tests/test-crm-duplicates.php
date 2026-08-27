<?php
/**
 * Смоук-тест дублей в базе RetailCRM.
 *
 * У покупателя в CRM нередко две карточки: старая (заказы, почта) и новая,
 * заведённая менеджером или программой лояльности. Проверяется, что плагин
 * берёт свежую карточку за основу, дополняет её недостающими данными из
 * старой, а из двух счетов программы показывает тот, где настоящий баланс,
 * а не приветственная тысяча.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/** Заглушка $wpdb: справочнику нужны только SHOW TABLES и выборка по телефону. */
class Fake_WPDB {
	public $prefix = 'wp_';
	public $rows   = array();

	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) {
		$sql = str_replace( array( '%d', '%s' ), '%s', $sql );

		return vsprintf( $sql, array_map( 'strval', is_array( $args[0] ?? null ) ? $args[0] : $args ) );
	}
	public function get_var( $sql ) {
		return ( false !== stripos( $sql, 'SHOW TABLES' ) ) ? 'wp_vlacc_crm_customers' : null;
	}
	public function get_results( $sql, $out = null ) {
		if ( preg_match( '/WHERE phone = (\S+) (?:ORDER|LIMIT)/', $sql, $m ) ) {
			$found = array();

			foreach ( $this->rows as $row ) {
				if ( (string) $row['phone'] === (string) $m[1] ) { $found[] = $row; }
			}

			return $found;
		}

		return array_values( $this->rows );
	}
	public function get_col( $sql ) { return array(); }
	public function insert( $table, $data ) { $this->rows[] = $data; return 1; }
	public function update( $table, $data, $where ) {
		foreach ( $this->rows as $i => $row ) {
			$match = true;

			foreach ( $where as $key => $value ) {
				if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) { $match = false; }
			}

			if ( $match ) { $this->rows[ $i ] = array_merge( $row, $data ); }
		}

		return 1;
	}
	public function delete( $table, $where ) {
		$before = count( $this->rows );

		foreach ( $this->rows as $i => $row ) {
			foreach ( $where as $key => $value ) {
				if ( (string) ( $row[ $key ] ?? '' ) === (string) $value ) { unset( $this->rows[ $i ] ); }
			}
		}

		$this->rows = array_values( $this->rows );

		return $before - count( $this->rows );
	}
	public function query( $sql ) { $this->rows = array(); return 1; }
}

$GLOBALS['wpdb'] = new Fake_WPDB();

function dbDelta( $sql ) { return array(); }

require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-directory.php';

update_option(
	'woocommerce_integration-retailcrm_settings',
	array(
		'api_url' => 'https://demo.simla.com',
		'api_key' => 'key',
		'loyalty' => 'yes',
	)
);

/**
 * Строка снимка для теста.
 *
 * @param array $values Значения.
 * @return array
 */
function card( $values = array() ) {
	return array_merge(
		array(
			'id'          => 0,
			'crm_id'      => 0,
			'external_id' => 0,
			'phone'       => '79047767897',
			'email'       => '',
			'first_name'  => '',
			'last_name'   => '',
			'city'        => '',
			'subscribed'  => 0,
			'user_id'     => 0,
			'status'      => 'matched',
			'note'        => '',
			'crm_created' => '0000-00-00 00:00:00',
			'updated'     => '2026-08-25 10:00:00',
		),
		$values
	);
}

echo "\n== 1. Какая карточка свежее ==\n";

$sorted = VL_Account_RetailCRM_Directory::sort_rows(
	array(
		card( array( 'crm_id' => 8698, 'crm_created' => '2024-03-01 10:00:00' ) ),
		card( array( 'crm_id' => 9638, 'crm_created' => '2026-08-20 18:00:00' ) ),
	)
);

check( 'первой идёт свежая карточка', 9638 === (int) $sorted[0]['crm_id'], (string) $sorted[0]['crm_id'] );

// Даты нет (снимок старой версии) — сравниваем по id: в CRM он растёт.
$sorted = VL_Account_RetailCRM_Directory::sort_rows(
	array(
		card( array( 'crm_id' => 8698 ) ),
		card( array( 'crm_id' => 9638 ) ),
	)
);

check( 'без даты свежесть определяется по id', 9638 === (int) $sorted[0]['crm_id'] );

echo "\n== 2. Склейка карточек одного человека ==\n";

$combined = VL_Account_RetailCRM_Directory::combine(
	array(
		// Старая карточка: почта и город есть, имени нет.
		card(
			array(
				'crm_id'      => 8698,
				'crm_created' => '2024-03-01 10:00:00',
				'email'       => 'old@example.com',
				'city'        => 'Москва',
				'user_id'     => 1223,
				'external_id' => 1223,
				'subscribed'  => 1,
			)
		),
		// Свежая карточка: имя есть, почты и города нет.
		card(
			array(
				'crm_id'      => 9638,
				'crm_created' => '2026-08-20 18:00:00',
				'first_name'  => 'Александр',
				'last_name'   => 'Иванов',
				'user_id'     => 1620,
				'external_id' => 1620,
			)
		),
	)
);

check( 'за основу взята свежая карточка', 9638 === (int) $combined['crm_id'], (string) $combined['crm_id'] );
check( 'имя из свежей карточки', 'Александр' === $combined['first_name'] );
check( 'почта дополнена из старой', 'old@example.com' === $combined['email'] );
check( 'город дополнен из старой', 'Москва' === $combined['city'] );
check( 'согласие на рассылку сохранено', 1 === (int) $combined['subscribed'] );
check( 'обе карточки в списке', array( 9638, 8698 ) === $combined['crm_ids'], print_r( $combined['crm_ids'], true ) );
check( 'оба аккаунта в списке', array( 1620, 1223 ) === $combined['user_ids'], print_r( $combined['user_ids'], true ) );
check( 'разные аккаунты — это конфликт', 'conflict' === $combined['status'] );

$one = VL_Account_RetailCRM_Directory::combine( array( card( array( 'crm_id' => 700, 'user_id' => 5 ) ) ) );

check( 'одна карточка конфликтом не считается', 'matched' === $one['status'] );
check( 'пустой список — пусто', false === VL_Account_RetailCRM_Directory::combine( array() ) );

echo "\n== 3. Карточка для конкретного аккаунта ==\n";

$GLOBALS['wpdb']->rows = array(
	card( array( 'crm_id' => 8698, 'crm_created' => '2024-03-01 10:00:00', 'external_id' => 0 ) ),
	card( array( 'crm_id' => 9638, 'crm_created' => '2026-08-20 18:00:00', 'external_id' => 1620 ) ),
);

$row = VL_Account_RetailCRM_Directory::row_for_user( '79047767897', 1620 );

check( 'своя карточка находится по externalId', 9638 === (int) $row['crm_id'], print_r( $row, true ) );

$row = VL_Account_RetailCRM_Directory::row_for_user( '79047767897', 1223 );

check( 'чужую карточку не отдаём, отдаём свободную', 8698 === (int) $row['crm_id'], print_r( $row, true ) );
check( 'все карточки номера видны', array( 9638, 8698 ) === VL_Account_RetailCRM_Directory::crm_ids_by_phone( '79047767897' ) );

echo "\n== 4. Из двух счетов показываем настоящий баланс ==\n";

VL_Account_Settings::update( array( 'crm_welcome_bonus' => 1000 ) );

$welcome = array( 'id' => 2853, 'active' => true, 'amount' => 1000, 'ordersSum' => 0 );
$real    = array( 'id' => 1200, 'active' => true, 'amount' => 350, 'ordersSum' => 24000 );

$pick = VL_Account_RetailCRM::pick_loyalty( array( $welcome, $real ) );
check( 'меньше тысячи, зато настоящие', 1200 === (int) $pick['id'], print_r( $pick, true ) );

$rich = array( 'id' => 1300, 'active' => true, 'amount' => 5200, 'ordersSum' => 90000 );
$pick = VL_Account_RetailCRM::pick_loyalty( array( $welcome, $rich ) );
check( 'больше тысячи — тоже настоящие', 1300 === (int) $pick['id'] );

// Пустой счёт (ни баллов, ни заказов) приветственному не конкурент.
$empty = array( 'id' => 1400, 'active' => false, 'amount' => 0, 'ordersSum' => 0 );
$pick  = VL_Account_RetailCRM::pick_loyalty( array( $empty, $welcome ) );
check( 'пустой счёт не перебивает приветственный', 2853 === (int) $pick['id'], print_r( $pick, true ) );

// Не активированное участие с настоящим балансом важнее активного пустого.
$pick = VL_Account_RetailCRM::pick_loyalty(
	array(
		array( 'id' => 1500, 'active' => true, 'amount' => 1000, 'ordersSum' => 0 ),
		array( 'id' => 1600, 'active' => false, 'amount' => 740, 'ordersSum' => 12000 ),
	)
);
check( 'настоящий баланс важнее активности', 1600 === (int) $pick['id'], print_r( $pick, true ) );

$pick = VL_Account_RetailCRM::pick_loyalty(
	array(
		array( 'id' => 1700, 'active' => false, 'amount' => 1000, 'ordersSum' => 0 ),
		array( 'id' => 1800, 'active' => true, 'amount' => 1000, 'ordersSum' => 0 ),
	)
);
check( 'при равных суммах берём активное', 1800 === (int) $pick['id'], print_r( $pick, true ) );

check( 'счетов нет — выбирать нечего', false === VL_Account_RetailCRM::pick_loyalty( array() ) );

// Размер приветственных баллов настраивается.
VL_Account_Settings::update( array( 'crm_welcome_bonus' => 500 ) );
$pick = VL_Account_RetailCRM::pick_loyalty(
	array(
		array( 'id' => 1900, 'active' => true, 'amount' => 500, 'ordersSum' => 0 ),
		array( 'id' => 2000, 'active' => true, 'amount' => 1000, 'ordersSum' => 0 ),
	)
);
check( 'приветственная сумма берётся из настроек', 2000 === (int) $pick['id'], print_r( $pick, true ) );
VL_Account_Settings::update( array( 'crm_welcome_bonus' => 1000 ) );

echo "\n== 4.1. Разные люди на одном номере ==\n";

// Карточки с разными почтами — это разные люди, номеру верить нельзя.
$mixed = VL_Account_RetailCRM_Directory::combine(
	array(
		card( array( 'crm_id' => 8698, 'email' => 'smirnova@example.com', 'external_id' => 1223 ) ),
		card( array( 'crm_id' => 9638, 'email' => 'aleksandr@example.com', 'external_id' => 1620 ) ),
	)
);

check( 'разные почты — номер негоден', '' !== VL_Account_RetailCRM_Directory::conflict_reason( $mixed ), print_r( $mixed, true ) );
check( 'в причине названы адреса', false !== mb_strpos( VL_Account_RetailCRM_Directory::conflict_reason( $mixed ), 'smirnova@example.com' ) );

// Один человек: почта одна, карточек несколько — это норма.
$same = VL_Account_RetailCRM_Directory::combine(
	array(
		card( array( 'crm_id' => 8698, 'email' => 'one@example.com', 'external_id' => 1223 ) ),
		card( array( 'crm_id' => 9638, 'email' => '', 'external_id' => 1223 ) ),
	)
);

check( 'одна почта на карточках — номер годен', '' === VL_Account_RetailCRM_Directory::conflict_reason( $same ), print_r( $same, true ) );

// Разные externalId — тоже разные люди, даже если почт в карточках нет.
$split = VL_Account_RetailCRM_Directory::combine(
	array(
		card( array( 'crm_id' => 8700, 'email' => '', 'external_id' => 1224 ) ),
		card( array( 'crm_id' => 8704, 'email' => '', 'external_id' => 1225 ) ),
	)
);

check( 'разные аккаунты в карточках — номер негоден', '' !== VL_Account_RetailCRM_Directory::conflict_reason( $split ) );

echo "\n== 4.2. Счёт без номера телефона не берём ==\n";

// Фильтр CRM по номеру иногда игнорируется: в ответ приходит чужой счёт,
// у которого своего номера нет. Раньше такой счёт принимался за «наш».
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[
		{"id":9001,"active":true,"amount":700,"customerId":8867},
		{"id":9002,"active":true,"amount":250,"phoneNumber":"+79506488684","customerId":8868}
	]}'
);

$api   = VL_Account_RetailCRM::api();
$found = VL_Account_RetailCRM_Directory::loyalty_accounts_by_phone( $api, '79047767897' );

check( 'чужие счета отброшены', array() === $found, print_r( $found, true ) );

WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[{"id":9003,"active":true,"amount":1000,"phoneNumber":"+79047767897","customerId":9638}]}'
);

$found = VL_Account_RetailCRM_Directory::loyalty_accounts_by_phone( $api, '79047767897' );

check( 'свой счёт находится', 1 === count( $found ) && 9003 === (int) $found[0]['id'], print_r( $found, true ) );

echo "\n== 4.2.1. Счёт с нашим номером на чужой карточке ==\n";

// Так и вышло на живой базе: участие в ПЛ завели с номером покупателя, но на
// карточке постороннего человека — у него в карточке свой, другой телефон.
$foreign = array( 'id' => 2853, 'active' => true, 'amount' => 48, 'customerId' => 8867, 'phoneNumber' => '+79047767897' );
$mine    = array( 'id' => 2900, 'active' => true, 'amount' => 1000, 'customerId' => 9638, 'phoneNumber' => '+79047767897' );

WC_Retailcrm_Proxy::$responses['customersGet'] = function ( $args ) {
	$id = (int) ( $args[0] ?? 0 );

	// 8867 — карточка постороннего: номер в ней другой.
	if ( 8867 === $id ) {
		return new WC_Retailcrm_Response( 200, '{"customer":{"id":8867,"externalId":146,"firstName":"Ярослав","phones":[{"number":"+79999999999"}]}}' );
	}

	return new WC_Retailcrm_Response( 200, '{"customer":{"id":9638,"firstName":"Александр","phones":[{"number":"+79047767897"}]}}' );
};

$api  = VL_Account_RetailCRM::api();
$own  = VL_Account_RetailCRM::own_loyalty_accounts( $api, array( $foreign, $mine ), '79047767897', 1620 );
$ids  = wp_list_pluck( $own, 'id' );

check( 'чужой счёт отброшен', ! in_array( 2853, $ids, true ), print_r( $ids, true ) );
check( 'свой счёт остался', array( 2900 ) === array_map( 'intval', $ids ), print_r( $ids, true ) );

// Карточка привязана к нашему аккаунту — счёт наш, даже если номера в ней нет.
$linked = VL_Account_RetailCRM::own_loyalty_accounts( $api, array( $foreign ), '79047767897', 146 );

check( 'карточка своего аккаунта не отбрасывается', 1 === count( $linked ) );

// Карточку не прочитать — доказательств чужого владельца нет, счёт оставляем.
WC_Retailcrm_Proxy::$responses['customersGet'] = new WC_Retailcrm_Response( 404, '{}' );

check( 'нечитаемая карточка счёт не убивает', 1 === count( VL_Account_RetailCRM::own_loyalty_accounts( $api, array( $mine ), '79047767897', 1620 ) ) );

echo "\n== 4.3. Карточки номера можно забыть ==\n";

$GLOBALS['wpdb']->rows = array(
	card( array( 'crm_id' => 8698 ) ),
	card( array( 'crm_id' => 9638 ) ),
	card( array( 'crm_id' => 7000, 'phone' => '79261234567' ) ),
);

$deleted = VL_Account_RetailCRM_Directory::forget_phone( '+7 (904) 776-78-97' );

check( 'строки номера удалены', 2 === $deleted, (string) $deleted );
check( 'чужой номер не тронут', 1 === count( $GLOBALS['wpdb']->rows ) );

echo "\n== 5. Карточка объединённого аккаунта переходит выжившему ==\n";

WC_Retailcrm_Proxy::$calls                                = array();
WC_Retailcrm_Proxy::$responses['customersFixExternalIds'] = new WC_Retailcrm_Response( 200, '{"success":true}' );

update_user_meta( 1223, VL_Account_User::META_PHONE, '79047767897' );

$GLOBALS['wpdb']->rows = array(
	card( array( 'crm_id' => 9638, 'crm_created' => '2026-08-20 18:00:00', 'external_id' => 1620 ) ),
);

check( 'осиротевшая карточка передана', true === VL_Account_RetailCRM::rebind_card( 1620, 1223 ) );
check( 'externalId переписан в CRM', in_array( 'customersFixExternalIds', WC_Retailcrm_Proxy::$calls, true ) );

// У выжившего своя карточка — вторую к нему не привязать.
WC_Retailcrm_Proxy::$calls = array();

$GLOBALS['wpdb']->rows = array(
	card( array( 'crm_id' => 8698, 'external_id' => 1223 ) ),
	card( array( 'crm_id' => 9638, 'external_id' => 1620 ) ),
);

check( 'вторую карточку не навязываем', false === VL_Account_RetailCRM::rebind_card( 1620, 1223 ) );
check( 'запросов в CRM не было', array() === WC_Retailcrm_Proxy::$calls, print_r( WC_Retailcrm_Proxy::$calls, true ) );

echo "\n== 6. Новый аккаунт забирает существующую карточку ==\n";

// Simla ищет клиента по почте, поэтому на повторной регистрации того же
// телефона она заводит вторую карточку. Мы успеваем раньше: находим карточку
// по номеру и проставляем ей externalId нового аккаунта.

WC_Retailcrm_Proxy::$responses['customersFixExternalIds'] = new WC_Retailcrm_Response( 200, '{"success":true}' );
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList']   = new WC_Retailcrm_Response( 200, '{"loyaltyAccounts":[]}' );
unset( WC_Retailcrm_Proxy::$responses['customersGet'] );

/**
 * Подготовить снимок и обнулить счётчики перед проверкой.
 *
 * @param array $rows Строки снимка.
 */
function adopt_setup( $rows ) {
	$GLOBALS['wpdb']->rows     = $rows;
	WC_Retailcrm_Proxy::$calls = array();

	VL_Account_RetailCRM_Directory::flush_cache();
	VL_Account_RetailCRM::flush( 4001 );
}

adopt_setup( array( card( array( 'crm_id' => 9638, 'external_id' => 0 ) ) ) );

$got = VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '+7 904 776-78-97' );

check( 'свободная карточка досталась новому аккаунту', 9638 === $got, (string) $got );
check( 'externalId проставлен в CRM', in_array( 'customersFixExternalIds', WC_Retailcrm_Proxy::$calls, true ) );
check( 'карточка сразу закреплена за аккаунтом', 9638 === VL_Account_RetailCRM::crm_customer_id( 4001 ) );

// Карточка указывает на удалённый аккаунт — забираем как осиротевшую.
adopt_setup( array( card( array( 'crm_id' => 8698, 'external_id' => 1223 ) ) ) );

check( 'осиротевшая карточка досталась новому аккаунту', 8698 === VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '79047767897' ) );

// Карточка занята живым аккаунтом — не перехватываем.
$GLOBALS['users'][1620] = new WP_User( array( 'ID' => 1620, 'user_email' => 'live@example.com' ) );

adopt_setup( array( card( array( 'crm_id' => 9638, 'external_id' => 1620 ) ) ) );

check( 'чужую живую карточку не забираем', 0 === VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '79047767897' ) );
check( 'externalId чужой карточки не тронут', ! in_array( 'customersFixExternalIds', WC_Retailcrm_Proxy::$calls, true ) );

// На номере карточки разных людей — номеру верить нельзя, пусть Simla заводит свою.
adopt_setup(
	array(
		card( array( 'crm_id' => 8700, 'email' => 'smirnova@example.com', 'external_id' => 0 ) ),
		card( array( 'crm_id' => 8704, 'email' => 'aleksandr@example.com', 'external_id' => 0 ) ),
	)
);

check( 'на спорном номере карточку не забираем', 0 === VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '79047767897' ) );
check( 'в CRM ничего не записано', ! in_array( 'customersFixExternalIds', WC_Retailcrm_Proxy::$calls, true ) );

// Счёт программы лояльности — опора надёжнее снимка: он показывает ту самую
// карточку владельца номера даже среди спорных.
WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response(
	200,
	'{"loyaltyAccounts":[{"id":9100,"active":true,"amount":740,"phoneNumber":"+79047767897","customerId":8704}]}'
);
WC_Retailcrm_Proxy::$responses['customersGet']          = new WC_Retailcrm_Response(
	200,
	'{"customer":{"id":8704,"firstName":"Александр","phones":[{"number":"+79047767897"}]}}'
);

adopt_setup(
	array(
		card( array( 'crm_id' => 8700, 'email' => 'smirnova@example.com', 'external_id' => 0 ) ),
		card( array( 'crm_id' => 8704, 'email' => 'aleksandr@example.com', 'external_id' => 0 ) ),
	)
);

check( 'карточку показал счёт программы лояльности', 8704 === VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '79047767897' ) );

// Счёт с нашим номером на карточке с чужим телефоном якорем не считается.
WC_Retailcrm_Proxy::$responses['customersGet'] = new WC_Retailcrm_Response(
	200,
	'{"customer":{"id":8704,"firstName":"Ярослав","phones":[{"number":"+79999999999"}]}}'
);

adopt_setup(
	array(
		card( array( 'crm_id' => 8700, 'email' => 'smirnova@example.com', 'external_id' => 0 ) ),
		card( array( 'crm_id' => 8704, 'email' => 'aleksandr@example.com', 'external_id' => 0 ) ),
	)
);

check( 'карточка с чужим телефоном якорем не стала', 0 === VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '79047767897' ) );

WC_Retailcrm_Proxy::$responses['getLoyaltyAccountList'] = new WC_Retailcrm_Response( 200, '{"loyaltyAccounts":[]}' );
unset( WC_Retailcrm_Proxy::$responses['customersGet'] );

// Без номера и с выключенной привязкой по телефону делать нечего.
adopt_setup( array( card( array( 'crm_id' => 9638, 'external_id' => 0 ) ) ) );

check( 'без номера карточку не ищем', 0 === VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '' ) );

VL_Account_Settings::update( array( 'crm_link_by_phone' => 0 ) );
adopt_setup( array( card( array( 'crm_id' => 9638, 'external_id' => 0 ) ) ) );

check( 'привязка по телефону выключена — не забираем', 0 === VL_Account_RetailCRM::adopt_card_for_new_user( 4001, '79047767897' ) );

VL_Account_Settings::update( array( 'crm_link_by_phone' => 1 ) );

echo "\n== 6.1. Создание карточки у Simla снимается ==\n";

/** Заглушка объекта Simla, у которого создание клиента висит на user_register. */
class Fake_Simla_Base extends WC_Retailcrm_Base {
	public function create_customer( $id ) {
		$GLOBALS['log'][] = array( 'simla_create_customer', $id );
	}
}

/** Заглушка реестра хуков WordPress. */
class Fake_Hook {
	public $callbacks = array();
}

$simla = new Fake_Simla_Base();

/**
 * Собрать $wp_filter с хуком Simla, как это делает WordPress.
 *
 * @param object $simla Объект Simla.
 */
function hook_simla( $simla ) {
	$hook                     = new Fake_Hook();
	$hook->callbacks          = array(
		10 => array(
			'simla' => array(
				'function'      => array( $simla, 'create_customer' ),
				'accepted_args' => 1,
			),
		),
	);
	$GLOBALS['wp_filter']     = array( 'user_register' => $hook );
	$GLOBALS['removed_actions'] = array();
}

VL_Account_Settings::update( array( 'crm_sync_customer' => 1 ) );

hook_simla( $simla );
adopt_setup( array( card( array( 'crm_id' => 9638, 'external_id' => 0 ) ) ) );

VL_Account_RetailCRM_Customer::expect_phone( '79047767897' );
VL_Account_RetailCRM_Customer::instance()->claim_existing_card( 4001 );

check( 'карточка забрана при регистрации', 9638 === VL_Account_RetailCRM::crm_customer_id( 4001 ) );
check( 'создание клиента у Simla снято', array() === $GLOBALS['wp_filter']['user_register']->callbacks[10], print_r( $GLOBALS['removed_actions'], true ) );

// Пропуск действует ровно на один аккаунт: следом хук Simla возвращается.
$GLOBALS['actions']['user_register'] = array();

VL_Account_RetailCRM_Customer::instance()->restore_simla_create();

check( 'хук Simla возвращён после регистрации', array( array( $simla, 'create_customer' ) ) == $GLOBALS['actions']['user_register'], print_r( $GLOBALS['actions']['user_register'], true ) ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- сравниваем объекты по содержимому.

$GLOBALS['actions']['user_register'] = array();

VL_Account_RetailCRM_Customer::instance()->restore_simla_create();

check( 'второй раз возвращать нечего', array() === $GLOBALS['actions']['user_register'] );

// Номер не передали (регистрация не из кабинета) — в чужие хуки не лезем.
hook_simla( $simla );
adopt_setup( array( card( array( 'crm_id' => 9638, 'external_id' => 0 ) ) ) );

VL_Account_RetailCRM_Customer::instance()->claim_existing_card( 4001 );

check( 'без номера хук Simla на месте', 1 === count( $GLOBALS['wp_filter']['user_register']->callbacks[10] ) );

// Номер спорный, карточку не забрали — Simla должна отработать как обычно.
hook_simla( $simla );
adopt_setup(
	array(
		card( array( 'crm_id' => 8700, 'email' => 'smirnova@example.com', 'external_id' => 0 ) ),
		card( array( 'crm_id' => 8704, 'email' => 'aleksandr@example.com', 'external_id' => 0 ) ),
	)
);

VL_Account_RetailCRM_Customer::expect_phone( '79047767897' );
VL_Account_RetailCRM_Customer::instance()->claim_existing_card( 4001 );

check( 'карточку не забрали — хук Simla на месте', 1 === count( $GLOBALS['wp_filter']['user_register']->callbacks[10] ) );

// Номер запоминается на одну регистрацию: следующий аккаунт его не унаследует.
hook_simla( $simla );
adopt_setup( array( card( array( 'crm_id' => 9638, 'external_id' => 0 ) ) ) );

VL_Account_RetailCRM_Customer::expect_phone( '79047767897' );
VL_Account_RetailCRM_Customer::instance()->claim_existing_card( 4001 );
VL_Account_RetailCRM::flush( 4002 );
VL_Account_RetailCRM_Customer::instance()->claim_existing_card( 4002 );

check( 'номер не переходит следующему аккаунту', 0 === VL_Account_RetailCRM::crm_customer_id( 4002 ) );

unset( $GLOBALS['wp_filter'] );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
