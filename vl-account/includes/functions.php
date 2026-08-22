<?php
/**
 * Общие функции-помощники.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Загрузить шаблон плагина с возможностью переопределения в теме.
 *
 * Порядок поиска:
 *  1. wp-content/themes/child-theme/vl-account/{$template}
 *  2. wp-content/themes/theme/vl-account/{$template}
 *  3. wp-content/plugins/vl-account/templates/{$template}
 *
 * @param string $template Относительный путь, например 'form-login.php'.
 * @param array  $args     Переменные шаблона.
 * @param bool   $return   Вернуть строкой вместо вывода.
 * @return string|void
 */
function vlacc_template( $template, $args = array(), $return = false ) {
	$template = ltrim( $template, '/' );

	$located = locate_template( array( 'vl-account/' . $template ) );
	if ( ! $located ) {
		$located = VLACC_PATH . 'templates/' . $template;
	}

	$located = apply_filters( 'vlacc_template_path', $located, $template, $args );

	if ( ! file_exists( $located ) ) {
		return $return ? '' : null;
	}

	if ( is_array( $args ) ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- контролируемый массив шаблона.
		extract( $args, EXTR_SKIP );
	}

	if ( $return ) {
		ob_start();
		include $located;
		return ob_get_clean();
	}

	include $located;
}

/**
 * Критические стили формы входа — печатаются рядом с самой формой.
 *
 * Это не оформление, а структура: какие шаги формы показаны, а какие скрыты,
 * и скрытие служебных полей. Без этих правил (например, если плагин оптимизации
 * объединил или вырезал внешний CSS) форма разваливается: видны сразу все шаги,
 * поле кода, восстановление пароля и антибот-поле.
 *
 * Печатается один раз за страницу.
 */
function vlacc_print_form_critical_css() {
	static $printed = false;

	if ( $printed ) {
		return;
	}

	$printed = true;

	$accent = esc_attr( VL_Account_Settings::get( 'accent_color', '#d40000' ) );
	$dark   = esc_attr( VL_Account_Settings::get( 'button_color', '#2f2f2f' ) );
	$radius = (int) VL_Account_Settings::get( 'radius', 0 );
	?>
<style id="vl-form-critical">
/* Структура: показываем только текущий шаг, прячем служебные поля. */
.vl-auth__pane{display:none}
.vl-auth__pane.is-active{display:block}
.vl-step{display:none}
.vl-step.is-active{display:block}
.vl-field__error{display:none}
.vl-field__error.is-visible{display:block;margin-top:6px;font-size:12px;color:<?php echo $accent; ?>}
.vl-hp{position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;opacity:0!important;padding:0!important;border:0!important}
.vl-form [hidden],.vl-auth [hidden],.vl-account [hidden],.vl-drawer [hidden]{display:none!important}
/* Минимальное оформление: страховка, если внешний CSS не доехал. */
.vl-auth,.vl-auth *{box-sizing:border-box}
.vl-auth .vl-field{position:relative;margin-bottom:18px}
.vl-auth .vl-label{display:flex;align-items:center;gap:6px;font-size:13px;line-height:1.3;color:#4a4a4a;margin-bottom:7px}
.vl-auth .vl-req{color:<?php echo $accent; ?>}
.vl-auth .vl-input{width:100%!important;height:44px!important;min-height:44px;padding:10px 14px!important;border:1px solid #e4e4e4!important;border-radius:<?php echo $radius; ?>px;background:#fff!important;font-size:14px;line-height:1.3;color:#333;box-shadow:none!important;margin:0}
.vl-auth .vl-btn{display:inline-flex!important;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:12px 28px!important;border:1px solid transparent!important;border-radius:<?php echo $radius; ?>px;font-size:12px!important;line-height:1.2;letter-spacing:.08em;text-transform:uppercase;text-decoration:none;cursor:pointer;box-shadow:none!important;width:auto}
.vl-auth .vl-btn--block{display:flex!important;width:100%!important}
.vl-auth .vl-btn--primary{background:<?php echo $accent; ?>!important;color:#fff!important}
.vl-auth .vl-btn--dark{background:<?php echo $dark; ?>!important;color:#fff!important}
.vl-auth .vl-btn--outline{background:#fff!important;color:#333!important;border-color:#bdbdbd!important;min-height:44px}
.vl-auth .vl-link{background:none!important;border:0!important;padding:0!important;min-height:0!important;font-size:13px!important;text-transform:none!important;letter-spacing:0!important;color:#333;text-decoration:underline;cursor:pointer;width:auto!important}
.vl-auth .vl-links{margin:14px 0 0;display:flex;gap:18px;flex-wrap:wrap}
.vl-auth .vl-form__intro{font-size:13px;line-height:1.6;margin:0 0 20px}
.vl-auth .vl-form__consent{font-size:11px;line-height:1.5;color:#8a8a8a;margin:12px 0 0}
.vl-auth .vl-step__hint{font-size:13px;line-height:1.5;margin:0 0 16px}
.vl-auth .vl-message{padding:12px 16px;margin-bottom:18px;font-size:13px;line-height:1.5;border-left:3px solid #8a8a8a;background:#f7f7f7}
.vl-auth .vl-message--error{border-left-color:<?php echo $accent; ?>;background:#fdf2f2;color:#8c1c1c}
.vl-auth .vl-message--success{border-left-color:#2a9d3f;background:#f1f9f2;color:#1f6b2e}
.vl-auth .vl-consents{margin:0 0 18px;display:grid;gap:12px}
.vl-auth .vl-check{display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:12px;line-height:1.5}
.vl-auth .vl-check input{position:absolute!important;opacity:0!important;width:1px!important;height:1px!important}
.vl-auth .vl-check__box{flex:0 0 auto;width:16px;height:16px;margin-top:1px;border:1px solid #bdbdbd;background:#fff;position:relative}
.vl-auth .vl-check input:checked+.vl-check__box::after{content:"";position:absolute;left:4px;top:0;width:5px;height:10px;border:solid <?php echo $accent; ?>;border-width:0 2px 2px 0;transform:rotate(45deg)}
.vl-auth .vl-input--code{letter-spacing:.4em;font-size:18px;text-align:center;max-width:220px}
</style>
	<?php
}

/**
 * IP клиента (с учётом прокси/CDN).
 *
 * @return string
 */
function vlacc_client_ip() {
	$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

	foreach ( $keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		$parts = explode( ',', $value );
		$ip    = trim( $parts[0] );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return '0.0.0.0';
}

/**
 * Запись в журнал плагина (последние 200 записей в опции).
 *
 * @param string $message Сообщение.
 * @param array  $context Контекст.
 */
function vlacc_log( $message, $context = array() ) {
	if ( ! VL_Account_Settings::get( 'logging', 1 ) ) {
		return;
	}

	$log = get_option( 'vlacc_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	array_unshift(
		$log,
		array(
			'time'    => current_time( 'mysql' ),
			'message' => (string) $message,
			'context' => $context,
		)
	);

	$log = array_slice( $log, 0, 200 );
	update_option( 'vlacc_log', $log, false );
}

/**
 * Маскирование телефона для вывода: +7 (926) ***-**-12.
 *
 * @param string $phone Телефон в любом формате.
 * @return string
 */
function vlacc_mask_phone( $phone ) {
	$digits = preg_replace( '/\D+/', '', (string) $phone );
	if ( strlen( $digits ) < 4 ) {
		return '';
	}
	$tail = substr( $digits, -2 );
	$head = substr( $digits, 0, 4 );
	return sprintf( '+%s (%s) ***-**-%s', substr( $head, 0, 1 ), substr( $digits, 1, 3 ), $tail );
}

/**
 * Маскирование e-mail: iva***@mail.ru.
 *
 * @param string $email E-mail.
 * @return string
 */
function vlacc_mask_email( $email ) {
	if ( ! is_email( $email ) ) {
		return '';
	}
	list( $name, $domain ) = explode( '@', $email, 2 );
	$visible               = mb_substr( $name, 0, min( 3, mb_strlen( $name ) ) );
	return $visible . '***@' . $domain;
}

/**
 * Активен ли WooCommerce.
 *
 * @return bool
 */
function vlacc_is_woo() {
	return class_exists( 'WooCommerce' );
}

/**
 * Безопасный редирект-URL из запроса.
 *
 * @param string $fallback Куда вести, если ничего не передано.
 * @return string
 */
function vlacc_redirect_url( $fallback = '' ) {
	$redirect = '';

	if ( ! empty( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	if ( ! $redirect ) {
		$redirect = $fallback ? $fallback : VL_Account_Settings::account_url();
	}

	return wp_validate_redirect( $redirect, VL_Account_Settings::account_url() );
}

/**
 * Иконка из набора плагина.
 *
 * Иконки линейные: рисуются контуром, а не заливкой. Залитые силуэты рядом
 * с тонкими иконками темы (поиск, сердечко, корзина) смотрятся жирными пятнами.
 *
 * @param string $name Имя иконки.
 * @param int    $size Размер.
 * @return string SVG-разметка.
 */
function vlacc_icon( $name, $size = 20 ) {
	$icons = array(
		'user'     => '<path d="M12 12.2a3.7 3.7 0 1 0 0-7.4 3.7 3.7 0 0 0 0 7.4Z"/><path d="M4.8 20.2c0-3.4 3.2-5.6 7.2-5.6s7.2 2.2 7.2 5.6"/>',
		'logout'   => '<path d="M14.5 3.8h3.7A1.8 1.8 0 0 1 20 5.6v12.8a1.8 1.8 0 0 1-1.8 1.8h-3.7"/><path d="m10.4 8.2 3.8 3.8-3.8 3.8"/><path d="M14.2 12H4"/>',
		'orders'   => '<path d="M13.8 3.8H7.6A1.8 1.8 0 0 0 5.8 5.6v12.8a1.8 1.8 0 0 0 1.8 1.8h8.8a1.8 1.8 0 0 0 1.8-1.8V7.8l-4.4-4Z"/><path d="M13.6 3.9v4h4.4"/><path d="M9 12.6h6"/><path d="M9 16h6"/>',
		'heart'    => '<path d="M12 20.3 4.7 13a4.4 4.4 0 0 1 6.2-6.2l1.1 1.1 1.1-1.1A4.4 4.4 0 0 1 19.3 13L12 20.3Z"/>',
		'gift'     => '<path d="M4.4 11.4h15.2v7.9a1.4 1.4 0 0 1-1.4 1.4H5.8a1.4 1.4 0 0 1-1.4-1.4v-7.9Z"/><path d="M3.2 8.1h17.6v3.3H3.2z"/><path d="M12 8.1v12.6"/><path d="M12 8.1H8.7a2.2 2.2 0 1 1 0-4.4c2.1 0 3.3 4.4 3.3 4.4Z"/><path d="M12 8.1h3.3a2.2 2.2 0 1 0 0-4.4c-2.1 0-3.3 4.4-3.3 4.4Z"/>',
		'star'     => '<path d="m12 3.8 2.6 5.3 5.8.8-4.2 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.2-4.1 5.8-.8L12 3.8Z"/>',
		'bell'     => '<path d="M18 16.6V11a6 6 0 0 0-12 0v5.6l-1.6 2h15.2l-1.6-2Z"/><path d="M10 20.9a2.3 2.3 0 0 0 4 0"/>',
		'settings' => '<path d="M4 7.4h8.4"/><path d="M17.6 7.4H20"/><path d="M4 16.6h2.4"/><path d="M11.6 16.6H20"/><path d="M15 9.6a2.2 2.2 0 1 0 0-4.4 2.2 2.2 0 0 0 0 4.4Z"/><path d="M9 18.8a2.2 2.2 0 1 0 0-4.4 2.2 2.2 0 0 0 0 4.4Z"/>',
		'lock'     => '<path d="M6.6 10.4h10.8a1.2 1.2 0 0 1 1.2 1.2v7.6a1.2 1.2 0 0 1-1.2 1.2H6.6a1.2 1.2 0 0 1-1.2-1.2v-7.6a1.2 1.2 0 0 1 1.2-1.2Z"/><path d="M8.6 10.4V7.7a3.4 3.4 0 0 1 6.8 0v2.7"/>',
		'copy'     => '<path d="M9.8 9.2h8.6a1.2 1.2 0 0 1 1.2 1.2v8.6a1.2 1.2 0 0 1-1.2 1.2H9.8a1.2 1.2 0 0 1-1.2-1.2v-8.6a1.2 1.2 0 0 1 1.2-1.2Z"/><path d="M5.6 15.4a1.6 1.6 0 0 1-1.6-1.6V5.4a1.6 1.6 0 0 1 1.6-1.6h8.4a1.6 1.6 0 0 1 1.6 1.6"/>',
		'check'    => '<path d="m4.8 12.6 4.8 4.8L19.2 6.8"/>',
		'phone'    => '<path d="M8.2 4.2H5.6a1.5 1.5 0 0 0-1.5 1.6c.4 6.9 5.2 12.8 12.1 14a1.5 1.5 0 0 0 1.7-1.5v-2.6l-3.9-1.1-1.5 1.9a13.6 13.6 0 0 1-5.3-6.1l1.9-1.4-.9-4.8Z"/>',
		'telegram' => '<path d="M21.2 4.4 2.9 11.2l5 1.8 1.8 5.7 2.8-3.2 4.6 3.4 4.1-14.5Z"/><path d="m7.9 13 12.4-8.4-8.6 10.6"/>',
	);

	if ( ! isset( $icons[ $name ] ) ) {
		return '';
	}

	// Толщину линии можно поменять фильтром — темы бывают и потоньше, и потолще.
	$stroke = (float) apply_filters( 'vlacc_icon_stroke', 1.4, $name, $size );

	return sprintf(
		'<svg class="vl-icon vl-icon--%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%4$s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
		esc_attr( $name ),
		(int) $size,
		$icons[ $name ],
		esc_attr( (string) $stroke )
	);
}

/**
 * Разрешённые HTML-теги для вывода наших SVG/разметки.
 *
 * @return array
 */
function vlacc_allowed_html() {
	$allowed = wp_kses_allowed_html( 'post' );

	$allowed['svg']  = array(
		'class'            => true,
		'width'            => true,
		'height'           => true,
		'viewbox'          => true,
		'fill'             => true,
		'stroke'           => true,
		'stroke-width'     => true,
		'stroke-linecap'   => true,
		'stroke-linejoin'  => true,
		'aria-hidden'      => true,
		'focusable'        => true,
		'xmlns'            => true,
	);
	$allowed['path'] = array(
		'd'                => true,
		'fill'             => true,
		'stroke'           => true,
		'stroke-width'     => true,
		'stroke-linecap'   => true,
		'stroke-linejoin'  => true,
	);

	return $allowed;
}
