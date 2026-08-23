<?php
/**
 * Смоук-тест справочника покупателей RetailCRM.
 *
 * Проверяется то, на чём держится сопоставление аккаунтов: разбор клиента CRM
 * (телефоны, externalId, почта), связывание с пользователем сайта, конфликты,
 * поиск по номеру в снимке и точечный запрос в CRM, а также постраничная
 * выгрузка базы маленькими пачками.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/**
 * Заглушка $wpdb: держит строки справочника в массиве и понимает те
 * несколько запросов, которые делает класс.
 */
class Fake_WPDB {
	public $prefix = 'wp_';
	public $rows   = array();
	private $next  = 1;

	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) {
		// Подставляем аргументы, чтобы разбирать запрос было проще.
		$sql = str_replace( array( '%d', '%s' ), '%s', $sql );

		return vsprintf( $sql, array_map( 'strval', is_array( $args[0] ?? null ) ? $args[0] : $args ) );
	}

	public function get_var( $sql ) {
		if ( false !== stripos( $sql, 'SHOW TABLES' ) ) {
			return 'wp_vlacc_crm_customers';
		}

		if ( preg_match( '/crm_id = (\d+) AND phone = (\S*)/', $sql, $m ) ) {
			foreach ( $this->rows as $row ) {
				if ( (int) $row['crm_id'] === (int) $m[1] && (string) $row['phone'] === (string) $m[2] ) {
					return $row['id'];
				}
			}
		}

		if ( false !== stripos( $sql, "phone <> ''" ) ) {
			$count = 0;

			foreach ( $this->rows as $row ) {
				if ( '' !== $row['phone'] ) { ++$count; }
			}

			return $count;
		}

		return null;
	}

	public function get_results( $sql, $out = null ) {
		if ( preg_match( '/WHERE phone = (\S+) ORDER/', $sql, $m ) ) {
			$found = array();

			foreach ( $this->rows as $row ) {
				if ( (string) $row['phone'] === (string) $m[1] ) { $found[] = $row; }
			}

			return $found;
		}

		if ( false !== stripos( $sql, 'GROUP BY status' ) ) {
			$by = array();

			foreach ( $this->rows as $row ) {
				$by[ $row['status'] ] = ( $by[ $row['status'] ] ?? 0 ) + 1;
			}

			$out = array();

			foreach ( $by as $status => $total ) {
				$out[] = array( 'status' => $status, 'total' => $total );
			}

			return $out;
		}

		if ( preg_match( '/ORDER BY id ASC LIMIT (\d+) OFFSET (\d+)/', $sql, $m ) ) {
			return array_slice( array_values( $this->rows ), (int) $m[2], (int) $m[1] );
		}

		return array_values( $this->rows );
	}

	public function get_col( $sql ) {
		// Телефоны, на которых сошлось несколько разных аккаунтов.
		$by = array();

		foreach ( $this->rows as $row ) {
			if ( '' === $row['phone'] || ! $row['user_id'] ) { continue; }

			$by[ $row['phone'] ][ $row['user_id'] ] = true;
		}

		$conflicts = array();

		foreach ( $by as $phone => $users ) {
			if ( count( $users ) > 1 ) { $conflicts[] = $phone; }
		}

		return $conflicts;
	}

	public function insert( $table, $data ) {
		$data['id']              = $this->next++;
		$this->rows[ $data['id'] ] = wp_parse_args( $data, array( 'user_id' => 0, 'status' => 'new', 'note' => '' ) );

		return 1;
	}

	public function update( $table, $data, $where ) {
		foreach ( $this->rows as $id => $row ) {
			$match = true;

			foreach ( $where as $key => $value ) {
				if ( (string) $row[ $key ] !== (string) $value ) { $match = false; }
			}

			if ( $match ) {
				$this->rows[ $id ] = array_merge( $row, $data );
			}
		}

		return 1;
	}

	public function query( $sql ) { $this->rows = array(); return 1; }
}

$GLOBALS['wpdb'] = new Fake_WPDB();

function dbDelta( $sql ) { return array(); }

require_once VLACC_PATH . 'includes/integrations/class-vl-retailcrm-directory.php';

// Интеграция включена: ключи CRM и настройки.
update_option(
	'woocommerce_integration-retailcrm_settings',
	array(
		'api_url' => 'https://demo.simla.com',
		'api_key' => 'key',
	)
);

/**
 * Клиент CRM для теста.
 */
function crm_customer( $id, $phones, $email = '', $external = 0 ) {
	$list = array();

	foreach ( (array) $phones as $phone ) {
		$list[] = array( 'number' => $phone );
	}

	return array(
		'id'         => $id,
		'externalId' => $external,
		'email'      => $email,
		'firstName'  => 'Иван',
		'lastName'   => 'Петров',
		'phones'     => $list,
		'address'    => array( 'city' => 'Москва' ),
		'subscribed' => true,
	);
}

echo "\n== 1. Разбор клиента CRM ==\n";

$GLOBALS['wpdb']->rows = array();

VL_Account_RetailCRM_Directory::store( crm_customer( 500, array( '+7 (926) 123-45-67', '8 926 765 43 21' ), 'ivan@example.com', 42 ) );

$rows = array_values( $GLOBALS['wpdb']->rows );

check( 'на каждый телефон своя строка', 2 === count( $rows ), print_r( $rows, true ) );
check( 'телефон нормализован', '79261234567' === $rows[0]['phone'], $rows[0]['phone'] );
check( 'второй телефон тоже', '79267654321' === $rows[1]['phone'], $rows[1]['phone'] );
check( 'externalId сохранён', 42 === (int) $rows[0]['external_id'] );
check( 'почта в нижнем регистре', 'ivan@example.com' === $rows[0]['email'] );
check( 'город из адреса', 'Москва' === $rows[0]['city'] );
check( 'подписка сохранена', 1 === (int) $rows[0]['subscribed'] );

$before = count( $GLOBALS['wpdb']->rows );
VL_Account_RetailCRM_Directory::store( crm_customer( 500, array( '+79261234567', '89267654321' ), 'ivan@example.com', 42 ) );
check( 'повторная выгрузка не плодит строки', $before === count( $GLOBALS['wpdb']->rows ), (string) count( $GLOBALS['wpdb']->rows ) );

$GLOBALS['wpdb']->rows = array();
VL_Account_RetailCRM_Directory::store( crm_customer( 501, array(), 'nophone@example.com' ) );
$rows = array_values( $GLOBALS['wpdb']->rows );
check( 'клиент без телефона тоже записан', 1 === count( $rows ) && '' === $rows[0]['phone'] );
check( 'мусор без id не записывается', 0 === VL_Account_RetailCRM_Directory::store( array( 'email' => 'x@example.com' ) ) );

echo "\n== 2. Связывание с аккаунтами сайта ==\n";

$GLOBALS['wpdb']->rows = array();
$GLOBALS['users']      = array(
	42 => new WP_User( array( 'ID' => 42, 'user_email' => 'ivan@example.com', 'user_login' => 'ivan' ) ),
	43 => new WP_User( array( 'ID' => 43, 'user_email' => 'maria@example.com', 'user_login' => 'maria' ) ),
);

VL_Account_RetailCRM_Directory::store( crm_customer( 500, array( '+79261234567' ), 'ivan@example.com', 42 ) );
VL_Account_RetailCRM_Directory::store( crm_customer( 502, array( '+79267654321' ), 'maria@example.com' ) );
VL_Account_RetailCRM_Directory::store( crm_customer( 503, array( '+79261111111' ), 'nobody@example.com' ) );

$stats = VL_Account_RetailCRM_Directory::match_all();

check( 'сопоставлено два клиента', 2 === $stats['matched'], print_r( $stats, true ) );
check( 'один остался без аккаунта', 1 === $stats['no_user'] );

$rows = array_values( $GLOBALS['wpdb']->rows );

check( 'связь по externalId', 42 === (int) $rows[0]['user_id'] && 'externalId' === $rows[0]['note'], print_r( $rows[0], true ) );
check( 'связь по почте', 43 === (int) $rows[1]['user_id'] && 'email' === $rows[1]['note'], print_r( $rows[1], true ) );
check( 'без аккаунта — статус no_user', 'no_user' === $rows[2]['status'] );

echo "\n== 3. Поиск по телефону ==\n";

$row = VL_Account_RetailCRM_Directory::find_by_phone( '8 (926) 123-45-67' );
check( 'номер в любом написании находится', $row && 42 === (int) $row['user_id'], print_r( $row, true ) );
check( 'кэш в пределах запроса', VL_Account_RetailCRM_Directory::find_by_phone( '79261234567' ) === $row );

echo "\n== 4. Один телефон у разных аккаунтов ==\n";

$GLOBALS['wpdb']->rows = array();

VL_Account_RetailCRM_Directory::store( crm_customer( 600, array( '+79269999999' ), 'ivan@example.com', 42 ) );
VL_Account_RetailCRM_Directory::store( crm_customer( 601, array( '+79269999999' ), 'maria@example.com', 43 ) );
VL_Account_RetailCRM_Directory::match_all();

$statuses = array();

foreach ( $GLOBALS['wpdb']->rows as $row ) {
	$statuses[] = $row['status'];
}

check( 'обе записи помечены конфликтом', array( 'conflict', 'conflict' ) === $statuses, print_r( $statuses, true ) );

$stats = VL_Account_RetailCRM_Directory::stats();
check( 'конфликты видны в сводке', 2 === $stats['conflict'], print_r( $stats, true ) );
check( 'телефоны посчитаны', 2 === $stats['phones'] );

echo "\n== 5. Запрос в CRM, когда номера нет в снимке ==\n";

$GLOBALS['wpdb']->rows = array();
VL_Account_RetailCRM_Directory::flush_cache();

WC_Retailcrm_Proxy::$responses = array(
	'customersList' => function ( $args ) {
		$filter = $args[0] ?? array();

		// Ищем только по варианту с плюсом — проверяем перебор написаний.
		if ( isset( $filter['name'] ) && '+79261234567' === $filter['name'] ) {
			return new WC_Retailcrm_Response(
				200,
				wp_json_encode(
					array(
						'customers' => array(
							array(
								'id'         => 700,
								'externalId' => 42,
								'email'      => 'ivan@example.com',
								'firstName'  => 'Иван',
								'phones'     => array( array( 'number' => '+7 926 123-45-67' ) ),
							),
						),
					)
				)
			);
		}

		return new WC_Retailcrm_Response( 200, '{"customers":[]}' );
	},
);

$row = VL_Account_RetailCRM_Directory::find_by_phone( '79261234567' );

check( 'клиент найден запросом в CRM', $row && 700 === (int) $row['crm_id'], print_r( $row, true ) );
check( 'ответ сохранён в снимок', 1 === count( $GLOBALS['wpdb']->rows ) );
check( 'и сразу связан с аккаунтом', 42 === (int) $row['user_id'] );

$GLOBALS['wpdb']->rows = array();
VL_Account_RetailCRM_Directory::flush_cache();
WC_Retailcrm_Proxy::$responses = array(
	'customersList' => new WC_Retailcrm_Response(
		200,
		wp_json_encode(
			array(
				'customers' => array(
					array( 'id' => 701, 'email' => 'other@example.com', 'phones' => array( array( 'number' => '+79260000000' ) ) ),
				),
			)
		)
	),
);

check( 'чужой номер из ответа не берём', false === VL_Account_RetailCRM_Directory::find_by_phone( '79265555555' ) );

echo "\n== 6. Постраничная выгрузка ==\n";

$GLOBALS['wpdb']->rows = array();
$GLOBALS['pages']      = array(
	1 => array( crm_customer( 800, array( '+79261000001' ), 'a@example.com' ), crm_customer( 801, array( '+79261000002' ), 'b@example.com' ) ),
	2 => array( crm_customer( 802, array( '+79261000003' ), 'c@example.com' ) ),
);

WC_Retailcrm_Proxy::$responses = array(
	'customersList' => function ( $args ) {
		$page = (int) ( $args[1] ?? 1 );

		return new WC_Retailcrm_Response(
			200,
			wp_json_encode(
				array(
					'customers'  => $GLOBALS['pages'][ $page ] ?? array(),
					'pagination' => array( 'totalPageCount' => 2 ),
				)
			)
		);
	},
);

VL_Account_RetailCRM_Directory::start();
VL_Account_RetailCRM_Directory::run_batch();

$state = VL_Account_RetailCRM_Directory::state();

check( 'все страницы выгружены', 3 === count( $GLOBALS['wpdb']->rows ), (string) count( $GLOBALS['wpdb']->rows ) );
check( 'выгрузка завершилась', empty( $state['running'] ) && ! empty( $state['finished'] ), print_r( $state, true ) );
check( 'счётчик записей верный', 3 === (int) $state['fetched'], (string) $state['fetched'] );

echo "\n== 7. Ошибка CRM останавливает выгрузку ==\n";

$GLOBALS['wpdb']->rows         = array();
WC_Retailcrm_Proxy::$responses = array( 'customersList' => new WC_Retailcrm_Response( 503, '{}' ) );

VL_Account_RetailCRM_Directory::start();
VL_Account_RetailCRM_Directory::run_batch();

$state = VL_Account_RetailCRM_Directory::state();

check( 'выгрузка остановлена', empty( $state['running'] ) );
check( 'причина записана', '' !== $state['error'], print_r( $state, true ) );
check( 'мусора в снимке нет', 0 === count( $GLOBALS['wpdb']->rows ) );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
