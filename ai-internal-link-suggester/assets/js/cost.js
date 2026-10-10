/**
 * Cost UI: interactive calculator and the pre-run confirmation dialog (vanilla JS).
 * Estimates come from the server's local pipeline; nothing here calls AI.
 */
( function () {
	'use strict';

	var A = window.ailsug || {};
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( s ) { return s; };
	var sprintf = i18n ? i18n.sprintf : function ( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return s.replace( /%(\d+\$)?[sd]/g, function () { return args.shift(); } );
	};
	var D = 'ai-internal-link-suggester';
	var num = function ( n ) { return Number( n || 0 ).toLocaleString(); };

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'class' === k ) {
				node.className = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function range( r ) {
		if ( ! r || ! r.known ) {
			return __( 'estimate unavailable (price unknown)', D );
		}
		var text = r.low === r.high ? A.money( r.high ) : A.money( r.low ) + ' – ' + A.money( r.high );
		return r.partial ? text + ' ' + __( '(some possible models have no known price)', D ) : text;
	}

	/* ---------- Calculator ---------- */

	var calc = document.querySelector( '.ailsug-calc' );
	if ( calc ) {
		var stats = JSON.parse( calc.getAttribute( 'data-stats' ) || '{}' );
		var timer = null;
		var sentence = calc.querySelector( '.ailsug-calc-sentence strong' );
		var note = calc.querySelector( '.ailsug-calc-note' );
		var how = calc.querySelector( '.ailsug-calc-assumptions' );

		var update = function () {
			var body = { keyword_share: stats.keyword_share };
			calc.querySelectorAll( '[data-calc]' ).forEach( function ( input ) {
				body[ input.getAttribute( 'data-calc' ) ] = 'tier' === input.getAttribute( 'data-calc' ) ? input.value : Math.max( 0, parseInt( input.value, 10 ) || 0 );
			} );
			if ( body.candidates < 1 ) { body.candidates = 1; }
			if ( body.suggestions < 1 ) { body.suggestions = 1; }
			A.api( 'estimate/calculator', 'POST', body ).then( function ( r ) {
				[ 'full_scan', 'per_post', 'monthly', 'per_sugg' ].forEach( function ( key ) {
					calc.querySelector( '[data-out="' + key + '"]' ).textContent = range( r[ key ] );
				} );
				sentence.textContent = r.full_scan.known
					? sprintf( /* translators: 1: pages, 2: cost range. */ __( 'Scanning your %1$s pages will cost about %2$s.', D ), num( body.posts ), range( r.full_scan ) )
					: __( 'A cost estimate is not available because the price of the AI model is unknown. Add it in the price table above.', D );
				var models = ( r.models || [] ).map( function ( m ) { return m.model; } );
				note.textContent = r.firm
					? __( 'You chose to use only one model, so this range only reflects how long your pages are.', D )
					: ( models.length
						? sprintf( /* translators: %s: model list. */ __( 'This is only an estimate. The range covers the models WordPress may use (%s) and normal variation in page length.', D ), models.join( ', ' ) )
						: __( 'Connect an AI provider to see estimates for the models you can use.', D ) );
				how.innerHTML = '';
				var ul = el( 'ul', { class: 'ul-disc' } );
				var a = r.assumptions;
				ul.appendChild( el( 'li', {}, __( 'AI does not read whole pages. To find a keyword it reads the title, headings and opening words; to choose links it reads only the few paragraphs that could hold a link, plus a short list of candidate pages.', D ) ) );
				ul.appendChild( el( 'li', {}, sprintf( /* translators: 1: tokens in, 2: tokens out. */ __( 'Finding a keyword: about %1$s tokens sent and %2$s received per page (a token is about ¾ of a word).', D ), num( r.tokens.keyword.input ), num( r.tokens.keyword.output ) ) ) );
				ul.appendChild( el( 'li', {}, sprintf( /* translators: 1: tokens in, 2: tokens out. */ __( 'Choosing links: about %1$s tokens sent and %2$s received per page.', D ), num( r.tokens.rank.input ), num( r.tokens.rank.output ) ) ) );
				ul.appendChild( el( 'li', {}, sprintf( /* translators: %s: percent. */ __( 'Pages that already have a keyword from you or your SEO plugin skip the keyword step (about %s%% of your pages need it).', D ), Math.round( ( stats.keyword_share || 0 ) * 100 ) ) ) );
				ul.appendChild( el( 'li', {}, sprintf( /* translators: 1: low %, 2: high %. */ __( 'The range runs from %1$s%% to %2$s%% of the typical size, at the cheapest and the most expensive possible model.', D ), Math.round( a.low_factor * 100 ), Math.round( a.high_factor * 100 ) ) ) );
				var cal = r.calibration;
				ul.appendChild( el( 'li', {}, cal.keyword.active || cal.rank.active
					? __( 'These numbers are corrected using your site\'s real AI usage so far.', D )
					: __( 'After 20 real AI requests, the numbers are corrected automatically using your site\'s real usage.', D ) ) );
				how.appendChild( ul );
			} ).catch( function ( e ) {
				sentence.textContent = e.message;
			} );
		};
		calc.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( update, 300 );
		} );
		update();
	}

	/* ---------- Pre-run confirmation ---------- */

	A.confirmRun = function ( type, button, start ) {
		var params = 'type=' + encodeURIComponent( type );
		var postTypes = JSON.parse( button.getAttribute( 'data-post-types' ) || 'null' );
		var postIds = JSON.parse( button.getAttribute( 'data-post-ids' ) || 'null' );
		( postTypes || [] ).forEach( function ( t ) { params += '&post_types[]=' + encodeURIComponent( t ); } );
		( postIds || [] ).forEach( function ( id ) { params += '&post_ids[]=' + encodeURIComponent( id ); } );

		var dialog = el( 'dialog', { class: 'ailsug-dialog', 'aria-labelledby': 'ailsug-dialog-title' } );
		dialog.appendChild( el( 'h2', { id: 'ailsug-dialog-title' }, __( 'Before AI reads your pages', D ) ) );
		var body = el( 'div', { class: 'ailsug-dialog-body', 'aria-live': 'polite' }, __( 'Working out the estimated cost…', D ) );
		dialog.appendChild( body );
		var actions = el( 'div', { class: 'ailsug-dialog-actions' } );
		var cancel = el( 'button', { type: 'button', class: 'button' }, __( 'Cancel', D ) );
		actions.appendChild( cancel );
		dialog.appendChild( actions );
		document.body.appendChild( dialog );
		var close = function () { dialog.close(); dialog.remove(); button.focus(); };
		cancel.addEventListener( 'click', close );
		dialog.addEventListener( 'cancel', function ( ev ) { ev.preventDefault(); close(); } );
		dialog.showModal();

		A.api( 'estimate/run?' + params ).then( function ( e ) {
			body.innerHTML = '';
			if ( ! e.posts ) {
				body.appendChild( el( 'p', {}, __( 'Nothing to do: every page is already up to date. No AI will be used.', D ) ) );
				cancel.textContent = __( 'Close', D );
				return;
			}
			var dl = el( 'dl', { class: 'ailsug-dialog-facts' } );
			var add = function ( k, v ) { dl.appendChild( el( 'dt', {}, k ) ); dl.appendChild( el( 'dd', {}, v ) ); };
			add( __( 'Pages', D ), num( e.posts ) + ( e.keyword_posts || e.rank_posts ? ' (' + sprintf( /* translators: 1: keyword pages, 2: link pages. */ __( '%1$s need a keyword, %2$s get link ideas', D ), num( e.keyword_posts ), num( e.rank_posts ) ) + ')' : '' ) );
			add( __( 'AI reads / writes', D ), sprintf( /* translators: 1: tokens in, 2: tokens out. */ __( 'about %1$s tokens in, %2$s out (a token is about ¾ of a word)', D ), num( e.input ), num( e.output ) ) );
			add( __( 'Estimated cost', D ), range( e ) );
			if ( e.likely ) {
				add( __( 'Likely model', D ), e.likely[ 0 ] + ' — ' + e.likely[ 1 ] + ( e.firm ? '' : ' ' + __( '(WordPress may use another connected model; costs are always worked out from the model that answers)', D ) ) );
			}
			add( __( 'Budget left this month', D ), null === e.remaining ? __( 'no cap set', D ) : A.money( e.remaining ) );
			body.appendChild( dl );
			body.appendChild( el( 'p', { class: 'description' }, __( 'This is only an estimate. Your provider\'s invoice is the source of truth.', D ) ) );

			if ( e.blocker ) {
				body.appendChild( el( 'p', { class: 'ailsug-dialog-error' }, e.blocker ) );
				return;
			}

			var ack = null;
			if ( e.needs_confirm ) {
				var label = el( 'label', { class: 'ailsug-dialog-ack' } );
				ack = el( 'input', { type: 'checkbox' } );
				label.appendChild( ack );
				label.appendChild( document.createTextNode( ' ' + sprintf( /* translators: %s: amount. */ __( 'I understand this run may cost up to %s.', D ), e.known ? A.money( e.high ) : __( 'an unknown amount', D ) ) ) );
				body.appendChild( label );
			}

			var go = function ( limit ) {
				close();
				start( { confirmed: true, limit: limit || 0, post_types: postTypes || undefined, post_ids: postIds || undefined } );
			};
			var runAll = el( 'button', { type: 'button', class: 'button button-primary' }, __( 'Run', D ) );
			if ( e.fits < e.posts ) {
				body.appendChild( el( 'p', { class: 'ailsug-dialog-error' }, sprintf( /* translators: 1: pages that fit, 2: total. */ __( 'Only %1$s of %2$s pages fit in the remaining budget.', D ), num( e.fits ), num( e.posts ) ) ) );
				runAll = el( 'button', { type: 'button', class: 'button button-primary' }, sprintf( /* translators: %s: pages. */ __( 'Run the first %s pages', D ), num( e.fits ) ) );
				runAll.addEventListener( 'click', function () { go( e.fits ); } );
			} else {
				runAll.addEventListener( 'click', function () { go( 0 ); } );
			}
			if ( ack ) {
				runAll.disabled = true;
				ack.addEventListener( 'change', function () { runAll.disabled = ! ack.checked; } );
			}
			actions.appendChild( runAll );
			( ack || runAll ).focus();
		} ).catch( function ( err ) {
			body.textContent = err.message;
		} );
	};

	window.ailsug = A;
}() );
