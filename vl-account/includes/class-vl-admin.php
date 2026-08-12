<?php
/**
 * Страница настроек в админке.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Админка.
 */
class VL_Account_Admin {

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Admin|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Admin
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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_vlacc_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_vlacc_test_sms', array( $this, 'test_sms' ) );
		add_action( 'admin_post_vlacc_test_email', array( $this, 'test_email' ) );
		add_action( 'admin_post_vlacc_flush_rules', array( $this, 'flush_rules' ) );
		add_action( 'admin_post_vlacc_clear_log', array( $this, 'clear_log' ) );
		add_filter( 'plugin_action_links_' . VLACC_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Пункт меню.
	 */
	public function menu() {
		add_menu_page(
			__( 'Личный кабинет', 'vl-account' ),
			__( 'Личный кабинет', 'vl-account' ),
			'manage_options',
			'vl-account',
			array( $this, 'render' ),
			'dashicons-admin-users',
			58
		);

		add_submenu_page(
			'vl-account',
			__( 'Настройки кабинета', 'vl-account' ),
			__( 'Настройки', 'vl-account' ),
			'manage_options',
			'vl-account',
			array( $this, 'render' )
		);

		$carts = add_submenu_page(
			'vl-account',
			__( 'Брошенные корзины', 'vl-account' ),
			__( 'Брошенные корзины', 'vl-account' ),
			'manage_options',
			'vl-account-carts',
			array( 'VL_Account_Carts', 'render_admin' )
		);

		// Счётчик брошенных корзин прямо в меню — как у комментариев.
		if ( $carts && VL_Account_Carts::enabled() ) {
			add_action( 'admin_head', array( $this, 'carts_bubble' ) );
		}
	}

	/**
	 * Счётчик брошенных корзин в пункте меню.
	 */
	public function carts_bubble() {
		global $submenu;

		if ( empty( $submenu['vl-account'] ) ) {
			return;
		}

		$stats = VL_Account_Carts::stats();

		if ( empty( $stats['abandoned'] ) ) {
			return;
		}

		foreach ( $submenu['vl-account'] as $key => $item ) {
			if ( isset( $item[2] ) && 'vl-account-carts' === $item[2] ) {
				$submenu['vl-account'][ $key ][0] .= sprintf(
					' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
					(int) $stats['abandoned']
				);
				break;
			}
		}
	}

	/**
	 * Ссылка «Настройки» в списке плагинов.
	 *
	 * @param array $links Ссылки.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=vl-account' ) ) . '">' . esc_html__( 'Настройки', 'vl-account' ) . '</a>' );

		return $links;
	}

	/**
	 * Текущая вкладка.
	 *
	 * @return string
	 */
	protected function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sms'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $tab, array( 'sms', 'forms', 'account', 'orders', 'crm', 'design', 'tools' ), true ) ? $tab : 'sms';
	}

	/**
	 * Вывод страницы.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = $this->tab();
		$s   = VL_Account_Settings::all();

		$tabs = array(
			'sms'     => __( 'SMS.RU', 'vl-account' ),
			'forms'   => __( 'Вход и регистрация', 'vl-account' ),
			'account' => __( 'Личный кабинет', 'vl-account' ),
			'orders'  => __( 'Заказы и письма', 'vl-account' ),
			'crm'     => __( 'Simla / RetailCRM', 'vl-account' ),
			'design'  => __( 'Оформление', 'vl-account' ),
			'tools'   => __( 'Диагностика', 'vl-account' ),
		);
		?>
		<div class="wrap vlacc-admin">
			<h1><?php esc_html_e( 'Личный кабинет и вход по SMS', 'vl-account' ); ?></h1>

			<?php $this->notices(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=vl-account&tab=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'tools' === $tab ) : ?>
				<?php $this->render_tools(); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vlacc_save_settings" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
					<?php wp_nonce_field( 'vlacc_save_settings' ); ?>

					<?php $this->{'render_' . $tab}( $s ); ?>

					<?php submit_button( __( 'Сохранить', 'vl-account' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Уведомления после действий.
	 */
	protected function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['vlacc_msg'] ) ) {
			$messages = array(
				'saved'      => __( 'Настройки сохранены.', 'vl-account' ),
				'flushed'    => __( 'Постоянные ссылки обновлены.', 'vl-account' ),
				'log_clear'  => __( 'Журнал очищен.', 'vl-account' ),
			);

			$key = sanitize_key( wp_unslash( $_GET['vlacc_msg'] ) );

			if ( isset( $messages[ $key ] ) ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $key ] ) );
			}
		}

		if ( ! empty( $_GET['vlacc_sms'] ) ) {
			$text = sanitize_text_field( wp_unslash( $_GET['vlacc_sms'] ) );
			$ok   = ! empty( $_GET['vlacc_sms_ok'] );

			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				esc_html( $text )
			);
		}
		// phpcs:enable
	}

	/**
	 * Поле-чекбокс.
	 *
	 * @param string $name  Имя.
	 * @param array  $s     Настройки.
	 * @param string $label Подпись.
	 * @param string $desc  Описание.
	 */
	protected function checkbox( $name, $s, $label, $desc = '' ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="vlacc[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( ! empty( $s[ $name ] ) ); ?> />
					<?php echo esc_html( $desc ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Текстовое поле.
	 *
	 * @param string $name  Имя.
	 * @param array  $s     Настройки.
	 * @param string $label Подпись.
	 * @param string $desc  Описание.
	 * @param string $type  Тип поля.
	 */
	protected function text( $name, $s, $label, $desc = '', $type = 'text' ) {
		?>
		<tr>
			<th scope="row"><label for="vlacc-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="vlacc-<?php echo esc_attr( $name ); ?>"
					name="vlacc[<?php echo esc_attr( $name ); ?>]"
					value="<?php echo esc_attr( isset( $s[ $name ] ) ? $s[ $name ] : '' ); ?>"
					class="regular-text" />
				<?php if ( $desc ) : ?>
					<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Выпадающий список.
	 *
	 * @param string $name    Имя.
	 * @param array  $s       Настройки.
	 * @param string $label   Подпись.
	 * @param array  $options Варианты.
	 * @param string $desc    Описание.
	 */
	protected function select( $name, $s, $label, $options, $desc = '' ) {
		?>
		<tr>
			<th scope="row"><label for="vlacc-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="vlacc-<?php echo esc_attr( $name ); ?>" name="vlacc[<?php echo esc_attr( $name ); ?>]">
					<?php foreach ( $options as $value => $title ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $s[ $name ] ) ? $s[ $name ] : '', $value ); ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $desc ) : ?>
					<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Список страниц.
	 *
	 * @param string $name  Имя.
	 * @param array  $s     Настройки.
	 * @param string $label Подпись.
	 * @param string $desc  Описание.
	 */
	protected function page_select( $name, $s, $label, $desc = '' ) {
		?>
		<tr>
			<th scope="row"><label for="vlacc-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => 'vlacc[' . $name . ']',
						'id'                => 'vlacc-' . $name,
						'selected'          => isset( $s[ $name ] ) ? (int) $s[ $name ] : 0,
						'show_option_none'  => __( '— не выбрано —', 'vl-account' ),
						'option_none_value' => 0,
					)
				);
				?>
				<?php if ( $desc ) : ?>
					<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Вкладка SMS.RU.
	 *
	 * @param array $s Настройки.
	 */
	protected function render_sms( $s ) {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->text(
				'api_id',
				$s,
				__( 'api_id SMS.RU', 'vl-account' ),
				__( 'Личный кабинет SMS.RU → главная страница → блок «Ваш api_id». Ключ даёт полный доступ к рассылкам — не публикуйте его.', 'vl-account' )
			);

			$this->text(
				'sms_from',
				$s,
				__( 'Имя отправителя', 'vl-account' ),
				__( 'Только согласованное с операторами имя (раздел «Отправители» в SMS.RU). Пусто — отправка от общего имени.', 'vl-account' )
			);

			$this->select(
				'delivery_method',
				$s,
				__( 'Как отправлять код', 'vl-account' ),
				array(
					'sms'           => __( 'SMS с кодом', 'vl-account' ),
					'call'          => __( 'Звонок: код — последние 4 цифры номера (дешевле)', 'vl-account' ),
					'sms_then_call' => __( 'Сначала звонок, если не вышло — SMS', 'vl-account' ),
				),
				__( 'Авторизация звонком в SMS.RU обычно дешевле SMS и не требует согласования имени отправителя.', 'vl-account' )
			);

			$this->text(
				'sms_text',
				$s,
				__( 'Текст SMS', 'vl-account' ),
				__( 'Метка <code>{code}</code> — сам код, <code>{site}</code> — название сайта.', 'vl-account' )
			);

			$this->checkbox( 'test_mode', $s, __( 'Тестовый режим', 'vl-account' ), __( 'Запросы уходят с параметром test=1: SMS.RU отвечает как обычно, но сообщение не отправляется и деньги не списываются.', 'vl-account' ) );
			$this->checkbox( 'debug_show_code', $s, __( 'Показывать код на экране', 'vl-account' ), __( 'Только для отладки на тестовом сайте! На рабочем сайте обязательно выключить.', 'vl-account' ) );
			?>
		</table>

		<h2><?php esc_html_e( 'Ограничения отправки', 'vl-account' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->text( 'code_length', $s, __( 'Длина кода', 'vl-account' ), __( 'Для авторизации звонком всегда 4 цифры.', 'vl-account' ), 'number' );
			$this->text( 'code_ttl', $s, __( 'Срок жизни кода, сек.', 'vl-account' ), '', 'number' );
			$this->text( 'resend_timeout', $s, __( 'Пауза между отправками, сек.', 'vl-account' ), '', 'number' );
			$this->text( 'max_attempts', $s, __( 'Попыток ввода кода', 'vl-account' ), '', 'number' );
			$this->text( 'max_per_phone_day', $s, __( 'Кодов на номер в сутки', 'vl-account' ), '', 'number' );
			$this->text( 'max_per_ip_hour', $s, __( 'Кодов с одного IP в час', 'vl-account' ), __( 'Защита от перебора и слива баланса.', 'vl-account' ), 'number' );
			?>
		</table>
		<?php
	}

	/**
	 * Вкладка форм.
	 *
	 * @param array $s Настройки.
	 */
	protected function render_forms( $s ) {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->select(
				'auth_mode',
				$s,
				__( 'Способы входа', 'vl-account' ),
				array(
					'sms'  => __( 'Только по коду из SMS', 'vl-account' ),
					'both' => __( 'По коду из SMS и по паролю', 'vl-account' ),
				)
			);

			$this->checkbox( 'passwordless', $s, __( 'Вход без пароля', 'vl-account' ), __( 'Пароль не спрашиваем: вход по коду. Задать пароль можно позже в кабинете.', 'vl-account' ) );
			$this->checkbox(
				'auto_register',
				$s,
				__( 'Регистрация в один шаг', 'vl-account' ),
				__( 'Незнакомый номер регистрируется автоматически после верного кода — отдельной формы регистрации нет. Имя, e-mail и адрес подтянутся из первого заказа. Если выключить, вход останется только у тех, кто уже есть в базе.', 'vl-account' )
			);
			$this->text( 'auth_intro', $s, __( 'Текст над полем телефона', 'vl-account' ), __( 'Пусто — «Авторизуйтесь или зарегистрируйтесь по номеру телефона — мы пришлём SMS с кодом подтверждения.»', 'vl-account' ) );
			$this->text( 'auth_consent_note', $s, __( 'Текст согласия под кнопкой', 'vl-account' ), __( 'Пусто — «Нажимая «получить код», вы соглашаетесь с обработкой персональных данных» со ссылкой на страницу политики.', 'vl-account' ) );
			$this->checkbox( 'auth_marketing_box', $s, __( 'Галочка рассылки в форме входа', 'vl-account' ), __( 'Необязательная галочка согласия на рекламные рассылки прямо в форме. Если выключить, согласие собирается в кабинете, в разделе «Подписки».', 'vl-account' ) );
			$this->checkbox( 'show_telegram', $s, __( 'Поле Telegram', 'vl-account' ), __( 'Показывать поле Telegram в кабинете.', 'vl-account' ) );
			$this->text( 'cookie_days', $s, __( 'Помнить вход, дней', 'vl-account' ), __( 'Сколько посетитель остаётся авторизованным.', 'vl-account' ), 'number' );
			$this->text( 'phone_mask', $s, __( 'Маска телефона', 'vl-account' ), __( 'Например <code>+7 (___) ___-__-__</code>. Оставьте пустым, чтобы отключить маску.', 'vl-account' ) );
			$this->text( 'default_country', $s, __( 'Код страны по умолчанию', 'vl-account' ), __( 'Подставляется, если номер введён без кода.', 'vl-account' ) );
			?>
		</table>

		<h2><?php esc_html_e( 'Вход перед покупкой', 'vl-account' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'gate_cart',
				$s,
				__( 'Требовать вход перед покупкой', 'vl-account' ),
				__( 'Справа выезжает панель с формой входа. После входа прерванное действие продолжается автоматически.', 'vl-account' )
			);
			$this->select(
				'gate_mode',
				$s,
				__( 'На каком шаге спрашивать вход', 'vl-account' ),
				array(
					'checkout' => __( 'При оформлении заказа (рекомендуется)', 'vl-account' ),
					'cart'     => __( 'При добавлении в корзину', 'vl-account' ),
				),
				__( 'При оформлении: гость свободно наполняет корзину, вход требуется на кнопке «Оформить заказ» и на самой странице оформления. При добавлении: вход нужен ещё до того, как товар попадёт в корзину.', 'vl-account' )
			);
			$this->text( 'gate_title', $s, __( 'Заголовок панели', 'vl-account' ), __( 'По умолчанию: «Вход в личный кабинет».', 'vl-account' ) );
			$this->text( 'gate_message', $s, __( 'Текст в панели', 'vl-account' ), __( 'Короткое объяснение, зачем нужен вход. Оставьте пустым — подставится стандартный текст.', 'vl-account' ) );
			$this->text(
				'gate_selectors',
				$s,
				__( 'Дополнительные кнопки', 'vl-account' ),
				sprintf(
					/* translators: %s — список селекторов, которые уже перехватываются. */
					__( 'CSS-селекторы через запятую, если у темы свои кнопки. Уже перехватываются: %s, а также любой элемент с атрибутом <code>data-vl-requires-auth</code>. В режиме «при оформлении» дополнительно ловится любая ссылка на страницу оформления заказа.', 'vl-account' ),
					'<code>' . implode( '</code>, <code>', VL_Account_Gate::selectors() ) . '</code>'
				)
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Согласия', 'vl-account' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox( 'consent_privacy', $s, __( 'Согласие на обработку данных', 'vl-account' ), __( 'Обязательная галочка в форме регистрации.', 'vl-account' ) );
			$this->text( 'consent_privacy_text', $s, __( 'Текст согласия', 'vl-account' ), __( '<code>%s</code> заменится ссылкой на страницу политики.', 'vl-account' ) );
			$this->page_select( 'privacy_page', $s, __( 'Страница политики', 'vl-account' ) );
			$this->checkbox( 'consent_marketing', $s, __( 'Согласие на рассылки', 'vl-account' ), __( 'Отдельная необязательная галочка — согласие на рекламные рассылки.', 'vl-account' ) );
			$this->text( 'consent_marketing_text', $s, __( 'Текст согласия на рассылки', 'vl-account' ) );
			?>
		</table>
		<?php
	}

	/**
	 * Вкладка кабинета.
	 *
	 * @param array $s Настройки.
	 */
	protected function render_account( $s ) {
		$tabs = array(
			'dashboard'     => __( 'Обзор', 'vl-account' ),
			'orders'        => __( 'Мои заказы', 'vl-account' ),
			'wishlist'      => __( 'Избранное', 'vl-account' ),
			'promo'         => __( 'Промокоды', 'vl-account' ),
			'bonus'         => __( 'Бонусы', 'vl-account' ),
			'subscriptions' => __( 'Подписки', 'vl-account' ),
			'profile'       => __( 'Мои данные', 'vl-account' ),
			'security'      => __( 'Пароль и вход', 'vl-account' ),
		);

		$enabled = (array) ( isset( $s['tabs'] ) ? $s['tabs'] : array() );
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->page_select( 'account_page', $s, __( 'Страница кабинета', 'vl-account' ), __( 'Страница с шорткодом <code>[vl_account]</code>. Если не выбрать — используется страница «Мой аккаунт» WooCommerce.', 'vl-account' ) );
			$this->page_select( 'auth_page', $s, __( 'Страница входа', 'vl-account' ), __( 'Страница с шорткодом <code>[vl_auth]</code>. Туда ведут иконка входа и все ссылки «войти».', 'vl-account' ) );
			$this->page_select( 'loyalty_page', $s, __( 'Условия программы лояльности', 'vl-account' ), __( 'Ссылка на неё появится в разделе «Бонусы».', 'vl-account' ) );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Разделы кабинета', 'vl-account' ); ?></th>
				<td>
					<?php foreach ( $tabs as $slug => $label ) : ?>
						<label style="display:block;margin-bottom:4px">
							<input type="checkbox" name="vlacc[tabs][]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $enabled, true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<?php $this->checkbox( 'wishlist_on_product', $s, __( 'Кнопка «в избранное» в карточке', 'vl-account' ), __( 'Добавить кнопку на страницу товара автоматически. Если на сайте уже есть своя кнопка — не включайте, используйте шорткод.', 'vl-account' ) ); ?>
		</table>

		<h2><?php esc_html_e( 'Промокод за регистрацию', 'vl-account' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->select(
				'promo_mode',
				$s,
				__( 'Режим', 'vl-account' ),
				array(
					'none'     => __( 'Не выдавать', 'vl-account' ),
					'shared'   => __( 'Один общий код для всех', 'vl-account' ),
					'personal' => __( 'Персональный купон каждому', 'vl-account' ),
				),
				__( 'Выданный промокод виден в кабинете в разделе «Промокоды» и приходит в письме.', 'vl-account' )
			);
			$this->text( 'promo_shared_code', $s, __( 'Общий код', 'vl-account' ), __( 'Код существующего купона WooCommerce.', 'vl-account' ) );
			$this->text( 'promo_prefix', $s, __( 'Префикс персонального кода', 'vl-account' ) );
			$this->text( 'promo_discount', $s, __( 'Скидка, %', 'vl-account' ), '', 'number' );
			$this->text( 'promo_days', $s, __( 'Срок действия, дней', 'vl-account' ), '', 'number' );
			?>
		</table>

		<h2><?php esc_html_e( 'Избранное и плагин WishSuite', 'vl-account' ); ?></h2>

		<?php if ( VL_Account_WishSuite::plugin_active() ) : ?>
			<table class="widefat striped" style="max-width:900px;margin-bottom:20px">
				<tbody>
					<?php foreach ( VL_Account_WishSuite::checks() as $check ) : ?>
						<tr>
							<td style="width:32px">
								<span style="color:<?php echo 'ok' === $check['status'] ? '#2a9d3f' : ( 'warn' === $check['status'] ? '#d98f00' : '#d40000' ); ?>;font-size:18px">●</span>
							</td>
							<td style="width:280px"><strong><?php echo esc_html( $check['title'] ); ?></strong></td>
							<td><?php echo wp_kses_post( $check['text'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Плагин WishSuite не активен. Настройки ниже сохранятся, но заработают только после его подключения.', 'vl-account' ); ?>
			</p>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'ws_enabled',
				$s,
				__( 'Показывать избранное WishSuite', 'vl-account' ),
				__( 'Товары, добавленные сердечком WishSuite, попадают в раздел «Избранное» кабинета и в счётчик.', 'vl-account' )
			);
			$this->select(
				'ws_source',
				$s,
				__( 'Какой список показывать', 'vl-account' ),
				array(
					'merge'     => __( 'Оба списка вместе', 'vl-account' ),
					'wishsuite' => __( 'Только список WishSuite', 'vl-account' ),
					'vlacc'     => __( 'Только собственный список кабинета', 'vl-account' ),
				),
				__( 'У кабинета есть своё хранилище избранного. «Оба списка вместе» — безопасный вариант при переезде с одного плагина на другой.', 'vl-account' )
			);
			$this->checkbox(
				'ws_two_way',
				$s,
				__( 'Возвращать изменения в WishSuite', 'vl-account' ),
				__( 'Убрали товар в кабинете — сердечко у товара тоже погаснет.', 'vl-account' )
			);
			$this->checkbox(
				'ws_merge_guest',
				$s,
				__( 'Переносить избранное гостя', 'vl-account' ),
				__( 'WishSuite сам не переносит список гостя в аккаунт после входа — товары «пропадают». Кабинет переносит их сам.', 'vl-account' )
			);
			$this->checkbox(
				'ws_hide_our_button',
				$s,
				__( 'Не дублировать сердечко', 'vl-account' ),
				__( 'Пока WishSuite активен, собственная кнопка кабинета в карточке товара не выводится.', 'vl-account' )
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Размеры кнопками', 'vl-account' ); ?></h2>

		<p class="description" style="max-width:900px">
			<?php esc_html_e( 'Выпадающий список размеров заменяется кнопками: и в карточке товара, и в быстрой корзине избранного. Размеры, которых нет в наличии, остаются видимыми, но неактивными. Если такие кнопки уже делает скрипт в шаблоне темы — его можно удалить, плагин перехватывает существующий контейнер .size-buttons и не даёт задвоения.', 'vl-account' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'ws_size_buttons',
				$s,
				__( 'Включить кнопки размеров', 'vl-account' ),
				__( 'Работает и без WishSuite.', 'vl-account' )
			);
			$this->select(
				'ws_size_scope',
				$s,
				__( 'Где выводить', 'vl-account' ),
				array(
					'all'       => __( 'Везде: карточка товара и быстрая корзина', 'vl-account' ),
					'wishsuite' => __( 'Только в быстрой корзине WishSuite', 'vl-account' ),
				)
			);
			$this->text(
				'ws_size_attributes',
				$s,
				__( 'Атрибуты для кнопок', 'vl-account' ),
				sprintf(
					/* translators: %s — список кодов атрибутов магазина. */
					__( 'Коды атрибутов через запятую. Пусто — кнопками выводятся все атрибуты. Атрибуты этого магазина: %s', 'vl-account' ),
					'<code>' . esc_html( $this->attribute_slugs() ) . '</code>'
				)
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Подписка на поступление размера', 'vl-account' ); ?></h2>

		<table class="widefat striped" style="max-width:900px;margin-bottom:20px">
			<tbody>
				<?php foreach ( VL_Account_Stock_Notifier::checks() as $check ) : ?>
					<tr>
						<td style="width:32px">
							<span style="color:<?php echo 'ok' === $check['status'] ? '#2a9d3f' : ( 'warn' === $check['status'] ? '#d98f00' : '#d40000' ); ?>;font-size:18px">●</span>
						</td>
						<td style="width:280px"><strong><?php echo esc_html( $check['title'] ); ?></strong></td>
						<td><?php echo wp_kses_post( $check['text'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'sn_enabled',
				$s,
				__( 'Показывать подписки в кабинете', 'vl-account' ),
				__( 'Раздел «Подписки» покажет товары и размеры, поступления которых ждёт покупатель, и даст отписаться.', 'vl-account' )
			);
			$this->checkbox(
				'sn_show_sent',
				$s,
				__( 'Показывать отработавшие подписки', 'vl-account' ),
				__( 'Те, по которым письмо о поступлении уже ушло. Покупателю полезно видеть, что товар вернулся.', 'vl-account' )
			);
			$this->checkbox(
				'sn_match_email',
				$s,
				__( 'Искать подписки по e-mail', 'vl-account' ),
				__( 'Подписаться можно и без входа в кабинет — тогда подписка привязана только к почте. С этой галочкой такие подписки тоже попадут в кабинет.', 'vl-account' )
			);
			?>
		</table>
		<?php
	}

	/**
	 * Коды атрибутов товаров магазина — подсказка в настройках.
	 *
	 * @return string
	 */
	protected function attribute_slugs() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return '—';
		}

		$slugs = array();

		foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
			if ( ! empty( $attribute->attribute_name ) ) {
				$slugs[] = 'pa_' . $attribute->attribute_name;
			}
		}

		return $slugs ? implode( ', ', $slugs ) : '—';
	}

	/**
	 * Вкладка заказов и писем.
	 *
	 * @param array $s Настройки.
	 */
	protected function render_orders( $s ) {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox( 'auto_create_account', $s, __( 'Создавать кабинет при заказе', 'vl-account' ), __( 'Если покупатель оформил заказ без входа — заводим аккаунт и отправляем письмо с доступом.', 'vl-account' ) );
			$this->checkbox( 'attach_guest_orders', $s, __( 'Подтягивать прошлые заказы', 'vl-account' ), __( 'При входе привязываем к аккаунту заказы, оформленные с тем же e-mail или телефоном.', 'vl-account' ) );
			$this->checkbox( 'match_by_phone', $s, __( 'Искать заказы по телефону', 'vl-account' ), __( 'Учитываются все варианты записи номера: +7, 8, со скобками и без.', 'vl-account' ) );
			$this->checkbox( 'email_on_register', $s, __( 'Письмо после регистрации', 'vl-account' ), __( 'Уходит только тем, у кого уже есть подтверждённый e-mail.', 'vl-account' ) );
			$this->checkbox( 'email_on_autocreate', $s, __( 'Письмо при автосоздании кабинета', 'vl-account' ), '' );
			$this->checkbox(
				'email_confirm',
				$s,
				__( 'Подтверждение e-mail из заказа', 'vl-account' ),
				__( 'После заказа покупателю приходит второе, отдельное письмо со ссылкой подтверждения. Пока по ней не перешли, адрес к кабинету не привязывается — так нельзя записать на себя чужую почту.', 'vl-account' )
			);
			$this->text( 'email_confirm_days', $s, __( 'Ссылка подтверждения живёт, дней', 'vl-account' ), '', 'number' );
			?>
		</table>

		<h2><?php esc_html_e( 'Брошенные корзины', 'vl-account' ); ?></h2>

		<p class="description" style="max-width:900px">
			<?php
			printf(
				/* translators: %s — ссылка на страницу отчёта. */
				esc_html__( 'Плагин записывает, что покупатели складывают в корзину, и показывает это в разделе %s. Гость виден как «неизвестный покупатель», но список его товаров сохраняется.', 'vl-account' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=vl-account-carts' ) ) . '">' . esc_html__( 'Брошенные корзины', 'vl-account' ) . '</a>'
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'carts_enabled',
				$s,
				__( 'Собирать корзины', 'vl-account' ),
				__( 'Снимок корзины сохраняется при каждом изменении. Когда покупатель оформляет заказ, запись помечается как купленная.', 'vl-account' )
			);
			$this->text(
				'carts_abandoned_after',
				$s,
				__( 'Считать брошенной через, минут', 'vl-account' ),
				__( 'Столько корзина должна пролежать без изменений, чтобы попасть в отчёт.', 'vl-account' ),
				'number'
			);
			$this->text(
				'carts_keep_days',
				$s,
				__( 'Хранить записи, дней', 'vl-account' ),
				__( 'Старые записи удаляются автоматически раз в сутки.', 'vl-account' ),
				'number'
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Автозаполнение форм', 'vl-account' ); ?></h2>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'autofill',
				$s,
				__( 'Автозаполнение полей заказа', 'vl-account' ),
				__( 'Полям оформления проставляются правильные атрибуты autocomplete — браузер подставляет сохранённые имя, телефон, почту и адрес. Данные из аккаунта тоже подставляются в пустые поля.', 'vl-account' )
			);
			$this->checkbox(
				'autofill_fix_forms',
				$s,
				__( 'Чинить остальные формы сайта', 'vl-account' ),
				__( 'С форм темы и конструкторов снимается запрет автозаполнения, а полям по их именам проставляется подходящий тип. Форму входа по номеру телефона и поля одноразового кода это не затрагивает.', 'vl-account' )
			);
			?>
		</table>
		<?php
	}

	/**
	 * Вкладка интеграции с Simla.com / RetailCRM.
	 *
	 * @param array $s Настройки.
	 */
	protected function render_crm( $s ) {
		$active  = VL_Account_RetailCRM::plugin_active();
		$api     = $active && VL_Account_RetailCRM::api_ready();
		$loyalty = $api && VL_Account_RetailCRM::loyalty_enabled();
		?>
		<h2><?php esc_html_e( 'Состояние связки', 'vl-account' ); ?></h2>

		<table class="widefat striped" style="max-width:900px;margin-bottom:20px">
			<tbody>
				<?php foreach ( $this->crm_checks() as $check ) : ?>
					<tr>
						<td style="width:32px">
							<span style="color:<?php echo 'ok' === $check['status'] ? '#2a9d3f' : ( 'warn' === $check['status'] ? '#d98f00' : '#d40000' ); ?>;font-size:18px">●</span>
						</td>
						<td style="width:280px"><strong><?php echo esc_html( $check['title'] ); ?></strong></td>
						<td><?php echo wp_kses_post( $check['text'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! $active ) : ?>
			<p class="description">
				<?php esc_html_e( 'Плагин Simla.com не активен. Настройки ниже сохранятся, но заработают только после его подключения.', 'vl-account' ); ?>
			</p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Обмен данными', 'vl-account' ); ?></h2>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'crm_enabled',
				$s,
				__( 'Включить интеграцию', 'vl-account' ),
				__( 'Общий выключатель: кабинет перестаёт обращаться к CRM и работает на локальных данных.', 'vl-account' )
			);
			$this->checkbox(
				'crm_sync_customer',
				$s,
				__( 'Досылать данные покупателя', 'vl-account' ),
				__( 'Плагин Simla выгружает покупателя в момент регистрации, когда телефон ещё не записан. Кабинет досылает телефон, имя и согласия сразу после.', 'vl-account' )
			);
			$this->checkbox(
				'crm_sync_consents',
				$s,
				__( 'Согласие на рассылку → подписка в CRM', 'vl-account' ),
				__( 'Галочка «хочу получать письма» в кабинете управляет полем «подписан» у покупателя в CRM.', 'vl-account' )
			);
			$this->checkbox(
				'crm_skip_tech_email',
				$s,
				__( 'Не отправлять технические адреса', 'vl-account' ),
				__( 'При регистрации по SMS без почты кабинет заводит адрес вида 79001234567@phone.сайт. В CRM он не нужен — покупатель уедет с телефоном.', 'vl-account' )
			);
			$this->checkbox(
				'crm_order_priority',
				$s,
				__( 'Привязывать заказ до выгрузки', 'vl-account' ),
				__( 'Кабинет создаёт аккаунт и привязывает заказ раньше, чем Simla отправит заказ в CRM. Иначе заказ уезжает гостевым.', 'vl-account' )
			);
			$this->text(
				'crm_wishlist_field',
				$s,
				__( 'Поле CRM для избранного', 'vl-account' ),
				__( 'Символьный код пользовательского поля покупателя в CRM. Пусто — избранное не выгружается.', 'vl-account' )
			);
			$this->text(
				'crm_cache_ttl',
				$s,
				__( 'Кэш данных лояльности, сек', 'vl-account' ),
				__( 'Баланс и история берутся из CRM по API. 300 секунд — разумный компромисс между свежестью и скоростью.', 'vl-account' ),
				'number'
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Программа лояльности в кабинете', 'vl-account' ); ?></h2>

		<table class="form-table" role="presentation">
			<?php
			$this->select(
				'crm_bonus_source',
				$s,
				__( 'Источник баланса баллов', 'vl-account' ),
				array(
					'auto'  => __( 'Автоматически: CRM, если участие активно', 'vl-account' ),
					'crm'   => __( 'Только CRM', 'vl-account' ),
					'local' => __( 'Только локальный баланс плагина', 'vl-account' ),
				),
				__( 'Локальный баланс задаётся вручную в профиле пользователя и подходит, пока программа лояльности в CRM не настроена.', 'vl-account' )
			);
			$this->checkbox(
				'crm_loyalty_ui',
				$s,
				__( 'Вступление и активация в кабинете', 'vl-account' ),
				__( 'В разделе «Бонусы» покупатель может вступить в программу и активировать участие (с кодом из SMS, если CRM его запрашивает).', 'vl-account' )
			);
			$this->checkbox(
				'crm_hide_wc_loyalty',
				$s,
				__( 'Убрать раздел Simla из меню', 'vl-account' ),
				__( 'Стандартный пункт «Loyalty program» дублирует наш раздел «Бонусы». Его адрес перенаправляется на «Бонусы».', 'vl-account' )
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Корзина и промокоды', 'vl-account' ); ?></h2>

		<table class="form-table" role="presentation">
			<?php
			$this->select(
				'crm_cart_widget',
				$s,
				__( 'Списание баллов в корзине', 'vl-account' ),
				array(
					'replace' => __( 'Вместо стандартного блока Simla', 'vl-account' ),
					'add'     => __( 'Дополнительно к стандартному блоку', 'vl-account' ),
					'off'     => __( 'Не выводить, оставить блок Simla', 'vl-account' ),
				),
				__( 'Блок можно поставить и вручную шорткодом <code>[vl_loyalty_cart]</code> — например, в блочной корзине.', 'vl-account' )
			);
			$this->checkbox(
				'crm_credit_top',
				$s,
				__( 'Начисление баллов рядом с итогом', 'vl-account' ),
				__( 'Строка «Начислим баллов» переносится из блока оплаты к сумме заказа — её видно сразу.', 'vl-account' )
			);
			$this->checkbox(
				'crm_promo_combine',
				$s,
				__( 'Промокод совместим с баллами', 'vl-account' ),
				__( 'С промокода за регистрацию снимается флаг «только один купон в заказе»: иначе WooCommerce выкидывает из корзины купон списания баллов.', 'vl-account' )
			);
			$this->checkbox(
				'crm_promo_hide_loyalty',
				$s,
				__( 'Прятать служебные купоны', 'vl-account' ),
				__( 'Купоны вида loyalty12345 создаёт Simla для списания баллов. В разделе «Промокоды» их быть не должно.', 'vl-account' )
			);
			$this->checkbox(
				'crm_fix_coupon_email',
				$s,
				__( 'Чинить купоны с техническим адресом', 'vl-account' ),
				__( 'Купон списания привязывается к почте покупателя. Для входа по SMS почта техническая, и купон становится неприменимым — такое ограничение снимается.', 'vl-account' )
			);
			?>
		</table>
		<?php
	}

	/**
	 * Проверки связки с CRM.
	 *
	 * @return array
	 */
	protected function crm_checks() {
		$checks = array();

		$active = VL_Account_RetailCRM::plugin_active();

		$checks[] = array(
			'title'  => __( 'Плагин Simla.com', 'vl-account' ),
			'status' => $active ? 'ok' : 'warn',
			'text'   => $active
				? __( 'Активен.', 'vl-account' )
				: __( 'Не найден. Раздел «Бонусы» работает на локальном балансе.', 'vl-account' ),
		);

		if ( ! $active ) {
			return $checks;
		}

		$ping = VL_Account_RetailCRM::ping();

		$checks[] = array(
			'title'  => __( 'Связь с CRM', 'vl-account' ),
			'status' => $ping['ok'] ? 'ok' : 'error',
			'text'   => esc_html( $ping['message'] ),
		);

		$loyalty = VL_Account_RetailCRM::loyalty_enabled();

		$checks[] = array(
			'title'  => __( 'Программа лояльности', 'vl-account' ),
			'status' => $loyalty ? 'ok' : 'warn',
			'text'   => $loyalty
				? __( 'Включена в настройках Simla — баланс и история берутся из CRM.', 'vl-account' )
				: __( 'Выключена в настройках Simla (WooCommerce → Настройки → Интеграция). Пока она выключена, раздел «Бонусы» показывает локальный баланс.', 'vl-account' ),
		);

		if ( $loyalty ) {
			$coupons = 'yes' === get_option( 'woocommerce_enable_coupons' );

			$checks[] = array(
				'title'  => __( 'Купоны WooCommerce', 'vl-account' ),
				'status' => $coupons ? 'ok' : 'error',
				'text'   => $coupons
					? __( 'Включены — списание баллов и промокоды работают.', 'vl-account' )
					: __( 'Выключены. Без них Simla не сможет списать баллы, а плагин — выдать промокод.', 'vl-account' ),
			);
		}

		$coupon_field = VL_Account_RetailCRM::crm_setting( 'woo_coupon_apply_field', 'not-upload' );

		$checks[] = array(
			'title'  => __( 'Промокоды в заказе', 'vl-account' ),
			'status' => ( $coupon_field && 'not-upload' !== $coupon_field ) ? 'ok' : 'warn',
			'text'   => ( $coupon_field && 'not-upload' !== $coupon_field )
				? sprintf(
					/* translators: %s — код поля в CRM. */
					esc_html__( 'Коды применённых купонов уезжают в поле «%s».', 'vl-account' ),
					esc_html( $coupon_field )
				)
				: esc_html__( 'В настройках Simla не выбрано поле для промокодов — в CRM не будет видно, каким кодом воспользовался покупатель.', 'vl-account' ),
		);

		$field = VL_Account_RetailCRM_Customer::wishlist_field();

		$checks[] = array(
			'title'  => __( 'Избранное в CRM', 'vl-account' ),
			'status' => $field ? 'ok' : 'warn',
			'text'   => $field
				? sprintf(
					/* translators: %s — код поля в CRM. */
					esc_html__( 'Выгружается в поле «%s».', 'vl-account' ),
					esc_html( $field )
				)
				: esc_html__( 'Поле не указано — избранное остаётся только в кабинете.', 'vl-account' ),
		);

		return $checks;
	}

	/**
	 * Вкладка оформления.
	 *
	 * @param array $s Настройки.
	 */
	protected function render_design( $s ) {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->text( 'accent_color', $s, __( 'Акцентный цвет', 'vl-account' ), __( 'Кнопка «зарегистрироваться», активные пункты меню.', 'vl-account' ), 'color' );
			$this->text( 'button_color', $s, __( 'Цвет тёмной кнопки', 'vl-account' ), __( 'Кнопка «войти».', 'vl-account' ), 'color' );
			$this->text( 'radius', $s, __( 'Скругление углов, px', 'vl-account' ), __( '0 — прямые углы, как в оформлении сайта.', 'vl-account' ), 'number' );
			?>
		</table>

		<h2><?php esc_html_e( 'Шорткоды', 'vl-account' ); ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<?php
				$shortcodes = array(
					'[vl_auth]'                     => __( 'Вход и регистрация в один шаг: телефон → код → кабинет', 'vl-account' ),
					'[vl_login]'                    => __( 'Синоним [vl_auth]', 'vl-account' ),
					'[vl_register]'                 => __( 'Синоним [vl_auth] — отдельной регистрации больше нет', 'vl-account' ),
					'[vl_lost_password]'            => __( 'Восстановление доступа', 'vl-account' ),
					'[vl_account]'                  => __( 'Личный кабинет целиком', 'vl-account' ),
					'[vl_account_menu]'             => __( 'Только меню кабинета', 'vl-account' ),
					'[vl_account_icon]'             => __( 'Иконки в шапке: человечек — вход/кабинет, стрелка — выход', 'vl-account' ),
					'[vl_wishlist_button]'          => __( 'Кнопка «в избранное» (в карточке товара)', 'vl-account' ),
					'[vl_wishlist_count]'           => __( 'Счётчик избранного для шапки', 'vl-account' ),
					'[vl_user_name]'                => __( 'Имя текущего покупателя', 'vl-account' ),
				);

				foreach ( $shortcodes as $code => $desc ) :
					?>
					<tr>
						<td style="width:220px"><code><?php echo esc_html( $code ); ?></code></td>
						<td><?php echo esc_html( $desc ); ?></td>
					</tr>
					<?php
				endforeach;
				?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Дополнительные атрибуты: [vl_auth redirect="/my-account/" title="Вход"], [vl_account_icon size="22" show_logout="no" show_label="yes" drawer="yes"].', 'vl-account' ); ?>
		</p>
		<?php
	}

	/**
	 * Вкладка диагностики.
	 */
	protected function render_tools() {
		$checks = $this->diagnostics();
		?>
		<h2><?php esc_html_e( 'Проверка настроек', 'vl-account' ); ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td style="width:32px">
							<span style="color:<?php echo 'ok' === $check['status'] ? '#2a9d3f' : ( 'warn' === $check['status'] ? '#d98f00' : '#d40000' ); ?>;font-size:18px">●</span>
						</td>
						<td style="width:280px"><strong><?php echo esc_html( $check['title'] ); ?></strong></td>
						<td><?php echo wp_kses_post( $check['text'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Тестовая отправка', 'vl-account' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vlacc_test_sms" />
			<?php wp_nonce_field( 'vlacc_test_sms' ); ?>
			<p>
				<input type="text" name="phone" placeholder="+7 926 000-00-00" class="regular-text" />
				<?php submit_button( __( 'Отправить тестовый код', 'vl-account' ), 'secondary', 'submit', false ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'Отправка идёт выбранным способом (SMS или звонок) и списывает деньги с баланса, если выключен тестовый режим.', 'vl-account' ); ?></p>
		</form>

		<h2><?php esc_html_e( 'Проверка почты', 'vl-account' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vlacc_test_email" />
			<?php wp_nonce_field( 'vlacc_test_email' ); ?>
			<p>
				<input type="email" name="email" placeholder="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
				<?php submit_button( __( 'Отправить тестовое письмо', 'vl-account' ), 'secondary', 'submit', false ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Письмо уходит тем же путём, что и подтверждение e-mail. Если тут ошибка — проблема в почте сайта, а не в плагине. Если письмо «отправлено», но не дошло — его отбросил почтовый сервис получателя, нужен SMTP и настроенные SPF/DKIM.', 'vl-account' ); ?>
			</p>
		</form>

		<h2><?php esc_html_e( 'Служебное', 'vl-account' ); ?></h2>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vlacc_flush_rules' ), 'vlacc_flush_rules' ) ); ?>">
				<?php esc_html_e( 'Обновить постоянные ссылки', 'vl-account' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vlacc_clear_log' ), 'vlacc_clear_log' ) ); ?>">
				<?php esc_html_e( 'Очистить журнал', 'vl-account' ); ?>
			</a>
		</p>

		<h2><?php esc_html_e( 'Журнал', 'vl-account' ); ?></h2>
		<?php
		$log = get_option( 'vlacc_log', array() );

		if ( ! $log ) {
			echo '<p>' . esc_html__( 'Пока пусто.', 'vl-account' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th style="width:160px"><?php esc_html_e( 'Время', 'vl-account' ); ?></th>
					<th><?php esc_html_e( 'Событие', 'vl-account' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $log, 0, 50 ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['time'] ); ?></td>
						<td>
							<?php echo esc_html( $row['message'] ); ?>
							<?php if ( ! empty( $row['context'] ) ) : ?>
								<br><code style="font-size:11px"><?php echo esc_html( wp_json_encode( $row['context'], JSON_UNESCAPED_UNICODE ) ); ?></code>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Набор проверок.
	 *
	 * @return array
	 */
	protected function diagnostics() {
		$checks = array();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// WooCommerce.
		$checks[] = array(
			'title'  => 'WooCommerce',
			'status' => vlacc_is_woo() ? 'ok' : 'warn',
			'text'   => vlacc_is_woo()
				? __( 'Активен — заказы, избранное и купоны работают.', 'vl-account' )
				: __( 'Не найден. Формы входа работать будут, разделы заказов и промокодов — нет.', 'vl-account' ),
		);

		// api_id.
		$api_ok = VL_Account_Settings::sms_ready();
		$text   = $api_ok ? __( 'Ключ указан.', 'vl-account' ) : __( 'Не заполнен — коды отправляться не будут.', 'vl-account' );

		if ( $api_ok ) {
			$balance = VL_Account_SmsRu::balance();

			if ( is_wp_error( $balance ) ) {
				$api_ok = false;
				$text   = sprintf(
					/* translators: %s — текст ошибки. */
					__( 'Ключ указан, но SMS.RU отвечает ошибкой: %s', 'vl-account' ),
					$balance->get_error_message()
				);
			} else {
				$text = sprintf(
					/* translators: %s — сумма баланса. */
					__( 'Связь есть. Баланс: %s ₽.', 'vl-account' ),
					number_format_i18n( $balance, 2 )
				);

				if ( $balance < 100 ) {
					$text .= ' ' . __( 'Баланса хватит ненадолго — пополните.', 'vl-account' );
				}
			}
		}

		$checks[] = array(
			'title'  => __( 'Подключение к SMS.RU', 'vl-account' ),
			'status' => $api_ok ? 'ok' : 'error',
			'text'   => $text,
		);

		// Имя отправителя.
		$from = trim( (string) VL_Account_Settings::get( 'sms_from', '' ) );

		if ( 'sms' === VL_Account_Settings::get( 'delivery_method', 'sms' ) ) {
			$checks[] = array(
				'title'  => __( 'Имя отправителя', 'vl-account' ),
				'status' => $from ? 'ok' : 'warn',
				'text'   => $from
					? sprintf(
						/* translators: %s — имя отправителя. */
						__( 'Используется имя «%s». Оно должно быть согласовано в разделе «Отправители» SMS.RU.', 'vl-account' ),
						esc_html( $from )
					)
					: __( 'Не задано — сообщения уйдут от общего имени SMS.RU. Это допустимо, но с фирменным именем доставляемость выше.', 'vl-account' ),
			);
		}

		// Отладочный вывод кода.
		if ( VL_Account_Settings::get( 'debug_show_code', 0 ) ) {
			$checks[] = array(
				'title'  => __( 'Показ кода на экране', 'vl-account' ),
				'status' => 'error',
				'text'   => __( 'Включён показ кода прямо в форме. На рабочем сайте это дыра в безопасности — выключите на вкладке SMS.RU.', 'vl-account' ),
			);
		}

		// Стили и скрипты.
		$css_url = VLACC_URL . 'assets/css/vl-account.css';
		$css_ok  = file_exists( VLACC_PATH . 'assets/css/vl-account.css' );

		$optimizers = array(
			'autoptimize/autoptimize.php'                 => 'Autoptimize',
			'wp-rocket/wp-rocket.php'                     => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php'         => 'LiteSpeed Cache',
			'perfmatters/perfmatters.php'                 => 'Perfmatters',
			'wp-fastest-cache/wpFastestCache.php'         => 'WP Fastest Cache',
			'sg-cachepress/sg-cachepress.php'             => 'SiteGround Optimizer',
			'w3-total-cache/w3-total-cache.php'           => 'W3 Total Cache',
		);

		$found_optimizers = array();

		foreach ( $optimizers as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$found_optimizers[] = $name;
			}
		}

		$assets_text = $css_ok
			? sprintf(
				/* translators: %s — ссылка на файл стилей. */
				__( 'Файл стилей на месте: %s — откройте ссылку, страница должна показать текст CSS, а не ошибку 404.', 'vl-account' ),
				'<a href="' . esc_url( $css_url ) . '" target="_blank">vl-account.css</a>'
			)
			: __( 'Файл стилей не найден — переустановите плагин.', 'vl-account' );

		if ( $found_optimizers ) {
			$assets_text .= '<br><strong>' . sprintf(
				/* translators: %s — названия плагинов оптимизации. */
				esc_html__( 'Найдены плагины оптимизации: %s.', 'vl-account' ),
				esc_html( implode( ', ', $found_optimizers ) )
			) . '</strong> ' . esc_html__( 'Добавьте vl-account.css и vl-account.js в исключения объединения и минификации, а также в исключения «удаления неиспользуемого CSS» — иначе выдвижная панель входа теряет оформление и появляется внизу страницы.', 'vl-account' );
		}

		$checks[] = array(
			'title'  => __( 'Стили и скрипты плагина', 'vl-account' ),
			'status' => $css_ok ? ( $found_optimizers ? 'warn' : 'ok' ) : 'error',
			'text'   => $assets_text,
		);

		// Отправка почты.
		$smtp_plugins = array(
			'wp-mail-smtp/wp_mail_smtp.php'          => 'WP Mail SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'          => 'Easy WP SMTP',
			'post-smtp/postman-smtp.php'             => 'Post SMTP',
			'fluent-smtp/fluent-smtp.php'            => 'FluentSMTP',
			'wp-smtp/wp-smtp.php'                    => 'WP SMTP',
			'wp-ses/wp-ses.php'                      => 'WP SES',
			'mailgun/mailgun.php'                    => 'Mailgun',
		);

		$smtp_found = array();

		foreach ( $smtp_plugins as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$smtp_found[] = $name;
			}
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? preg_replace( '/^www\./', '', $host ) : '';

		// Письма плагина уходят через почтовик WooCommerce, если он активен.
		$from = vlacc_is_woo()
			? get_option( 'woocommerce_email_from_address', '' )
			: '';

		if ( ! $from ) {
			$from = apply_filters( 'wp_mail_from', 'wordpress@' . $host );
		}

		$mail_text = sprintf(
			/* translators: %s — адрес отправителя. */
			__( 'Письма уходят от отправителя <code>%s</code>.', 'vl-account' ),
			esc_html( $from )
		);

		if ( $smtp_found ) {
			$mail_text .= ' ' . sprintf(
				/* translators: %s — названия SMTP-плагинов. */
				__( 'Подключён SMTP: %s.', 'vl-account' ),
				esc_html( implode( ', ', $smtp_found ) )
			);
		} else {
			$mail_text .= ' ' . __( 'SMTP-плагин не найден: письма отправляются функцией PHP mail(). Gmail, Яндекс и Mail.ru часто молча отбрасывают такие письма — они не попадают даже в «Спам». Поставьте SMTP-плагин (например, WP Mail SMTP) и отправляйте с адреса на вашем домене.', 'vl-account' );
		}

		$mail_text .= '<br>' . __( 'Ниже есть кнопка «Отправить тестовое письмо» — она покажет, уходит ли почта с сайта вообще.', 'vl-account' );

		$checks[] = array(
			'title'  => __( 'Отправка почты', 'vl-account' ),
			'status' => $smtp_found ? 'ok' : 'warn',
			'text'   => $mail_text,
		);

		// Адрес сайта.
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$site = wp_parse_url( site_url(), PHP_URL_HOST );

		$checks[] = array(
			'title'  => __( 'Адрес сайта', 'vl-account' ),
			'status' => $home === $site ? 'ok' : 'error',
			'text'   => $home === $site
				? __( 'Адреса сайта и WordPress совпадают.', 'vl-account' )
				: __( 'Адрес сайта и адрес WordPress различаются (например, с www и без). Из-за этого куки авторизации теряются при переходе между страницами — приведите оба адреса к одному виду в «Настройки → Общие».', 'vl-account' ),
		);

		// Кеш.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$cache_plugins = array(
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
		);

		$active = array();

		foreach ( $cache_plugins as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $name;
			}
		}

		if ( $active ) {
			$checks[] = array(
				'title'  => __( 'Плагин кеширования', 'vl-account' ),
				'status' => VL_Account_Settings::get( 'no_cache', 1 ) ? 'warn' : 'error',
				'text'   => sprintf(
					/* translators: %s — названия плагинов кеширования. */
					__( 'Найден: %s. Плагин просит не кешировать страницы кабинета и залогиненных посетителей, но в настройках самого кеша тоже включите «не кешировать для авторизованных» и добавьте страницы входа/кабинета/корзины в исключения.', 'vl-account' ),
					esc_html( implode( ', ', $active ) )
				),
			);
		}

		// Страницы с шорткодами.
		$account_page = (int) VL_Account_Settings::get( 'account_page', 0 );
		$auth_page    = (int) VL_Account_Settings::get( 'auth_page', 0 );

		$checks[] = array(
			'title'  => __( 'Страница кабинета', 'vl-account' ),
			'status' => ( $account_page || vlacc_is_woo() ) ? 'ok' : 'warn',
			'text'   => $account_page
				? sprintf(
					/* translators: %s — ссылка на страницу. */
					__( 'Используется страница: %s', 'vl-account' ),
					'<a href="' . esc_url( get_permalink( $account_page ) ) . '" target="_blank">' . esc_html( get_the_title( $account_page ) ) . '</a>'
				)
				: __( 'Используется страница «Мой аккаунт» WooCommerce.', 'vl-account' ),
		);

		$checks[] = array(
			'title'  => __( 'Страница входа', 'vl-account' ),
			'status' => $auth_page ? 'ok' : 'warn',
			'text'   => $auth_page
				? sprintf(
					/* translators: %s — ссылка на страницу. */
					__( 'Используется страница: %s', 'vl-account' ),
					'<a href="' . esc_url( get_permalink( $auth_page ) ) . '" target="_blank">' . esc_html( get_the_title( $auth_page ) ) . '</a>'
				)
				: __( 'Не выбрана: ссылки «войти» ведут на страницу кабинета. Создайте страницу с шорткодом [vl_auth] и укажите её в настройках.', 'vl-account' ),
		);

		// Связка с RetailCRM / Simla.
		if ( VL_Account_RetailCRM::plugin_active() ) {
			$checks = array_merge( $checks, $this->crm_checks() );
		}

		// Связка с плагином избранного WishSuite.
		if ( VL_Account_WishSuite::plugin_active() ) {
			$checks = array_merge( $checks, VL_Account_WishSuite::checks() );
		}

		// Связка с плагином подписок на поступление.
		if ( VL_Account_Stock_Notifier::plugin_active() ) {
			$checks = array_merge( $checks, VL_Account_Stock_Notifier::checks() );
		}

		// ЧПУ.
		$checks[] = array(
			'title'  => __( 'Постоянные ссылки', 'vl-account' ),
			'status' => get_option( 'permalink_structure' ) ? 'ok' : 'warn',
			'text'   => get_option( 'permalink_structure' )
				? __( 'ЧПУ включены — разделы кабинета открываются красивыми адресами.', 'vl-account' )
				: __( 'ЧПУ выключены. Кабинет будет работать через параметры адреса — это нормально, но ссылки менее красивые.', 'vl-account' ),
		);

		return $checks;
	}

	/**
	 * Сохранение настроек.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vlacc_save_settings' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'vl-account' ) );
		}

		$input = isset( $_POST['vlacc'] ) ? wp_unslash( $_POST['vlacc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'sms';

		$current  = VL_Account_Settings::all();
		$defaults = VL_Account_Settings::defaults();

		// Чекбоксы текущей вкладки, которых нет в POST, сбрасываем в 0.
		$checkbox_map = array(
			'sms'     => array( 'test_mode', 'debug_show_code' ),
			'forms'   => array( 'passwordless', 'auto_register', 'auth_marketing_box', 'show_telegram', 'gate_cart', 'consent_privacy', 'consent_marketing' ),
			'account' => array( 'wishlist_on_product', 'ws_enabled', 'ws_two_way', 'ws_merge_guest', 'ws_hide_our_button', 'ws_size_buttons', 'sn_enabled', 'sn_show_sent', 'sn_match_email' ),
			'orders'  => array( 'auto_create_account', 'attach_guest_orders', 'match_by_phone', 'email_on_register', 'email_on_autocreate', 'email_confirm', 'carts_enabled', 'autofill', 'autofill_fix_forms' ),
			'crm'     => array( 'crm_enabled', 'crm_sync_customer', 'crm_sync_consents', 'crm_skip_tech_email', 'crm_order_priority', 'crm_loyalty_ui', 'crm_hide_wc_loyalty', 'crm_credit_top', 'crm_promo_combine', 'crm_promo_hide_loyalty', 'crm_fix_coupon_email' ),
			'design'  => array(),
		);

		$clean = array();

		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			if ( 'tabs' === $key ) {
				$clean['tabs'] = array_map( 'sanitize_key', (array) $value );
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_map( 'sanitize_text_field', $value );
				continue;
			}

			if ( in_array( $key, array( 'consent_privacy_text', 'consent_marketing_text' ), true ) ) {
				$clean[ $key ] = wp_kses_post( $value );
				continue;
			}

			$clean[ $key ] = sanitize_text_field( $value );
		}

		if ( isset( $checkbox_map[ $tab ] ) ) {
			foreach ( $checkbox_map[ $tab ] as $key ) {
				if ( ! isset( $clean[ $key ] ) ) {
					$clean[ $key ] = 0;
				}
			}
		}

		// На вкладке кабинета список разделов может прийти пустым.
		if ( 'account' === $tab && ! isset( $clean['tabs'] ) ) {
			$clean['tabs'] = array();
		}

		VL_Account_Settings::update( array_merge( $current, $clean ) );

		do_action( 'vlacc_settings_saved', $clean );

		wp_safe_redirect( admin_url( 'admin.php?page=vl-account&tab=' . $tab . '&vlacc_msg=saved' ) );
		exit;
	}

	/**
	 * Тестовая отправка кода.
	 */
	public function test_sms() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vlacc_test_sms' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'vl-account' ) );
		}

		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$phone = VL_Account_Phone::normalize( $phone );

		if ( ! VL_Account_Phone::is_valid( $phone ) ) {
			$this->redirect_tools( __( 'Проверьте номер телефона.', 'vl-account' ), false );
		}

		$method = VL_Account_Settings::get( 'delivery_method', 'sms' );

		if ( 'call' === $method ) {
			$result = VL_Account_SmsRu::call_code( $phone );

			if ( ! empty( $result['success'] ) ) {
				$this->redirect_tools(
					sprintf(
						/* translators: %s — код подтверждения. */
						__( 'Звонок заказан. Код: %s (последние 4 цифры номера).', 'vl-account' ),
						$result['code']
					),
					true
				);
			}
		} else {
			$result = VL_Account_SmsRu::send_sms( $phone, __( 'Проверка связи с сайтом. Код: 1234', 'vl-account' ) );

			if ( ! empty( $result['success'] ) ) {
				$this->redirect_tools( __( 'SMS отправлено успешно.', 'vl-account' ), true );
			}
		}

		$this->redirect_tools(
			sprintf(
				/* translators: %s — текст ошибки. */
				__( 'Не отправлено. Ответ SMS.RU: %s', 'vl-account' ),
				$result['message']
			),
			false
		);
	}

	/**
	 * Редирект с сообщением.
	 *
	 * @param string $message Сообщение.
	 * @param bool   $ok      Успех.
	 */
	protected function redirect_tools( $message, $ok ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'vl-account',
					'tab'          => 'tools',
					'vlacc_sms'    => rawurlencode( $message ),
					'vlacc_sms_ok' => $ok ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Тестовое письмо — тем же путём, что и письма плагина.
	 */
	public function test_email() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vlacc_test_email' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'vl-account' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			$email = wp_get_current_user()->user_email;
		}

		if ( ! is_email( $email ) ) {
			$this->redirect_tools( __( 'Укажите корректный адрес.', 'vl-account' ), false );
		}

		$content  = '<p>' . esc_html__( 'Это тестовое письмо с вашего сайта.', 'vl-account' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Если вы его читаете — почта с сайта уходит, и письма плагина будут доходить так же.', 'vl-account' ) . '</p>';

		$sent = VL_Account_Emails::instance()->send(
			$email,
			sprintf(
				/* translators: %s — название сайта. */
				__( 'Проверка почты — %s', 'vl-account' ),
				get_bloginfo( 'name' )
			),
			$content
		);

		if ( $sent ) {
			$this->redirect_tools(
				sprintf(
					/* translators: %s — адрес электронной почты. */
					__( 'Письмо принято к отправке на %s. Если оно не придёт за пару минут — сервер отдал его, но почтовый сервис получателя отбросил: нужен SMTP и настроенные SPF/DKIM.', 'vl-account' ),
					$email
				),
				true
			);
		}

		$error = VL_Account_Emails::instance()->last_error();

		$this->redirect_tools(
			sprintf(
				/* translators: %s — текст ошибки. */
				__( 'Сайт не смог отправить письмо. Ответ сервера: %s', 'vl-account' ),
				$error ? $error : __( 'без описания (обычно на хостинге отключена функция mail() — поставьте SMTP-плагин)', 'vl-account' )
			),
			false
		);
	}

	/**
	 * Сброс правил ЧПУ.
	 */
	public function flush_rules() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vlacc_flush_rules' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'vl-account' ) );
		}

		VL_Account_MyAccount::register_endpoints();
		flush_rewrite_rules();

		wp_safe_redirect( admin_url( 'admin.php?page=vl-account&tab=tools&vlacc_msg=flushed' ) );
		exit;
	}

	/**
	 * Очистка журнала.
	 */
	public function clear_log() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vlacc_clear_log' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'vl-account' ) );
		}

		delete_option( 'vlacc_log' );

		wp_safe_redirect( admin_url( 'admin.php?page=vl-account&tab=tools&vlacc_msg=log_clear' ) );
		exit;
	}
}
