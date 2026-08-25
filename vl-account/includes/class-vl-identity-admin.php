<?php
/**
 * Админка: отчёт по сопоставлению и объединению аккаунтов.
 *
 * Всё, что подсистема делает автоматически, должно быть видно и выгружаемо:
 * какой аккаунт с каким объединён, откуда взялась связь, что перенесено,
 * где телефон сошёлся на нескольких аккаунтах и требует ручного решения.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Экран «Сопоставление аккаунтов».
 */
class VL_Account_Identity_Admin {

	/**
	 * Слаг страницы.
	 */
	const PAGE = 'vl-account-identity';

	/**
	 * Экземпляр.
	 *
	 * @var VL_Account_Identity_Admin|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return VL_Account_Identity_Admin
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
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_vlacc_identity_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_vlacc_identity_export', array( $this, 'export' ) );
	}

	/**
	 * Пункт меню.
	 */
	public function menu() {
		add_submenu_page(
			'vl-account',
			__( 'Сопоставление аккаунтов', 'vl-account' ),
			__( 'Сопоставление аккаунтов', 'vl-account' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/* ------------------------------------------------------------------
	 * Действия
	 * ------------------------------------------------------------------ */

	/**
	 * Кнопки экрана.
	 */
	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vlacc_identity' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'vl-account' ) );
		}

		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$notice = '';

		switch ( $action ) {
			case 'sync':
				$started = VL_Account_RetailCRM_Directory::start();
				$notice  = is_wp_error( $started ) ? 'crm_off' : 'sync_started';
				break;

			case 'stop':
				VL_Account_RetailCRM_Directory::stop();
				$notice = 'sync_stopped';
				break;

			case 'match':
				VL_Account_RetailCRM_Directory::match_all();
				$notice = 'matched';
				break;

			case 'backfill':
				$count  = self::backfill_phones();
				$notice = 'backfill_' . $count;
				break;

			case 'logins':
				$count  = self::rename_logins();
				$notice = 'logins_' . $count;
				break;

			case 'clear':
				VL_Account_RetailCRM_Directory::truncate();
				$notice = 'cleared';
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&vlacc_msg=' . rawurlencode( $notice ) ) );
		exit;
	}

	/**
	 * Дописать телефоны из справочника в профили аккаунтов.
	 *
	 * После этого штатный поиск по номеру находит старые аккаунты сам,
	 * без обращения к CRM.
	 *
	 * @return int Сколько аккаунтов получили номер.
	 */
	public static function backfill_phones() {
		$rows    = VL_Account_RetailCRM_Directory::query(
			array(
				'status' => 'matched',
				'limit'  => 5000,
			)
		);
		$updated = 0;

		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$phone   = VL_Account_Phone::normalize( $row['phone'] );

			if ( ! $user_id || '' === $phone ) {
				continue;
			}

			if ( '' !== (string) get_user_meta( $user_id, VL_Account_User::META_PHONE, true ) ) {
				continue;
			}

			if ( ! VL_Account_Identity::is_adoptable( $user_id ) ) {
				continue;
			}

			// Номер уже занят другим аккаунтом — это конфликт, а не находка.
			$owner = get_users(
				array(
					'meta_key'   => VL_Account_User::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $phone,                      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
					'fields'     => 'ID',
				)
			);

			if ( ! empty( $owner[0] ) && (int) $owner[0] !== $user_id ) {
				continue;
			}

			update_user_meta( $user_id, VL_Account_User::META_PHONE, $phone );

			if ( '' === (string) get_user_meta( $user_id, 'billing_phone', true ) ) {
				update_user_meta( $user_id, 'billing_phone', VL_Account_Phone::format( $phone ) );
			}

			VL_Account_Identity::log(
				'backfill',
				array(
					'phone'   => $phone,
					'email'   => $row['email'],
					'user_id' => $user_id,
					'source'  => 'crm',
					'note'    => 'телефон из CRM записан в профиль',
				)
			);

			++$updated;
		}

		return $updated;
	}

	/**
	 * Что известно про телефон: аккаунт, карточка CRM, участие, заказы.
	 *
	 * Это ответ на вопрос «почему у покупателя ничего не подтянулось»:
	 * видно, какой способ поиска сработал, а какой вернул пусто.
	 *
	 * @param string $phone Номер в любом виде.
	 * @return array Строки отчёта: label => value.
	 */
	public static function probe( $phone ) {
		$normalized = VL_Account_Phone::normalize( $phone );
		$report     = array();

		if ( '' === $normalized ) {
			return array( __( 'Номер', 'vl-account' ) => __( 'не разобран — проверьте, что это телефон', 'vl-account' ) );
		}

		$report[ __( 'Номер', 'vl-account' ) ] = VL_Account_Phone::format( $normalized ) . ' (' . $normalized . ')';

		// 1. Аккаунт на сайте.
		$user = VL_Account_User::get_by_phone( $normalized );

		$report[ __( 'Аккаунт на сайте', 'vl-account' ) ] = $user
			? sprintf( '#%d — %s (%s)', $user->ID, $user->user_login, $user->user_email )
			: __( 'не найден', 'vl-account' );

		// 2. Заказы сайта с этим номером.
		$orders = class_exists( 'VL_Account_Orders' ) ? VL_Account_Orders::find_orders_by_phone( $normalized ) : array();

		$report[ __( 'Заказы сайта с этим номером', 'vl-account' ) ] = $orders
			? count( $orders )
			: __( 'нет', 'vl-account' );

		// 3. Снимок базы CRM.
		$row = VL_Account_RetailCRM_Directory::query(
			array(
				'search' => $normalized,
				'limit'  => 3,
			)
		);

		$report[ __( 'В снимке базы CRM', 'vl-account' ) ] = $row
			? sprintf( 'клиент %d, статус %s, аккаунт %d', (int) $row[0]['crm_id'], $row[0]['status'], (int) $row[0]['user_id'] )
			: __( 'нет записи', 'vl-account' );

		if ( ! VL_Account_RetailCRM::enabled() ) {
			$report[ __( 'Связь с CRM', 'vl-account' ) ] = __( 'выключена или не настроена — дальше проверять нечего', 'vl-account' );

			return $report;
		}

		$api = VL_Account_RetailCRM::api();

		// 4. Поиск клиента в CRM всеми способами.
		$customer = VL_Account_RetailCRM_Directory::search_everywhere( $api, $normalized );

		if ( $customer ) {
			$report[ __( 'Клиент в CRM', 'vl-account' ) ] = sprintf(
				'id %d, externalId %s, %s %s, %s',
				isset( $customer['id'] ) ? (int) $customer['id'] : 0,
				! empty( $customer['externalId'] ) ? (int) $customer['externalId'] : '—',
				isset( $customer['firstName'] ) ? $customer['firstName'] : '',
				isset( $customer['lastName'] ) ? $customer['lastName'] : '',
				isset( $customer['email'] ) ? $customer['email'] : '—'
			);
		} else {
			$report[ __( 'Клиент в CRM', 'vl-account' ) ] = __( 'не найден ни одним способом (см. журнал плагина — там записана каждая попытка)', 'vl-account' );
		}

		// 5. Участие в программе лояльности по номеру.
		$account = VL_Account_RetailCRM_Directory::loyalty_account_by_phone( $api, $normalized );

		if ( $account ) {
			$report[ __( 'Участие в программе лояльности', 'vl-account' ) ] = sprintf(
				'id %d, %s, баллов: %s, уровень: %s',
				isset( $account['id'] ) ? (int) $account['id'] : 0,
				empty( $account['active'] ) ? __( 'не активировано', 'vl-account' ) : __( 'активно', 'vl-account' ),
				isset( $account['amount'] ) ? (string) $account['amount'] : '0',
				isset( $account['level']['name'] ) ? $account['level']['name'] : '—'
			);
		} else {
			$report[ __( 'Участие в программе лояльности', 'vl-account' ) ] = __( 'по номеру не найдено', 'vl-account' );
		}

		// 6. Что увидит кабинет.
		if ( $user ) {
			VL_Account_RetailCRM::flush( $user->ID );
			$state = VL_Account_RetailCRM::account( $user->ID, true );

			$report[ __( 'Кабинет покажет', 'vl-account' ) ] = sprintf(
				'статус «%s», баллов: %s',
				$state['status'],
				number_format_i18n( $state['amount'] )
			);

			$crm_orders = VL_Account_RetailCRM::orders( $user->ID, 50 );

			$report[ __( 'Заказы из CRM для кабинета', 'vl-account' ) ] = $crm_orders ? count( $crm_orders ) : __( 'нет', 'vl-account' );
		}

		return $report;
	}

	/**
	 * Заменить логины-номера на логины из имени.
	 *
	 * Разовая уборка для аккаунтов, заведённых до появления поля «Имя».
	 * Условия — те же, что и при входе: аккаунт без пароля, без лишних прав,
	 * с заполненным именем.
	 *
	 * @return int Сколько логинов заменено.
	 */
	public static function rename_logins() {
		$users = get_users(
			array(
				'meta_key' => VL_Account_User::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'number'   => 2000,
				'fields'   => 'ID',
			)
		);

		$renamed = 0;

		foreach ( (array) $users as $user_id ) {
			// Ник и отображаемое имя могли остаться номером — поправим заодно.
			VL_Account_User::sync_display_name( (int) $user_id );

			if ( VL_Account_User::maybe_rename_login( (int) $user_id ) ) {
				++$renamed;

				VL_Account_Identity::log(
					'login',
					array(
						'user_id' => (int) $user_id,
						'source'  => 'admin',
						'note'    => 'логин-номер заменён на имя',
					)
				);
			}
		}

		return $renamed;
	}

	/**
	 * Выгрузка отчёта в CSV.
	 */
	public function export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vlacc_identity_export' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'vl-account' ) );
		}

		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : 'log';
		$file = 'vlacc-' . ( 'directory' === $what ? 'crm-customers' : 'identity-log' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $file );

		$out = fopen( 'php://output', 'w' );

		// BOM: без него Excel открывает кириллицу кракозябрами.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		if ( 'directory' === $what ) {
			fputcsv( $out, array( 'id', 'crm_id', 'external_id', 'phone', 'email', 'first_name', 'last_name', 'city', 'subscribed', 'user_id', 'user_login', 'user_email', 'status', 'note', 'updated' ) );

			$offset = 0;

			do {
				$rows = VL_Account_RetailCRM_Directory::query(
					array(
						'limit'  => 500,
						'offset' => $offset,
					)
				);

				foreach ( $rows as $row ) {
					$user = $row['user_id'] ? get_user_by( 'id', (int) $row['user_id'] ) : false;

					fputcsv(
						$out,
						array(
							$row['id'],
							$row['crm_id'],
							$row['external_id'],
							$row['phone'],
							$row['email'],
							$row['first_name'],
							$row['last_name'],
							$row['city'],
							$row['subscribed'],
							$row['user_id'],
							$user ? $user->user_login : '',
							$user ? $user->user_email : '',
							$row['status'],
							$row['note'],
							$row['updated'],
						)
					);
				}

				$offset += 500;
			} while ( ! empty( $rows ) );
		} else {
			fputcsv( $out, array( 'id', 'created', 'event', 'phone', 'email', 'user_id', 'from_user_id', 'source', 'note' ) );

			$offset = 0;

			do {
				$rows = VL_Account_Identity::log_query(
					array(
						'limit'  => 500,
						'offset' => $offset,
					)
				);

				foreach ( $rows as $row ) {
					fputcsv(
						$out,
						array(
							$row['id'],
							$row['created'],
							$row['event'],
							$row['phone'],
							$row['email'],
							$row['user_id'],
							$row['from_user_id'],
							$row['source'],
							$row['note'],
						)
					);
				}

				$offset += 500;
			} while ( ! empty( $rows ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ------------------------------------------------------------------
	 * Экран
	 * ------------------------------------------------------------------ */

	/**
	 * Текст уведомления после действия.
	 *
	 * @param string $code Код.
	 * @return string
	 */
	protected function notice( $code ) {
		if ( 0 === strpos( $code, 'backfill_' ) ) {
			return sprintf(
				/* translators: %d — сколько аккаунтов. */
				__( 'Телефон дописан в профили: %d.', 'vl-account' ),
				(int) substr( $code, 9 )
			);
		}

		if ( 0 === strpos( $code, 'logins_' ) ) {
			return sprintf(
				/* translators: %d — сколько логинов. */
				__( 'Логинов заменено на имя: %d.', 'vl-account' ),
				(int) substr( $code, 7 )
			);
		}

		$map = array(
			'sync_started' => __( 'Сверка запущена. Данные подтягиваются пачками в фоне — обновляйте страницу.', 'vl-account' ),
			'sync_stopped' => __( 'Сверка остановлена.', 'vl-account' ),
			'matched'      => __( 'Справочник заново сопоставлен с аккаунтами сайта.', 'vl-account' ),
			'cleared'      => __( 'Снимок базы CRM удалён.', 'vl-account' ),
			'crm_off'      => __( 'Интеграция с CRM выключена или не настроена — сверять нечего.', 'vl-account' ),
		);

		return isset( $map[ $code ] ) ? $map[ $code ] : '';
	}

	/**
	 * Вывод страницы.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		VL_Account_Identity::install();
		VL_Account_RetailCRM_Directory::install();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['vlacc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['vlacc_msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$probe = isset( $_GET['probe'] ) ? sanitize_text_field( wp_unslash( $_GET['probe'] ) ) : '';

		$state = VL_Account_RetailCRM_Directory::state();
		$stats = VL_Account_RetailCRM_Directory::stats();
		$log   = VL_Account_Identity::log_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Сопоставление аккаунтов', 'vl-account' ); ?></h1>

			<?php if ( $msg && $this->notice( $msg ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $this->notice( $msg ) ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:900px">
				<?php esc_html_e( 'Старые аккаунты покупателей заводились почтой и паролем — телефона в них нет. Здесь телефон доводится до такого аккаунта: сначала по заказам самого сайта, затем по базе покупателей RetailCRM, где хранится связка «телефон — почта — ID аккаунта». Пустые аккаунты, созданные входом по SMS, объединяются со старыми автоматически; всё это видно в журнале ниже.', 'vl-account' ); ?>
			</p>

			<h2><?php esc_html_e( 'Снимок базы покупателей CRM', 'vl-account' ); ?></h2>

			<table class="widefat striped" style="max-width:900px;margin-bottom:16px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Записей в снимке', 'vl-account' ); ?></td>
						<td><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong>
							<?php if ( $stats['phones'] ) : ?>
								<span class="description"><?php printf( /* translators: %s — количество. */ esc_html__( 'из них с телефоном: %s', 'vl-account' ), esc_html( number_format_i18n( $stats['phones'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Сопоставлено с аккаунтами сайта', 'vl-account' ); ?></td>
						<td><strong style="color:#2a9d3f"><?php echo esc_html( number_format_i18n( $stats['matched'] ) ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Клиент есть в CRM, аккаунта на сайте нет', 'vl-account' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $stats['no_user'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Конфликты (один телефон у разных аккаунтов)', 'vl-account' ); ?></td>
						<td><strong style="color:<?php echo $stats['conflict'] ? '#d98f00' : 'inherit'; ?>"><?php echo esc_html( number_format_i18n( $stats['conflict'] ) ); ?></strong>
							<span class="description"><?php esc_html_e( 'автоматически такие не объединяются', 'vl-account' ); ?></span>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Состояние выгрузки', 'vl-account' ); ?></td>
						<td>
							<?php if ( ! empty( $state['running'] ) ) : ?>
								<strong><?php esc_html_e( 'идёт', 'vl-account' ); ?></strong>
								<?php
								printf(
									/* translators: 1: страница, 2: всего страниц, 3: записей. */
									esc_html__( ' — страница %1$d из %2$d, записей: %3$d', 'vl-account' ),
									(int) $state['page'],
									(int) $state['pages'],
									(int) $state['fetched']
								);
								?>
							<?php elseif ( ! empty( $state['finished'] ) ) : ?>
								<?php
								printf(
									/* translators: %s — дата и время. */
									esc_html__( 'завершена %s', 'vl-account' ),
									esc_html( date_i18n( 'd.m.Y H:i', (int) $state['finished'] ) )
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'не запускалась', 'vl-account' ); ?>
							<?php endif; ?>

							<?php if ( ! empty( $state['error'] ) ) : ?>
								<br /><span style="color:#d40000"><?php echo esc_html( $state['error'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px">
				<?php wp_nonce_field( 'vlacc_identity' ); ?>
				<input type="hidden" name="action" value="vlacc_identity_action" />

				<button class="button button-primary" name="do" value="sync"><?php esc_html_e( 'Сверить базу с CRM', 'vl-account' ); ?></button>
				<button class="button" name="do" value="stop"><?php esc_html_e( 'Остановить', 'vl-account' ); ?></button>
				<button class="button" name="do" value="match"><?php esc_html_e( 'Пересопоставить с аккаунтами', 'vl-account' ); ?></button>
				<button class="button" name="do" value="backfill"><?php esc_html_e( 'Дописать телефоны в аккаунты', 'vl-account' ); ?></button>
				<button class="button" name="do" value="logins" onclick="return confirm('<?php echo esc_js( __( 'Заменить логины-номера на логины из имени? Изменение затронет только аккаунты без пароля; те, кто сейчас авторизован, войдут заново.', 'vl-account' ) ); ?>')"><?php esc_html_e( 'Логины из имени', 'vl-account' ); ?></button>
				<button class="button" name="do" value="clear" onclick="return confirm('<?php echo esc_js( __( 'Удалить снимок базы CRM? Аккаунты и заказы не пострадают.', 'vl-account' ) ); ?>')"><?php esc_html_e( 'Очистить снимок', 'vl-account' ); ?></button>

				<p class="description">
					<?php esc_html_e( 'Выгрузка идёт в фоне маленькими пачками (по 100 клиентов, несколько запросов в минуту) — сайт при этом не нагружается. «Дописать телефоны» — разовая операция: номер из CRM попадает в профиль старого аккаунта, и дальше вход по SMS находит его без обращения к CRM. «Логины из имени» меняет логины-номера у аккаунтов без пароля на логины вида aleksandr — WordPress сам такого не умеет.', 'vl-account' ); ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Проверка покупателя по телефону', 'vl-account' ); ?></h2>

			<p class="description" style="max-width:900px">
				<?php esc_html_e( 'Показывает по шагам, что о номере знает сайт и что отвечает CRM: есть ли аккаунт, есть ли карточка клиента, каким способом она нашлась, есть ли участие в программе лояльности и сколько на нём баллов. Запросы идут в CRM прямо сейчас, кэш при этом сбрасывается.', 'vl-account' ); ?>
			</p>

			<form method="get" style="margin-bottom:12px">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="text" name="probe" value="<?php echo esc_attr( $probe ); ?>" placeholder="+7 (___) ___-__-__" style="width:220px" />
				<button class="button button-primary"><?php esc_html_e( 'Проверить', 'vl-account' ); ?></button>
			</form>

			<?php if ( '' !== $probe ) : ?>
				<table class="widefat striped" style="max-width:900px;margin-bottom:24px">
					<tbody>
						<?php foreach ( self::probe( $probe ) as $vl_label => $vl_value ) : ?>
							<tr>
								<td style="width:280px"><strong><?php echo esc_html( $vl_label ); ?></strong></td>
								<td><?php echo esc_html( is_scalar( $vl_value ) ? (string) $vl_value : wp_json_encode( $vl_value ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Журнал сопоставлений', 'vl-account' ); ?></h2>

			<p>
				<?php
				$labels = array(
					'login'    => __( 'логин из имени', 'vl-account' ),
					'match'    => __( 'вход в найденный аккаунт', 'vl-account' ),
					'merge'    => __( 'объединение', 'vl-account' ),
					'adopt'    => __( 'данные из CRM', 'vl-account' ),
					'backfill' => __( 'телефон дописан', 'vl-account' ),
					'conflict' => __( 'конфликт', 'vl-account' ),
				);

				foreach ( $labels as $key => $label ) {
					printf(
						'<span style="margin-right:18px">%s: <strong>%s</strong></span>',
						esc_html( $label ),
						esc_html( number_format_i18n( isset( $log[ $key ] ) ? $log[ $key ] : 0 ) )
					);
				}
				?>
			</p>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vlacc_identity_export&what=log' ), 'vlacc_identity_export' ) ); ?>"><?php esc_html_e( 'Скачать журнал (CSV)', 'vl-account' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vlacc_identity_export&what=directory' ), 'vlacc_identity_export' ) ); ?>"><?php esc_html_e( 'Скачать снимок CRM (CSV)', 'vl-account' ); ?></a>
			</p>

			<?php $this->render_log(); ?>

			<h2><?php esc_html_e( 'Справочник покупателей CRM', 'vl-account' ); ?></h2>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<select name="status">
					<option value=""><?php esc_html_e( 'Все записи', 'vl-account' ); ?></option>
					<option value="matched" <?php selected( $status, 'matched' ); ?>><?php esc_html_e( 'Сопоставленные', 'vl-account' ); ?></option>
					<option value="no_user" <?php selected( $status, 'no_user' ); ?>><?php esc_html_e( 'Без аккаунта', 'vl-account' ); ?></option>
					<option value="conflict" <?php selected( $status, 'conflict' ); ?>><?php esc_html_e( 'Конфликты', 'vl-account' ); ?></option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'телефон, почта, имя', 'vl-account' ); ?>" />
				<button class="button"><?php esc_html_e( 'Показать', 'vl-account' ); ?></button>
			</form>

			<?php
			$this->render_directory(
				array(
					'status' => $status,
					'search' => $search,
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Таблица журнала.
	 */
	protected function render_log() {
		$rows = VL_Account_Identity::log_query( array( 'limit' => 50 ) );
		?>
		<table class="widefat striped" style="margin-bottom:24px">
			<thead>
				<tr>
					<th style="width:140px"><?php esc_html_e( 'Когда', 'vl-account' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Событие', 'vl-account' ); ?></th>
					<th style="width:140px"><?php esc_html_e( 'Телефон', 'vl-account' ); ?></th>
					<th><?php esc_html_e( 'Аккаунт', 'vl-account' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Источник', 'vl-account' ); ?></th>
					<th><?php esc_html_e( 'Подробности', 'vl-account' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Пока пусто.', 'vl-account' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $rows as $row ) : ?>
					<?php $user = $row['user_id'] ? get_user_by( 'id', (int) $row['user_id'] ) : false; ?>
					<tr>
						<td><?php echo esc_html( $row['created'] ); ?></td>
						<td><?php echo esc_html( $row['event'] ); ?></td>
						<td><?php echo esc_html( $row['phone'] ? VL_Account_Phone::format( $row['phone'] ) : '—' ); ?></td>
						<td>
							<?php if ( $user ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
								<span class="description">#<?php echo esc_html( $user->ID ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $row['email'] ? $row['email'] : '—' ); ?>
							<?php endif; ?>

							<?php if ( ! empty( $row['from_user_id'] ) ) : ?>
								<br /><span class="description"><?php printf( /* translators: %d — ID аккаунта. */ esc_html__( 'из аккаунта #%d', 'vl-account' ), (int) $row['from_user_id'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['source'] ); ?></td>
						<td><span class="description"><?php echo esc_html( $row['note'] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Таблица справочника.
	 *
	 * @param array $args Фильтры.
	 */
	protected function render_directory( $args ) {
		$rows = VL_Account_RetailCRM_Directory::query( array_merge( $args, array( 'limit' => 100 ) ) );
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:140px"><?php esc_html_e( 'Телефон', 'vl-account' ); ?></th>
					<th><?php esc_html_e( 'Покупатель в CRM', 'vl-account' ); ?></th>
					<th><?php esc_html_e( 'Аккаунт на сайте', 'vl-account' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Статус', 'vl-account' ); ?></th>
					<th style="width:150px"><?php esc_html_e( 'Основание', 'vl-account' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Записей нет. Запустите сверку с CRM.', 'vl-account' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $rows as $row ) : ?>
					<?php $user = $row['user_id'] ? get_user_by( 'id', (int) $row['user_id'] ) : false; ?>
					<tr>
						<td><?php echo esc_html( $row['phone'] ? VL_Account_Phone::format( $row['phone'] ) : '—' ); ?></td>
						<td>
							<?php echo esc_html( trim( $row['first_name'] . ' ' . $row['last_name'] ) ); ?>
							<br /><span class="description"><?php echo esc_html( $row['email'] ? $row['email'] : __( 'без почты', 'vl-account' ) ); ?></span>
						</td>
						<td>
							<?php if ( $user ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_login ); ?></a>
								<br /><span class="description"><?php echo esc_html( $user->user_email ); ?></span>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><span class="description"><?php echo esc_html( $row['note'] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
