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

		// Fruchterman–Reingold: k is the ideal edge length for this many
		// nodes in this much area, repulsion is k²/d, attraction d²/k, and a
		// cooling temperature caps how far a node may move per step so the
		// layout settles instead of bouncing off the walls. Scales from a
		// handful of nodes to a few hundred without retuning.
		var count = data.nodes.length;
		var k = Math.sqrt((width * height) / count) * 0.5;
		var iterations = count > 300 ? 100 : 200;
		var temp = Math.max(width, height) / 8;
		var cool = Math.pow(0.5 / temp, 1 / iterations);

		for (var step = 0; step < iterations; step++) {
			data.nodes.forEach(function (n) { n.vx = 0; n.vy = 0; });
			// Repulsion.
			for (var i = 0; i < count; i++) {
				var a = data.nodes[i];
				for (var j = i + 1; j < count; j++) {
					var b = data.nodes[j];
					var dx = a.x - b.x;
					var dy = a.y - b.y;
					var d = Math.sqrt(dx * dx + dy * dy) + 0.01;
					var f = (k * k) / d / d;
					a.vx += dx * f; a.vy += dy * f;
					b.vx -= dx * f; b.vy -= dy * f;
				}
			}
			// Attraction along edges.
			edges.forEach(function (e) {
				var a = idIndex[e.from], b = idIndex[e.to];
				var dx = b.x - a.x, dy = b.y - a.y;
				var d = Math.sqrt(dx * dx + dy * dy) + 0.01;
				var f = d / k;
				a.vx += dx * f; a.vy += dy * f;
				b.vx -= dx * f; b.vy -= dy * f;
			});
			// Gentle pull to the centre, then move by at most `temp`.
			data.nodes.forEach(function (n) {
				n.vx += (width / 2 - n.x) * 0.05;
				n.vy += (height / 2 - n.y) * 0.05;
				var len = Math.sqrt(n.vx * n.vx + n.vy * n.vy) + 0.01;
				var move = Math.min(len, temp) / len;
				n.x += n.vx * move;
				n.y += n.vy * move;
				n.x = Math.max(10, Math.min(width - 10, n.x));
				n.y = Math.max(10, Math.min(height - 10, n.y));
			});
			temp *= cool;
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

	// --- Site time ---------------------------------------------------------

	function setupServerTime() {
		var clocks = document.querySelectorAll('[data-memex-server-time]');
		if (!clocks.length) return;

		Array.prototype.forEach.call(clocks, function (clock) {
			var timestamp = parseInt(clock.getAttribute('data-server-timestamp'), 10);
			if (!timestamp) return;

			var offset = timestamp * 1000 - Date.now();
			var timezone = clock.getAttribute('data-timezone') || 'UTC';
			var format = clock.getAttribute('data-format') || 'H:i';

			function update() {
				var date = new Date(Date.now() + offset);
				var parts = getTimezoneParts(date, timezone);
				if (!parts) return;
				clock.textContent = formatWpDate(format, parts);
				clock.setAttribute('datetime', parts.iso);
			}

			update();
			window.setInterval(update, 1000);
		});
	}

	function getTimezoneParts(date, timezone) {
		var offsetMatch = timezone.match(/^([+-])(\d{2}):(\d{2})$/);
		if (offsetMatch) {
			var direction = offsetMatch[1] === '-' ? -1 : 1;
			var offsetMinutes = direction * (parseInt(offsetMatch[2], 10) * 60 + parseInt(offsetMatch[3], 10));
			return getUtcParts(new Date(date.getTime() + offsetMinutes * 60000), timezone, offsetMinutes, 'UTC' + timezone);
		}

		try {
			var formatter = new Intl.DateTimeFormat('en-US', {
				timeZone: timezone,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit',
				hour12: false,
			});
			var values = {};
			formatter.formatToParts(date).forEach(function (part) {
				values[part.type] = part.value;
			});
			var hour = parseInt(values.hour, 10);
			if (hour === 24) hour = 0;
			var minute = parseInt(values.minute, 10);
			var second = parseInt(values.second, 10);
			var year = parseInt(values.year, 10);
			var month = parseInt(values.month, 10);
			var day = parseInt(values.day, 10);
			var offsetMinutesNamed = Math.round((Date.UTC(year, month - 1, day, hour, minute, second) - date.getTime()) / 60000);
			return {
				year: year,
				month: month,
				day: day,
				hour: hour,
				minute: minute,
				second: second,
				timezone: timezone,
				timezoneName: getTimezoneName(date, timezone),
				offsetMinutes: offsetMinutesNamed,
				iso: pad(year, 4) + '-' + pad(month, 2) + '-' + pad(day, 2) + 'T' + pad(hour, 2) + ':' + pad(minute, 2) + ':' + pad(second, 2),
			};
		} catch (e) {
			return null;
		}
	}

	function getUtcParts(date, timezone, offsetMinutes, timezoneName) {
		var year = date.getUTCFullYear();
		var month = date.getUTCMonth() + 1;
		var day = date.getUTCDate();
		var hour = date.getUTCHours();
		var minute = date.getUTCMinutes();
		var second = date.getUTCSeconds();
		return {
			year: year,
			month: month,
			day: day,
			hour: hour,
			minute: minute,
			second: second,
			timezone: timezone,
			timezoneName: timezoneName,
			offsetMinutes: offsetMinutes,
			iso: pad(year, 4) + '-' + pad(month, 2) + '-' + pad(day, 2) + 'T' + pad(hour, 2) + ':' + pad(minute, 2) + ':' + pad(second, 2),
		};
	}

	function getTimezoneName(date, timezone) {
		try {
			var formatter = new Intl.DateTimeFormat('en-US', {
				timeZone: timezone,
				timeZoneName: 'short',
			});
			var parts = formatter.formatToParts(date);
			for (var i = 0; i < parts.length; i++) {
				if (parts[i].type === 'timeZoneName') return parts[i].value;
			}
		} catch (e) {}
		return timezone;
	}

	function formatWpDate(format, parts) {
		var replacements = {
			H: pad(parts.hour, 2),
			G: String(parts.hour),
			h: pad(toTwelveHour(parts.hour), 2),
			g: String(toTwelveHour(parts.hour)),
			i: pad(parts.minute, 2),
			s: pad(parts.second, 2),
			a: parts.hour < 12 ? 'am' : 'pm',
			A: parts.hour < 12 ? 'AM' : 'PM',
			O: formatTimezoneOffset(parts.offsetMinutes, false),
			P: formatTimezoneOffset(parts.offsetMinutes, true),
			T: parts.timezoneName || '',
			e: parts.timezone || '',
		};
		var output = '';
		var escaped = false;
		for (var i = 0; i < format.length; i++) {
			var character = format.charAt(i);
			if (escaped) {
				output += character;
				escaped = false;
				continue;
			}
			if (character === '\\') {
				escaped = true;
				continue;
			}
			output += Object.prototype.hasOwnProperty.call(replacements, character) ? replacements[character] : character;
		}
		return output;
	}

	function formatTimezoneOffset(offsetMinutes, includeColon) {
		if (typeof offsetMinutes !== 'number' || isNaN(offsetMinutes)) return '';
		var sign = offsetMinutes < 0 ? '-' : '+';
		var absolute = Math.abs(offsetMinutes);
		var hours = pad(Math.floor(absolute / 60), 2);
		var minutes = pad(absolute % 60, 2);
		return sign + hours + (includeColon ? ':' : '') + minutes;
	}

	function toTwelveHour(hour) {
		var value = hour % 12;
		return value || 12;
	}

	function pad(value, length) {
		var output = String(value);
		while (output.length < length) output = '0' + output;
		return output;
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

	function markdownToolbarButtons() {
		if (!window.defaultToolbarButtons || !window.defaultToolbarButtons.length) return null;

		var buttons = window.defaultToolbarButtons.filter(function (button) {
			return button && button.name !== 'viewMode';
		});

		while (buttons.length && buttons[buttons.length - 1].name === 'separator') {
			buttons.pop();
		}

		return buttons;
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
				value: source.value.replace(/\s*$/, "\n\n"),
				theme: theme,
				toolbar: true,
				toolbarButtons: markdownToolbarButtons(),
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
			source.setAttribute('tabindex', '-1');
			source.setAttribute('aria-hidden', 'true');
			editor.textarea._memexOvertypeEditor = editor;
			source._memexOvertypeEditor = editor;
			keepToolbarOutOfTabOrder(host);

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

	function keepToolbarOutOfTabOrder(host) {
		var buttons = host.querySelectorAll('.overtype-toolbar button, .overtype-toolbar [tabindex]');
		Array.prototype.forEach.call(buttons, function (button) {
			button.setAttribute('tabindex', '-1');
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
			if ((ev.metaKey || ev.ctrlKey) && ev.key === 'Enter') {
				var form = ta.form || ta.closest('form');
				if (!form) return;
				ev.preventDefault();
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else {
					form.submit();
				}
				return;
			}

			if (ev.altKey || ev.ctrlKey || ev.metaKey) return;

			var pairs = {
				'`': ['`', '`'],
				'*': ['**', '**'],
				'_': ['_', '_'],
				'(': ['(', ')'],
				')': ['(', ')'],
				'[': ['[[', ']]'],
				']': ['[[', ']]'],
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

	function setupRevisionDiffs() {
		var hosts = document.querySelectorAll('[data-memex-revisions]');
		if (!hosts.length) return;
		var editorSource = document.querySelector('[data-memex-markdown-source]');
		Array.prototype.forEach.call(hosts, function (host) {
			var empty = host.querySelector('[data-memex-revision-empty]');
			var triggers = host.querySelectorAll('[data-memex-revision-trigger]');
			var panels = host.querySelectorAll('[data-memex-revision-panel]');

			Array.prototype.forEach.call(host.querySelectorAll('.diff-deletedline'), function (cell) {
				cell.setAttribute('role', 'button');
				cell.setAttribute('tabindex', '0');
				cell.addEventListener('click', function () {
					insertRevisionLine(editorSource, getDiffCellText(cell));
				});
				cell.addEventListener('keydown', function (ev) {
					if (ev.key === 'Enter' || ev.key === ' ') {
						ev.preventDefault();
						insertRevisionLine(editorSource, getDiffCellText(cell));
					}
				});
			});
			Array.prototype.forEach.call(host.querySelectorAll('.diff-addedline'), function (cell) {
				cell.setAttribute('role', 'button');
				cell.setAttribute('tabindex', '0');
				cell.addEventListener('click', function () {
					removeCurrentLine(editorSource, getDiffCellText(cell));
				});
				cell.addEventListener('keydown', function (ev) {
					if (ev.key === 'Enter' || ev.key === ' ') {
						ev.preventDefault();
						removeCurrentLine(editorSource, getDiffCellText(cell));
					}
				});
			});

			Array.prototype.forEach.call(host.querySelectorAll('[data-memex-revision-load]'), function (button) {
				button.addEventListener('click', function () {
					loadRevision(editorSource, button);
				});
			});

			// Accordion: one revision open at a time, each diff directly under
			// its entry; clicking the open entry again collapses it.
			function select(id) {
				Array.prototype.forEach.call(triggers, function (trigger) {
					var active = id !== null && trigger.getAttribute('data-memex-revision-trigger') === id;
					trigger.classList.toggle('is-selected', active);
					trigger.setAttribute('aria-expanded', active ? 'true' : 'false');
				});
				Array.prototype.forEach.call(panels, function (panel) {
					panel.hidden = id === null || panel.getAttribute('data-memex-revision-panel') !== id;
				});
				if (empty) empty.hidden = id !== null;
			}

			Array.prototype.forEach.call(triggers, function (trigger) {
				trigger.addEventListener('click', function () {
					var id = trigger.getAttribute('data-memex-revision-trigger');
					select(trigger.getAttribute('aria-expanded') === 'true' ? null : id);
				});
			});
		});
	}

	function getDiffCellText(cell) {
		var clone = cell.cloneNode(true);
		Array.prototype.forEach.call(clone.querySelectorAll('.screen-reader-text'), function (node) {
			node.remove();
		});
		var deleted = clone.querySelectorAll('del');
		var text = deleted.length
			? Array.prototype.map.call(deleted, function (node) { return node.textContent; }).join('')
			: clone.textContent;
			return text
				.replace(/\u00a0/g, ' ')
				.replace(/^\s*Deleted:\s*/i, '')
				.replace(/^\s*Added:\s*/i, '')
				.replace(/^\s*-\s?/, '')
				.replace(/^\s*\+\s?/, '')
				.replace(/\n+$/g, '');
		}

	function setEditorValue(textarea, value) {
		var editor = textarea._memexOvertypeEditor;
		if (editor && typeof editor.setValue === 'function') {
			editor.setValue(value);
		}
		textarea.value = value;
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
		if (editor && typeof editor.updatePreview === 'function') {
			editor.updatePreview();
		}
	}

	// Puts a revision's title and text into the form without saving; the
	// user reviews and saves as usual. Asks first when the form already
	// holds edits that differ from what the page loaded with.
	function loadRevision(textarea, button) {
		if (!textarea) return;
		var form = textarea.form || textarea.closest('form');
		var title = form ? form.querySelector('input[name="title"]') : null;
		var editor = textarea._memexOvertypeEditor;
		var current = editor && typeof editor.getValue === 'function' ? editor.getValue() : textarea.value;
		var dirty = current.replace(/\s+$/, '') !== textarea.defaultValue.replace(/\s+$/, '')
			|| (title && title.value !== title.defaultValue);
		if (dirty && !window.confirm(button.getAttribute('data-confirm'))) return;
		if (title) {
			title.value = button.getAttribute('data-title') || '';
		}
		setEditorValue(textarea, button.getAttribute('data-content') || '');
		var target = editor && editor.textarea ? editor.textarea : textarea;
		target.focus();
	}

	function insertRevisionLine(textarea, text) {
		if (!textarea || !text) return;
		var editor = textarea._memexOvertypeEditor;
		var target = editor && editor.textarea ? editor.textarea : textarea;
		var value = editor && typeof editor.getValue === 'function' ? editor.getValue() : target.value;
		var insert = text;
		var start = target.selectionStart;
		var end = target.selectionEnd;
		if (typeof start !== 'number' || typeof end !== 'number') {
			start = end = value.length;
		}
		if (start === end) {
			var prefix = start > 0 && value.charAt(start - 1) !== '\n' ? '\n' : '';
			var suffix = start < value.length && value.charAt(start) !== '\n' ? '\n' : '';
			insert = prefix + insert + suffix;
		}
		value = value.slice(0, start) + insert + value.slice(end);
		if (editor && typeof editor.setValue === 'function') {
			editor.setValue(value);
			textarea.value = value;
		} else {
			textarea.value = value;
		}
		target.selectionStart = start + insert.length;
		target.selectionEnd = start + insert.length;
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
		if (textarea._memexOvertypeEditor && typeof textarea._memexOvertypeEditor.updatePreview === 'function') {
			textarea._memexOvertypeEditor.updatePreview();
		}
	}

	function removeCurrentLine(textarea, text) {
		if (!textarea || !text) return;
		var editor = textarea._memexOvertypeEditor;
		var target = editor && editor.textarea ? editor.textarea : textarea;
		var value = editor && typeof editor.getValue === 'function' ? editor.getValue() : target.value;
		var lines = value.split('\n');
		var index = lines.indexOf(text);
		if (index === -1) return;
		lines.splice(index, 1);
		value = lines.join('\n');
		if (editor && typeof editor.setValue === 'function') {
			editor.setValue(value);
			textarea.value = value;
		} else {
			textarea.value = value;
		}
		target.selectionStart = 0;
		target.selectionEnd = 0;
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
		if (textarea._memexOvertypeEditor && typeof textarea._memexOvertypeEditor.updatePreview === 'function') {
			textarea._memexOvertypeEditor.updatePreview();
		}
	}

	// --- Task list checkboxes ---------------------------------------------

	function setupTaskCheckboxes() {
		var body = document.querySelector('.memex-note-body[data-memex-task-post]');
		if (!body) return;
		var postId = body.getAttribute('data-memex-task-post');
		var nonce = body.getAttribute('data-memex-task-nonce');

		body.addEventListener('change', function (ev) {
			var box = ev.target;
			if (!box || box.type !== 'checkbox') return;
			var all = body.querySelectorAll('input[type="checkbox"]');
			var index = Array.prototype.indexOf.call(all, box);
			if (index < 0) return;

			var checked = box.checked;
			box.disabled = true;
			var params = new URLSearchParams();
			params.set('action', 'memex_toggle_task');
			params.set('_ajax_nonce', nonce);
			params.set('id', postId);
			params.set('index', String(index));
			params.set('checked', checked ? '1' : '0');

			fetch(ajaxurl(), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: params.toString(),
			})
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (!json || !json.success) throw new Error('toggle failed');
				})
				.catch(function () {
					box.checked = !checked;
				})
				.then(function () {
					box.disabled = false;
				});
		});
	}

	// --- Chunked import ----------------------------------------------------
	//
	// The upload creates a job; we then call `memex_import_step` until the
	// server reports `done`, rendering progress after every step. A failed
	// step leaves the job resumable (same job ID), so "Resume" just loops again.

	function setupImport() {
		var form = document.getElementById('memex-import-form');
		if (!form) return;

		var progress = document.getElementById('memex-import-progress');
		var bar = progress.querySelector('progress');
		var label = progress.querySelector('.memex-import-progress-label');
		var detail = progress.querySelector('.memex-import-progress-detail');
		var result = document.getElementById('memex-import-result');
		var errorBox = document.getElementById('memex-import-error');
		var resumeBox = document.getElementById('memex-import-resume');
		var submit = form.querySelector('button[type="submit"]');
		var nonce = form.getAttribute('data-nonce');
		var url = form.getAttribute('data-ajax-url') || ajaxurl();
		var currentJob = null;
		var lastStatus = null;
		var running = false;
		var retries = 0;
		var MAX_RETRIES = 3;
		var budget = 5; // seconds of work per step; halved when a step times out

		function t(key, values) {
			var str = form.getAttribute('data-i18n-' + key) || '';
			(values || []).forEach(function (v, i) {
				str = str.replace('%' + (i + 1) + '$s', v).replace('%s', v);
			});
			return str;
		}

		function fmt(n) {
			return Number(n).toLocaleString();
		}

		function show(el, visible) {
			if (visible) el.removeAttribute('hidden'); else el.setAttribute('hidden', '');
		}

		function setProgress(text, done, total, extra) {
			show(progress, true);
			label.textContent = text;
			if (total > 0) {
				bar.max = total;
				bar.value = done;
				detail.textContent = t('progress', [fmt(done), fmt(total)]);
			} else {
				bar.removeAttribute('value');
				detail.textContent = extra || '';
			}
		}

		function render(status) {
			lastStatus = status;
			if (status.phase === 'prepare') {
				setProgress(t('preparing', [status.file]), 0, 0, status.done > 0 ? t('found', [fmt(status.done)]) : '');
			} else if (status.phase === 'links') {
				setProgress(t('links'), status.done, status.total);
			} else {
				setProgress(t('importing'), status.done, status.total);
			}
		}

		function finish(status) {
			running = false;
			currentJob = null;
			show(progress, false);
			show(errorBox, false);
			if (resumeBox) show(resumeBox, false);
			result.querySelector('.memex-import-result-summary').textContent = t('done', [fmt(status.count), status.type]);
			var details = result.querySelector('details');
			var list = details.querySelector('ul');
			list.innerHTML = '';
			if (status.errors && status.errors.length) {
				details.querySelector('summary').textContent = t('warnings', [fmt(status.errors.length)]);
				status.errors.slice(0, 50).forEach(function (e) {
					var li = document.createElement('li');
					li.textContent = e;
					list.appendChild(li);
				});
			}
			show(details, status.errors && status.errors.length > 0);
			show(result, true);
			form.reset();
			show(form, true);
			submit.disabled = false;
		}

		function fail(message) {
			running = false;
			show(progress, false);
			errorBox.querySelector('p').textContent = t('failed', [message]);
			show(errorBox.querySelector('[data-import-retry]').parentNode, !!currentJob);
			show(errorBox, true);
			// Without a resumable job there is nothing else to do but try again.
			show(form, !currentJob);
			submit.disabled = false;
		}

		function post(action, params) {
			var body = params instanceof FormData ? params : new URLSearchParams(params || {});
			body.set('action', action);
			body.set('_ajax_nonce', nonce);
			return fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) {
					return r.json().catch(function () {
						throw new Error('HTTP ' + r.status);
					});
				})
				.then(function (json) {
					if (!json || !json.success) {
						var err = new Error((json && json.data && json.data.message) || 'Request failed');
						err.data = json && json.data;
						throw err;
					}
					return json.data;
				});
		}

		function loop() {
			if (!currentJob) return;
			running = true;
			post('memex_import_step', { job: currentJob, budget: String(budget) })
				.then(function (status) {
					retries = 0;
					render(status);
					if (status.phase === 'done') {
						finish(status);
					} else {
						loop();
					}
				})
				.catch(function (err) {
					// A dropped connection (proxy timeout, flaky network) leaves the
					// job intact on the server; retry a few times before giving up.
					if (!err.data && retries < MAX_RETRIES) {
						retries++;
						budget = Math.max(1, budget / 2);
						label.textContent = t('retrying', [retries, MAX_RETRIES]);
						setTimeout(loop, 2000 * retries);
						return;
					}
					fail(err.message);
				});
		}

		function start(job, resuming) {
			currentJob = job;
			retries = 0;
			show(result, false);
			show(errorBox, false);
			if (resumeBox) show(resumeBox, false);
			show(form, false);
			if (resuming) {
				// Show something right away; the first step can take several seconds.
				var known = lastStatus && lastStatus.total > 0;
				setProgress(t('resuming'), known ? lastStatus.done : 0, known ? lastStatus.total : 0);
			}
			loop();
		}

		function upload(formData) {
			submit.disabled = true;
			show(result, false);
			show(errorBox, false);
			show(form, false);
			running = true;
			var file = form.querySelector('input[type="file"]').files[0];
			setProgress(t('uploading', [file ? file.name : '']), 0, 0);

			formData.set('action', 'memex_import_start');
			formData.set('_ajax_nonce', nonce);
			var xhr = new XMLHttpRequest();
			xhr.open('POST', url);
			xhr.withCredentials = true;
			xhr.upload.onprogress = function (e) {
				if (e.lengthComputable) {
					setProgress(t('uploading', [file ? file.name : '']), e.loaded, e.total);
				}
			};
			xhr.onerror = function () {
				fail('network error');
			};
			xhr.onload = function () {
				var json = null;
				try { json = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
				if (!json || !json.success) {
					var data = json && json.data;
					if (data && data.code === 'import-in-progress' && data.status) {
						// Another (interrupted) job exists: pick it up instead.
						lastStatus = data.status;
						start(data.status.job, true);
						return;
					}
					fail((data && data.message) || ('HTTP ' + xhr.status));
					return;
				}
				render(json.data);
				start(json.data.job);
			};
			xhr.send(formData);
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (running) return;
			upload(new FormData(form));
		});

		errorBox.querySelector('[data-import-retry]').addEventListener('click', function () {
			if (currentJob && !running) start(currentJob, true);
		});

		if (resumeBox) {
			resumeBox.querySelector('[data-import-resume]').addEventListener('click', function () {
				lastStatus = {
					phase: resumeBox.getAttribute('data-phase'),
					done: Number(resumeBox.getAttribute('data-done')),
					total: Number(resumeBox.getAttribute('data-total')),
					file: resumeBox.getAttribute('data-file'),
				};
				start(resumeBox.getAttribute('data-job'), true);
			});
			resumeBox.querySelector('[data-import-discard]').addEventListener('click', function () {
				var job = resumeBox.getAttribute('data-job');
				post('memex_import_cancel', { job: job })
					.catch(function () { /* already gone */ })
					.then(function () {
						show(resumeBox, false);
						show(form, true);
					});
			});
		}

		window.addEventListener('beforeunload', function (e) {
			if (!running) return;
			e.preventDefault();
			e.returnValue = t('leave');
			return e.returnValue;
		});
	}

	// --- Collapsible sidebar (narrow screens) ------------------------------

	function setupSidebarToggle() {
		var toggle = document.querySelector('[data-memex-sidebar-toggle]');
		var sidebar = toggle && toggle.closest('.memex-sidebar');
		if (!toggle || !sidebar) return;

		function setOpen(open) {
			sidebar.classList.toggle('is-open', open);
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		}

		toggle.addEventListener('click', function () {
			setOpen(!sidebar.classList.contains('is-open'));
		});

		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && sidebar.classList.contains('is-open')) {
				setOpen(false);
				toggle.focus();
			}
		});

		// Tapping the page content dismisses the menu.
		document.addEventListener('click', function (ev) {
			if (sidebar.classList.contains('is-open') && !sidebar.contains(ev.target)) {
				setOpen(false);
			}
		});
	}

	// --- Bootstrap ---------------------------------------------------------

	document.addEventListener('DOMContentLoaded', function () {
		setupSidebarToggle();
		var graph = document.getElementById('memex-graph');
		if (graph) renderGraph(graph);
		initQuickDue();
		setupServerTime();
		setupAiAssistantRefresh();
		setupMarkdownEditor();
		setupAutocomplete();
		setupRevisionDiffs();
		setupImport();
		setupTaskCheckboxes();
	});
})();
