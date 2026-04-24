/**
 * Memex — client-side interactions.
 *
 * - Graph view: force-directed layout in pure JS (no d3 dependency).
 * - Title autocomplete on [[ in textareas (cheap, fits in ~40 lines).
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
			var title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
			title.textContent = n.title;
			g.appendChild(title);

			svg.appendChild(g);
		});
	}

	// --- [[ autocomplete in textareas --------------------------------------
	//
	// When the user types `[[` in the quick-capture textarea, we show a
	// tiny popover of matching note titles.

	function setupAutocomplete() {
		var textareas = document.querySelectorAll('.memex-quick-capture textarea, .memex-quick-capture-full textarea');
		textareas.forEach(function (ta) {
			var popover;
			var active = -1;
			var items = [];

			function close() {
				if (popover) popover.remove();
				popover = null;
				active = -1;
			}

			function open(results, caretCoords) {
				close();
				if (!results.length) return;
				popover = document.createElement('div');
				popover.className = 'memex-autocomplete';
				Object.assign(popover.style, {
					position: 'absolute',
					background: 'var(--memex-surface, #fff)',
					border: '1px solid var(--memex-border, #ccc)',
					borderRadius: '4px',
					boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
					padding: '4px 0',
					zIndex: 10000,
					minWidth: '200px',
					fontSize: '0.9rem',
					maxHeight: '220px',
					overflowY: 'auto'
				});
				items = results;
				results.forEach(function (r, i) {
					var row = document.createElement('div');
					row.textContent = r.title;
					Object.assign(row.style, {
						padding: '4px 10px',
						cursor: 'pointer',
						color: 'var(--memex-text, #111)'
					});
					row.addEventListener('mousedown', function (ev) {
						ev.preventDefault();
						insert(r.title);
					});
					popover.appendChild(row);
				});
				document.body.appendChild(popover);
				popover.style.left = caretCoords.left + 'px';
				popover.style.top = (caretCoords.top + 20) + 'px';
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
				close();
				ta.focus();
			}

			ta.addEventListener('input', function () {
				var pos = ta.selectionStart;
				var before = ta.value.slice(0, pos);
				var idx = before.lastIndexOf('[[');
				if (idx === -1 || before.slice(idx).indexOf(']]') !== -1) {
					close();
					return;
				}
				var query = before.slice(idx + 2);
				if (query.length < 1) {
					close();
					return;
				}
				fetch(ajaxurl() + '?action=memex_title_suggest&q=' + encodeURIComponent(query), {
					credentials: 'same-origin'
				})
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json.success) return;
						var rect = ta.getBoundingClientRect();
						open(json.data, {
							left: rect.left + window.scrollX + 12,
							top: rect.top + window.scrollY + 8
						});
					})
					.catch(function () { /* silent */ });
			});

			ta.addEventListener('blur', function () { setTimeout(close, 120); });
		});
	}

	function ajaxurl() {
		return (window.ajaxurl || (window.location.origin + '/wp-admin/admin-ajax.php'));
	}

	// --- Bootstrap ---------------------------------------------------------

	document.addEventListener('DOMContentLoaded', function () {
		var graph = document.getElementById('memex-graph');
		if (graph) renderGraph(graph);
		setupAutocomplete();
	});
})();
