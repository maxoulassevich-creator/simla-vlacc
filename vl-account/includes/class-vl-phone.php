<?php
/**
 * Работа с номерами телефонов.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Нормализация и валидация телефонов (в первую очередь РФ/СНГ).
 */
class VL_Account_Phone {

	/**
	 * Привести номер к формату 79261234567 (только цифры, с кодом страны).
	 *
	 * @param string $phone Номер в любом виде.
	 * @return string Пустая строка, если номер непригоден.
	 */
	public static function normalize( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );

		if ( '' === $digits ) {
			return '';
		}

		// 8 (926) ... -> 7926...
		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
			$digits = '7' . substr( $digits, 1 );
		}

		// 9261234567 -> 79261234567 (добавляем код страны по умолчанию).
		if ( 10 === strlen( $digits ) && '9' === $digits[0] ) {
			$digits = (string) VL_Account_Settings::get( 'default_country', '7' ) . $digits;
		}

		if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
			return '';
		}

		return apply_filters( 'vlacc_normalize_phone', $digits, $phone );
	}

	/**
	 * Проверка корректности номера.
	 *
	 * @param string $phone Номер.
	 * @return bool
	 */
	public static function is_valid( $phone ) {
		$normalized = self::normalize( $phone );

		if ( '' === $normalized ) {
			return false;
		}

		// Для российских номеров дополнительно проверяем формат 7 9XX XXXXXXX.
		if ( 0 === strpos( $normalized, '7' ) && 11 === strlen( $normalized ) ) {
			return (bool) preg_match( '/^7[3489]\d{9}$/', $normalized );
		}

		return true;
	}

	/**
	 * Красивый вывод: +7 (926) 123-45-67.
	 *
	 * @param string $phone Номер.
	 * @return string
	 */
	public static function format( $phone ) {
		$d = self::normalize( $phone );

		if ( 11 === strlen( $d ) && '7' === $d[0] ) {
			return sprintf(
				'+7 (%s) %s-%s-%s',
				substr( $d, 1, 3 ),
				substr( $d, 4, 3 ),
				substr( $d, 7, 2 ),
				substr( $d, 9, 2 )
			);
		}

		return $d ? '+' . $d : '';
	}

	/**
	 * Все варианты записи номера — для поиска по старым записям в базе.
	 *
	 * @param string $phone Номер.
	 * @return array
	 */
	public static function variants( $phone ) {
		$d = self::normalize( $phone );

		if ( '' === $d ) {
			return array();
		}

		$variants = array( $d, '+' . $d );

		if ( 11 === strlen( $d ) && '7' === $d[0] ) {
			$local = substr( $d, 1 );

			$variants[] = '8' . $local;
			$variants[] = $local;
			$variants[] = self::format( $d );
			$variants[] = sprintf( '+7%s', $local );
			$variants[] = sprintf( '8 (%s) %s-%s-%s', substr( $local, 0, 3 ), substr( $local, 3, 3 ), substr( $local, 6, 2 ), substr( $local, 8, 2 ) );
			$variants[] = sprintf( '+7 %s %s %s %s', substr( $local, 0, 3 ), substr( $local, 3, 3 ), substr( $local, 6, 2 ), substr( $local, 8, 2 ) );
		}

		return array_values( array_unique( $variants ) );
	}
}
