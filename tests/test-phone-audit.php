<?php
/**
 * Смоук-тест разбора телефонов, записанных плагином в чужие профили.
 *
 * Телефон попадает в аккаунт из двух мест: вход по найденному аккаунту и
 * кнопка «Дописать телефоны в аккаунты». Оба пишутся в журнал, поэтому по
 * журналу можно и проверить каждую запись, и откатить её. Проверяется, что
 * подтверждённые номера отличаются от сомнительных и что снятие номера
 * ничего, кроме номера, не трогает.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки ---- */

class WC_Order {
	public $id;
	public $customer_id;
	public $phone;
	public function __construct( $id, $customer_id, $phone ) {
		$this->id          = $id;
		$this->customer_id = $customer_id;
		$this->phone       = $phone;
	}
	public function get_id() { return $this->id; }
	public function get_customer_id() { return $this->customer_id; }
	public function get_billing_phone() { return $this->phone; }
}

/** Заказы сайта: ищем по номеру, как настоящий класс. */
class Fake_Orders {
	public static $orders = array();

	public static function find_orders_by_phone( $phone ) {
		$found = array();

		foreach ( self::$orders as $order ) {
			if ( VL_Account_Phone::normalize( $order->get_billing_phone() ) === VL_Account_Phone::normalize( $phone ) ) {
				$found[] = $order;
			}
		}

		return $found;
	}
}

class_alias( 'Fake_Orders', 'VL_Account_Orders' );

/** Снимок базы CRM: ровно те методы, которые нужны проверке номера. */
class Fake_Directory {
	public static $rows = array();

	public static function rows_by_phone( $phone ) {
		$found = array();

		foreach ( self::$rows as $row ) {
			if ( (string) $row['phone'] === (string) VL_Account_Phone::normalize( $phone ) ) { $found[] = $row; }
		}

		return $found;
	}

	public static function combine( $rows ) {
		$rows = array_values( (array) $rows );

		if ( ! $rows ) {
			return false;
		}

		$best                 = $rows[0];
		$best['emails']       = array_values( array_unique( array_filter( wp_list_pluck( $rows, 'email' ) ) ) );
		$best['external_ids'] = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'external_id' ) ) ) ) );

		if ( '' === (string) $best['email'] && $best['emails'] ) {
			$best['email'] = $best['emails'][0];
		}

		return $best;
	}

	public static function conflict_reason( $row ) {
		if ( count( (array) ( $row['emails'] ?? array() ) ) > 1 ) { return 'на номере карточки разных людей'; }
		if ( count( (array) ( $row['external_ids'] ?? array() ) ) > 1 ) { return 'карточки привязаны к разным аккаунтам'; }

		return '';
	}

	public static function install() {}
	public static function flush_cache() {}
	public static function mixed_phones() { return array(); }
	public static function query( $args = array() ) { return array(); }
	public static function stats() { return array(); }
}

class_alias( 'Fake_Directory', 'VL_Account_RetailCRM_Directory' );

/** Журнал живёт в массиве: класс читает его через $wpdb. */
$GLOBALS['journal'] = array();

class Fake_WPDB {
	public $prefix = 'wp_';

	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) {
		// Подставляем аргументы, чтобы разбирать запрос было проще.
		$sql  = str_replace( array( '%d', '%s' ), "'%s'", $sql );
		$list = is_array( $args[0] ?? null ) ? $args[0] : $args;

		return $list ? vsprintf( $sql, array_map( 'strval', $list ) ) : $sql;
	}
	public function get_var( $sql ) { return false !== stripos( $sql, 'SHOW TABLES' ) ? 'wp_vlacc_identity_log' : null; }
	public function get_results( $sql, $out = null ) {
		$rows = $GLOBALS['journal'];

		if ( preg_match( "/event IN \('?([a-z, ']+)\)/", $sql, $m ) || preg_match( "/event = '?([a-z]+)/", $sql, $m ) ) {
			$events = array_map( 'trim', explode( ',', str_replace( array( "'", ')' ), '', $m[1] ) ) );
			$rows   = array();

			foreach ( $GLOBALS['journal'] as $row ) {
				if ( in_array( $row['event'], $events, true ) ) { $rows[] = $row; }
			}
		}

		return $rows;
	}
	public function insert( $table, $data ) { $GLOBALS['journal'][] = $data; return 1; }
	public function update( $table, $data, $where ) { return 1; }
	public function delete( $table, $where ) { return 1; }
}

$GLOBALS['wpdb'] = new Fake_WPDB();

function dbDelta( $sql ) { return array(); }
function get_edit_user_link( $id ) { return 'https://example.com/user/' . (int) $id; }

require_once VLACC_PATH . 'includes/class-vl-identity.php';
require_once VLACC_PATH . 'includes/class-vl-identity-admin.php';

/**
 * Записать в журнал так же, как это делает плагин.
 *
 * @param string $event   Событие.
 * @param int    $user_id Аккаунт.
 * @param string $phone   Номер.
 */
function journal( $event, $user_id, $phone ) {
	$GLOBALS['journal'][] = array(
		'id'      => count( $GLOBALS['journal'] ) + 1,
		'created' => '2026-08-25 12:00:00',
		'event'   => $event,
		'phone'   => $phone,
		'user_id' => $user_id,
	);
}

/**
 * Аккаунт для теста.
 *
 * @param int    $id    ID.
 * @param string $email Почта.
 * @param string $phone Номер в профиле.
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
		update_user_meta( $id, 'billing_phone', VL_Account_Phone::format( $phone ) );
	}
}

$GLOBALS['users']    = array();
$GLOBALS['usermeta'] = array();
$GLOBALS['caps']     = array();
$GLOBALS['log']      = array();

echo "\n== 1. Чем подтверждается записанный номер ==\n";

// 1223: номер записал вход, а в CRM на этом номере разные люди — ошибка.
user( 1223, 'smirnova@example.com', '79047767897' );
journal( 'match', 1223, '79047767897' );

// 1300: номер подтверждён собственным заказом аккаунта.
user( 1300, 'buyer@example.com', '79261112233' );
journal( 'backfill', 1300, '79261112233' );
Fake_Orders::$orders[] = new WC_Order( 501, 1300, '+7 (926) 111-22-33' );

// 1400: заказов нет, но карточка CRM с этим номером — того же человека.
user( 1400, 'crm@example.com', '79265556677' );
journal( 'backfill', 1400, '79265556677' );

Fake_Directory::$rows = array(
	array( 'crm_id' => 8698, 'phone' => '79047767897', 'email' => 'smirnova@example.com', 'external_id' => 1223 ),
	array( 'crm_id' => 9638, 'phone' => '79047767897', 'email' => 'aleksandr@example.com', 'external_id' => 1620 ),
	array( 'crm_id' => 9700, 'phone' => '79265556677', 'email' => 'crm@example.com', 'external_id' => 1400 ),
);

$audit = VL_Account_Identity_Admin::audit_phones();
$by_id = array();

foreach ( $audit as $row ) {
	$by_id[ $row['user']->ID ] = $row;
}

check( 'в списке все три записи', 3 === count( $audit ), print_r( array_keys( $by_id ), true ) );
check( 'сомнительная запись найдена', isset( $by_id[1223] ) && 'bad' === $by_id[1223]['verdict'], print_r( $by_id[1223] ?? array(), true ) );
check( 'и причина названа', isset( $by_id[1223] ) && false !== mb_strpos( $by_id[1223]['note'], 'разных людей' ) );
check( 'заказ аккаунта подтверждает номер', isset( $by_id[1300] ) && 'ok' === $by_id[1300]['verdict'], print_r( $by_id[1300] ?? array(), true ) );
check( 'карточка CRM с той же почтой подтверждает', isset( $by_id[1400] ) && 'ok' === $by_id[1400]['verdict'], print_r( $by_id[1400] ?? array(), true ) );
check( 'сомнительные идут первыми', 'bad' === $audit[0]['verdict'] );
check( 'видно, откуда номер', 'match' === $by_id[1223]['source'] && 'backfill' === $by_id[1300]['source'] );

echo "\n== 2. Чужая почта в карточке — тоже повод разобраться ==\n";

user( 1500, 'mine@example.com', '79269998877' );
journal( 'match', 1500, '79269998877' );

Fake_Directory::$rows[] = array( 'crm_id' => 9800, 'phone' => '79269998877', 'email' => 'someone@example.com', 'external_id' => 1500 );

$audit = VL_Account_Identity_Admin::audit_phones();
$found = false;

foreach ( $audit as $row ) {
	if ( 1500 === (int) $row['user']->ID ) { $found = $row; }
}

check( 'номер с чужой почтой в карточке — сомнителен', $found && 'bad' === $found['verdict'], print_r( $found, true ) );

echo "\n== 3. Снятие номера ==\n";

$before_email = $GLOBALS['users'][1223]->user_email;

check( 'номер снят', true === VL_Account_Identity_Admin::unlink_phone( 1223, '+7 (904) 776-78-97' ) );
check( 'в профиле номера нет', '' === (string) get_user_meta( 1223, VL_Account_User::META_PHONE, true ) );
check( 'телефон плательщика тоже убран', '' === (string) get_user_meta( 1223, 'billing_phone', true ) );
check( 'аккаунт на месте', isset( $GLOBALS['users'][1223] ) && $before_email === $GLOBALS['users'][1223]->user_email );
check( 'снятие записано в журнал', 'unlink' === end( $GLOBALS['journal'] )['event'] );
check( 'чужой номер не трогаем', false === VL_Account_Identity_Admin::unlink_phone( 1300, '79047767897' ) );
check( 'у 1300 номер остался', '79261112233' === (string) get_user_meta( 1300, VL_Account_User::META_PHONE, true ) );

check( 'снятая запись уходит из списка', ! isset( array_column( VL_Account_Identity_Admin::audit_phones(), null, 'phone' )['79047767897'] ) );

echo "\n== 4. Откат кнопки «Дописать телефоны» ==\n";

$count = VL_Account_Identity_Admin::undo_backfill();

check( 'откачены обе записи backfill', 2 === $count, (string) $count );
check( 'у 1300 номера больше нет', '' === (string) get_user_meta( 1300, VL_Account_User::META_PHONE, true ) );
check( 'у 1400 номера больше нет', '' === (string) get_user_meta( 1400, VL_Account_User::META_PHONE, true ) );
check( 'вход по номеру откат не трогает', '79269998877' === (string) get_user_meta( 1500, VL_Account_User::META_PHONE, true ) );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
