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

			case 'unlink':
				$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
				$users = isset( $_POST['users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['users'] ) ) : array();
				$count = 0;

				foreach ( $users as $user_id ) {
					$count += self::unlink_phone( $user_id, $phone ) ? 1 : 0;
				}

				$notice = 'unlinked_' . $count;
				break;

			case 'unlink_many':
				$pairs = isset( $_POST['pairs'] ) ? (array) wp_unslash( $_POST['pairs'] ) : array();
				$count = 0;

				foreach ( $pairs as $pair ) {
					$parts = explode( '|', sanitize_text_field( $pair ) );

					if ( 2 !== count( $parts ) ) {
						continue;
					}

					$count += self::unlink_phone( (int) $parts[0], $parts[1] ) ? 1 : 0;
				}

				$notice = 'unlinked_' . $count;
				break;

			case 'undo_backfill':
				$count  = self::undo_backfill();
				$notice = 'unlinked_' . $count;
				break;

			case 'forget':
				$phone  = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
				$count  = VL_Account_RetailCRM_Directory::forget_phone( $phone );
				$notice = 'forgot_' . $count;

				VL_Account_Identity::log(
					'forget',
					array(
						'phone'  => VL_Account_Phone::normalize( $phone ),
						'source' => 'admin',
						'note'   => 'карточки номера удалены из снимка: ' . $count,
					)
				);
				break;
		}

		$url = admin_url( 'admin.php?page=' . self::PAGE . '&vlacc_msg=' . rawurlencode( $notice ) );

		// После разбора номера возвращаемся к его же проверке — видно результат.
		if ( in_array( $action, array( 'unlink', 'forget' ), true ) && ! empty( $_POST['phone'] ) ) {
			$url .= '&probe=' . rawurlencode( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Размер пачки для разовых операций по всей базе.
	 */
	const BATCH = 500;

	/**
	 * Опция со смещением для разовых операций пачками.
	 */
	const OFFSET_OPTION = 'vlacc_identity_offset';

	/**
	 * Сколько строк уже обработано в этой операции.
	 *
	 * @param string $key Операция.
	 * @return int
	 */
	protected static function offset( $key ) {
		$all = get_option( self::OFFSET_OPTION, array() );

		return is_array( $all ) && isset( $all[ $key ] ) ? (int) $all[ $key ] : 0;
	}

	/**
	 * Запомнить смещение (0 — операция закончена).
	 *
	 * @param string $key   Операция.
	 * @param int    $value Смещение.
	 */
	protected static function set_offset( $key, $value ) {
		$all = get_option( self::OFFSET_OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $key ] = (int) $value;

		update_option( self::OFFSET_OPTION, $all, false );
	}

	/**
	 * Дописать телефоны из справочника в профили аккаунтов.
	 *
	 * Идём пачками по 500 строк: на базе в тысячи аккаунтов один заход
	 * упирался бы в максимальное время выполнения PHP и обрывался посреди
	 * работы. Смещение запоминается, следующее нажатие продолжает с него.
	 *
	 * @return int Сколько аккаунтов получили номер.
	 */
	public static function backfill_phones() {
		$offset  = self::offset( 'backfill' );
		$rows    = VL_Account_RetailCRM_Directory::query(
			array(
				'status' => 'matched',
				'limit'  => self::BATCH,
				'offset' => $offset,
			)
		);
		$updated = 0;

		// Пачка кончилась — в следующий раз начинаем сначала.
		self::set_offset( 'backfill', count( $rows ) < self::BATCH ? 0 : $offset + self::BATCH );

		// Номера, на которых в CRM намешаны разные люди, не разносим по профилям.
		$mixed = array_flip( VL_Account_RetailCRM_Directory::mixed_phones() );

		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$phone   = VL_Account_Phone::normalize( $row['phone'] );

			if ( ! $user_id || '' === $phone || isset( $mixed[ $phone ] ) ) {
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
	 * Аккаунты, у которых этот номер записан в профиле.
	 *
	 * @param string $phone Номер в любом виде.
	 * @return array WP_User[].
	 */
	public static function phone_holders( $phone ) {
		$phone = VL_Account_Phone::normalize( $phone );

		if ( '' === $phone ) {
			return array();
		}

		$users = get_users(
			array(
				'meta_key'   => VL_Account_User::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $phone,                      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 20,
			)
		);

		return is_array( $users ) ? $users : array();
	}

	/**
	 * Снять номер с аккаунта.
	 *
	 * Разбор последствий неверного сопоставления: пока номер стоит в профиле,
	 * вход по SMS будет приводить в этот аккаунт, что бы ни лежало в CRM.
	 *
	 * @param int    $user_id Аккаунт.
	 * @param string $phone   Номер в любом виде.
	 * @return bool
	 */
	public static function unlink_phone( $user_id, $phone ) {
		$user_id = (int) $user_id;
		$phone   = VL_Account_Phone::normalize( $phone );

		if ( ! $user_id || '' === $phone ) {
			return false;
		}

		if ( (string) get_user_meta( $user_id, VL_Account_User::META_PHONE, true ) !== $phone ) {
			return false;
		}

		delete_user_meta( $user_id, VL_Account_User::META_PHONE );
		delete_user_meta( $user_id, VL_Account_User::META_VERIFIED );

		// Телефон плательщика трогаем только если это тот же номер.
		if ( VL_Account_Phone::normalize( (string) get_user_meta( $user_id, 'billing_phone', true ) ) === $phone ) {
			delete_user_meta( $user_id, 'billing_phone' );
		}

		VL_Account_Identity::log(
			'unlink',
			array(
				'phone'   => $phone,
				'user_id' => $user_id,
				'source'  => 'admin',
				'note'    => 'номер снят с аккаунта вручную',
			)
		);

		VL_Account_Identity::flush_cache();

		if ( class_exists( 'VL_Account_RetailCRM' ) ) {
			VL_Account_RetailCRM::flush( $user_id );
		}

		return true;
	}

	/**
	 * Что плагин записал в чужие профили: телефоны и чем они подтверждаются.
	 *
	 * Телефон в аккаунт пишут ровно два места: вход по найденному аккаунту
	 * (событие match) и кнопка «Дописать телефоны в аккаунты» (событие
	 * backfill). Оба пишутся в журнал, поэтому список всегда полный —
	 * остальные записи номера идут из собственных заказов аккаунта и
	 * подозрений не вызывают.
	 *
	 * @param int $limit Сколько записей журнала просмотреть.
	 * @return array Строки: user, phone, source, verdict, note.
	 */
	public static function audit_phones( $limit = 200 ) {
		$rows = VL_Account_Identity::log_query(
			array(
				'event' => array( 'match', 'backfill' ),
				'limit' => (int) $limit,
			)
		);

		$seen   = array();
		$result = array();

		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$phone   = VL_Account_Phone::normalize( $row['phone'] );
			$key     = $user_id . '-' . $phone;

			if ( ! $user_id || '' === $phone || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			// Номер уже сняли или заменили — строка неактуальна.
			if ( (string) get_user_meta( $user_id, VL_Account_User::META_PHONE, true ) !== $phone ) {
				continue;
			}

			$user = get_user_by( 'id', $user_id );

			if ( ! $user ) {
				continue;
			}

			$check = self::phone_evidence( $user, $phone );

			$result[] = array(
				'user'    => $user,
				'phone'   => $phone,
				'source'  => (string) $row['event'],
				'date'    => (string) $row['created'],
				'verdict' => $check['verdict'],
				'note'    => $check['note'],
			);
		}

		// Сомнительные — наверх, с ними и надо разбираться.
		usort(
			$result,
			static function ( $a, $b ) {
				$weight = array(
					'bad'     => 0,
					'unknown' => 1,
					'ok'      => 2,
				);

				return $weight[ $a['verdict'] ] - $weight[ $b['verdict'] ];
			}
		);

		return $result;
	}

	/**
	 * Чем подтверждается, что номер принадлежит владельцу аккаунта.
	 *
	 * @param WP_User $user  Аккаунт.
	 * @param string  $phone Нормализованный номер.
	 * @return array ['verdict' => ok|unknown|bad, 'note' => string]
	 */
	public static function phone_evidence( $user, $phone ) {
		// 1. Заказ самого аккаунта с этим номером — доказательство надёжнее нет.
		if ( class_exists( 'VL_Account_Orders' ) && vlacc_is_woo() ) {
			foreach ( (array) VL_Account_Orders::find_orders_by_phone( $phone ) as $order ) {
				if ( $order instanceof WC_Order && (int) $order->get_customer_id() === (int) $user->ID ) {
					return array(
						'verdict' => 'ok',
						'note'    => sprintf(
							/* translators: %d — номер заказа. */
							__( 'подтверждён заказом №%d этого аккаунта', 'vl-account' ),
							$order->get_id()
						),
					);
				}
			}
		}

		if ( ! class_exists( 'VL_Account_RetailCRM_Directory' ) ) {
			return array(
				'verdict' => 'unknown',
				'note'    => __( 'проверить нечем: заказов с этим номером у аккаунта нет', 'vl-account' ),
			);
		}

		$rows     = VL_Account_RetailCRM_Directory::rows_by_phone( $phone );
		$combined = VL_Account_RetailCRM_Directory::combine( $rows );

		// 2. На номере разные люди — записывать его в профиль было ошибкой.
		if ( $combined ) {
			$reason = VL_Account_RetailCRM_Directory::conflict_reason( $combined );

			if ( '' !== $reason ) {
				return array(
					'verdict' => 'bad',
					'note'    => $reason,
				);
			}

			// 3. Карточка CRM с этим номером принадлежит владельцу аккаунта.
			$email = isset( $combined['email'] ) ? strtolower( (string) $combined['email'] ) : '';

			if ( '' !== $email && strtolower( $user->user_email ) === $email ) {
				return array(
					'verdict' => 'ok',
					'note'    => __( 'подтверждён карточкой CRM с той же почтой', 'vl-account' ),
				);
			}

			if ( '' !== $email ) {
				return array(
					'verdict' => 'bad',
					'note'    => sprintf(
						/* translators: %s — почта из карточки CRM. */
						__( 'в карточке CRM с этим номером другая почта: %s', 'vl-account' ),
						$email
					),
				);
			}
		}

		return array(
			'verdict' => 'unknown',
			'note'    => __( 'заказов с этим номером нет, в CRM подтверждения тоже нет', 'vl-account' ),
		);
	}

	/**
	 * Откатить кнопку «Дописать телефоны в аккаунты».
	 *
	 * Снимает номера ровно с тех аккаунтов, которым их записала эта кнопка,
	 * и только если номер с тех пор не менялся.
	 *
	 * @return int Сколько аккаунтов вычищено.
	 */
	public static function undo_backfill() {
		$offset = self::offset( 'undo' );
		$rows   = VL_Account_Identity::log_query(
			array(
				'event'  => 'backfill',
				'limit'  => self::BATCH,
				'offset' => $offset,
			)
		);
		$count  = 0;

		self::set_offset( 'undo', count( $rows ) < self::BATCH ? 0 : $offset + self::BATCH );

		foreach ( $rows as $row ) {
			if ( self::unlink_phone( (int) $row['user_id'], (string) $row['phone'] ) ) {
				++$count;
			}
		}

		return $count;
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

		// 2. Заказы сайта с этим номером. Без WooCommerce искать нечем:
		// wc_get_orders() тогда не существует, и экран падал бы с фаталом.
		$orders = ( class_exists( 'VL_Account_Orders' ) && vlacc_is_woo() )
			? VL_Account_Orders::find_orders_by_phone( $normalized )
			: array();

		$report[ __( 'Заказы сайта с этим номером', 'vl-account' ) ] = $orders
			? count( $orders )
			: __( 'нет', 'vl-account' );

		// 3. Живой опрос CRM: все карточки этого номера кладём в снимок и
		// сопоставляем с аккаунтами — так проверка заодно чинит данные.
		$api = VL_Account_RetailCRM::enabled() ? VL_Account_RetailCRM::api() : false;

		if ( $api ) {
			$customers = VL_Account_RetailCRM_Directory::collect_from_api( $api, $normalized );
			$live      = array();

			foreach ( $customers as $customer ) {
				VL_Account_RetailCRM_Directory::store( $customer );

				$live[] = sprintf(
					'id %d, externalId %s, %s %s, %s',
					isset( $customer['id'] ) ? (int) $customer['id'] : 0,
					! empty( $customer['externalId'] ) ? (int) $customer['externalId'] : '—',
					isset( $customer['firstName'] ) ? $customer['firstName'] : '',
					isset( $customer['lastName'] ) ? $customer['lastName'] : '',
					isset( $customer['email'] ) ? $customer['email'] : '—'
				);
			}

			foreach ( VL_Account_RetailCRM_Directory::rows_by_phone( $normalized ) as $row ) {
				VL_Account_RetailCRM_Directory::match_row( $row );
			}

			VL_Account_RetailCRM_Directory::flush_cache();
			VL_Account_Identity::flush_cache();

			$report[ __( 'Клиент в CRM', 'vl-account' ) ] = $live
				? implode( '; ', $live )
				: __( 'не найден ни одним способом (см. журнал плагина — там записана каждая попытка)', 'vl-account' );
		}

		// 4. Снимок базы CRM: все карточки с этим номером, от свежей к старой.
		$rows  = VL_Account_RetailCRM_Directory::rows_by_phone( $normalized );
		$cards = array();

		foreach ( $rows as $row ) {
			$cards[] = sprintf(
				'клиент %d (заведён %s), externalId %s, аккаунт %d, %s',
				(int) $row['crm_id'],
				( empty( $row['crm_created'] ) || 0 === strpos( (string) $row['crm_created'], '0000' ) ) ? '—' : $row['crm_created'],
				! empty( $row['external_id'] ) ? (int) $row['external_id'] : '—',
				(int) $row['user_id'],
				$row['email'] ? $row['email'] : '—'
			);
		}

		$report[ __( 'В снимке базы CRM', 'vl-account' ) ] = $cards
			? implode( '; ', $cards )
			: __( 'нет записи', 'vl-account' );

		// 4.1. У кого этот номер записан в профиле — именно они перехватывают вход.
		$holders = array();

		foreach ( self::phone_holders( $normalized ) as $holder ) {
			$holders[] = sprintf( '#%d — %s (%s)', $holder->ID, $holder->user_login, $holder->user_email );
		}

		$report[ __( 'Номер записан в профиле', 'vl-account' ) ] = $holders
			? implode( '; ', $holders )
			: __( 'ни у кого', 'vl-account' );

		// 5. Что плагин соберёт из этих карточек и куда пустит покупателя.
		$combined = VL_Account_RetailCRM_Directory::combine( $rows );

		if ( $combined ) {
			$reason     = VL_Account_RetailCRM_Directory::conflict_reason( $combined );
			$candidates = VL_Account_Identity::crm_candidates( $combined );

			$report[ __( 'Номер пригоден для поиска', 'vl-account' ) ] = '' === $reason
				? __( 'да — карточки принадлежат одному человеку', 'vl-account' )
				: sprintf(
					/* translators: %s — причина. */
					__( 'НЕТ: %s. По такому номеру плагин никуда не пускает и данные из CRM не берёт.', 'vl-account' ),
					$reason
				);

			$report[ __( 'Склеенная карточка', 'vl-account' ) ] = sprintf(
				'%s %s, %s, город %s, карточек: %d',
				$combined['first_name'],
				$combined['last_name'],
				$combined['email'] ? $combined['email'] : '—',
				$combined['city'] ? $combined['city'] : '—',
				count( isset( $combined['crm_ids'] ) ? $combined['crm_ids'] : array() )
			);

			$chosen = '' === $reason ? VL_Account_Identity::pick_account( $candidates, $normalized ) : 0;

			$report[ __( 'Аккаунты-кандидаты', 'vl-account' ) ] = $candidates
				? implode( ', ', $candidates ) . sprintf( ' → выбран %s', $chosen ? '#' . $chosen : __( 'ни один', 'vl-account' ) )
				: __( 'нет', 'vl-account' );
		}

		if ( ! $api ) {
			$report[ __( 'Связь с CRM', 'vl-account' ) ] = __( 'выключена или не настроена — дальше проверять нечего', 'vl-account' );

			return $report;
		}

		// 6. Участие в программе лояльности по номеру: счетов может быть несколько.
		$accounts = VL_Account_RetailCRM_Directory::loyalty_accounts_by_phone( $api, $normalized );
		$account  = VL_Account_RetailCRM::pick_loyalty( $accounts );
		$lines    = array();

		foreach ( $accounts as $item ) {
			$card_id  = VL_Account_RetailCRM_Directory::customer_id_from_account( $item );
			$card     = $card_id ? VL_Account_RetailCRM_Directory::fetch_customer( $api, $card_id ) : false;
			$card_has = is_array( $card ) && VL_Account_RetailCRM_Directory::has_phone( $card, $normalized );

			$lines[] = sprintf(
				'счёт %d, %s, баллов: %s, уровень: %s; карточка CRM %d (externalId %s, %s %s), номер в карточке: %s%s',
				isset( $item['id'] ) ? (int) $item['id'] : 0,
				empty( $item['active'] ) ? __( 'не активировано', 'vl-account' ) : __( 'активно', 'vl-account' ),
				isset( $item['amount'] ) ? (string) $item['amount'] : '0',
				isset( $item['level']['name'] ) ? $item['level']['name'] : '—',
				$card_id,
				( is_array( $card ) && ! empty( $card['externalId'] ) ) ? (int) $card['externalId'] : '—',
				is_array( $card ) && isset( $card['firstName'] ) ? $card['firstName'] : '',
				is_array( $card ) && isset( $card['lastName'] ) ? $card['lastName'] : '',
				$card_has ? __( 'тот же', 'vl-account' ) : __( 'ДРУГОЙ — счёт заведён на чужую карточку, кабинет его не возьмёт', 'vl-account' ),
				( $account && isset( $account['id'], $item['id'] ) && $account['id'] === $item['id'] && $card_has ) ? ' ← берём этот' : ''
			);
		}

		$report[ __( 'Участие в программе лояльности', 'vl-account' ) ] = $lines
			? implode( '; ', $lines )
			: __( 'по номеру не найдено', 'vl-account' );

		// 7. Что увидит кабинет. Аккаунт мог найтись только сейчас — после того,
		// как карточки CRM попали в снимок; строку отчёта тогда переписываем.
		if ( ! $user ) {
			$user = VL_Account_User::get_by_phone( $normalized );

			if ( $user ) {
				$report[ __( 'Аккаунт на сайте', 'vl-account' ) ] = sprintf(
					'#%d — %s (%s) — найден по карточке CRM',
					$user->ID,
					$user->user_login,
					$user->user_email
				);
			}
		}

		if ( $user ) {
			// Сначала смотрим, что лежит в кэше: именно это видит покупатель.
			$before = VL_Account_RetailCRM::cached( $user->ID );

			VL_Account_RetailCRM::flush( $user->ID );
			$state = VL_Account_RetailCRM::account( $user->ID, true );

			$report[ __( 'Кабинет покажет', 'vl-account' ) ] = sprintf(
				'статус «%s», доступно баллов: %s; ждут активации: %s; сгорят: %s',
				$state['status'],
				number_format_i18n( $state['amount'] ),
				number_format_i18n( $state['activation_sum'] ),
				number_format_i18n( $state['burn_sum'] )
			);

			$report[ __( 'Было в кэше кабинета', 'vl-account' ) ] = null === $before
				? __( 'пусто — кабинет спросит CRM при первом же открытии', 'vl-account' )
				: sprintf(
					/* translators: 1: баллы из кэша, 2: секунды жизни кэша. */
					__( '%1$s баллов (кэш живёт %2$d сек. — столько данные могут отставать от CRM)', 'vl-account' ),
					number_format_i18n( $before['amount'] ),
					(int) VL_Account_RetailCRM::cache_ttl()
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
				__( 'Телефон дописан в профили: %d. Операция идёт пачками по 500 записей — если в снимке их больше, нажмите кнопку ещё раз.', 'vl-account' ),
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

		if ( 0 === strpos( $code, 'unlinked_' ) ) {
			return sprintf(
				/* translators: %d — сколько аккаунтов. */
				__( 'Номер снят с аккаунтов: %d.', 'vl-account' ),
				(int) substr( $code, 9 )
			);
		}

		if ( 0 === strpos( $code, 'forgot_' ) ) {
			return sprintf(
				/* translators: %d — сколько строк снимка. */
				__( 'Карточек номера удалено из снимка: %d.', 'vl-account' ),
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
				<table class="widefat striped" style="max-width:900px;margin-bottom:12px">
					<tbody>
						<?php foreach ( self::probe( $probe ) as $vl_label => $vl_value ) : ?>
							<tr>
								<td style="width:280px"><strong><?php echo esc_html( $vl_label ); ?></strong></td>
								<td><?php echo esc_html( is_scalar( $vl_value ) ? (string) $vl_value : wp_json_encode( $vl_value ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php $vl_holders = self::phone_holders( $probe ); ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px;margin-bottom:24px">
					<?php wp_nonce_field( 'vlacc_identity' ); ?>
					<input type="hidden" name="action" value="vlacc_identity_action" />
					<input type="hidden" name="phone" value="<?php echo esc_attr( $probe ); ?>" />

					<?php if ( $vl_holders ) : ?>
						<p><strong><?php esc_html_e( 'Снять номер с аккаунта:', 'vl-account' ); ?></strong></p>

						<?php foreach ( $vl_holders as $vl_holder ) : ?>
							<label style="display:block;margin-bottom:4px">
								<input type="checkbox" name="users[]" value="<?php echo esc_attr( $vl_holder->ID ); ?>" />
								<?php echo esc_html( sprintf( '#%d — %s (%s)', $vl_holder->ID, $vl_holder->user_login, $vl_holder->user_email ) ); ?>
							</label>
						<?php endforeach; ?>

						<p>
							<button class="button" name="do" value="unlink" onclick="return confirm('<?php echo esc_js( __( 'Снять номер с отмеченных аккаунтов? Сами аккаунты, заказы и баллы останутся на месте.', 'vl-account' ) ); ?>')">
								<?php esc_html_e( 'Отвязать номер', 'vl-account' ); ?>
							</button>
							<button class="button" name="do" value="forget" onclick="return confirm('<?php echo esc_js( __( 'Удалить карточки этого номера из снимка базы CRM? В самой CRM ничего не изменится.', 'vl-account' ) ); ?>')">
								<?php esc_html_e( 'Забыть карточки номера', 'vl-account' ); ?>
							</button>
						</p>
					<?php else : ?>
						<p>
							<button class="button" name="do" value="forget" onclick="return confirm('<?php echo esc_js( __( 'Удалить карточки этого номера из снимка базы CRM? В самой CRM ничего не изменится.', 'vl-account' ) ); ?>')">
								<?php esc_html_e( 'Забыть карточки номера', 'vl-account' ); ?>
							</button>
						</p>
					<?php endif; ?>

					<p class="description">
						<?php esc_html_e( 'Разбор неверного сопоставления. «Отвязать номер» убирает телефон из профиля аккаунта — после этого вход по SMS больше не приводит в него. «Забыть карточки номера» удаляет строки этого телефона из снимка базы CRM: нужно, если в CRM на одном номере оказались разные люди. Данные в самой CRM ни одна из кнопок не меняет.', 'vl-account' ); ?>
					</p>
				</form>
			<?php endif; ?>

			<h2 id="vlacc-audit"><?php esc_html_e( 'Телефоны, записанные плагином', 'vl-account' ); ?></h2>

			<p class="description" style="max-width:900px">
				<?php esc_html_e( 'Номер в профиль аккаунта попадает от плагина ровно в двух случаях: вход по найденному аккаунту и кнопка «Дописать телефоны в аккаунты». Оба случая записаны в журнал, поэтому список полный. Для каждой записи видно, чем номер подтверждается: заказом самого аккаунта или карточкой CRM с той же почтой. Сомнительные записи — сверху; отметьте и снимите номер, аккаунт при этом не пострадает.', 'vl-account' ); ?>
			</p>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$vl_show_audit = ! empty( $_GET['audit'] );
			$vl_audit      = $vl_show_audit ? self::audit_phones() : array();
			?>

			<?php if ( ! $vl_show_audit ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&audit=1#vlacc-audit' ) ); ?>">
						<?php esc_html_e( 'Показать записанные телефоны', 'vl-account' ); ?>
					</a>
					<span class="description">
						<?php esc_html_e( 'Проверка каждой записи спрашивает заказы и снимок CRM, поэтому список строится по кнопке, а не при каждом открытии экрана.', 'vl-account' ); ?>
					</span>
				</p>
			<?php else : ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px">
				<?php wp_nonce_field( 'vlacc_identity' ); ?>
				<input type="hidden" name="action" value="vlacc_identity_action" />

				<table class="widefat striped" style="max-width:1100px;margin-bottom:12px">
					<thead>
						<tr>
							<th style="width:28px"></th>
							<th><?php esc_html_e( 'Аккаунт', 'vl-account' ); ?></th>
							<th><?php esc_html_e( 'Номер', 'vl-account' ); ?></th>
							<th><?php esc_html_e( 'Откуда', 'vl-account' ); ?></th>
							<th><?php esc_html_e( 'Подтверждение', 'vl-account' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $vl_audit ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'Плагин никому телефон не записывал.', 'vl-account' ); ?></td></tr>
						<?php endif; ?>

						<?php foreach ( $vl_audit as $vl_row ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="pairs[]" value="<?php echo esc_attr( $vl_row['user']->ID . '|' . $vl_row['phone'] ); ?>"
										<?php checked( 'bad', $vl_row['verdict'] ); ?> />
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_user_link( $vl_row['user']->ID ) ); ?>">
										#<?php echo (int) $vl_row['user']->ID; ?> — <?php echo esc_html( $vl_row['user']->user_login ); ?>
									</a>
									<br /><span class="description"><?php echo esc_html( $vl_row['user']->user_email ); ?></span>
								</td>
								<td><?php echo esc_html( VL_Account_Phone::format( $vl_row['phone'] ) ); ?></td>
								<td>
									<?php echo esc_html( 'backfill' === $vl_row['source'] ? __( 'кнопка «Дописать телефоны»', 'vl-account' ) : __( 'вход по номеру', 'vl-account' ) ); ?>
									<br /><span class="description"><?php echo esc_html( $vl_row['date'] ); ?></span>
								</td>
								<td>
									<?php if ( 'bad' === $vl_row['verdict'] ) : ?>
										<span style="color:#d40000"><strong><?php esc_html_e( 'сомнительно', 'vl-account' ); ?></strong></span> —
									<?php elseif ( 'ok' === $vl_row['verdict'] ) : ?>
										<span style="color:#1a7f37"><strong><?php esc_html_e( 'подтверждён', 'vl-account' ); ?></strong></span> —
									<?php endif; ?>
									<?php echo esc_html( $vl_row['note'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<button class="button" name="do" value="unlink_many" onclick="return confirm('<?php echo esc_js( __( 'Снять номер с отмеченных аккаунтов? Аккаунты, заказы и баллы останутся на месте.', 'vl-account' ) ); ?>')">
					<?php esc_html_e( 'Отвязать номера от отмеченных', 'vl-account' ); ?>
				</button>
				<button class="button" name="do" value="undo_backfill" onclick="return confirm('<?php echo esc_js( __( 'Снять все номера, записанные кнопкой «Дописать телефоны в аккаунты»? Затронет только те аккаунты, где номер с тех пор не менялся.', 'vl-account' ) ); ?>')">
					<?php esc_html_e( 'Откатить «Дописать телефоны»', 'vl-account' ); ?>
				</button>
			</form>

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
