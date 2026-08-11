/**
 * VL Account — логика форм входа/регистрации и кабинета.
 * Без внешних зависимостей.
 */
( function () {
	'use strict';

	if ( typeof window.VLACC === 'undefined' ) {
		return;
	}

	var cfg = window.VLACC;

	/* ------------------------------------------------------------------
	 * Вспомогательные функции
	 * ------------------------------------------------------------------ */

	function post( action, data ) {
		var body = new FormData();

		body.append( 'action', 'vlacc_' + action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			if ( data[ key ] !== undefined && data[ key ] !== null ) {
				body.append( key, data[ key ] );
			}
		} );

		return fetch( cfg.ajax_url, {
			method: 'POST',
			body: body,
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: { message: cfg.i18n.network } };
			} );
		} ).catch( function () {
			return { success: false, data: { message: cfg.i18n.network } };
		} );
	}

	function qs( root, selector ) {
		return root ? root.querySelector( selector ) : null;
	}

	function qsa( root, selector ) {
		return root ? Array.prototype.slice.call( root.querySelectorAll( selector ) ) : [];
	}

	function message( form, text, type ) {
		var box = qs( form, '[data-vl-messages]' );

		if ( ! box ) {
			return;
		}

		if ( ! text ) {
			box.innerHTML = '';
			return;
		}

		box.innerHTML = '<div class="vl-message vl-message--' + ( type || 'info' ) + '"></div>';
		box.firstChild.textContent = text;

		if ( typeof box.scrollIntoView === 'function' ) {
			box.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
	}

	function fieldErrors( form, errors ) {
		qsa( form, '.vl-field__error' ).forEach( function ( el ) {
			el.textContent = '';
			el.classList.remove( 'is-visible' );
		} );

		qsa( form, '.vl-input' ).forEach( function ( el ) {
			el.classList.remove( 'is-error' );
		} );

		if ( ! errors ) {
			return;
		}

		Object.keys( errors ).forEach( function ( name ) {
			var holder = qs( form, '[data-vl-error="' + name + '"]' );
			var input = qs( form, '[name="' + name + '"]' );

			if ( holder ) {
				holder.textContent = errors[ name ];
				holder.classList.add( 'is-visible' );
			}

			if ( input ) {
				input.classList.add( 'is-error' );
			}
		} );
	}

	function loading( button, state ) {
		if ( ! button ) {
			return;
		}

		if ( state ) {
			button.classList.add( 'is-loading' );
			button.dataset.vlLabel = button.textContent;
			button.textContent = cfg.i18n.sending;
		} else {
			button.classList.remove( 'is-loading' );

			if ( button.dataset.vlLabel ) {
				button.textContent = button.dataset.vlLabel;
				delete button.dataset.vlLabel;
			}
		}
	}

	function value( form, name ) {
		var el = qs( form, '[name="' + name + '"]' );

		return el ? el.value.trim() : '';
	}

	function checked( form, name ) {
		var el = qs( form, '[name="' + name + '"]' );

		return el && el.checked ? 1 : '';
	}

	function step( form, name ) {
		qsa( form, '[data-vl-step]' ).forEach( function ( el ) {
			el.classList.toggle( 'is-active', el.dataset.vlStep === name );
		} );

		var input = qs( form, '[data-vl-step="' + name + '"] input' );

		if ( input ) {
			setTimeout( function () {
				input.focus();
			}, 60 );
		}
	}

	function redirect( url ) {
		window.location.href = url || window.location.href;
	}

	/* ------------------------------------------------------------------
	 * Маска телефона
	 * ------------------------------------------------------------------ */

	function formatPhone( raw ) {
		var digits = ( raw || '' ).replace( /\D/g, '' );

		if ( ! digits ) {
			return '';
		}

		if ( digits[ 0 ] === '8' ) {
			digits = '7' + digits.slice( 1 );
		}

		if ( digits[ 0 ] === '9' ) {
			digits = '7' + digits;
		}

		if ( digits[ 0 ] !== '7' ) {
			return '+' + digits.slice( 0, 15 );
		}

		digits = digits.slice( 0, 11 );

		var out = '+7';

		if ( digits.length > 1 ) {
			out += ' (' + digits.slice( 1, 4 );
		}

		if ( digits.length >= 5 ) {
			out += ') ' + digits.slice( 4, 7 );
		}

		if ( digits.length >= 8 ) {
			out += '-' + digits.slice( 7, 9 );
		}

		if ( digits.length >= 10 ) {
			out += '-' + digits.slice( 9, 11 );
		}

		return out;
	}

	document.addEventListener( 'input', function ( event ) {
		var el = event.target;

		if ( ! el.matches || ! el.matches( '[data-vl-phone]' ) || ! cfg.phone_mask ) {
			return;
		}

		var start = el.selectionStart;
		var before = el.value.length;

		el.value = formatPhone( el.value );

		if ( start !== null && start < before ) {
			var shift = el.value.length - before;
			el.setSelectionRange( Math.max( 0, start + shift ), Math.max( 0, start + shift ) );
		}
	} );

	/* ------------------------------------------------------------------
	 * Таймер повторной отправки
	 * ------------------------------------------------------------------ */

	var timers = {};

	function startTimer( form, seconds ) {
		var button = qs( form, '[data-vl-action="resend"]' );

		if ( ! button ) {
			return;
		}

		var key = form.dataset.vlForm || 'form';
		var left = seconds || cfg.resend_wait;

		if ( timers[ key ] ) {
			clearInterval( timers[ key ] );
		}

		button.disabled = true;

		var tick = function () {
			if ( left <= 0 ) {
				clearInterval( timers[ key ] );
				button.disabled = false;
				button.textContent = cfg.i18n.resend;
				return;
			}

			button.textContent = cfg.i18n.wait.replace( '%d', left );
			left--;
		};

		tick();
		timers[ key ] = setInterval( tick, 1000 );
	}

	/* ------------------------------------------------------------------
	 * Действия
	 * ------------------------------------------------------------------ */

	function sendCode( form, button, purpose ) {
		var phone = value( form, 'phone' );

		fieldErrors( form, null );
		message( form, '' );

		if ( phone.replace( /\D/g, '' ).length < 10 ) {
			fieldErrors( form, { phone: cfg.i18n.bad_phone } );
			return;
		}

		loading( button, true );

		post( 'send_code', { phone: phone, purpose: purpose } ).then( function ( res ) {
			loading( button, false );

			if ( ! res.success ) {
				var data = res.data || {};

				message( form, data.message || cfg.i18n.network, 'error' );

				if ( data.wait ) {
					startTimer( form, data.wait );
				}

				if ( data.reload ) {
					setTimeout( function () {
						window.location.reload();
					}, 2500 );
				}

				return;
			}

			var payload = res.data || {};

			form.dataset.vlPhone = payload.phone || phone;

			var hint = qs( form, '[data-vl-code-hint]' );

			if ( hint ) {
				hint.textContent = payload.message || '';
			}

			var wrap = qs( form, '[data-vl-code-wrap]' );

			if ( wrap ) {
				wrap.hidden = false;
			}

			if ( qs( form, '[data-vl-step="code"]' ) ) {
				step( form, 'code' );
			}

			var codeInput = qs( form, '[data-vl-code]' );

			if ( codeInput ) {
				codeInput.value = '';
				setTimeout( function () {
					codeInput.focus();
				}, 60 );
			}

			startTimer( form, payload.wait );

			if ( payload.debug_code ) {
				message( form, 'DEBUG: код ' + payload.debug_code, 'info' );
			}
		} );
	}

	function verifyCode( form, button, purpose ) {
		var code = value( form, 'code' );
		var phone = form.dataset.vlPhone || value( form, 'phone' );

		fieldErrors( form, null );

		if ( ! code ) {
			fieldErrors( form, { code: cfg.i18n.bad_code.replace( '%d', cfg.code_length ) } );
			return;
		}

		loading( button, true );

		post( 'verify_code', {
			phone: phone,
			code: code,
			purpose: purpose,
			consent_marketing: checked( form, 'consent_marketing' )
		} ).then( function ( res ) {
			loading( button, false );

			if ( ! res.success ) {
				var err = ( res.data && res.data.message ) || cfg.i18n.network;

				fieldErrors( form, { code: err } );
				return;
			}

			var data = res.data || {};

			// Вход и регистрация — одно действие: незнакомый номер
			// регистрируется автоматически и сразу попадает в кабинет.
			if ( data.logged_in ) {
				message( form, data.message || '', 'success' );
				redirect( data.redirect );
			}
		} );
	}

	function switchPane( form, pane ) {
		var root = form.closest( '[data-vl-auth]' );

		if ( ! root ) {
			return null;
		}

		qsa( root, '[data-vl-pane]' ).forEach( function ( el ) {
			el.classList.toggle( 'is-active', el.dataset.vlPane === pane );
		} );

		qsa( root, '[data-vl-tab]' ).forEach( function ( el ) {
			el.classList.toggle( 'is-active', el.dataset.vlTab === pane );
		} );

		return qs( root, '[data-vl-pane="' + pane + '"] [data-vl-form]' );
	}

	function loginPassword( form, button ) {
		fieldErrors( form, null );
		message( form, '' );
		loading( button, true );

		post( 'login_password', {
			login: value( form, 'login' ),
			password: value( form, 'password' ),
			redirect_to: value( form, 'redirect_to' )
		} ).then( function ( res ) {
			loading( button, false );

			if ( ! res.success ) {
				message( form, ( res.data && res.data.message ) || cfg.i18n.network, 'error' );
				return;
			}

			redirect( res.data.redirect );
		} );
	}

	function lostPassword( form, button ) {
		fieldErrors( form, null );
		message( form, '' );

		var login = value( form, 'login' );

		if ( ! login ) {
			fieldErrors( form, { login: 'Укажите телефон или e-mail.' } );
			return;
		}

		loading( button, true );

		post( 'lost_password', { login: login } ).then( function ( res ) {
			loading( button, false );

			if ( ! res.success ) {
				message( form, ( res.data && res.data.message ) || cfg.i18n.network, 'error' );
				return;
			}

			var data = res.data || {};

			if ( data.mode === 'sms' ) {
				form.dataset.vlPhone = data.phone;

				var hint = qs( form, '[data-vl-code-hint]' );

				if ( hint ) {
					hint.textContent = data.message || '';
				}

				step( form, 'code' );
				startTimer( form, data.wait );

				if ( data.debug_code ) {
					message( form, 'DEBUG: код ' + data.debug_code, 'info' );
				}

				return;
			}

			message( form, data.message || '', 'success' );
		} );
	}

	function resetPassword( form, button ) {
		fieldErrors( form, null );
		message( form, '' );
		loading( button, true );

		post( 'reset_password', {
			phone: form.dataset.vlPhone || '',
			code: value( form, 'code' ),
			password: value( form, 'password' ),
			password2: value( form, 'password2' )
		} ).then( function ( res ) {
			loading( button, false );

			if ( ! res.success ) {
				message( form, ( res.data && res.data.message ) || cfg.i18n.network, 'error' );
				return;
			}

			message( form, res.data.message || '', 'success' );
			redirect( res.data.redirect );
		} );
	}

	function saveProfile( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'profile_save', {
			first_name: value( form, 'first_name' ),
			last_name: value( form, 'last_name' ),
			telegram: value( form, 'telegram' ),
			email: value( form, 'email' )
		} ).then( function ( res ) {
			loading( button, false );
			message( form, ( res.data && res.data.message ) || cfg.i18n.network, res.success ? 'success' : 'error' );
		} );
	}

	function savePassword( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'password_save', {
			current_password: value( form, 'current_password' ),
			password: value( form, 'password' ),
			password2: value( form, 'password2' )
		} ).then( function ( res ) {
			loading( button, false );
			message( form, ( res.data && res.data.message ) || cfg.i18n.network, res.success ? 'success' : 'error' );

			if ( res.success ) {
				qsa( form, 'input[type="password"]' ).forEach( function ( el ) {
					el.value = '';
				} );
			}
		} );
	}

	function resendEmail( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'resend_email', {} ).then( function ( res ) {
			loading( button, false );
			message( form, ( res.data && res.data.message ) || cfg.i18n.network, res.success ? 'success' : 'error' );
		} );
	}

	function saveConsents( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'consents_save', {
			consent_privacy: checked( form, 'consent_privacy' ),
			consent_marketing: checked( form, 'consent_marketing' )
		} ).then( function ( res ) {
			loading( button, false );
			message( form, ( res.data && res.data.message ) || cfg.i18n.network, res.success ? 'success' : 'error' );
		} );
	}

	/**
	 * Отписка от уведомления о поступлении размера.
	 */
	function removeSubscription( form, button ) {
		var id = button.dataset.id;

		if ( ! id ) {
			return;
		}

		message( form, '' );
		loading( button, true );

		post( 'subscription_remove', { id: id } ).then( function ( res ) {
			loading( button, false );

			if ( ! res.success ) {
				message( form, ( res.data && res.data.message ) || cfg.i18n.network, 'error' );
				return;
			}

			var row = document.querySelector( '[data-vl-subscription="' + id + '"]' );

			if ( row && row.parentNode ) {
				row.parentNode.removeChild( row );
			}

			message( form, ( res.data && res.data.message ) || '', 'success' );
		} );
	}

	/* ------------------------------------------------------------------
	 * Программа лояльности (RetailCRM)
	 * ------------------------------------------------------------------ */

	/**
	 * Общий обработчик: показать сообщение и, если нужно, перезагрузить страницу.
	 * Данные бонусов приходят с сервера, поэтому после действия страница обновляется.
	 */
	function loyaltyResult( form, button, res, silent ) {
		loading( button, false );

		if ( res.success && res.data && res.data.reload ) {
			if ( res.data.message ) {
				message( form, res.data.message, 'success' );
			}

			window.location.reload();
			return;
		}

		if ( ! res.success || ! silent ) {
			message( form, ( res.data && res.data.message ) || cfg.i18n.network, res.success ? 'success' : 'error' );
		}
	}

	function loyaltyJoin( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'loyalty_register', {
			phone: value( form, 'phone' ),
			terms: checked( form, 'terms' ),
			privacy: checked( form, 'privacy' )
		} ).then( function ( res ) {
			loyaltyResult( form, button, res );
		} );
	}

	function loyaltyActivate( form, button ) {
		if ( qs( form, '[name="loyalty_confirm"]' ) && ! checked( form, 'loyalty_confirm' ) ) {
			message( form, cfg.i18n.loyalty_confirm, 'error' );
			return;
		}

		message( form, '' );
		loading( button, true );

		post( 'loyalty_activate', {} ).then( function ( res ) {
			// CRM может попросить подтвердить активацию кодом из SMS.
			if ( res.success && res.data && res.data.sms ) {
				loading( button, false );

				var field = qs( form, '[name="check_id"]' );

				if ( field ) {
					field.value = res.data.check_id;
				}

				message( form, res.data.message || '', 'success' );
				step( form, 'sms' );
				return;
			}

			loyaltyResult( form, button, res );
		} );
	}

	function loyaltyConfirm( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'loyalty_confirm', {
			code: value( form, 'code' ),
			check_id: value( form, 'check_id' )
		} ).then( function ( res ) {
			loyaltyResult( form, button, res );
		} );
	}

	function loyaltyRefresh( form, button ) {
		loading( button, true );

		post( 'loyalty_refresh', {} ).then( function ( res ) {
			loyaltyResult( form, button, res, true );
		} );
	}

	function loyaltyCharge( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'loyalty_charge', { amount: value( form, 'amount' ) } ).then( function ( res ) {
			loyaltyResult( form, button, res );
		} );
	}

	function loyaltyChargeCancel( form, button ) {
		message( form, '' );
		loading( button, true );

		post( 'loyalty_charge_cancel', {} ).then( function ( res ) {
			loyaltyResult( form, button, res );
		} );
	}

	/* ------------------------------------------------------------------
	 * Выдвижная панель входа (справа)
	 * ------------------------------------------------------------------ */

	var drawerReturnFocus = null;
	var drawerMoved = false;

	function drawerNode() {
		var node = document.querySelector( '[data-vl-drawer]' );

		if ( ! node ) {
			return null;
		}

		// Переносим панель прямым потомком <body>.
		// Темы и конструкторы (Elementor и подобные) часто оборачивают подвал
		// в контейнер с transform/filter/overflow, а такой контейнер становится
		// точкой отсчёта для position:fixed — панель перестаёт цепляться
		// к экрану и уезжает вниз страницы.
		if ( ! drawerMoved && node.parentNode !== document.body ) {
			document.body.appendChild( node );
			drawerMoved = true;
		}

		return node;
	}

	/**
	 * Проверить, что панель действительно висит поверх экрана.
	 *
	 * Если стили плагина не доехали (объединение CSS, «удаление неиспользуемого
	 * CSS», кеш темы), панель окажется в обычном потоке внизу страницы.
	 * В этом случае проставляем минимально необходимое оформление прямо в атрибут
	 * style — так форма остаётся рабочей даже без внешнего файла стилей.
	 */
	function ensureDrawerStyles( node ) {
		var panel = qs( node, '.vl-drawer__panel' );
		var overlay = qs( node, '.vl-drawer__overlay' );

		if ( ! panel ) {
			return;
		}

		if ( window.getComputedStyle( node ).position !== 'fixed' ) {
			node.style.cssText = 'position:fixed;top:0;right:0;bottom:0;left:0;z-index:999990;margin:0;padding:0;';
		}

		if ( window.getComputedStyle( panel ).position !== 'absolute' ) {
			panel.style.cssText = 'position:absolute;top:0;right:0;width:440px;max-width:100%;height:100%;margin:0;background:#fff;overflow-y:auto;box-shadow:-6px 0 40px rgba(0,0,0,.12);padding:0;';

			if ( window.innerWidth <= 600 ) {
				panel.style.width = '100%';
			}

			var inner = qs( panel, '.vl-drawer__inner' );

			if ( inner ) {
				inner.style.padding = window.innerWidth <= 600 ? '52px 20px 32px' : '56px 40px 40px';
			}
		}

		if ( overlay && window.getComputedStyle( overlay ).position !== 'absolute' ) {
			overlay.style.cssText = 'position:absolute;top:0;right:0;bottom:0;left:0;background:rgba(0,0,0,.45);';
		}
	}

	function isLoggedIn() {
		return !! cfg.is_logged_in || document.body.classList.contains( 'logged-in' );
	}

	function openDrawer( text ) {
		var node = drawerNode();

		if ( ! node ) {
			return false;
		}

		drawerReturnFocus = document.activeElement;

		ensureDrawerStyles( node );

		var note = qs( node, '[data-vl-drawer-message]' );

		if ( note && text ) {
			note.textContent = text;
			note.hidden = false;
		}

		node.hidden = false;
		node.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'vl-drawer-open' );

		window.requestAnimationFrame( function () {
			node.classList.add( 'is-open' );
		} );

		setTimeout( function () {
			var field = qs( node, '.vl-auth__pane.is-active input:not([type="hidden"]):not(.vl-hp)' );

			if ( field ) {
				field.focus();
			}
		}, 340 );

		return true;
	}

	function closeDrawer() {
		var node = drawerNode();

		if ( ! node || node.hidden ) {
			return;
		}

		node.classList.remove( 'is-open' );
		node.setAttribute( 'aria-hidden', 'true' );
		document.body.classList.remove( 'vl-drawer-open' );

		setTimeout( function () {
			node.hidden = true;
		}, 320 );

		if ( drawerReturnFocus && drawerReturnFocus.focus ) {
			drawerReturnFocus.focus();
		}
	}

	/**
	 * Открыть вход: панелью, а если её на странице нет — страницей входа.
	 */
	function requestAuth( text, nextUrl ) {
		if ( openDrawer( text ) ) {
			return;
		}

		var url = cfg.auth_url || '';

		if ( ! url ) {
			return;
		}

		if ( nextUrl ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'redirect_to=' + encodeURIComponent( nextUrl );
		}

		window.location.href = url;
	}

	// Закрытие: крестик, подложка, Esc.
	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest && event.target.closest( '[data-vl-drawer-close]' ) ) {
			event.preventDefault();
			closeDrawer();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		var node = drawerNode();

		if ( ! node || node.hidden ) {
			return;
		}

		if ( event.key === 'Escape' ) {
			closeDrawer();
			return;
		}

		// Простая ловушка фокуса внутри панели.
		if ( event.key !== 'Tab' ) {
			return;
		}

		var focusable = qsa( node, 'a[href], button:not([disabled]), input:not([type="hidden"]):not(.vl-hp), select, textarea' ).filter( function ( el ) {
			return el.offsetParent !== null;
		} );

		if ( ! focusable.length ) {
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	/* ------------------------------------------------------------------
	 * Вход перед покупкой
	 * ------------------------------------------------------------------ */

	var PENDING_KEY = 'vlacc_pending';
	var PENDING_TTL = 15 * 60 * 1000;

	function gateEnabled() {
		return !! ( cfg.gate && cfg.gate.enabled ) && ! isLoggedIn();
	}

	/**
	 * Найти кнопку покупки, по которой кликнули.
	 */
	function matchGate( target ) {
		var selectors = ( cfg.gate && cfg.gate.selectors ) || [];

		for ( var i = 0; i < selectors.length; i++ ) {
			var found;

			try {
				found = target.closest( selectors[ i ] );
			} catch ( e ) {
				found = null;
			}

			if ( found ) {
				return found;
			}
		}

		return null;
	}

	/**
	 * Запомнить прерванное действие, чтобы повторить его после входа.
	 */
	function savePending( el ) {
		var data = {
			url: window.location.href,
			time: Date.now()
		};

		if ( el.tagName === 'A' && el.getAttribute( 'href' ) && el.getAttribute( 'href' ).charAt( 0 ) !== '#' ) {
			data.kind = 'link';
			data.href = el.href;
		} else {
			var form = el.closest( 'form' );
			var fields = [];
			var productId = el.getAttribute( 'data-product_id' ) || el.getAttribute( 'data-product-id' ) || '';

			if ( form ) {
				data.kind = 'form';
				data.action = form.getAttribute( 'action' ) || window.location.href;
				data.method = ( form.getAttribute( 'method' ) || 'post' ).toLowerCase();

				try {
					new FormData( form ).forEach( function ( fieldValue, fieldName ) {
						if ( typeof fieldValue === 'string' ) {
							fields.push( [ fieldName, fieldValue ] );
						}
					} );
				} catch ( e ) {
					fields = [];
				}

				// У кнопки может быть name="add-to-cart" value="ID".
				if ( el.name && el.value ) {
					fields.push( [ el.name, el.value ] );
				}
			} else if ( productId ) {
				data.kind = 'form';
				data.action = window.location.href;
				data.method = 'post';
			} else {
				return;
			}

			var hasAddToCart = fields.some( function ( pair ) {
				return pair[ 0 ] === 'add-to-cart';
			} );

			if ( ! hasAddToCart && productId ) {
				fields.push( [ 'add-to-cart', productId ] );
			}

			data.fields = fields;
		}

		try {
			window.sessionStorage.setItem( PENDING_KEY, JSON.stringify( data ) );
		} catch ( e ) {
			// Приватный режим — просто не сможем продолжить автоматически.
		}
	}

	/**
	 * Повторить действие после успешного входа.
	 */
	function replayPending() {
		if ( ! isLoggedIn() ) {
			return;
		}

		var raw = null;

		try {
			raw = window.sessionStorage.getItem( PENDING_KEY );
			window.sessionStorage.removeItem( PENDING_KEY );
		} catch ( e ) {
			return;
		}

		if ( ! raw ) {
			return;
		}

		var data;

		try {
			data = JSON.parse( raw );
		} catch ( e ) {
			return;
		}

		if ( ! data || ! data.kind || Date.now() - ( data.time || 0 ) > PENDING_TTL ) {
			return;
		}

		if ( data.kind === 'link' && data.href ) {
			window.location.href = data.href;
			return;
		}

		if ( data.kind === 'form' && data.fields && data.fields.length ) {
			var form = document.createElement( 'form' );

			form.method = data.method === 'get' ? 'get' : 'post';
			form.action = data.action || window.location.href;
			form.style.display = 'none';

			data.fields.forEach( function ( pair ) {
				var input = document.createElement( 'input' );

				input.type = 'hidden';
				input.name = pair[ 0 ];
				input.value = pair[ 1 ];
				form.appendChild( input );
			} );

			document.body.appendChild( form );
			form.submit();
		}
	}

	// Перехват на фазе захвата — раньше обработчиков темы и WooCommerce.
	document.addEventListener( 'click', function ( event ) {
		if ( ! gateEnabled() || ! event.target.closest ) {
			return;
		}

		// Внутри самой панели ничего не перехватываем.
		if ( event.target.closest( '[data-vl-drawer]' ) ) {
			return;
		}

		var button = matchGate( event.target );

		if ( ! button || button.disabled || button.classList.contains( 'disabled' ) ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		if ( event.stopImmediatePropagation ) {
			event.stopImmediatePropagation();
		}

		savePending( button );
		requestAuth( cfg.gate.message, window.location.href );
	}, true );

	// Любой элемент с data-vl-open-auth открывает панель входа.
	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest ? event.target.closest( '[data-vl-open-auth]' ) : null;

		if ( ! trigger || isLoggedIn() ) {
			return;
		}

		event.preventDefault();
		requestAuth( trigger.getAttribute( 'data-vl-auth-message' ) || '', window.location.href );
	}, true );

	function initGate() {
		replayPending();

		// Сервер вернул гостя со страницы оформления — сразу открываем панель.
		if ( ! isLoggedIn() && /[?&]vlacc_auth=1/.test( window.location.search ) ) {
			openDrawer( cfg.gate ? cfg.gate.message : '' );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initGate );
	} else {
		initGate();
	}

	/* ------------------------------------------------------------------
	 * Обработчики
	 * ------------------------------------------------------------------ */

	var actions = {
		'send-code': function ( form, button, el ) {
			sendCode( form, button, el.dataset.purpose || 'login' );
		},
		'resend': function ( form, button, el ) {
			sendCode( form, button, el.dataset.purpose || 'login' );
		},
		'verify-code': function ( form, button, el ) {
			verifyCode( form, button, el.dataset.purpose || 'login' );
		},
		'login-password': function ( form, button ) {
			loginPassword( form, button );
		},
		'lost-password': function ( form, button ) {
			lostPassword( form, button );
		},
		'reset-password': function ( form, button ) {
			resetPassword( form, button );
		},
		'save-profile': function ( form, button ) {
			saveProfile( form, button );
		},
		'save-password': function ( form, button ) {
			savePassword( form, button );
		},
		'save-consents': function ( form, button ) {
			saveConsents( form, button );
		},
		'resend-email': function ( form, button ) {
			resendEmail( form, button );
		},
		'change-phone': function ( form ) {
			step( form, 'phone' );
		},
		'show-password-login': function ( form ) {
			step( form, 'password' );
		},
		'show-sms-login': function ( form ) {
			step( form, 'phone' );
		},
		'show-lost': function ( form ) {
			switchPane( form, 'lost' );
		},
		'show-login': function ( form ) {
			switchPane( form, 'login' );
		},
		'loyalty-join': function ( form, button ) {
			loyaltyJoin( form, button );
		},
		'loyalty-activate': function ( form, button ) {
			loyaltyActivate( form, button );
		},
		'loyalty-confirm': function ( form, button ) {
			loyaltyConfirm( form, button );
		},
		'loyalty-refresh': function ( form, button ) {
			loyaltyRefresh( form, button );
		},
		'loyalty-charge': function ( form, button ) {
			loyaltyCharge( form, button );
		},
		'loyalty-charge-cancel': function ( form, button ) {
			loyaltyChargeCancel( form, button );
		},
		'subscription-remove': function ( form, button ) {
			removeSubscription( form, button );
		}
	};

	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest ? event.target.closest( '[data-vl-action]' ) : null;

		if ( el ) {
			var form = el.closest( '[data-vl-form]' );

			if ( form && actions[ el.dataset.vlAction ] ) {
				event.preventDefault();
				actions[ el.dataset.vlAction ]( form, el, el );
				return;
			}
		}

		// Переключение вкладок «войти / зарегистрироваться».
		var tab = event.target.closest ? event.target.closest( '[data-vl-tab]' ) : null;

		if ( tab ) {
			event.preventDefault();

			var root = tab.closest( '[data-vl-auth]' );

			qsa( root, '[data-vl-pane]' ).forEach( function ( pane ) {
				pane.classList.toggle( 'is-active', pane.dataset.vlPane === tab.dataset.vlTab );
			} );

			qsa( root, '[data-vl-tab]' ).forEach( function ( item ) {
				item.classList.toggle( 'is-active', item === tab );
			} );

			return;
		}

		// Меню кабинета на мобильных.
		var toggle = event.target.closest ? event.target.closest( '[data-vl-menu-toggle]' ) : null;

		if ( toggle ) {
			event.preventDefault();

			var account = toggle.closest( '[data-vl-account]' );
			var menu = qs( account, '[data-vl-menu]' );

			if ( menu ) {
				var open = menu.classList.toggle( 'is-open' );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}

			return;
		}

		// Показать/скрыть пароль.
		var eye = event.target.closest ? event.target.closest( '[data-vl-toggle-password]' ) : null;

		if ( eye ) {
			event.preventDefault();

			var input = qs( eye.parentNode, 'input' );

			if ( input ) {
				input.type = input.type === 'password' ? 'text' : 'password';
			}

			return;
		}

		// Копирование промокода.
		var copy = event.target.closest ? event.target.closest( '[data-vl-copy]' ) : null;

		if ( copy ) {
			event.preventDefault();

			var code = copy.dataset.vlCopy;

			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( code );
			}

			var original = copy.getAttribute( 'title' );
			copy.setAttribute( 'title', cfg.i18n.copied );

			setTimeout( function () {
				if ( original ) {
					copy.setAttribute( 'title', original );
				} else {
					copy.removeAttribute( 'title' );
				}
			}, 1500 );

			return;
		}

		// Избранное.
		var wish = event.target.closest ? event.target.closest( '[data-vl-wishlist]' ) : null;

		if ( wish ) {
			event.preventDefault();

			post( 'wishlist_toggle', { product_id: wish.dataset.vlWishlist } ).then( function ( res ) {
				if ( ! res.success ) {
					return;
				}

				var active = res.data.in_list;

				wish.classList.toggle( 'is-active', active );
				wish.setAttribute( 'aria-pressed', active ? 'true' : 'false' );

				var label = qs( wish, '.vl-wishlist-btn__label' );

				if ( label ) {
					label.textContent = active ? label.dataset.labelOn : label.dataset.labelOff;
				}

				qsa( document, '[data-vl-wishlist-count]' ).forEach( function ( counter ) {
					counter.textContent = res.data.count;
				} );
			} );

			return;
		}

		var remove = event.target.closest ? event.target.closest( '[data-vl-wishlist-remove]' ) : null;

		if ( remove ) {
			event.preventDefault();

			var id = remove.dataset.vlWishlistRemove;

			post( 'wishlist_remove', { product_id: id } ).then( function ( res ) {
				if ( ! res.success ) {
					return;
				}

				var item = document.querySelector( '[data-vl-wishlist-item="' + id + '"]' );

				if ( item ) {
					item.parentNode.removeChild( item );
				}

				qsa( document, '[data-vl-wishlist-count]' ).forEach( function ( counter ) {
					counter.textContent = res.data.count;
				} );

				var grid = document.querySelector( '[data-vl-wishlist-grid]' );

				if ( grid && ! grid.children.length ) {
					window.location.reload();
				}
			} );
		}
	} );

	// Отправка форм по Enter — используем основное действие формы.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest ? event.target.closest( '[data-vl-form]' ) : null;

		if ( ! form ) {
			return;
		}

		event.preventDefault();

		var activeStep = qs( form, '[data-vl-step].is-active' );
		var scope = activeStep || form;
		var button = qs( scope, 'button[type="submit"][data-vl-action]' ) || qs( scope, '[data-vl-action]' );

		if ( button && actions[ button.dataset.vlAction ] ) {
			actions[ button.dataset.vlAction ]( form, button, button );
		}
	} );

	// Автоотправка кода, когда введено нужное количество цифр.
	document.addEventListener( 'input', function ( event ) {
		var el = event.target;

		if ( ! el.matches || ! el.matches( '[data-vl-code]' ) ) {
			return;
		}

		el.value = el.value.replace( /\D/g, '' );

		var form = el.closest( '[data-vl-form]' );

		if ( ! form || el.value.length < cfg.code_length ) {
			return;
		}

		var button = qs( form, '[data-vl-action="verify-code"]' );

		if ( button && ! button.classList.contains( 'is-loading' ) ) {
			actions[ 'verify-code' ]( form, button, button );
		}
	} );

	// Подтверждение выхода.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '[data-vl-confirm-logout]' ) : null;

		if ( link && ! window.confirm( cfg.i18n.confirm_exit ) ) {
			event.preventDefault();
		}
	} );

	// Проверка e-mail на «уже зарегистрирован» при потере фокуса.
	document.addEventListener( 'blur', function ( event ) {
		var el = event.target;

		if ( ! el.matches || ! el.matches( '[data-vl-email]' ) || ! el.value ) {
			return;
		}

		var form = el.closest( '[data-vl-form]' );

		post( 'check_email', { email: el.value } ).then( function ( res ) {
			if ( res.success && res.data.exists ) {
				fieldErrors( form, { email: res.data.message } );
			}
		} );
	}, true );
}() );
