/* PandaBot admin JS — settings test-connection buttons, knowledge page
 * reindex control, and manual Q&A add/delete.
 *
 * REST URL + nonce come from data-* attributes on ".pandabot-app" wrapper
 * elements rather than wp_localize_script's inline <script> tag, so this
 * still works if a strict CSP (host or a security plugin) blocks inline
 * scripts but allows this external file.
 */
( function () {
	'use strict';

	function restCall( method, url, nonce, body ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce }
		};
		if ( body ) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( url, opts )
			.then( function ( r ) { return r.json(); } )
			.catch( function () { return null; } );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var app = document.querySelector( '.pandabot-app' );
		if ( ! app ) {
			return;
		}
		var restUrl = app.getAttribute( 'data-rest-url' );
		var nonce = app.getAttribute( 'data-nonce' );

		initTestConnection( restUrl, nonce );
		initReindex( restUrl, nonce );
		initManualQa( restUrl, nonce );
		initTuning( restUrl, nonce );
		initSourceManagement( restUrl, nonce );
		initChatTester( restUrl, nonce );
	} );

	function initSourceManagement( restUrl, nonce ) {
		document.querySelectorAll( '.pandabot-exclude-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'להחריג את הפריט הזה מהאינדוקס? הקטעים שלו יוסרו מיד.' ) ) {
					return;
				}
				var id = btn.getAttribute( 'data-id' );
				btn.disabled = true;
				restCall( 'POST', restUrl + 'admin/exclude-source', nonce, { id: parseInt( id, 10 ) } ).then( function () {
					window.location.reload();
				} );
			} );
		} );

		document.querySelectorAll( '.pandabot-include-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = btn.getAttribute( 'data-id' );
				btn.disabled = true;
				restCall( 'POST', restUrl + 'admin/include-source', nonce, { id: parseInt( id, 10 ) } ).then( function () {
					window.location.reload();
				} );
			} );
		} );
	}

	function initTuning( restUrl, nonce ) {
		var saveBtn = document.getElementById( 'pandabot-tune-save' );
		if ( ! saveBtn ) {
			return;
		}
		var floorInput = document.getElementById( 'pandabot-tune-floor' );
		var topkInput = document.getElementById( 'pandabot-tune-topk' );
		var maxTokensInput = document.getElementById( 'pandabot-tune-maxtokens' );
		var resultEl = document.getElementById( 'pandabot-tune-result' );

		saveBtn.addEventListener( 'click', function () {
			saveBtn.disabled = true;
			restCall( 'POST', restUrl + 'admin/tuning', nonce, {
				similarity_floor: parseFloat( floorInput.value ),
				top_k: parseInt( topkInput.value, 10 ),
				max_tokens: parseInt( maxTokensInput.value, 10 )
			} ).then( function ( res ) {
				saveBtn.disabled = false;
				if ( res && res.success ) {
					resultEl.textContent = 'נשמר.';
					resultEl.className = 'pandabot-test-result is-success';
				} else {
					resultEl.textContent = 'שגיאה בשמירה.';
					resultEl.className = 'pandabot-test-result is-error';
				}
			} );
		} );
	}

	function uuid4() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function initTestConnection( restUrl, nonce ) {
		var buttons = document.querySelectorAll( '.pandabot-test-btn' );
		if ( ! buttons.length ) {
			return;
		}

		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var which = btn.getAttribute( 'data-provider' );
				var resultEl = document.querySelector( '.pandabot-test-result[data-provider="' + which + '"]' );
				var original = btn.textContent;

				btn.disabled = true;
				btn.textContent = 'בודק…';
				if ( resultEl ) {
					resultEl.textContent = '';
					resultEl.className = 'pandabot-test-result';
				}

				restCall( 'POST', restUrl + 'admin/test-provider', nonce, { provider: which } ).then( function ( data ) {
					btn.disabled = false;
					btn.textContent = original;
					if ( ! resultEl ) {
						return;
					}
					if ( ! data ) {
						resultEl.textContent = 'שגיאת תקשורת בבדיקה.';
						resultEl.className = 'pandabot-test-result is-error';
						return;
					}
					resultEl.textContent = data.message || '';
					resultEl.className = 'pandabot-test-result ' + ( data.success ? 'is-success' : 'is-error' );

					if ( data.success && which === 'embeddings' && data.dimension ) {
						var dimField = document.getElementById( 'emb_dimension' );
						if ( dimField ) {
							dimField.value = data.dimension;
						}
					}
				} );
			} );
		} );
	}

	function initReindex( restUrl, nonce ) {
		var btn = document.getElementById( 'pandabot-reindex-btn' );
		if ( ! btn ) {
			return;
		}
		var progressWrap = document.getElementById( 'pandabot-reindex-progress' );
		var progressFill = document.getElementById( 'pandabot-progress-fill' );
		var progressText = document.getElementById( 'pandabot-progress-text' );

		function runStep() {
			restCall( 'POST', restUrl + 'admin/reindex/step', nonce, {} ).then( function ( res ) {
				if ( ! res ) {
					progressText.textContent = 'שגיאת תקשורת באינדוקס.';
					btn.disabled = false;
					return;
				}
				var pct = res.total ? Math.round( ( res.done / res.total ) * 100 ) : 100;
				progressFill.style.width = pct + '%';
				progressText.textContent = res.done + ' / ' + res.total + ( res.failed ? ' (נכשלו: ' + res.failed + ')' : '' );

				if ( res.finished ) {
					btn.disabled = false;
					progressText.textContent += ' — הושלם';
					setTimeout( function () { window.location.reload(); }, 800 );
					return;
				}
				runStep();
			} );
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			progressWrap.hidden = false;
			progressFill.style.width = '0%';
			progressText.textContent = 'מתחיל…';

			restCall( 'POST', restUrl + 'admin/reindex/start', nonce, {} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					progressText.textContent = 'שגיאה בהתחלת האינדוקס.';
					btn.disabled = false;
					return;
				}
				if ( ! res.total ) {
					progressText.textContent = 'אין תוכן לאינדוקס.';
					btn.disabled = false;
					return;
				}
				runStep();
			} );
		} );
	}

	function initManualQa( restUrl, nonce ) {
		var addBtn = document.getElementById( 'pandabot-qa-add' );
		if ( ! addBtn ) {
			return;
		}

		addBtn.addEventListener( 'click', function () {
			var q = document.getElementById( 'pandabot-qa-question' ).value.trim();
			var a = document.getElementById( 'pandabot-qa-answer' ).value.trim();
			var resultEl = document.getElementById( 'pandabot-qa-result' );

			if ( ! q || ! a ) {
				resultEl.textContent = 'יש למלא שאלה ותשובה.';
				resultEl.className = 'pandabot-test-result is-error';
				return;
			}

			addBtn.disabled = true;
			restCall( 'POST', restUrl + 'admin/manual-qa', nonce, { question: q, answer: a } ).then( function ( res ) {
				addBtn.disabled = false;
				if ( res && res.success ) {
					window.location.reload();
					return;
				}
				resultEl.textContent = ( res && res.message ) ? res.message : 'שגיאה בהוספה.';
				resultEl.className = 'pandabot-test-result is-error';
			} );
		} );

		document.querySelectorAll( '.pandabot-qa-delete' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'למחוק את הערך?' ) ) {
					return;
				}
				var id = btn.getAttribute( 'data-id' );
				restCall( 'DELETE', restUrl + 'admin/manual-qa/' + id, nonce, null ).then( function () {
					window.location.reload();
				} );
			} );
		} );
	}

	function initChatTester( restUrl, nonce ) {
		var transcript = document.getElementById( 'pandabot-chat-transcript' );
		var input = document.getElementById( 'pandabot-chat-input' );
		var sendBtn = document.getElementById( 'pandabot-chat-send' );
		var resetBtn = document.getElementById( 'pandabot-chat-reset' );
		if ( ! transcript || ! input || ! sendBtn ) {
			return;
		}

		var sessionId = uuid4();

		function addBubble( role, html ) {
			var row = document.createElement( 'div' );
			row.className = 'pandabot-chat-msg pandabot-chat-' + role;
			row.innerHTML = html;
			transcript.appendChild( row );
			transcript.scrollTop = transcript.scrollHeight;
			return row;
		}

		function renderChunks( candidates, floor ) {
			if ( ! candidates || ! candidates.length ) {
				return '<div class="pandabot-chat-chunks pandabot-chat-chunks-empty">אין קטעים מאונדקסים כלל.</div>';
			}
			var floorTxt = ( typeof floor === 'number' ) ? floor.toFixed( 2 ) : floor;
			var html = '<div class="pandabot-chat-chunks"><strong>כל המועמדים שנבדקו (סף נוכחי: ' + escapeHtml( floorTxt ) + '):</strong><ul>';
			candidates.forEach( function ( c ) {
				var sim = ( typeof c.similarity === 'number' ) ? c.similarity.toFixed( 3 ) : c.similarity;
				var passed = ( typeof c.similarity === 'number' && typeof floor === 'number' ) ? c.similarity >= floor : null;
				var mark = passed === true ? '✓' : ( passed === false ? '✗' : '·' );
				var warn = c.dim_mismatch ? ' <span class="pandabot-chat-error">⚠ מימד וקטור שונה (' + escapeHtml( c.vector_dim ) + ')</span>' : '';
				html += '<li>' + mark + ' <b>' + escapeHtml( c.title || '(ללא כותרת)' ) + '</b> — דמיון: ' + escapeHtml( sim ) +
					' <span class="description">[' + escapeHtml( c.source_type ) + ', chunk ' + escapeHtml( c.chunk_index ) + ']</span>' + warn + '</li>';
			} );
			html += '</ul></div>';
			return html;
		}

		function send() {
			var text = input.value.trim();
			if ( ! text ) {
				return;
			}
			input.value = '';
			sendBtn.disabled = true;

			addBubble( 'user', escapeHtml( text ) );
			var typing = addBubble( 'bot', '<em>מקליד…</em>' );

			restCall( 'POST', restUrl + 'admin/chat-test', nonce, { session_id: sessionId, message: text } ).then( function ( res ) {
				sendBtn.disabled = false;
				typing.remove();

				if ( ! res || ! res.success ) {
					var errMsg = res && res.message ? res.message : 'שגיאת תקשורת.';
					var debug = res && res.debug ? '<div class="description">' + escapeHtml( res.debug ) + '</div>' : '';
					var errChunks = res && res.candidates ? renderChunks( res.candidates, res.floor ) : '';
					addBubble( 'bot', '<div class="pandabot-chat-error">' + escapeHtml( errMsg ) + '</div>' + debug + errChunks );
					return;
				}

				var answerHtml = escapeHtml( res.answer || '(תשובה ריקה)' ).replace( /\n/g, '<br>' );
				var action = res.guardrail_action || 'none';
				var isGuard = ( action !== 'none' );
				var actionBadge = '<span class="pandabot-chat-badge pandabot-chat-badge-' + escapeHtml( action ) + '">' + escapeHtml( action ) + '</span>';
				var meta = '<div class="description">latency: ' + escapeHtml( res.latency_ms ) + 'ms · נכנסו להקשר: ' + ( res.chunks ? res.chunks.length : 0 ) + ' · ' + actionBadge + '</div>';
				var row = addBubble( 'bot', answerHtml + meta + renderChunks( res.candidates, res.floor ) );
				if ( isGuard ) {
					row.classList.add( 'pandabot-chat-guard' );
				}
			} );
		}

		sendBtn.addEventListener( 'click', send );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				send();
			}
		} );

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				sessionId = uuid4();
				transcript.innerHTML = '';
				addBubble( 'bot', '<em>שיחה חדשה (session_id חדש).</em>' );
			} );
		}
	}
} )();
