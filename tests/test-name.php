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

	foreach ( array( 'first_name', 'last_name' ) as $key ) {
		if ( ! empty( $data[ $key ] ) ) {
			update_user_meta( $id, $key, $data[ $key ] );
		}
	}

	return $id;
}
function get_role( $role ) { return 'customer' === $role ? (object) array( 'name' => 'customer' ) : null; }
function sanitize_user( $login, $strict = false ) { return preg_replace( '/[^a-zA-Z0-9_.\-@]/', '', (string) $login ); }
function username_exists( $login ) { return false; }
function wp_hash_password( $p ) { return 'hash'; }

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

echo "\n------------------------------------------------------------\n";
echo "Пройдено: $pass, провалено: $fail\n";

exit( $fail > 0 ? 1 : 0 );
