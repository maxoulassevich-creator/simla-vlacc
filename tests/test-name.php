<?php
/**
 * Смоук-тест поля «Имя» в форме входа.
 *
 * Правило: имя пишется только тем, у кого его ещё нет. Своё имя покупателя
 * форма входа не перезаписывает, а номер телефона именем не считается —
 * иначе в CRM вместо покупателя уезжает его номер.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check( $name, $condition, $extra = '' ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "  ok  $name\n"; } else { ++$fail; echo "FAIL  $name  $extra\n"; }
}

/* ---- Заглушки WordPress для создания пользователя ---- */

$GLOBALS['next_user_id'] = 100;

function wp_insert_user( $data ) {
	$id = $GLOBALS['next_user_id']++;

	$GLOBALS['users'][ $id ] = new WP_User(
		array(
			'ID'           => $id,
			'user_login'   => $data['user_login'] ?? '',
			'user_email'   => $data['user_email'] ?? '',
			'display_name' => $data['display_name'] ?? '',
		)
	);

	foreach ( array( 'first_name', 'last_name', 'nickname' ) as $key ) {
		if ( ! empty( $data[ $key ] ) ) {
			update_user_meta( $id, $key, $data[ $key ] );
		}
	}

	return $id;
}
function get_role( $role ) { return 'customer' === $role ? (object) array( 'name' => 'customer' ) : null; }
function sanitize_user( $login, $strict = false ) { return preg_replace( '/[^a-zA-Z0-9_.\-@]/', '', (string) $login ); }
function username_exists( $login ) {
	foreach ( $GLOBALS['users'] as $user ) {
		if ( isset( $user->user_login ) && $user->user_login === $login ) { return $user->ID; }
	}

	return false;
}
function wp_hash_password( $p ) { return 'hash'; }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-z0-9\-]+/i', '-', (string) $t ) ); }
function clean_user_cache( $u ) { return true; }

/** Заглушка $wpdb: переименование логина идёт прямым запросом. */
class Fake_WPDB {
	public $users = 'wp_users';
	public function update( $table, $data, $where ) {
		$id   = (int) ( $where['ID'] ?? 0 );
		$user = $GLOBALS['users'][ $id ] ?? null;

		if ( ! $user ) { return 0; }

		foreach ( $data as $key => $value ) { $user->$key = $value; }

		return 1;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

/**
 * Завести пользователя.
 */
function make_user( $id, $login, $email, $display = '' ) {
	$GLOBALS['users'][ $id ] = new WP_User(
		array(
			'ID'           => $id,
			'user_login'   => $login,
			'user_email'   => $email,
			'display_name' => '' !== $display ? $display : $login,
		)
	);
	$GLOBALS['usermeta'][ $id ] = array();

	return $GLOBALS['users'][ $id ];
}

echo "\n== 1. Что вообще считается именем ==\n";

check( 'обычное имя', 'Ярослав' === VL_Account_User::sanitize_name( '  Ярослав  ' ) );
check( 'двойные пробелы схлопываются', 'Анна Мария' === VL_Account_User::sanitize_name( "Анна   Мария" ) );
check( 'телефон именем не считается', '' === VL_Account_User::sanitize_name( '+7 (926) 123-45-67' ) );
check( 'цифры без разделителей тоже', '' === VL_Account_User::sanitize_name( '79261234567' ) );
check( 'пустая строка', '' === VL_Account_User::sanitize_name( '   ' ) );
check( 'длина ограничена', 60 === mb_strlen( VL_Account_User::sanitize_name( str_repeat( 'я', 100 ) ) ) );
check( 'теги вырезаются', 'Ярослав' === VL_Account_User::sanitize_name( '<b>Ярослав</b>' ) );

echo "\n== 2. У покупателя имени нет — записываем ==\n";

make_user( 10, '79261234567', '79261234567@phone.example.test', '+7 (926) 123-45-67' );

check( 'имя записано', VL_Account_User::maybe_set_name( 10, 'Ярослав' ) );
check( 'в профиле', 'Ярослав' === get_user_meta( 10, 'first_name', true ) );
check( 'в биллинге', 'Ярослав' === get_user_meta( 10, 'billing_first_name', true ) );
check( 'отображаемое имя больше не телефон', 'Ярослав' === $GLOBALS['users'][10]->display_name, $GLOBALS['users'][10]->display_name );

echo "\n== 3. Имя уже есть — не трогаем ==\n";

make_user( 11, '79261111111', 'client@example.com', 'Мария Соколова' );
update_user_meta( 11, 'first_name', 'Мария' );

check( 'повторно не пишем', ! VL_Account_User::maybe_set_name( 11, 'Кто-то Другой' ) );
check( 'имя осталось прежним', 'Мария' === get_user_meta( 11, 'first_name', true ) );
check( 'отображаемое имя не тронуто', 'Мария Соколова' === $GLOBALS['users'][11]->display_name );

echo "\n== 4. Имя в профиле пустое, но отображаемое — человеческое ==\n";

make_user( 12, 'sokolova', 'sokolova@example.com', 'Мария Соколова' );

check( 'имя записано', VL_Account_User::maybe_set_name( 12, 'Мария' ) );
check( 'в профиль', 'Мария' === get_user_meta( 12, 'first_name', true ) );
check( 'отображаемое имя оставили как было', 'Мария Соколова' === $GLOBALS['users'][12]->display_name );

echo "\n== 5. Отображаемое имя = логин ==\n";

make_user( 13, '79262222222', '79262222222@phone.example.test' );

VL_Account_User::maybe_set_name( 13, 'Пётр' );
check( 'логин заменён на имя', 'Пётр' === $GLOBALS['users'][13]->display_name, $GLOBALS['users'][13]->display_name );

// Фамилия из профиля попадает в отображаемое имя.
make_user( 14, '79263333333', '79263333333@phone.example.test' );
update_user_meta( 14, 'last_name', 'Иванов' );

VL_Account_User::maybe_set_name( 14, 'Иван' );
check( 'имя и фамилия вместе', 'Иван Иванов' === $GLOBALS['users'][14]->display_name, $GLOBALS['users'][14]->display_name );

echo "\n== 6. Мусор вместо имени ==\n";

make_user( 15, '79264444444', '79264444444@phone.example.test' );

check( 'пустое имя ничего не меняет', ! VL_Account_User::maybe_set_name( 15, '' ) );
check( 'телефон вместо имени тоже', ! VL_Account_User::maybe_set_name( 15, '+79264444444' ) );
check( 'профиль не тронут', '' === get_user_meta( 15, 'first_name', true ) );

echo "\n== 7. Новый аккаунт создаётся с именем ==\n";

VL_Account_Settings::update( array( 'promo_mode' => 'none' ) );

$user_id = VL_Account_User::create(
	array(
		'phone'      => '+7 (926) 555-44-33',
		'first_name' => 'Ярослав',
		'verified'   => true,
		'source'     => 'sms_one_step',
	)
);

check( 'аккаунт создан', is_int( $user_id ) && $user_id > 0, print_r( $user_id, true ) );
check( 'имя в профиле', 'Ярослав' === get_user_meta( $user_id, 'first_name', true ) );
check( 'имя в биллинге', 'Ярослав' === get_user_meta( $user_id, 'billing_first_name', true ) );
check( 'отображается имя, а не телефон', 'Ярослав' === $GLOBALS['users'][ $user_id ]->display_name, $GLOBALS['users'][ $user_id ]->display_name );

$user_id = VL_Account_User::create(
	array(
		'phone'      => '+7 (926) 555-44-22',
		'first_name' => '+7 (926) 555-44-22',
		'verified'   => true,
		'source'     => 'sms_one_step',
	)
);

check( 'телефон в поле имени не становится именем', '' === get_user_meta( $user_id, 'first_name', true ) );

echo "\n== 8. Логин нового аккаунта ==\n";

check( 'транслитерация', 'aleksandr' === VL_Account_User::translit( 'Александр' ), VL_Account_User::translit( 'Александр' ) );
check( 'буквы без латинского аналога', 'schi' === VL_Account_User::translit( 'Щи' ), VL_Account_User::translit( 'Щи' ) );

$user_id = VL_Account_User::create(
	array(
		'phone'      => '+7 (926) 777-66-55',
		'first_name' => 'Александр',
		'verified'   => true,
	)
);

check( 'логин из имени, а не из телефона', 'aleksandr' === $GLOBALS['users'][ $user_id ]->user_login, $GLOBALS['users'][ $user_id ]->user_login );
check( 'ник — имя', 'Александр' === get_user_meta( $user_id, 'nickname', true ), get_user_meta( $user_id, 'nickname', true ) );

// Тёзка: к логину добавляется хвост номера.
$twin = VL_Account_User::create(
	array(
		'phone'      => '+7 (926) 777-11-22',
		'first_name' => 'Александр',
		'verified'   => true,
	)
);

check( 'у тёзки логин с хвостом номера', 'aleksandr1122' === $GLOBALS['users'][ $twin ]->user_login, $GLOBALS['users'][ $twin ]->user_login );

// Без имени логин остаётся номером.
$nameless = VL_Account_User::create(
	array(
		'phone'    => '+7 (926) 777-00-11',
		'verified' => true,
	)
);

check( 'без имени логин — номер', '79267770011' === $GLOBALS['users'][ $nameless ]->user_login, $GLOBALS['users'][ $nameless ]->user_login );

VL_Account_Settings::update( array( 'login_from_name' => 0 ) );

$plain = VL_Account_User::create(
	array(
		'phone'      => '+7 (926) 777-33-44',
		'first_name' => 'Николай',
		'verified'   => true,
	)
);

check( 'настройка возвращает логин-номер', '79267773344' === $GLOBALS['users'][ $plain ]->user_login, $GLOBALS['users'][ $plain ]->user_login );
VL_Account_Settings::update( array( 'login_from_name' => 1 ) );

echo "\n== 9. Ник у старых аккаунтов ==\n";

make_user( 20, '79269998877', '79269998877@phone.example.test', 'Мария Соколова' );
update_user_meta( 20, 'nickname', '79269998877' );

VL_Account_User::maybe_set_name( 20, 'Мария' );

check( 'ник заменён на имя', 'Мария' === get_user_meta( 20, 'nickname', true ), get_user_meta( 20, 'nickname', true ) );
check( 'отображаемое имя не тронуто', 'Мария Соколова' === $GLOBALS['users'][20]->display_name );

echo "\n== 10. Логин-номер у старого аккаунта ==\n";

// Аккаунт от входа по SMS: логин — номер, пароля нет, имя уже есть.
make_user( 30, '79047767897', '79047767897@phone.example.test', 'Александр' );
update_user_meta( 30, 'first_name', 'Александр' );
update_user_meta( 30, VL_Account_User::META_NOPASS, 1 );
update_user_meta( 30, VL_Account_User::META_PHONE, '79047767897' );

check( 'логин заменён', VL_Account_User::maybe_rename_login( 30 ) );
check( 'логин теперь имя', 'aleksandr7897' === $GLOBALS['users'][30]->user_login || 'aleksandr' === $GLOBALS['users'][30]->user_login, $GLOBALS['users'][30]->user_login );
check( 'повторно не переименовываем', ! VL_Account_User::maybe_rename_login( 30 ) );

// У аккаунта есть свой пароль — логин трогать нельзя.
make_user( 31, '79047767898', 'pass@example.com', 'Мария' );
update_user_meta( 31, 'first_name', 'Мария' );
update_user_meta( 31, VL_Account_User::META_PHONE, '79047767898' );

check( 'аккаунт с паролем не трогаем', ! VL_Account_User::maybe_rename_login( 31 ) );
check( 'логин остался прежним', '79047767898' === $GLOBALS['users'][31]->user_login );

// Без имени переименовывать не во что.
make_user( 32, '79047767899', '79047767899@phone.example.test' );
update_user_meta( 32, VL_Account_User::META_NOPASS, 1 );

check( 'без имени логин остаётся номером', ! VL_Account_User::maybe_rename_login( 32 ) );

// Человеческий логин не трогаем.
make_user( 33, 'sokolova', 'sokolova2@example.com', 'Мария' );
update_user_meta( 33, 'first_name', 'Мария' );
update_user_meta( 33, VL_Account_User::META_NOPASS, 1 );

check( 'обычный логин не трогаем', ! VL_Account_User::maybe_rename_login( 33 ) );

VL_Account_Settings::update( array( 'login_from_name' => 0 ) );
make_user( 34, '79047767800', '79047767800@phone.example.test' );
update_user_meta( 34, 'first_name', 'Пётр' );
update_user_meta( 34, VL_Account_User::META_NOPASS, 1 );

check( 'выключенная настройка ничего не меняет', ! VL_Account_User::maybe_rename_login( 34 ) );
VL_Account_Settings::update( array( 'login_from_name' => 1 ) );

echo "\n== 11. Имя во всех трёх полях ==\n";

// Аккаунт как на скриншоте: логин латиницей, ник — тот же логин.
make_user( 40, 'yaroslav', '79047767897@phone.example.test', 'Ярослав' );
update_user_meta( 40, 'first_name', 'Ярослав' );
update_user_meta( 40, 'last_name', 'Кацуба' );
update_user_meta( 40, 'nickname', 'yaroslav' );

check( 'ник поправлен', VL_Account_User::sync_display_name( 40 ) );
check( 'ник — имя', 'Ярослав' === get_user_meta( 40, 'nickname', true ), get_user_meta( 40, 'nickname', true ) );
check( 'отображаемое имя не тронуто', 'Ярослав' === $GLOBALS['users'][40]->display_name );

// Тот же вход второй раз ничего не меняет.
check( 'повторный проход вхолостую', ! VL_Account_User::sync_display_name( 40 ) );

// Свой ник покупателя не трогаем.
make_user( 41, '79047767801', 'nick@example.com', 'Мария' );
update_user_meta( 41, 'first_name', 'Мария' );
update_user_meta( 41, 'nickname', 'машенька' );

VL_Account_User::sync_display_name( 41 );
check( 'свой ник остаётся', 'машенька' === get_user_meta( 41, 'nickname', true ) );

// Имя есть, ник — номер: maybe_set_name чинит ник, даже когда имя не пишет.
make_user( 42, '79047767802', '79047767802@phone.example.test', 'Пётр' );
update_user_meta( 42, 'first_name', 'Пётр' );
update_user_meta( 42, 'nickname', '79047767802' );

check( 'имя повторно не пишем', ! VL_Account_User::maybe_set_name( 42, 'Пётр' ) );
check( 'а ник всё равно поправлен', 'Пётр' === get_user_meta( 42, 'nickname', true ), get_user_meta( 42, 'nickname', true ) );

// Новый аккаунт: имя сразу во всех трёх полях.
$fresh = VL_Account_User::create(
	array(
		'phone'      => '+7 (926) 321-11-22',
		'first_name' => 'Ярослав',
		'verified'   => true,
	)
);

// Тёзка «yaroslav» уже есть выше — логин получает хвост номера.
check( 'логин из имени', 'yaroslav1122' === $GLOBALS['users'][ $fresh ]->user_login, $GLOBALS['users'][ $fresh ]->user_login );
check( 'имя в профиле', 'Ярослав' === get_user_meta( $fresh, 'first_name', true ) );
check( 'ник — имя', 'Ярослав' === get_user_meta( $fresh, 'nickname', true ), get_user_meta( $fresh, 'nickname', true ) );
check( 'отображается имя', 'Ярослав' === $GLOBALS['users'][ $fresh ]->display_name );

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
