/**
 * VL Account — размеры кнопками и доработки плагина WishSuite.
 *
 * 1. Выпадающий список размеров заменяется кнопками — и в карточке товара,
 *    и в быстрой корзине избранного WishSuite. Список остаётся в разметке
 *    (WooCommerce читает значение именно из него), но скрыт.
 * 2. Недоступные для выбранной комбинации размеры остаются видимыми, но
 *    неактивными: покупателю видно, каких размеров нет.
 * 3. Скрипт забирает на себя уже существующий контейнер .size-buttons, если
 *    его успел создать шаблон темы, — двух рядов кнопок не будет.
 * 4. Счётчик избранного в шапке обновляется после клика по сердечку WishSuite.
 * 5. Заглушка Яндекс.Метрики: WishSuite вызывает ym() без проверки, и если
 *    счётчик не загрузился (блокировщик, ошибка сети), клик по сердечку
 *    падал с ошибкой и товар не добавлялся.
 *
 * Без внешних зависимостей; jQuery используется, только если он уже есть.
 */
( function () {
	'use strict';

	var cfg = window.VLACC_WS || {};
	var acc = window.VLACC || {};

	/* ------------------------------------------------------------------
	 * Заглушка счётчика Метрики
	 * ------------------------------------------------------------------ */

	if ( typeof window.ym !== 'function' ) {
		window.ym = function () {};
	}

	/* ------------------------------------------------------------------
	 * Размеры кнопками
	 * ------------------------------------------------------------------ */

	var ATTR_PREFIX = 'attribute_';
	var SELECTOR    = 'form.variations_form select[name^="' + ATTR_PREFIX + '"]';
	var QUICK_CART  = '.wishsuite-quick-cart-area, [data-vl-size-buttons]';
	var BOXES       = '.vl-size-buttons, .size-buttons';

	function sizeButtonsOn() {
		return false !== cfg.size_buttons;
	}

	function isAllowed( select ) {
		var list = cfg.attributes || [];

		if ( ! list.length ) {
			return true;
		}

		var name = ( select.getAttribute( 'name' ) || '' ).replace( ATTR_PREFIX, '' ).toLowerCase();

		return list.indexOf( name ) !== -1;
	}

	/**
	 * Полный список вариантов запоминаем при первом проходе: WooCommerce
	 * вычищает из списка недоступные варианты, а нам их нужно показать серыми.
	 */
	function allOptions( select ) {
		var current = Array.prototype.slice.call( select.options )
			.filter( function ( option ) {
				return option.value;
			} )
			.map( function ( option ) {
				return { value: option.value, label: option.textContent };
			} );

		if ( ! select.vlOptions || ! select.vlOptions.length ) {
			select.vlOptions = current;

			return select.vlOptions;
		}

		// Появился вариант, которого раньше не было — добавим в конец.
		current.forEach( function ( option ) {
			var known = select.vlOptions.some( function ( item ) {
				return item.value === option.value;
			} );

			if ( ! known ) {
				select.vlOptions.push( option );
			}
		} );

		return select.vlOptions;
	}

	function availableValues( select ) {
		return Array.prototype.slice.call( select.options )
			.filter( function ( option ) {
				return option.value && ! option.disabled;
			} )
			.map( function ( option ) {
				return option.value;
			} );
	}

	/**
	 * Контейнер кнопок: свой, ранее созданный или созданный шаблоном темы.
	 * Лишние контейнеры (например, от инлайнового скрипта) убираем.
	 */
	function boxFor( select ) {
		var scope = ( select.closest ? select.closest( 'td.value' ) : null ) || select.parentNode;

		if ( ! scope ) {
			return null;
		}

		var existing = Array.prototype.slice.call( scope.querySelectorAll( BOXES ) );
		var box      = existing.shift();

		existing.forEach( function ( extra ) {
			if ( extra.parentNode ) {
				extra.parentNode.removeChild( extra );
			}
		} );

		if ( ! box ) {
			box = document.createElement( 'div' );
			// Класс size-buttons оставлен для совместимости с вёрсткой темы.
			box.className = 'size-buttons vl-size-buttons';
			scope.insertBefore( box, select );
		} else if ( box.className.indexOf( 'vl-size-buttons' ) === -1 ) {
			box.className += ' vl-size-buttons';
		}

		return box;
	}

	function choose( select, value ) {
		select.value = value;

		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		if ( window.jQuery ) {
			var $select = window.jQuery( select );

			$select.trigger( 'change' );
			$select.closest( 'form.variations_form' ).trigger( 'check_variations' );
		}

		render( select );
	}

	function render( select ) {
		if ( ! isAllowed( select ) ) {
			return;
		}

		var options = allOptions( select );

		if ( ! options.length ) {
			return;
		}

		var box = boxFor( select );

		if ( ! box ) {
			return;
		}

		var enabled = availableValues( select );

		// Перерисовываем, только если что-то изменилось: иначе наблюдатель за DOM
		// поймает нашу же правку и уйдёт в бесконечный цикл.
		var signature = options.map( function ( option ) {
			return option.value;
		} ).join( '|' ) + '::' + enabled.join( '|' ) + '::' + select.value;

		if ( box.getAttribute( 'data-vl-sig' ) === signature ) {
			return;
		}

		box.innerHTML = '';

		options.forEach( function ( option ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			// Класс size-button оставлен для совместимости с вёрсткой темы.
			button.className = 'size-button vl-size-button';
			button.textContent = option.label;
			button.setAttribute( 'data-value', option.value );

			if ( enabled.indexOf( option.value ) === -1 ) {
				button.disabled = true;
				button.classList.add( 'is-disabled' );

				if ( cfg.i18n && cfg.i18n.out_of_stock ) {
					button.title = cfg.i18n.out_of_stock;
				}
			}

			if ( select.value === option.value ) {
				button.classList.add( 'active', 'is-active' );
			}

			button.addEventListener( 'click', function () {
				if ( button.disabled ) {
					return;
				}

				choose( select, option.value );
			} );

			box.appendChild( button );
		} );

		box.setAttribute( 'data-vl-sig', signature );

		select.style.display = 'none';
	}

	function scan() {
		if ( ! sizeButtonsOn() ) {
			return;
		}

		var selects = 'all' === cfg.scope
			? document.querySelectorAll( SELECTOR )
			: quickCartSelects();

		Array.prototype.forEach.call( selects, function ( select ) {
			render( select );
		} );
	}

	function quickCartSelects() {
		var result = [];
		var areas  = document.querySelectorAll( QUICK_CART );

		Array.prototype.forEach.call( areas, function ( area ) {
			Array.prototype.push.apply( result, area.querySelectorAll( SELECTOR ) );
		} );

		return result;
	}

	/* ------------------------------------------------------------------
	 * Счётчик избранного
	 * ------------------------------------------------------------------ */

	function refreshCount() {
		var nodes = document.querySelectorAll( '[data-vl-wishlist-count]' );

		if ( ! nodes.length || ! acc.ajax_url || ! acc.nonce ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'vlacc_wishlist_count' );
		body.append( 'nonce', acc.nonce );

		fetch( acc.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success || ! result.data ) {
					return;
				}

				Array.prototype.forEach.call( nodes, function ( node ) {
					node.textContent = result.data.count;
				} );
			} )
			.catch( function () {} );
	}

	function isWishSuiteRequest( settings ) {
		if ( ! settings ) {
			return false;
		}

		var haystack = '';

		if ( typeof settings.url === 'string' ) {
			haystack += settings.url;
		}

		if ( typeof settings.data === 'string' ) {
			haystack += settings.data;
		} else if ( settings.data && typeof settings.data === 'object' && settings.data.action ) {
			haystack += settings.data.action;
		}

		return haystack.indexOf( 'wishsuite_add_to_list' ) !== -1
			|| haystack.indexOf( 'wishsuite_remove_from_list' ) !== -1;
	}

	/* ------------------------------------------------------------------
	 * Запуск
	 * ------------------------------------------------------------------ */

	function init() {
		scan();

		// Форма вариаций приезжает по AJAX, а инлайновый скрипт темы может
		// добавить свои кнопки позже — следим за изменениями разметки.
		if ( window.MutationObserver ) {
			var pending = null;

			var observer = new MutationObserver( function () {
				if ( pending ) {
					return;
				}

				pending = window.setTimeout( function () {
					pending = null;
					scan();
				}, 60 );
			} );

			observer.observe( document.body, { childList: true, subtree: true } );
		}

		if ( ! window.jQuery ) {
			return;
		}

		var $ = window.jQuery;

		// WooCommerce пересобирает список доступных вариантов — обновляем кнопки.
		$( document.body ).on(
			'woocommerce_update_variation_values reset_data show_variation hide_variation wc_variation_form',
			function () {
				window.setTimeout( scan, 0 );
			}
		);

		// Инлайновый скрипт темы отрабатывает на ready — проходим после него.
		$( function () {
			window.setTimeout( scan, 0 );
		} );

		// Сердечко WishSuite отработало — обновляем наш счётчик.
		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( isWishSuiteRequest( settings ) ) {
				refreshCount();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
