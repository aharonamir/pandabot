/**
 * PandaBot widget behavior. Vanilla, no framework, no external requests
 * beyond the plugin's own REST routes.
 *
 * All markup this file needs already exists in the DOM (rendered by
 * class-pandabot-widget.php) or in <template> elements — nothing is built
 * from HTML strings, so a hostile answer string can never become markup.
 */
(function () {
	'use strict';

	var root = document.getElementById('pandabot-root');
	if (!root) {
		return;
	}

	var restUrl = root.getAttribute('data-rest-url');
	var nonce = root.getAttribute('data-nonce');
	var errorText = root.getAttribute('data-error-text');
	var autoOpen = parseInt(root.getAttribute('data-auto-open'), 10) || 0;

	var panel = root.querySelector('#pandabot-panel');
	var launcher = root.querySelector('[data-pandabot="launcher"]');
	var openBtns = root.querySelectorAll('[data-pandabot="open"]');
	var teaser = root.querySelector('[data-pandabot="teaser"]');
	var teaserClose = root.querySelector('[data-pandabot="dismiss-teaser"]');
	var closeBtn = root.querySelector('[data-pandabot="close"]');
	var body = root.querySelector('[data-pandabot="body"]');
	var chips = root.querySelector('[data-pandabot="chips"]');
	var form = root.querySelector('[data-pandabot="form"]');
	var input = root.querySelector('[data-pandabot="input"]');
	var sendBtn = form.querySelector('button[type="submit"]');

	var tplTyping = root.querySelector('[data-pandabot="tpl-typing"]');
	var tplGuard = root.querySelector('[data-pandabot="tpl-guard"]');
	var tplCta = root.querySelector('[data-pandabot="tpl-cta"]');

	// Guardrail actions the widget renders as a distinct "this is a
	// boundary, not an answer" bubble rather than a normal reply.
	var GUARD_ACTIONS = ['blocked_medical', 'rate_limited', 'input_too_long'];

	var busy = false;

	/* ---------- session state (sessionStorage: cleared when the tab closes) ---------- */

	var SID_KEY = 'pandabot_session';
	var TX_KEY = 'pandabot_transcript';
	var OPENED_KEY = 'pandabot_opened';
	var TEASER_KEY = 'pandabot_teaser_dismissed';

	function store(key, value) {
		try {
			window.sessionStorage.setItem(key, value);
		} catch (e) {
			/* private mode / storage disabled — the widget still works, it just won't persist. */
		}
	}

	function read(key) {
		try {
			return window.sessionStorage.getItem(key);
		} catch (e) {
			return null;
		}
	}

	function uuid4() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			var v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	var sessionId = read(SID_KEY);
	if (!sessionId) {
		sessionId = uuid4();
		store(SID_KEY, sessionId);
	}

	function transcript() {
		try {
			var raw = read(TX_KEY);
			var parsed = raw ? JSON.parse(raw) : [];
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function remember(entry) {
		var tx = transcript();
		tx.push(entry);
		store(TX_KEY, JSON.stringify(tx));
	}

	/* ---------- rendering ---------- */

	function scrollDown() {
		body.scrollTop = body.scrollHeight;
	}

	/** Renders plain text preserving the model's line breaks, without ever parsing HTML. */
	function fillText(el, text) {
		var lines = String(text).split('\n');
		lines.forEach(function (line, i) {
			if (i > 0) {
				el.appendChild(document.createElement('br'));
			}
			el.appendChild(document.createTextNode(line));
		});
	}

	function addBubble(text, kind) {
		var msg = document.createElement('div');
		msg.className = 'pandabot-msg pandabot-msg--' + (kind === 'user' ? 'user' : 'bot');
		if (kind === 'error') {
			msg.className += ' pandabot-msg--error';
		}

		var bubble = document.createElement('div');
		bubble.className = 'pandabot-bubble';
		fillText(bubble, text);

		msg.appendChild(bubble);
		body.appendChild(msg);
		scrollDown();
	}

	function addGuard(text) {
		var frag = tplGuard.content.cloneNode(true);
		fillText(frag.querySelector('[data-pandabot="text"]'), text);
		body.appendChild(frag);
		scrollDown();
	}

	function addCta() {
		body.appendChild(tplCta.content.cloneNode(true));
		scrollDown();
	}

	function showTyping() {
		var frag = tplTyping.content.cloneNode(true);
		var el = frag.firstElementChild;
		body.appendChild(frag);
		scrollDown();
		return el;
	}

	function hideChips() {
		if (chips) {
			chips.hidden = true;
		}
	}

	function restore() {
		var tx = transcript();
		if (!tx.length) {
			return;
		}
		hideChips();
		tx.forEach(function (entry) {
			if (entry.role === 'user') {
				addBubble(entry.text, 'user');
			} else if (entry.guard) {
				addGuard(entry.text);
			} else {
				addBubble(entry.text, entry.error ? 'error' : 'bot');
			}
			if (entry.cta) {
				addCta();
			}
		});
	}

	/* ---------- REST ---------- */

	function post(route, payload, keepalive) {
		return fetch(restUrl + route, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: !!keepalive,
			headers: {
				'Content-Type': 'application/json',
				'X-PandaBot-Nonce': nonce
			},
			body: JSON.stringify(payload)
		});
	}

	function track(eventType) {
		// Fire-and-forget: analytics must never block or break the chat.
		// keepalive lets a CTA click's event survive the navigation it triggers.
		post('event', { session_id: sessionId, event_type: eventType }, true).catch(function () {});
	}

	/* ---------- open / close ---------- */

	function hideTeaser() {
		if (teaser) {
			teaser.hidden = true;
		}
		store(TEASER_KEY, '1');
	}

	function openPanel() {
		panel.hidden = false;
		launcher.hidden = true;
		// Once someone has opened the chat, the nudge has done its job —
		// bringing it back on every page view would just be noise.
		hideTeaser();
		openBtns.forEach(function (btn) {
			btn.setAttribute('aria-expanded', 'true');
		});

		// The consent line is part of the panel, so it is on screen before
		// the open is ever recorded (plan §9).
		if (!read(OPENED_KEY)) {
			store(OPENED_KEY, '1');
			track('open');
		}

		if (window.matchMedia('(min-width: 481px)').matches) {
			input.focus();
		}
		scrollDown();
	}

	function closePanel() {
		panel.hidden = true;
		launcher.hidden = false;
		openBtns.forEach(function (btn) {
			btn.setAttribute('aria-expanded', 'false');
		});
	}

	openBtns.forEach(function (btn) {
		btn.addEventListener('click', openPanel);
	});
	closeBtn.addEventListener('click', closePanel);

	if (teaserClose) {
		teaserClose.addEventListener('click', hideTeaser);
	}

	if (teaser && read(TEASER_KEY)) {
		teaser.hidden = true;
	}

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !panel.hidden) {
			closePanel();
		}
	});

	/* ---------- sending ---------- */

	function setBusy(state) {
		busy = state;
		input.disabled = state;
		sendBtn.disabled = state;
	}

	function send(text) {
		if (busy) {
			return;
		}

		var message = String(text).trim();
		if (!message) {
			return;
		}

		var isFirst = transcript().length === 0;

		hideChips();
		addBubble(message, 'user');
		remember({ role: 'user', text: message });
		input.value = '';
		setBusy(true);

		if (isFirst) {
			track('first_message');
		}

		var typing = showTyping();

		post('chat', { session_id: sessionId, message: message })
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				typing.remove();

				if (!data || !data.success || !data.answer) {
					addBubble(errorText, 'error');
					remember({ role: 'bot', text: errorText, error: true });
					return;
				}

				var guard = GUARD_ACTIONS.indexOf(data.guardrail_action) !== -1;
				var cta = !!data.show_cta;

				if (guard) {
					addGuard(data.answer);
				} else {
					addBubble(data.answer, 'bot');
				}
				if (cta) {
					addCta();
				}

				remember({ role: 'bot', text: data.answer, guard: guard, cta: cta });
			})
			.catch(function () {
				typing.remove();
				addBubble(errorText, 'error');
				remember({ role: 'bot', text: errorText, error: true });
			})
			.then(function () {
				setBusy(false);
				if (window.matchMedia('(min-width: 481px)').matches) {
					input.focus();
				}
			});
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		send(input.value);
	});

	if (chips) {
		chips.addEventListener('click', function (e) {
			var chip = e.target.closest('.pandabot-chip');
			if (!chip) {
				return;
			}
			track('suggested_prompt_click');
			send(chip.textContent);
		});
	}

	// CTA buttons are cloned in at runtime, so the click handler lives on the
	// message list rather than on each button.
	body.addEventListener('click', function (e) {
		var link = e.target.closest('[data-pandabot-event]');
		if (link) {
			track(link.getAttribute('data-pandabot-event'));
		}
	});

	restore();

	if (autoOpen > 0) {
		window.setTimeout(openPanel, autoOpen * 1000);
	}
})();
