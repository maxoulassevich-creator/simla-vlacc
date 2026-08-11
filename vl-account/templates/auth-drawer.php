<?php
/**
 * Выдвижная панель входа/регистрации (справа, на всю высоту экрана).
 *
 * Переопределяется темой: wp-content/themes/ваша-тема/vl-account/auth-drawer.php
 *
 * @package VL_Account
 *
 * @var string $title    Заголовок панели.
 * @var string $message  Пояснение под заголовком.
 * @var string $redirect Куда вести после входа.
 */

defined( 'ABSPATH' ) || exit;

$vl_title    = isset( $title ) ? $title : '';
$vl_message  = isset( $message ) ? $message : '';
$vl_redirect = isset( $redirect ) ? $redirect : '';
?>
<?php
/*
 * Критические стили печатаем прямо здесь, а не только в подключаемом файле.
 *
 * Причина: на боевых сайтах плагины оптимизации (Autoptimize, WP Rocket,
 * LiteSpeed, Perfmatters — «объединить CSS», «удалить неиспользуемый CSS»)
 * и агрессивные стили темы регулярно ломают внешние стили. Без позиционирования
 * панель падает в обычный поток и появляется внизу страницы, под футером.
 * Этот блок идёт после стилей темы, поэтому выигрывает и при равной специфичности.
 */
?>
<style id="vl-drawer-critical">
.vl-drawer{position:fixed!important;top:0!important;right:0!important;bottom:0!important;left:0!important;width:auto!important;height:auto!important;margin:0!important;padding:0!important;z-index:999990!important;pointer-events:auto}
.vl-drawer[hidden]{display:none!important}
.vl-drawer__overlay{position:absolute!important;top:0;right:0;bottom:0;left:0;background:rgba(0,0,0,.45);opacity:0;transition:opacity .3s ease;cursor:pointer;margin:0!important}
.vl-drawer.is-open .vl-drawer__overlay{opacity:1}
.vl-drawer__panel{position:absolute!important;top:0!important;right:0!important;left:auto!important;bottom:auto!important;width:440px;max-width:100%;height:100%;height:100dvh;margin:0!important;background:#fff;box-shadow:-6px 0 40px rgba(0,0,0,.12);transform:translateX(100%);transition:transform .32s cubic-bezier(.22,.61,.36,1);overflow-y:auto;-webkit-overflow-scrolling:touch;display:flex;flex-direction:column;float:none!important;clear:both}
.vl-drawer.is-open .vl-drawer__panel{transform:translateX(0)}
.vl-drawer__inner{padding:56px 40px 40px;flex:1 1 auto}
.vl-drawer__close{position:absolute;top:14px;right:14px;width:40px;height:40px;border:0;background:none;cursor:pointer;padding:0;z-index:2;color:#333;min-height:0}
body.vl-drawer-open{overflow:hidden!important}
@media (max-width:600px){.vl-drawer__panel{width:100%}.vl-drawer__inner{padding:52px 20px 32px}}
@media (min-width:601px) and (max-width:1024px){.vl-drawer__panel{width:400px}.vl-drawer__inner{padding:56px 30px 34px}}
</style>
<div class="vl-drawer" data-vl-drawer hidden aria-hidden="true">
	<div class="vl-drawer__overlay" data-vl-drawer-close></div>

	<aside class="vl-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $vl_title ); ?>">
		<button type="button" class="vl-drawer__close" data-vl-drawer-close aria-label="<?php esc_attr_e( 'Закрыть', 'vl-account' ); ?>">
			<span aria-hidden="true"></span>
		</button>

		<div class="vl-drawer__inner">
			<?php if ( $vl_title ) : ?>
				<h2 class="vl-drawer__title"><?php echo esc_html( $vl_title ); ?></h2>
			<?php endif; ?>

			<?php if ( $vl_message ) : ?>
				<p class="vl-drawer__message" data-vl-drawer-message><?php echo esc_html( $vl_message ); ?></p>
			<?php else : ?>
				<p class="vl-drawer__message" data-vl-drawer-message hidden></p>
			<?php endif; ?>

			<div class="vl-drawer__body">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- разметка формируется шаблоном формы.
				echo VL_Account_Shortcodes::render_auth( array( 'redirect' => $vl_redirect ) );
				?>
			</div>
		</div>
	</aside>
</div>
