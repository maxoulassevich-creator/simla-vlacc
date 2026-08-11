<?php
/**
 * Промокоды в личном кабинете.
 *
 * Закрывает задачу «в ЛК нет самого промокода, который был указан в попапе»:
 * промокод за регистрацию выдаётся сразу и всегда виден в кабинете.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Промокоды.
 */
class VL_Account_Promo {

	const META = 'vlacc_promo_codes';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Promo|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Promo
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		add_action( 'vlacc_user_registered', array( $this, 'issue_on_register' ), 10, 2 );
	}

	/**
	 * Выдать приветственный промокод при регистрации.
	 *
	 * @param int   $user_id Пользователь.
	 * @param array $data    Данные регистрации.
	 */
	public function issue_on_register( $user_id, $data = array() ) {
		$mode = VL_Account_Settings::get( 'promo_mode', 'none' );

		if ( 'none' === $mode || ! vlacc_is_woo() ) {
			return;
		}

		if ( 'shared' === $mode ) {
			$code = trim( (string) VL_Account_Settings::get( 'promo_shared_code', '' ) );

			if ( '' === $code ) {
				return;
			}

			self::attach_code( $user_id, $code, __( 'Промокод на первую покупку', 'vl-account' ) );
			return;
		}

		self::create_personal_coupon( $user_id );
	}

	/**
	 * Создать персональный купон WooCommerce.
	 *
	 * @param int $user_id Пользователь.
	 * @return string Код купона.
	 */
	public static function create_personal_coupon( $user_id ) {
		if ( ! vlacc_is_woo() || ! class_exists( 'WC_Coupon' ) ) {
			return '';
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return '';
		}

		$prefix = (string) VL_Account_Settings::get( 'promo_prefix', 'HELLO' );
		$code   = strtoupper( $prefix . wp_generate_password( 5, false, false ) );

		$amount = (float) VL_Account_Settings::get( 'promo_discount', 10 );
		$days   = (int) VL_Account_Settings::get( 'promo_days', 30 );

		// «Только один купон в заказе» ломает списание баллов программы лояльности:
		// WooCommerce выкинет из корзины технический купон CRM. Интеграция выключает флаг.
		$individual = (bool) apply_filters( 'vlacc_promo_individual_use', true, $user_id );

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $amount );
		$coupon->set_individual_use( $individual );
		$coupon->set_usage_limit( 1 );
		$coupon->set_description( __( 'Промокод за регистрацию', 'vl-account' ) );

		if ( VL_Account_User::has_real_email( $user ) ) {
			$coupon->set_email_restrictions( array( $user->user_email ) );
		}

		if ( $days > 0 ) {
			$coupon->set_date_expires( time() + $days * DAY_IN_SECONDS );
		}

		$coupon->save();

		self::attach_code(
			$user_id,
			$code,
			sprintf(
				/* translators: %s — размер скидки в процентах. */
				__( 'Скидка %s%% на первый заказ', 'vl-account' ),
				wc_format_localized_price( $amount )
			)
		);

		return $code;
	}

	/**
	 * Привязать промокод к пользователю (для показа в кабинете).
	 *
	 * @param int    $user_id     Пользователь.
	 * @param string $code        Код.
	 * @param string $description Описание.
	 */
	public static function attach_code( $user_id, $code, $description = '' ) {
		$codes = get_user_meta( $user_id, self::META, true );

		if ( ! is_array( $codes ) ) {
			$codes = array();
		}

		$code = strtoupper( trim( $code ) );

		if ( '' === $code || isset( $codes[ $code ] ) ) {
			return;
		}

		$codes[ $code ] = array(
			'description' => $description,
			'issued'      => current_time( 'mysql' ),
		);

		update_user_meta( $user_id, self::META, $codes );

		do_action( 'vlacc_promo_attached', $user_id, $code );
	}

	/**
	 * Промокоды пользователя для вывода.
	 *
	 * @param int $user_id Пользователь.
	 * @return array
	 */
	public static function get_user_codes( $user_id ) {
		$stored = get_user_meta( $user_id, self::META, true );
		$stored = is_array( $stored ) ? $stored : array();

		$result = array();

		foreach ( $stored as $code => $meta ) {
			$row = array(
				'code'        => $code,
				'description' => isset( $meta['description'] ) ? $meta['description'] : '',
				'expires'     => '',
				'used'        => false,
				'valid'       => true,
			);

			if ( vlacc_is_woo() && function_exists( 'wc_get_coupon_id_by_code' ) ) {
				$coupon_id = wc_get_coupon_id_by_code( $code );

				if ( $coupon_id ) {
					$coupon = new WC_Coupon( $coupon_id );

					$expires = $coupon->get_date_expires();
					if ( $expires ) {
						$row['expires'] = $expires->date_i18n( 'd.m.Y' );
						$row['valid']   = $expires->getTimestamp() > time();
					}

					$limit = $coupon->get_usage_limit();
					if ( $limit && $coupon->get_usage_count() >= $limit ) {
						$row['used']  = true;
						$row['valid'] = false;
					}

					if ( ! $row['description'] ) {
						$row['description'] = $coupon->get_description();
					}
				} else {
					// Купон удалён из WooCommerce — код показываем, но без гарантий.
					$row['valid'] = false;
				}
			}

			$result[] = $row;
		}

		return apply_filters( 'vlacc_user_promo_codes', $result, $user_id );
	}
}
