<?php
/**
 * Кабинет: бонусы программы лояльности.
 *
 * Раздел работает в двух режимах:
 *  — программа лояльности RetailCRM подключена: баланс, уровень, сгорание и
 *    история берутся из CRM, здесь же можно вступить в программу и активировать её;
 *  — интеграция выключена: показываем локальный баланс из метаполя.
 *
 * @package VL_Account
 *
 * @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

$vl_state = class_exists( 'VL_Account_RetailCRM_Loyalty' )
	? VL_Account_RetailCRM_Loyalty::state( $user_id )
	: array( 'crm' => false, 'status' => 'off' );

$vl_crm      = ! empty( $vl_state['crm'] );
$vl_status   = isset( $vl_state['status'] ) ? $vl_state['status'] : 'off';
$vl_account  = isset( $vl_state['account'] ) ? $vl_state['account'] : array();
$vl_balance  = VL_Account_Bonus::get_balance( $user_id );
$vl_history  = VL_Account_Bonus::get_history( $user_id );
$vl_page     = (int) VL_Account_Settings::get( 'loyalty_page', 0 );
$vl_auto     = class_exists( 'VL_Account_RetailCRM_Loyalty' ) && VL_Account_RetailCRM_Loyalty::auto_enabled();
$vl_check    = isset( $vl_state['check_id'] ) ? (string) $vl_state['check_id'] : '';
$vl_discount = $vl_crm && class_exists( 'VL_Account_RetailCRM_Loyalty' )
	? VL_Account_RetailCRM_Loyalty::is_discount_level( $vl_account )
	: false;
?>
<div class="vl-bonus" data-vl-form="loyalty">

	<div class="vl-form__messages" data-vl-messages></div>

	<?php if ( $vl_crm && 'active' === $vl_status && $vl_discount ) : ?>

		<div class="vl-bonus__balance vl-bonus__balance--discount">
			<span class="vl-bonus__value"><?php echo esc_html( number_format_i18n( $vl_account['level']['size'], 0 ) ); ?>%</span>
			<span class="vl-bonus__label"><?php esc_html_e( 'ваша скидка по программе лояльности', 'vl-account' ); ?></span>
		</div>

	<?php elseif ( $vl_crm && 'active' === $vl_status ) : ?>

		<div class="vl-bonus__balance">
			<span class="vl-bonus__value"><?php echo esc_html( number_format_i18n( $vl_balance ) ); ?></span>
			<span class="vl-bonus__label"><?php esc_html_e( 'баллов на счету', 'vl-account' ); ?></span>
		</div>

	<?php elseif ( ! $vl_crm ) : ?>

		<div class="vl-bonus__balance">
			<span class="vl-bonus__value"><?php echo esc_html( number_format_i18n( $vl_balance ) ); ?></span>
			<span class="vl-bonus__label"><?php esc_html_e( 'баллов на счету', 'vl-account' ); ?></span>
		</div>

	<?php endif; ?>

	<?php if ( $vl_crm && 'active' === $vl_status ) : ?>

		<div class="vl-loyalty">
			<?php if ( ! empty( $vl_account['level']['name'] ) ) : ?>
				<p class="vl-loyalty__level">
					<?php
					printf(
						/* translators: %s — название уровня. */
						esc_html__( 'Уровень: %s', 'vl-account' ),
						'<strong>' . esc_html( $vl_account['level']['name'] ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php $vl_rules = VL_Account_RetailCRM_Loyalty::level_rules( $vl_account['level'], $vl_account['currency'] ); ?>

			<?php if ( $vl_rules ) : ?>
				<ul class="vl-loyalty__rules">
					<?php foreach ( $vl_rules as $vl_rule ) : ?>
						<li><?php echo esc_html( $vl_rule ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="vl-info-list">
				<?php if ( ! $vl_discount && ! empty( $vl_account['activation'] ) ) : ?>
					<?php
					// Партий ожидания бывает несколько: показываем всю сумму,
					// а дату — ближайшую, иначе часть баллов выглядит пропавшей.
					$vl_waiting = ! empty( $vl_account['activation_sum'] )
						? (float) $vl_account['activation_sum']
						: (float) $vl_account['activation']['amount'];
					?>
					<div class="vl-info-list__row">
						<span class="vl-info-list__label"><?php esc_html_e( 'Ждут активации', 'vl-account' ); ?></span>
						<span class="vl-info-list__value">
							<?php
							printf(
								/* translators: 1: баллы, 2: дата. */
								esc_html__( '%1$s — ближайшие станут доступны %2$s', 'vl-account' ),
								esc_html( number_format_i18n( $vl_waiting ) ),
								esc_html( $vl_account['activation']['date'] )
							);
							?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( ! $vl_discount && ! empty( $vl_account['burn'] ) ) : ?>
					<?php
					$vl_burning = ! empty( $vl_account['burn_sum'] )
						? (float) $vl_account['burn_sum']
						: (float) $vl_account['burn']['amount'];
					?>
					<div class="vl-info-list__row">
						<span class="vl-info-list__label"><?php esc_html_e( 'Сгорят', 'vl-account' ); ?></span>
						<span class="vl-info-list__value vl-negative">
							<?php
							printf(
								/* translators: 1: баллы, 2: дата. */
								esc_html__( '%1$s — до %2$s', 'vl-account' ),
								esc_html( number_format_i18n( $vl_burning ) ),
								esc_html( $vl_account['burn']['date'] )
							);
							?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( $vl_account['orders_sum'] > 0 ) : ?>
					<div class="vl-info-list__row">
						<span class="vl-info-list__label"><?php esc_html_e( 'Сумма покупок', 'vl-account' ); ?></span>
						<span class="vl-info-list__value">
							<?php echo esc_html( number_format_i18n( $vl_account['orders_sum'] ) . ' ' . $vl_account['currency'] ); ?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( $vl_account['next_level'] > $vl_account['orders_sum'] ) : ?>
					<div class="vl-info-list__row">
						<span class="vl-info-list__label"><?php esc_html_e( 'До следующего уровня', 'vl-account' ); ?></span>
						<span class="vl-info-list__value">
							<?php echo esc_html( number_format_i18n( $vl_account['next_level'] - $vl_account['orders_sum'] ) . ' ' . $vl_account['currency'] ); ?>
						</span>
					</div>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( $vl_crm && 'none' === $vl_status && ! $vl_auto ) : ?>

		<div class="vl-loyalty vl-loyalty--join">
			<h3 class="vl-subtitle"><?php esc_html_e( 'Программа лояльности', 'vl-account' ); ?></h3>
			<p class="vl-note"><?php esc_html_e( 'Вступите в программу — за покупки будем начислять баллы, их можно списать в корзине.', 'vl-account' ); ?></p>

			<div class="vl-field">
				<label class="vl-label" for="vl-loyalty-phone"><?php esc_html_e( 'Телефон', 'vl-account' ); ?></label>
				<input type="tel" id="vl-loyalty-phone" name="phone" class="vl-input" data-vl-phone
					value="<?php echo esc_attr( $vl_state['phone_masked'] ); ?>" />
			</div>

			<div class="vl-consents">
				<?php if ( ! empty( $vl_state['terms'] ) ) : ?>
					<label class="vl-check">
						<input type="checkbox" name="terms" value="1" />
						<span class="vl-check__box"></span>
						<span class="vl-check__text">
							<?php esc_html_e( 'Согласен с условиями программы лояльности', 'vl-account' ); ?>
							<?php if ( $vl_page && get_post_status( $vl_page ) === 'publish' ) : ?>
								— <a class="vl-link" href="<?php echo esc_url( get_permalink( $vl_page ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'прочитать', 'vl-account' ); ?></a>
							<?php endif; ?>
						</span>
					</label>
				<?php endif; ?>

				<?php if ( ! empty( $vl_state['privacy'] ) ) : ?>
					<label class="vl-check">
						<input type="checkbox" name="privacy" value="1" />
						<span class="vl-check__box"></span>
						<span class="vl-check__text"><?php esc_html_e( 'Согласен на обработку персональных данных', 'vl-account' ); ?></span>
					</label>
				<?php endif; ?>
			</div>

			<button type="button" class="vl-btn vl-btn--primary" data-vl-action="loyalty-join">
				<?php esc_html_e( 'вступить в программу', 'vl-account' ); ?>
			</button>
		</div>

	<?php elseif ( $vl_crm && 'none' === $vl_status ) : ?>

		<p class="vl-note">
			<?php esc_html_e( 'Оформляем участие в программе лояльности — баллы появятся здесь автоматически, обновите страницу через минуту.', 'vl-account' ); ?>
		</p>

	<?php elseif ( $vl_crm && 'inactive' === $vl_status && '' === $vl_check && $vl_auto ) : ?>

		<p class="vl-note">
			<?php esc_html_e( 'Участие оформляется — баллы появятся здесь автоматически, обновите страницу через минуту.', 'vl-account' ); ?>
		</p>

	<?php elseif ( $vl_crm && 'inactive' === $vl_status ) : ?>

		<?php // Кабинет пытался активировать участие сам, но CRM прислала свой код. ?>

		<div class="vl-loyalty vl-loyalty--activate">
			<h3 class="vl-subtitle"><?php esc_html_e( 'Осталось активировать участие', 'vl-account' ); ?></h3>

			<?php if ( '' !== $vl_check ) : ?>
				<p class="vl-note"><?php esc_html_e( 'Мы отправили код подтверждения в SMS — введите его, и баллы начнут начисляться.', 'vl-account' ); ?></p>
			<?php else : ?>
				<p class="vl-note"><?php esc_html_e( 'Участие оформлено, но ещё не активно. Подтвердите его — и баллы начнут начисляться.', 'vl-account' ); ?></p>
			<?php endif; ?>

			<div class="vl-step<?php echo '' === $vl_check ? ' is-active' : ''; ?>" data-vl-step="activate">
				<label class="vl-check">
					<input type="checkbox" name="loyalty_confirm" value="1" />
					<span class="vl-check__box"></span>
					<span class="vl-check__text"><?php esc_html_e( 'Подтверждаю участие в программе лояльности', 'vl-account' ); ?></span>
				</label>

				<button type="button" class="vl-btn vl-btn--primary" data-vl-action="loyalty-activate">
					<?php esc_html_e( 'активировать', 'vl-account' ); ?>
				</button>
			</div>

			<div class="vl-step<?php echo '' !== $vl_check ? ' is-active' : ''; ?>" data-vl-step="sms">
				<div class="vl-field">
					<label class="vl-label" for="vl-loyalty-code"><?php esc_html_e( 'Код из SMS', 'vl-account' ); ?></label>
					<input type="text" id="vl-loyalty-code" name="code" class="vl-input vl-input--code" inputmode="numeric" autocomplete="one-time-code" />
					<input type="hidden" name="check_id" value="<?php echo esc_attr( $vl_check ); ?>" />
				</div>

				<button type="button" class="vl-btn vl-btn--primary" data-vl-action="loyalty-confirm">
					<?php esc_html_e( 'подтвердить', 'vl-account' ); ?>
				</button>
			</div>
		</div>

	<?php elseif ( $vl_crm && 'error' === $vl_status ) : ?>

		<p class="vl-message vl-message--error">
			<?php esc_html_e( 'Не удалось получить данные программы лояльности. Показываем сохранённые значения — попробуйте обновить страницу позже.', 'vl-account' ); ?>
		</p>

	<?php endif; ?>

	<?php if ( $vl_page && get_post_status( $vl_page ) === 'publish' ) : ?>
		<p class="vl-links">
			<a class="vl-link" href="<?php echo esc_url( get_permalink( $vl_page ) ); ?>"><?php esc_html_e( 'Условия программы лояльности', 'vl-account' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( ! $vl_crm || in_array( $vl_status, array( 'active', 'error' ), true ) || $vl_history ) : ?>
		<h3 class="vl-subtitle"><?php esc_html_e( 'История', 'vl-account' ); ?></h3>

		<?php vlacc_template( 'parts/bonus-history.php', array( 'history' => $vl_history ) ); ?>
	<?php endif; ?>

	<?php if ( $vl_crm && in_array( $vl_status, array( 'active', 'error' ), true ) ) : ?>
		<p class="vl-links">
			<button type="button" class="vl-link vl-link--muted" data-vl-action="loyalty-refresh">
				<?php esc_html_e( 'Обновить данные из CRM', 'vl-account' ); ?>
			</button>
		</p>
	<?php endif; ?>
</div>
