/**
 * Memex — client-side interactions.
 *
 * - Graph view: force-directed layout in pure JS (no d3 dependency).
 */

(function () {
	'use strict';

	// --- Graph view --------------------------------------------------------

	function renderGraph(host) {
		var dataAttr = host.getAttribute('data-graph');
		if (!dataAttr) return;
		var data;
		try {
			data = JSON.parse(dataAttr);
		} catch (e) {
			return;
		}
		if (!data.nodes || !data.nodes.length) {
			host.innerHTML = '<p class="memex-muted" style="padding:1rem;">No notes to graph yet.</p>';
			return;
		}

		var width = host.clientWidth;
		var height = host.clientHeight;
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
		svg.setAttribute('role', 'img');
		svg.setAttribute('aria-label', host.getAttribute('aria-label') || 'Note graph');
		host.innerHTML = '';
		host.appendChild(svg);

		var idIndex = {};
		data.nodes.forEach(function (n, i) {
			n.x = width / 2 + (Math.cos((i / data.nodes.length) * Math.PI * 2) * Math.min(width, height)) / 3;
			n.y = height / 2 + (Math.sin((i / data.nodes.length) * Math.PI * 2) * Math.min(width, height)) / 3;
			n.vx = 0;
			n.vy = 0;
			idIndex[n.id] = n;
		});

		var edges = (data.edges || []).filter(function (e) { return idIndex[e.from] && idIndex[e.to]; });

		// Force-directed iterations.
		var iterations = 180;
		var repulse = 8000;
		var springLen = 80;
		var springK = 0.04;
		var damping = 0.85;

		for (var step = 0; step < iterations; step++) {
			// Repulsion.
			for (var i = 0; i < data.nodes.length; i++) {
				var a = data.nodes[i];
				for (var j = i + 1; j < data.nodes.length; j++) {
					var b = data.nodes[j];
					var dx = a.x - b.x;
					var dy = a.y - b.y;
					var d2 = dx * dx + dy * dy + 0.01;
					var f = repulse / d2;
					var d = Math.sqrt(d2);
					var fx = (dx / d) * f;
					var fy = (dy / d) * f;
					a.vx += fx; a.vy += fy;
					b.vx -= fx; b.vy -= fy;
				}
			}
			// Springs.
			edges.forEach(function (e) {
				var a = idIndex[e.from], b = idIndex[e.to];
				var dx = b.x - a.x, dy = b.y - a.y;
				var d = Math.sqrt(dx * dx + dy * dy) + 0.01;
				var f = (d - springLen) * springK;
				var fx = (dx / d) * f, fy = (dy / d) * f;
				a.vx += fx; a.vy += fy;
				b.vx -= fx; b.vy -= fy;
			});
			// Centering.
			data.nodes.forEach(function (n) {
				n.vx += (width / 2 - n.x) * 0.001;
				n.vy += (height / 2 - n.y) * 0.001;
				n.x += n.vx;
				n.y += n.vy;
				n.vx *= damping;
				n.vy *= damping;
				n.x = Math.max(10, Math.min(width - 10, n.x));
				n.y = Math.max(10, Math.min(height - 10, n.y));
			});
		}

		// Edges first so they render beneath nodes.
		edges.forEach(function (e) {
			var a = idIndex[e.from], b = idIndex[e.to];
			var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			line.setAttribute('class', 'memex-graph-edge');
			line.setAttribute('x1', a.x); line.setAttribute('y1', a.y);
			line.setAttribute('x2', b.x); line.setAttribute('y2', b.y);
			svg.appendChild(line);
		});

		data.nodes.forEach(function (n) {
			var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
			g.setAttribute('class', 'memex-graph-node' + (n.stub ? ' is-stub' : ''));
			g.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')');
			g.setAttribute('tabindex', '0');
			g.setAttribute('role', 'link');
			g.setAttribute('aria-label', n.title);

			var degree = 0;
			edges.forEach(function (e) {
				if (e.from === n.id || e.to === n.id) degree++;
			});
			var r = 4 + Math.min(10, degree);

			var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
			circle.setAttribute('r', r);
			g.appendChild(circle);

			var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
			text.setAttribute('x', r + 3);
			text.setAttribute('y', 3);
			text.textContent = n.title.length > 30 ? n.title.slice(0, 30) + '…' : n.title;
			g.appendChild(text);

			g.addEventListener('click', function () { window.location.href = n.url; });
			g.addEventListener('keydown', function (ev) {
				if (ev.key === 'Enter' || ev.key === ' ') {
					ev.preventDefault();
					window.location.href = n.url;
				}
			});
			var title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
			title.textContent = n.title;
			g.appendChild(title);

			svg.appendChild(g);
		});
	}

	// --- Quick-due presets on /memex/reminders ----------------------------

	function initQuickDue() {
		var buttons = document.querySelectorAll('[data-quick-due]');
		if (!buttons.length) return;
		Array.prototype.forEach.call(buttons, function (btn) {
			btn.addEventListener('click', function () {
				var date = computeQuickDue(btn.getAttribute('data-quick-due'));
				if (!date) return;
				var form = btn.closest('form');
				if (!form) return;
				var input = form.querySelector('input[type="datetime-local"]');
				if (!input) return;
				input.value = toDatetimeLocalValue(date);
				renderReadout(form, date);
			});
		});
		// Keep the readout in sync when the user edits the picker by hand.
		Array.prototype.forEach.call(
			document.querySelectorAll('.memex-reminder-form input[type="datetime-local"]'),
			function (input) {
				input.addEventListener('change', function () {
					if (!input.value) return;
					var d = new Date(input.value);
					if (!isNaN(d.getTime())) renderReadout(input.form, d);
				});
			}
		);
	}

	function computeQuickDue(spec) {
		if (!spec) return null;
		var now = new Date();
		var m;
		if ((m = spec.match(/^\+(\d+)(min|hour|day)s?$/))) {
			var n = parseInt(m[1], 10);
			var ms = m[2] === 'min' ? 60000 : m[2] === 'hour' ? 3600000 : 86400000;
			return new Date(now.getTime() + n * ms);
		}
		if ((m = spec.match(/^(today|tomorrow|weekend|monday|\+(\d+)days?)\s+(\d{1,2}):(\d{2})$/))) {
			var which = m[1];
			var h = parseInt(m[3], 10);
			var min = parseInt(m[4], 10);
			var d = new Date(now);
			d.setHours(h, min, 0, 0);
			if (which === 'today') {
				if (d <= now) d.setDate(d.getDate() + 1);
			} else if (which === 'tomorrow') {
				d.setDate(d.getDate() + 1);
			} else if (which === 'weekend') {
				// Saturday = 6. If already Saturday, jump to next Saturday.
				var add = (6 - d.getDay() + 7) % 7;
				if (add === 0) add = 7;
				d.setDate(d.getDate() + add);
			} else if (which === 'monday') {
				var addM = (1 - d.getDay() + 7) % 7;
				if (addM === 0) addM = 7;
				d.setDate(d.getDate() + addM);
			} else {
				d.setDate(d.getDate() + parseInt(m[2], 10));
			}
			return d;
		}
		return null;
	}

	function toDatetimeLocalValue(d) {
		var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
			+ 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function renderReadout(form, d) {
		if (!form) return;
		var readout = form.querySelector('[data-quick-readout]');
		if (!readout) return;
		try {
			readout.textContent = '→ ' + d.toLocaleString(undefined, {
				weekday: 'long', month: 'short', day: 'numeric',
				hour: 'numeric', minute: '2-digit',
			});
		} catch (e) {
			readout.textContent = '→ ' + d.toString();
		}
	}

	// --- AI Assistant ability callbacks ------------------------------------

	function setupAiAssistantRefresh() {
		var abilities = {
			'memex/save-note': true,
			'memex/capture': true,
			'memex/save-reminder': true,
		};
		var subscription = {
			criteria: function (context) {
				var input = context && (context.input || context.arguments);
				return !!(
					context &&
					context.success &&
					input &&
					abilities[input.ability]
				);
			},
			callback: function () {
				if (window.location.pathname.indexOf('/memex') === 0) {
					window.location.reload();
				}
			},
		};

		if (window.aiAssistant && typeof window.aiAssistant.onToolCall === 'function') {
			window.aiAssistant.onToolCall(subscription.criteria, subscription.callback);
		} else {
			window.aiAssistantToolCallbacks = window.aiAssistantToolCallbacks || [];
			window.aiAssistantToolCallbacks.push(subscription);
		}
	}

	// --- Markdown editor ----------------------------------------------------

	function setupMarkdownSyntax() {
		if (!window.OverType || typeof window.OverType.setCustomSyntax !== 'function') return;
		if (window.OverType._memexWikiSyntaxReady) return;

		window.OverType.setCustomSyntax(function (html) {
			return html.replace(
				/\[\[([^\]\|<]+?)(?:\|([^\]<]+?))?\]\]/g,
				function (match, target, label) {
					if (label) {
						return '<span class="memex-editor-wikilink"><span class="memex-editor-wikilink-marker">[[</span><span class="memex-editor-wikilink-target">' + target + '</span><span class="memex-editor-wikilink-marker">|</span><span class="memex-editor-wikilink-label">' + label + '</span><span class="memex-editor-wikilink-marker">]]</span></span>';
					}
					return '<span class="memex-editor-wikilink"><span class="memex-editor-wikilink-marker">[[</span><span class="memex-editor-wikilink-label">' + target + '</span><span class="memex-editor-wikilink-marker">]]</span></span>';
				}
			);
		});
		window.OverType._memexWikiSyntaxReady = true;
	}

	function setupMarkdownEditor() {
		if (!window.OverType) return;
		setupMarkdownSyntax();

		var forms = document.querySelectorAll('.memex-edit-form');
		Array.prototype.forEach.call(forms, function (form) {
			var source = form.querySelector('textarea[data-memex-markdown-source]');
			var host = form.querySelector('[data-memex-markdown-editor]');
			if (!source || !host || host.dataset.memexOvertypeReady) return;

			var styles = window.getComputedStyle(document.documentElement);
			function cssVar(name, fallback) {
				var value = styles.getPropertyValue(name).trim();
				return value || fallback;
			}

			var theme = {
				name: 'memex',
				colors: {
					bgPrimary: cssVar('--wp-app-color-surface', '#ffffff'),
					bgSecondary: cssVar('--wp-app-color-surface-alt', '#f6f7f7'),
					border: cssVar('--wp-app-color-border', '#dcdcde'),
					text: cssVar('--wp-app-color-text', '#1d2327'),
					textPrimary: cssVar('--wp-app-color-text', '#1d2327'),
					textSecondary: cssVar('--wp-app-color-muted', '#646970'),
					primary: cssVar('--wp-app-color-link', '#2271b1'),
					link: cssVar('--wp-app-color-link', '#2271b1'),
					cursor: cssVar('--wp-app-color-link', '#2271b1'),
					selection: 'rgba(34, 113, 177, 0.22)',
					codeBg: cssVar('--wp-app-color-surface-alt', '#f6f7f7'),
					toolbarBg: cssVar('--wp-app-color-surface', '#ffffff'),
					toolbarBorder: cssVar('--wp-app-color-border', '#dcdcde'),
					toolbarHover: cssVar('--wp-app-color-surface-alt', '#f6f7f7'),
					toolbarIcon: cssVar('--wp-app-color-text', '#1d2327'),
					syntaxMarker: cssVar('--wp-app-color-muted', '#646970'),
				},
			};

			var editors = new window.OverType(host, {
				value: source.value,
				theme: theme,
				toolbar: true,
				showStats: true,
				smartLists: true,
				spellcheck: true,
				fontSize: '15px',
				lineHeight: 1.55,
				minHeight: '28rem',
				textareaProps: {
					'aria-label': source.getAttribute('aria-label') || 'Note markdown',
				},
				onChange: function (value) {
					source.value = value;
				},
			});

			var editor = editors && editors[0];
			if (!editor) return;

			host.dataset.memexOvertypeReady = '1';
			host.classList.add('is-ready');
			source.classList.add('memex-markdown-source-hidden');
			source.removeAttribute('autofocus');
			editor.textarea._memexOvertypeEditor = editor;

			form.addEventListener('submit', function () {
				source.value = editor.getValue();
			});

			if (source.hasAttribute('data-memex-should-focus') || document.activeElement === source) {
				editor.focus();
			} else if (source.defaultValue === source.value) {
				setTimeout(function () { editor.focus(); }, 0);
			}
		});
	}

	// --- [[ autocomplete in textareas --------------------------------------

	function looksLikeUrl(text) {
		return /^(https?:\/\/|mailto:|ftp:\/\/|\/|#|\?|\.)\S+$/i.test(text.trim());
	}

	function replaceSelection(ta, replacement, selectionStart, selectionEnd) {
		var start = ta.selectionStart;
		var end = ta.selectionEnd;
		ta.value = ta.value.slice(0, start) + replacement + ta.value.slice(end);
		ta.selectionStart = start + selectionStart;
		ta.selectionEnd = start + selectionEnd;
		ta.dispatchEvent(new Event('input', { bubbles: true }));
		if (ta._memexOvertypeEditor && typeof ta._memexOvertypeEditor.updatePreview === 'function') {
			ta._memexOvertypeEditor.updatePreview();
		}
	}

	function setupPasteLinkWrapping(ta) {
		if (ta._memexPasteLinkWrappingReady) return;
		ta._memexPasteLinkWrappingReady = true;

		ta.addEventListener('paste', function (ev) {
			var start = ta.selectionStart;
			var end = ta.selectionEnd;
			if (typeof start !== 'number' || typeof end !== 'number' || start === end) return;

			var pasted = ev.clipboardData && ev.clipboardData.getData('text/plain');
			if (!pasted || !looksLikeUrl(pasted)) return;

			var url = pasted.trim();
			var selected = ta.value.slice(start, end);
			if (/^\[[^\]]+\]\([^)]+\)$/.test(selected)) return;

			ev.preventDefault();
			var replacement = '[' + selected + '](' + url + ')';
			replaceSelection(ta, replacement, 0, replacement.length);
		});
	}

	function setupSelectionWrapping(ta) {
		if (ta._memexSelectionWrappingReady) return;
		ta._memexSelectionWrappingReady = true;

		ta.addEventListener('keydown', function (ev) {
			if (ev.altKey || ev.ctrlKey || ev.metaKey) return;

			var pairs = {
				'`': ['`', '`'],
				'*': ['*', '*'],
				'_': ['_', '_'],
				'(': ['(', ')'],
				')': ['(', ')'],
				'[': ['[', ']'],
				']': ['[', ']'],
			};
			var pair = pairs[ev.key];
			if (!pair) return;

			var start = ta.selectionStart;
			var end = ta.selectionEnd;
			if (typeof start !== 'number' || typeof end !== 'number' || start === end) return;

			ev.preventDefault();
			var selected = ta.value.slice(start, end);
			var replacement = pair[0] + selected + pair[1];
			replaceSelection(ta, replacement, pair[0].length, pair[0].length + selected.length);
		});
	}

	function setupAutocomplete() {
		var textareas = document.querySelectorAll(
			'.memex-quick-capture textarea, .memex-quick-capture-full textarea, .memex-edit-form textarea'
		);
		Array.prototype.forEach.call(textareas, function (ta) {
			if (ta.classList.contains('memex-markdown-source-hidden')) return;
			setupPasteLinkWrapping(ta);
			setupSelectionWrapping(ta);
			var popover;

			function close() {
				if (popover) popover.remove();
				popover = null;
			}

			function open(results, caretCoords) {
				close();
				if (!results.length) return;
				popover = document.createElement('div');
				popover.className = 'memex-autocomplete';
				results.forEach(function (r) {
					var row = document.createElement('button');
					row.type = 'button';
					row.textContent = r.title;
					row.addEventListener('mousedown', function (ev) {
						ev.preventDefault();
						insert(r.title);
					});
					popover.appendChild(row);
				});
				document.body.appendChild(popover);
				popover.style.left = caretCoords.left + 'px';
				popover.style.top = (caretCoords.top + 24) + 'px';
			}

			function insert(title) {
				var val = ta.value;
				var pos = ta.selectionStart;
				var before = val.slice(0, pos);
				var after = val.slice(pos);
				var idx = before.lastIndexOf('[[');
				if (idx === -1) return;
				ta.value = before.slice(0, idx + 2) + title + ']]' + after;
				var newPos = idx + 2 + title.length + 2;
				ta.selectionStart = ta.selectionEnd = newPos;
				ta.dispatchEvent(new Event('input', { bubbles: true }));
				if (ta._memexOvertypeEditor && typeof ta._memexOvertypeEditor.updatePreview === 'function') {
					ta._memexOvertypeEditor.updatePreview();
				}
				close();
				ta.focus();
			}

			ta.addEventListener('input', function () {
				var pos = ta.selectionStart;
				var before = ta.value.slice(0, pos);
				var idx = before.lastIndexOf('[[');
				if (idx === -1) {
					close();
					return;
				}
				var query = before.slice(idx + 2);
				if (query.length < 1 || /[\]\n]/.test(query)) {
					close();
					return;
				}
				fetch(ajaxurl() + '?action=memex_title_suggest&q=' + encodeURIComponent(query), {
					credentials: 'same-origin',
				})
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json.success) return;
						var rect = ta.getBoundingClientRect();
						open(json.data, {
							left: rect.left + window.scrollX + 12,
							top: rect.top + window.scrollY + 8,
						});
					})
					.catch(function () {});
			});

			ta.addEventListener('blur', function () { setTimeout(close, 120); });
		});
	}

	function ajaxurl() {
		return window.ajaxurl || (window.location.origin + '/wp-admin/admin-ajax.php');
	}

	// --- Bootstrap ---------------------------------------------------------

	document.addEventListener('DOMContentLoaded', function () {
		var graph = document.getElementById('memex-graph');
		if (graph) renderGraph(graph);
		initQuickDue();
		setupAiAssistantRefresh();
		setupMarkdownEditor();
		setupAutocomplete();
	});
})();
