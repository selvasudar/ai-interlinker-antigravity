/**
 * AI Internal Link Suggester admin script (vanilla JS, no external libraries).
 *
 * - Run loop: starts a run and processes it batch by batch over REST, so it works without WP-Cron.
 * - Inline keyword editing on the Keywords screen.
 */
( function () {
	'use strict';

	var cfg = window.ailsugAdmin || {};
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( s ) { return s; };
	var sprintf = i18n ? i18n.sprintf : function ( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return s.replace( /%(\d+\$)?[sd]/g, function () { return args.shift(); } );
	};
	var D = 'ai-internal-link-suggester';

	function api( path, method, body ) {
		return window.fetch( cfg.restUrl + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( data && data.message ? data.message : __( 'Something went wrong. Please try again.', D ) );
					err.data = data;
					throw err;
				}
				return data;
			} );
		} );
	}

	function money( micros ) {
		var usd = ( micros || 0 ) / 1000000;
		var text = '$' + ( usd < 0.01 && usd > 0 ? usd.toFixed( 4 ) : usd.toFixed( 2 ) );
		if ( cfg.currency2 && cfg.currency2Rate > 0 ) {
			text += ' (≈ ' + ( usd * cfg.currency2Rate ).toFixed( 2 ) + ' ' + cfg.currency2 + ')';
		}
		return text;
	}

	/* ---------- Run loop ---------- */

	var Runner = function ( box ) {
		this.box = box;
		this.label = box.querySelector( '.ailsug-progress-label' );
		this.bar = box.querySelector( '.ailsug-progress-bar' );
		this.pauseBtn = box.querySelector( '.ailsug-run-pause' );
		this.resumeBtn = box.querySelector( '.ailsug-run-resume' );
		this.cancelBtn = box.querySelector( '.ailsug-run-cancel' );
		this.runId = null;
		this.stopped = false;
		var self = this;
		this.pauseBtn.addEventListener( 'click', function () { self.control( 'pause' ); } );
		this.resumeBtn.addEventListener( 'click', function () { self.control( 'resume' ); } );
		this.cancelBtn.addEventListener( 'click', function () { self.control( 'cancel' ); } );
	};

	Runner.prototype.start = function ( type, extra ) {
		var self = this;
		var body = Object.assign( { type: type }, extra || {} );
		this.show();
		this.say( __( 'Starting…', D ) );
		return api( 'runs', 'POST', body ).then( function ( p ) {
			self.follow( p );
		} ).catch( function ( e ) {
			self.say( e.message );
		} );
	};

	Runner.prototype.follow = function ( progress ) {
		this.runId = progress.run_id;
		this.stopped = false;
		this.show();
		this.render( progress );
		if ( 'running' === progress.status ) {
			this.tick();
		}
	};

	Runner.prototype.tick = function () {
		var self = this;
		if ( this.stopped ) {
			return;
		}
		api( 'runs/' + this.runId + '/process', 'POST' ).then( function ( p ) {
			self.render( p );
			if ( 'running' === p.status && ! self.stopped ) {
				window.setTimeout( function () { self.tick(); }, 250 );
			}
		} ).catch( function ( e ) {
			self.say( e.message + ' ' + __( 'The run continues in the background; you can reload this page.', D ) );
		} );
	};

	Runner.prototype.control = function ( action ) {
		var self = this;
		if ( ! this.runId ) {
			return;
		}
		this.stopped = 'resume' !== action;
		api( 'runs/' + this.runId + '/' + action, 'POST' ).then( function ( p ) {
			self.render( p );
			if ( 'resume' === action && 'running' === p.status ) {
				self.tick();
			}
		} ).catch( function ( e ) { self.say( e.message ); } );
	};

	Runner.prototype.render = function ( p ) {
		var pct = p.total ? Math.round( ( p.processed / p.total ) * 100 ) : 100;
		this.bar.value = pct;
		this.bar.textContent = pct + '%';
		var noun = 'index' === p.type ? __( 'pages read', D ) : __( 'pages analyzed', D );
		var parts = [ sprintf( /* translators: 1: done, 2: total, 3: noun. */ __( '%1$s of %2$s %3$s', D ), p.processed, p.total, noun ) ];
		if ( p.spent_micros > 0 || 'index' !== p.type ) {
			parts.push( sprintf( /* translators: %s: amount. */ __( 'estimated spend so far: %s', D ), money( p.spent_micros ) ) );
		}
		if ( p.counts && p.counts.failed ) {
			parts.push( sprintf( /* translators: %s: count. */ __( '%s could not be processed', D ), p.counts.failed ) );
		}
		var status = {
			running: __( 'Working…', D ),
			paused: __( 'Paused.', D ),
			cancelled: __( 'Cancelled. Nothing more will run.', D ),
			done: __( 'Done.', D ),
			budget: __( 'Paused: AI spending has stopped because the estimated monthly budget is reached or a model price is unknown. See Settings → AI & Cost; the run continues once that is fixed.', D )
		}[ p.status ] || '';
		this.say( status + ' ' + parts.join( ' · ' ) );

		var active = 'running' === p.status || 'paused' === p.status;
		this.pauseBtn.hidden = 'running' !== p.status;
		this.resumeBtn.hidden = 'paused' !== p.status;
		this.cancelBtn.hidden = ! active;

		if ( 'done' === p.status ) {
			this.stopped = true;
			var link = this.box.querySelector( '.ailsug-progress-reload' );
			if ( ! link ) {
				link = document.createElement( 'a' );
				link.className = 'button button-primary ailsug-progress-reload';
				link.href = window.location.href;
				link.textContent = __( 'Show the results', D );
				this.box.appendChild( link );
			}
		}
		document.dispatchEvent( new window.CustomEvent( 'ailsug:progress', { detail: p } ) );
	};

	Runner.prototype.show = function () {
		this.box.hidden = false;
	};

	Runner.prototype.say = function ( text ) {
		this.label.textContent = text;
	};

	var box = document.querySelector( '.ailsug-progress' );
	var runner = box ? new Runner( box ) : null;

	window.ailsug = window.ailsug || {};
	window.ailsug.api = api;
	window.ailsug.money = money;
	window.ailsug.runner = runner;

	if ( runner && box.getAttribute( 'data-active-run' ) ) {
		api( 'runs/' + box.getAttribute( 'data-active-run' ) ).then( function ( p ) { runner.follow( p ); } ).catch( function () {} );
	}

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '.ailsug-start-run' ) : null;
		if ( ! btn || ! runner ) {
			return;
		}
		ev.preventDefault();
		var type = btn.getAttribute( 'data-run-type' );
		// Paid runs go through the cost confirmation first (see cost.js); free runs start directly.
		if ( window.ailsug.confirmRun && 'index' !== type ) {
			window.ailsug.confirmRun( type, btn, function ( extra ) { runner.start( type, extra ); } );
			return;
		}
		runner.start( type );
	} );

	/* ---------- Suggestions: preview and inline edit ---------- */

	function toggle( btn, panel ) {
		var open = panel.hidden;
		panel.hidden = ! open;
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		return open;
	}

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '.ailsug-preview-toggle' ) : null;
		if ( btn ) {
			var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			if ( toggle( btn, panel ) && ! panel.getAttribute( 'data-loaded' ) ) {
				panel.textContent = __( 'Loading…', D );
				api( 'suggestions/' + btn.getAttribute( 'data-id' ) + '/preview' ).then( function ( p ) {
					panel.textContent = '';
					var para = document.createElement( 'p' );
					para.className = 'ailsug-preview-text';
					para.appendChild( document.createTextNode( '…' + p.context.before ) );
					var mark = document.createElement( 'mark' );
					mark.textContent = p.context.match;
					para.appendChild( mark );
					para.appendChild( document.createTextNode( p.context.after + '…' ) );
					panel.appendChild( para );
					var to = document.createElement( 'p' );
					to.className = 'description';
					to.textContent = ( p.found ? '' : __( 'This text is no longer in the page as shown. ', D ) ) + sprintf( /* translators: 1: page title, 2: address. */ __( 'The highlighted words will link to "%1$s" (%2$s).', D ), p.target.title, p.target.url );
					panel.appendChild( to );
					panel.setAttribute( 'data-loaded', '1' );
				} ).catch( function ( e ) { panel.textContent = e.message; } );
			}
			return;
		}
		btn = ev.target.closest ? ev.target.closest( '.ailsug-edit-toggle' ) : null;
		if ( btn ) {
			var edit = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			if ( toggle( btn, edit ) ) {
				edit.querySelector( 'input' ).focus();
			}
			return;
		}
		btn = ev.target.closest ? ev.target.closest( '.ailsug-edit-save' ) : null;
		if ( btn ) {
			var box = btn.closest( '.ailsug-edit' );
			var status = box.querySelector( '.ailsug-edit-status' );
			btn.disabled = true;
			status.textContent = __( 'Checking…', D );
			api( 'suggestions/' + box.getAttribute( 'data-id' ), 'POST', {
				anchor_text: box.querySelector( '.ailsug-edit-anchor' ).value,
				target: box.querySelector( '.ailsug-edit-target' ).value
			} ).then( function () {
				status.textContent = __( 'Saved. Updating the list…', D );
				window.setTimeout( function () { window.location.reload(); }, 600 );
			} ).catch( function ( e ) {
				status.textContent = e.message;
				btn.disabled = false;
			} );
		}
	} );

	/* ---------- Inline keyword edit ---------- */

	function saveKeyword( wrap ) {
		var input = wrap.querySelector( '.ailsug-kw-input' );
		var status = wrap.querySelector( '.ailsug-kw-status' );
		var button = wrap.querySelector( '.ailsug-kw-save' );
		button.disabled = true;
		status.textContent = __( 'Saving…', D );
		api( 'keywords/' + wrap.getAttribute( 'data-post-id' ), 'POST', { keyword: input.value } ).then( function ( row ) {
			input.value = row.primary_keyword;
			status.textContent = '' === row.primary_keyword ? __( 'Cleared.', D ) : __( 'Saved.', D );
			var badgeCell = wrap.closest( 'tr' ) ? wrap.closest( 'tr' ).querySelector( '.column-source' ) : null;
			if ( badgeCell ) {
				var labels = { manual: __( 'You', D ), seo_plugin: __( 'SEO plugin', D ), ai: __( 'AI', D ) };
				badgeCell.innerHTML = '';
				var span = document.createElement( 'span' );
				span.className = 'ailsug-badge is-' + ( row.keyword_source ? row.keyword_source.replace( '_', '-' ) : 'none' );
				span.textContent = labels[ row.keyword_source ] || __( 'Not set', D );
				badgeCell.appendChild( span );
			}
		} ).catch( function ( e ) {
			status.textContent = e.message;
		} ).then( function () {
			button.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '.ailsug-kw-save' ) : null;
		if ( btn ) {
			saveKeyword( btn.closest( '.ailsug-kw-edit' ) );
		}
	} );
	document.addEventListener( 'keydown', function ( ev ) {
		if ( 'Enter' === ev.key && ev.target.classList && ev.target.classList.contains( 'ailsug-kw-input' ) ) {
			ev.preventDefault();
			saveKeyword( ev.target.closest( '.ailsug-kw-edit' ) );
		}
	} );
}() );
