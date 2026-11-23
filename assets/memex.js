/* Online import, reminder, and sidebar behavior. Notes use memex-shell.js. */
(function(){
'use strict';
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
	function setupCaptureTasks() {
		document.querySelectorAll('[data-memex-capture-task]').forEach(function (toggle) {
			if (toggle.dataset.taskReady) return;
			var input = toggle.closest('form').querySelector('[name="content"]');
			if (!input) return;
			toggle.dataset.taskReady = 'true';
			var marker = /^\[[ xX]\](?:[\t ]+|$)/;
			function sync() {
				toggle.setAttribute('aria-pressed', marker.test(input.value) ? 'true' : 'false');
			}
			input.addEventListener('input', sync);
			toggle.addEventListener('keydown', function (event) {
				if (event.key === ' ') {
					event.preventDefault();
					toggle.click();
				}
			});
			toggle.addEventListener('click', function (event) {
				event.preventDefault();
				var start = input.selectionStart;
				var end = input.selectionEnd;
				var match = input.value.match(marker);
				var shift = match ? -match[0].length : 4;
				input.value = match ? input.value.slice(match[0].length) : '[ ] ' + input.value;
				input.focus();
				input.setSelectionRange(Math.max(0, start + shift), Math.max(0, end + shift));
				input.dispatchEvent(new Event('input', { bubbles: true }));
			});
			input.form.addEventListener('reset', function () { window.setTimeout(sync, 0); });
			sync();
		});
	}

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

function ajaxurl(){return window.memexAjaxUrl;}
document.addEventListener('DOMContentLoaded',function(){setupSidebarToggle();setupServerTime();setupCaptureTasks();initQuickDue();setupImport();});
})();
