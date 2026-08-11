<?php
/**
 * Шорткоды плагина.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Шорткоды.
 */
class VL_Account_Shortcodes {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Shortcodes|null
	 */
	private static $instance = null;

	/**
	 * Форма входа уже выведена на этой странице шорткодом.
	 *
	 * Нужно, чтобы не дублировать её ещё раз в выдвижной панели
	 * (одинаковые id полей ломают подписи и автозаполнение).
	 *
	 * @var bool
	 */
	private static $auth_rendered = false;

	/**
	 * Выводилась ли форма входа на этой странице.
	 *
	 * @return bool
	 */
	public static function auth_rendered() {
		return self::$auth_rendered;
	}

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Shortcodes
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
		add_shortcode( 'vl_auth', array( $this, 'sc_auth' ) );
		add_shortcode( 'vl_login', array( $this, 'sc_login' ) );
		add_shortcode( 'vl_register', array( $this, 'sc_register' ) );
		add_shortcode( 'vl_lost_password', array( $this, 'sc_lost_password' ) );
		add_shortcode( 'vl_account', array( $this, 'sc_account' ) );
		add_shortcode( 'vl_account_menu', array( $this, 'sc_account_menu' ) );
		add_shortcode( 'vl_account_icon', array( $this, 'sc_account_icon' ) );
		add_shortcode( 'vl_wishlist_button', array( $this, 'sc_wishlist_button' ) );
		add_shortcode( 'vl_wishlist_count', array( $this, 'sc_wishlist_count' ) );
		add_shortcode( 'vl_user_name', array( $this, 'sc_user_name' ) );
	}

	/**
	 * Вход + регистрация одним блоком.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_auth( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'redirect' => '',
				'notice'   => '',
				'title'    => '',
			),
			$atts,
			'vl_auth'
		);

		return self::render_auth( $atts );
	}

	/**
	 * Синоним [vl_auth]: вход и регистрация — одно действие.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_login( $atts = array() ) {
		return $this->sc_auth( $atts );
	}

	/**
	 * Синоним [vl_auth]. Оставлен, чтобы не ломать уже расставленные шорткоды.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_register( $atts = array() ) {
		return $this->sc_auth( $atts );
	}

	/**
	 * Восстановление пароля.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_lost_password( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Восстановление доступа', 'vl-account' ),
			),
			$atts,
			'vl_lost_password'
		);

		if ( is_user_logged_in() ) {
			return self::logged_in_box();
		}

		return vlacc_template( 'form-lost-password.php', $atts, true );
	}

	/**
	 * Личный кабинет.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_account( $atts = array() ) {
		return VL_Account_MyAccount::render();
	}

	/**
	 * Меню кабинета.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_account_menu( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'class' => '',
				'icons' => 'yes',
			),
			$atts,
			'vl_account_menu'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		return VL_Account_MyAccount::render_menu(
			array(
				'class' => $atts['class'],
				'icons' => 'no' !== $atts['icons'],
			)
		);
	}

	/**
	 * Иконка кабинета для шапки.
	 *
	 * Задача из ТЗ: «поменять местами иконки — зайти в ЛК человечек,
	 * выйти из открытого ЛК стрелочка».
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_account_icon( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'size'        => 20,
				'class'       => '',
				'show_logout' => 'yes',
				'show_label'  => 'no',
				'drawer'      => 'no',
				'label_in'    => __( 'Личный кабинет', 'vl-account' ),
				'label_out'   => __( 'Войти', 'vl-account' ),
			),
			$atts,
			'vl_account_icon'
		);

		$size   = (int) $atts['size'];
		$label  = 'yes' === $atts['show_label'];
		$drawer = 'yes' === $atts['drawer'] ? ' data-vl-open-auth="1"' : '';

		ob_start();

		echo '<span class="vl-account-icons ' . esc_attr( $atts['class'] ) . '">';

		if ( is_user_logged_in() ) {
			printf(
				'<a class="vl-account-icons__link vl-account-icons__link--account" href="%1$s" title="%2$s" aria-label="%2$s">%3$s%4$s</a>',
				esc_url( VL_Account_Settings::account_url() ),
				esc_attr( $atts['label_in'] ),
				vlacc_icon( 'user', $size ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$label ? '<span class="vl-account-icons__label">' . esc_html( $atts['label_in'] ) . '</span>' : ''
			);

			if ( 'no' !== $atts['show_logout'] ) {
				printf(
					'<a class="vl-account-icons__link vl-account-icons__link--logout" href="%1$s" title="%2$s" aria-label="%2$s" data-vl-confirm-logout="1">%3$s</a>',
					esc_url( VL_Account_Auth::logout_url( home_url( '/' ) ) ),
					esc_attr__( 'Выйти', 'vl-account' ),
					vlacc_icon( 'logout', $size ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			}
		} else {
			printf(
				'<a class="vl-account-icons__link vl-account-icons__link--login" href="%1$s" title="%2$s" aria-label="%2$s"%5$s>%3$s%4$s</a>',
				esc_url( VL_Account_Settings::auth_url() ),
				esc_attr( $atts['label_out'] ),
				vlacc_icon( 'user', $size ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$label ? '<span class="vl-account-icons__label">' . esc_html( $atts['label_out'] ) . '</span>' : '',
				$drawer // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- статичный атрибут.
			);
		}

		echo '</span>';

		return ob_get_clean();
	}

	/**
	 * Кнопка «в избранное».
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_wishlist_button( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'product_id' => 0,
				'class'      => '',
				'text'       => 'yes',
			),
			$atts,
			'vl_wishlist_button'
		);

		$product_id = (int) $atts['product_id'];

		if ( ! $product_id ) {
			global $product;

			if ( $product instanceof WC_Product ) {
				$product_id = $product->get_id();
			} else {
				$product_id = get_the_ID();
			}
		}

		return VL_Account_Wishlist::button(
			$product_id,
			array(
				'class' => $atts['class'],
				'text'  => 'no' !== $atts['text'],
			)
		);
	}

	/**
	 * Счётчик избранного.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_wishlist_count( $atts = array() ) {
		return '<span class="vl-wishlist-count" data-vl-wishlist-count>' . (int) VL_Account_Wishlist::count() . '</span>';
	}

	/**
	 * Имя текущего пользователя.
	 *
	 * @param array $atts Атрибуты.
	 * @return string
	 */
	public function sc_user_name( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'fallback' => '',
			),
			$atts,
			'vl_user_name'
		);

		if ( ! is_user_logged_in() ) {
			return esc_html( $atts['fallback'] );
		}

		$user = wp_get_current_user();
		$name = $user->first_name ? $user->first_name : $user->display_name;

		return esc_html( $name );
	}

	/**
	 * Отрисовка блока входа/регистрации.
	 *
	 * @param array $args Аргументы.
	 * @return string
	 */
	public static function render_auth( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'redirect' => '',
				'notice'   => '',
				'title'    => '',
			)
		);

		if ( is_user_logged_in() ) {
			return self::logged_in_box();
		}

		$args['redirect'] = $args['redirect'] ? $args['redirect'] : vlacc_redirect_url();

		// Панель в подвале отрисуется только если форма не выведена на странице.
		if ( ! did_action( 'wp_footer' ) ) {
			self::$auth_rendered = true;
		}

		return vlacc_template( 'auth.php', $args, true );
	}

	/**
	 * Блок для уже авторизованного посетителя.
	 *
	 * @return string
	 */
	protected static function logged_in_box() {
		$user = wp_get_current_user();

		ob_start();
		?>
		<div class="vl-box vl-box--logged">
			<p class="vl-box__title">
				<?php
				printf(
					/* translators: %s — имя пользователя. */
					esc_html__( 'Вы уже вошли как %s', 'vl-account' ),
					esc_html( $user->first_name ? $user->first_name : $user->display_name )
				);
				?>
			</p>
			<p class="vl-box__actions">
				<a class="vl-btn vl-btn--primary" href="<?php echo esc_url( VL_Account_Settings::account_url() ); ?>"><?php esc_html_e( 'В личный кабинет', 'vl-account' ); ?></a>
				<a class="vl-btn vl-btn--dark" href="<?php echo esc_url( VL_Account_Auth::logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Выйти', 'vl-account' ); ?></a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
