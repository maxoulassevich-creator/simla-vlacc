/**
 * VL Account — автозаполнение форм.
 *
 * Браузер подставляет сохранённые данные только в поля с правильным атрибутом
 * autocomplete. Темы и конструкторы форм его теряют или ставят autocomplete="off",
 * и покупатель вводит имя, телефон и адрес руками. Скрипт снимает запрет и
 * проставляет подходящее значение по имени поля.
 *
 * Форму входа по номеру телефона и одноразовые коды не трогаем.
 */
( function () {
	'use strict';

	var cfg    = window.VLACC_AUTOFILL || {};
	var ignore = cfg.ignore || [];

	// Значения, которые считаем «нет автозаполнения».
	var BLOCKED = [ '', 'off', 'nope', 'none', 'false', 'disabled', 'chrome-off', 'new-field' ];

	/**
	 * Поле по имени/идентификатору → значение autocomplete.
	 * Порядок важен: сначала точные совпадения, потом общие.
	 */
	var RULES = [
		[ /(^|_|-)(first[_-]?name|fname|imya|name_first)($|_|-)/i, 'given-name' ],
		[ /(^|_|-)(last[_-]?name|lname|surname|familiya)($|_|-)/i, 'family-name' ],
		[ /(^|_|-)(company|organization|organizaciya)($|_|-)/i, 'organization' ],
		[ /(^|_|-)(address[_-]?1|street|address)($|_|-)/i, 'address-line1' ],
		[ /(^|_|-)(address[_-]?2|apartment|kvartira)($|_|-)/i, 'address-line2' ],
		[ /(^|_|-)(city|gorod|town)($|_|-)/i, 'address-level2' ],
		[ /(^|_|-)(state|region|oblast|province)($|_|-)/i, 'address-level1' ],
		[ /(^|_|-)(postcode|zip|postal|index)($|_|-)/i, 'postal-code' ],
		[ /(^|_|-)(country|strana)($|_|-)/i, 'country' ],
		[ /(^|_|-)(email|e[_-]?mail|pochta)($|_|-)/i, 'email' ],
		[ /(^|_|-)(phone|tel|telefon|mobile)($|_|-)/i, 'tel' ],
		[ /(^|_|-)(name|fio|imya)($|_|-)/i, 'name' ]
	];

	function isIgnored( el ) {
		for ( var i = 0; i < ignore.length; i++ ) {
			try {
				if ( el.closest( ignore[ i ] ) ) {
					return true;
				}
			} catch ( e ) {
				// Некорректный селектор из фильтра — просто пропускаем.
			}
		}

		return false;
	}

	function blocked( value ) {
		return BLOCKED.indexOf( String( value || '' ).trim().toLowerCase() ) !== -1;
	}

	/**
	 * Подобрать значение autocomplete по типу и имени поля.
	 */
	function guess( field ) {
		var type = ( field.getAttribute( 'type' ) || 'text' ).toLowerCase();

		if ( 'email' === type ) {
			return 'email';
		}

		if ( 'tel' === type ) {
			return 'tel';
		}

		var haystack = [
			field.getAttribute( 'name' ) || '',
			field.getAttribute( 'id' ) || ''
		].join( ' ' );

		if ( ! haystack.trim() ) {
			return '';
		}

		for ( var i = 0; i < RULES.length; i++ ) {
			if ( RULES[ i ][ 0 ].test( haystack ) ) {
				return RULES[ i ][ 1 ];
			}
		}

		return '';
	}

	function fixField( field ) {
		var type = ( field.getAttribute( 'type' ) || 'text' ).toLowerCase();

		// Пароли, поиск, служебные и одноразовые поля оставляем как есть.
		if ( [ 'hidden', 'password', 'search', 'file', 'checkbox', 'radio', 'submit', 'button' ].indexOf( type ) !== -1 ) {
			return;
		}

		if ( field.classList.contains( 'vl-hp' ) || field.dataset.vlCode !== undefined ) {
			return;
		}

		var current = field.getAttribute( 'autocomplete' );

		if ( ! blocked( current ) ) {
			return; // Значение уже осмысленное — не мешаем.
		}

		var value = guess( field );

		if ( value ) {
			field.setAttribute( 'autocomplete', value );
		} else if ( 'off' === String( current || '' ).toLowerCase() ) {
			// Имя поля ничего не подсказало, но запрет снимаем: пусть браузер решает.
			field.setAttribute( 'autocomplete', 'on' );
		}
	}

	function scan( root ) {
		var scope = root && root.querySelectorAll ? root : document;

		// Запрет на уровне формы перекрывает поля — снимаем в первую очередь.
		Array.prototype.forEach.call( scope.querySelectorAll( 'form[autocomplete]' ), function ( form ) {
			if ( isIgnored( form ) ) {
				return;
			}

			if ( blocked( form.getAttribute( 'autocomplete' ) ) ) {
				form.setAttribute( 'autocomplete', 'on' );
			}
		} );

		Array.prototype.forEach.call( scope.querySelectorAll( 'input, select, textarea' ), function ( field ) {
			if ( isIgnored( field ) ) {
				return;
			}

			fixField( field );
		} );
	}

	function init() {
		scan( document );

		// Оформление заказа, попапы Elementor и корзины подгружаются позже.
		if ( window.MutationObserver ) {
			var pending = null;

			new MutationObserver( function () {
				if ( pending ) {
					return;
				}

				pending = window.setTimeout( function () {
					pending = null;
					scan( document );
				}, 200 );
			} ).observe( document.body, { childList: true, subtree: true } );
		}

		if ( window.jQuery ) {
			window.jQuery( document.body ).on(
				'updated_checkout updated_cart_totals country_to_state_changed',
				function () {
					window.setTimeout( function () {
						scan( document );
					}, 0 );
				}
			);
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
