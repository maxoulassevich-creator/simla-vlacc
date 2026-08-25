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

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
