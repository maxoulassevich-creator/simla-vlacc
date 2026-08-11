<?php
/**
 * Промокоды и скидки: развод с купонами программы лояльности RetailCRM.
 *
 * Плагин Simla списывает баллы через технические купоны с кодом вида loyalty12345:
 * он создаёт их на лету и удаляет после оформления заказа. В разделе «Промокоды»
 * их показывать нельзя, а наш промокод за регистрацию не должен их вытеснять.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Промокоды и скидки в связке с CRM.
 */
class VL_Account_RetailCRM_Promo {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_RetailCRM_Promo|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_RetailCRM_Promo
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
		add_filter( 'vlacc_user_promo_codes', array( $this, 'hide_loyalty_coupons' ), 10, 2 );
		add_filter( 'vlacc_promo_individual_use', array( $this, 'individual_use' ), 10, 2 );
		add_action( 'vlacc_after_tab', array( $this, 'discount_note' ), 10, 2 );
	}

	/**
	 * Технический купон списания баллов.
	 *
	 * @param string $code Код купона.
	 * @return bool
	 */
	public static function is_loyalty_coupon( $code ) {
		return 1 === preg_match( '/^loyalty\d+$/i', (string) $code );
	}

	/**
	 * Убрать служебные купоны лояльности из списка промокодов кабинета.
	 *
	 * @param array $codes   Промокоды.
	 * @param int   $user_id Пользователь.
	 * @return array
	 */
	public function hide_loyalty_coupons( $codes, $user_id = 0 ) {
		if ( ! VL_Account_Settings::get( 'crm_promo_hide_loyalty', 1 ) || ! is_array( $codes ) ) {
			return $codes;
		}

		$result = array();

		foreach ( $codes as $row ) {
			$code = isset( $row['code'] ) ? $row['code'] : '';

			if ( self::is_loyalty_coupon( $code ) ) {
				continue;
			}

			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Промокод за регистрацию не должен быть «только один купон в заказе».
	 *
	 * Иначе WooCommerce при его применении выкинет из корзины купон списания
	 * баллов — покупатель не сможет одновременно потратить баллы и промокод.
	 *
	 * @param bool $individual Текущее значение.
	 * @param int  $user_id    Пользователь.
	 * @return bool
	 */
	public function individual_use( $individual, $user_id = 0 ) {
		if ( ! VL_Account_RetailCRM::loyalty_active() ) {
			return $individual;
		}

		return VL_Account_Settings::get( 'crm_promo_combine', 1 ) ? false : $individual;
	}

	/**
	 * Пояснение о скидке по программе лояльности в разделе «Промокоды».
	 *
	 * @param string $tab  Раздел.
	 * @param array  $args Аргументы шаблона.
	 */
	public function discount_note( $tab, $args = array() ) {
		if ( 'promo' !== $tab || ! VL_Account_RetailCRM::loyalty_active() ) {
			return;
		}

		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$account = VL_Account_RetailCRM::account( $user_id );

		if ( 'active' !== $account['status'] ) {
			return;
		}

		if ( VL_Account_RetailCRM_Loyalty::is_discount_level( $account ) ) {
			printf(
				'<p class="vl-note vl-note--loyalty">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: процент скидки, 2: название уровня. */
						__( 'Скидка %1$s%% по программе лояльности (уровень «%2$s») применяется в корзине автоматически — промокод для неё не нужен.', 'vl-account' ),
						number_format_i18n( $account['level']['size'], 0 ),
						$account['level']['name']
					)
				)
			);

			return;
		}

		if ( $account['amount'] > 0 ) {
			printf(
				'<p class="vl-note vl-note--loyalty">%s <a class="vl-link" href="%s">%s</a></p>',
				esc_html(
					sprintf(
						/* translators: %s — количество баллов. */
						__( 'Кроме промокодов у вас есть %s баллов — их можно списать в корзине.', 'vl-account' ),
						number_format_i18n( $account['amount'] )
					)
				),
				esc_url( VL_Account_Router::url( 'bonus' ) ),
				esc_html__( 'подробнее о баллах', 'vl-account' )
			);
		}
	}
}
