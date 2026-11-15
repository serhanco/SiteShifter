/* SiteShifter job runner: drives a job slice by slice and shows live progress.
 * by Serhan Demirel — https://serhandemirel.com */
(function () {
	'use strict';

	var cfg = window.siteshifterJobs || {};
	var MAX_RETRIES = 5;
	var transport = 'rest';

	function post(op, payload) {
		if (transport === 'rest') {
			var path = { checks: 'checks', site_check: 'site-check', packages: 'packages', delete_many: 'packages/delete', retention: 'retention' }[op];
			var url = cfg.restUrl + (path ? path : 'jobs' + (payload.id ? '/' + payload.id + '/' + op : ''));
			if (op === 'status') {
				return fetch(url.replace(/\/status$/, ''), { headers: { 'X-WP-Nonce': cfg.restNonce }, credentials: 'same-origin', cache: 'no-store' });
			}
			return fetch(url, {
				method: 'POST',
				headers: { 'X-WP-Nonce': cfg.restNonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				cache: 'no-store',
				body: JSON.stringify(payload)
			});
		}
		var body = new URLSearchParams();
		body.set('action', cfg.ajaxAction);
		body.set('_ajax_nonce', cfg.ajaxNonce);
		body.set('op', op);
		Object.keys(payload).forEach(function (k) {
			body.set(k, typeof payload[k] === 'object' ? JSON.stringify(payload[k]) : payload[k]);
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body });
	}

	/* A reply counts only if it is our JSON envelope: a firewall or cache page is not. */
	function call(op, payload) {
		return post(op, payload).then(function (res) {
			return res.text().then(function (text) {
				var data;
				try { data = JSON.parse(text); } catch (e) { data = null; }
				if (!data || typeof data.ok === 'undefined') {
					var err = new Error(cfg.i18n.badReply);
					err.transport = true;
					throw err;
				}
				return data;
			});
		});
	}

	function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

	/* Retries with 1,2,4,8,16 s; switches REST -> admin-ajax once if REST is blocked. */
	function resilient(op, payload, ui) {
		var attempt = 0, switched = false;
		function go() {
			return call(op, payload).catch(function (err) {
				if (err.transport && transport === 'rest' && !switched) {
					switched = true; transport = 'ajax';
					return go();
				}
				if (attempt >= MAX_RETRIES) { throw err; }
				attempt++;
				ui.note(cfg.i18n.retrying.replace('%d', attempt));
				return sleep(1000 * Math.pow(2, attempt - 1)).then(go);
			});
		}
		return go();
	}

	function bar(root) {
		root.innerHTML = '<div class="ss-bar" style="background:#dcdcde;border-radius:3px;height:22px;max-width:520px"><div class="ss-fill" style="background:#2271b1;height:100%;width:0;border-radius:3px;transition:width .3s"></div></div><p class="ss-line" aria-live="polite"></p>';
		var fill = root.querySelector('.ss-fill'), line = root.querySelector('.ss-line');
		return {
			set: function (job) {
				fill.style.width = job.percent + '%';
				line.textContent = job.percent + '% ' + (job.detail || job.phase || '');
			},
			note: function (t) { line.textContent = t; },
			text: function (t) { line.textContent = t; }
		};
	}

	function run(type, params, root, onJob) {
		var ui = bar(root);
		return resilient('start', { type: type, params: params || {} }, ui).then(function (r) {
			if (!r.ok) { throw new Error(r.message); }
			var id = r.job.id;
			if (onJob) { onJob(r.job); }
			function loop(job) {
				ui.set(job);
				if (job.status === 'done') { ui.text(cfg.i18n.done); return job; }
				if (job.status === 'paused') { ui.text(cfg.i18n.paused + ' ' + (job.error || '')); return job; }
				if (job.status !== 'running') { return job; }
				return resilient('step', { id: id }, ui).then(function (s) {
					if (!s.ok) { throw new Error(s.message); }
					return (s.job.busy ? sleep(1500) : Promise.resolve()).then(function () { return loop(s.job); });
				});
			}
			return loop(r.job);
		}).catch(function (e) { ui.text(cfg.i18n.failed + ' ' + e.message); });
	}

	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }

	/* Renders a pre-flight / site-check list: block, warn, ok, info with the suggested fix. */
	function renderReport(root, rows) {
		var order = { block: 0, warn: 1, info: 2, ok: 3 }, color = { block: '#d63638', warn: '#dba617', info: '#2271b1', ok: '#00a32a' };
		rows = rows.slice().sort(function (a, b) { return order[a.level] - order[b.level]; });
		root.innerHTML = '<ul style="margin:0;padding:0;list-style:none">' + rows.map(function (r) {
			return '<li style="border-left:4px solid ' + color[r.level] + ';padding:4px 10px;margin:0 0 6px;background:#fff"><strong>' + esc(r.title) + '</strong> <span style="color:' + color[r.level] + '">' + esc(r.level.toUpperCase()) + '</span>' +
				(r.message ? '<br>' + esc(r.message) : '') + (r.fix && r.level !== 'ok' ? '<br><em>' + esc(r.fix) + '</em>' : '') + '</li>';
		}).join('') + '</ul>';
	}

	window.SiteShifterJobs = { run: run, call: resilient, renderReport: renderReport, ui: function (root) { return { note: function (t) { root.textContent = t; } }; } };

	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-ss-host-test]');
		if (!b) { return; }
		e.preventDefault();
		b.disabled = true;
		run('host_test', { mb: 32 }, document.getElementById('ss-host-test-progress')).then(function () { b.disabled = false; });
	});
})();

/* Change address screen */
(function () {
	'use strict';
	var cfg = window.siteshifterJobs || {};
	var $ = function (id) { return document.getElementById(id); };
	var form = $('ss-address');
	if (!form) { return; }
	var lastJob = null;

	function report(newUrl) {
		var box = $('ss-address-report');
		box.textContent = cfg.i18n.checking;
		return window.SiteShifterJobs.call('checks', { params: { new_url: newUrl } }, window.SiteShifterJobs.ui(box)).then(function (r) {
			if (!r.ok) { box.textContent = r.message; return null; }
			window.SiteShifterJobs.renderReport(box, r.results);
			return r;
		}).catch(function (e) { box.textContent = cfg.i18n.failed + ' ' + e.message; return null; });
	}

	$('ss-address-check').addEventListener('click', function (e) {
		e.preventDefault();
		report($('ss-new-url').value.trim());
	});

	$('ss-address-start').addEventListener('click', function (e) {
		e.preventDefault();
		var btn = e.target, newUrl = $('ss-new-url').value.trim(), oldUrl = $('ss-old-url').value.trim();
		btn.disabled = true;
		report(newUrl).then(function (r) {
			if (!r || r.blockers) { btn.disabled = false; return; }
			var prog = $('ss-address-progress');
			return window.SiteShifterJobs.run('address_change', { old_url: oldUrl, new_url: newUrl }, prog, function (job) { lastJob = job; }).then(function (job) {
				btn.disabled = false;
				if (!job || job.status !== 'done' || !job.result) { return; }
				var res = job.result, out = $('ss-address-result');
				out.innerHTML = '<p><strong>' + esc(cfg.i18n.changed.replace('%d', res.rows_changed)) + '</strong></p>' +
					(res.leftover_total ? '<p>' + esc(cfg.i18n.leftovers.replace('%d', res.leftover_total)) + '</p>' : '<p>' + esc(cfg.i18n.noLeftovers) + '</p>') +
					'<p>' + esc(cfg.i18n.login) + ' <a href="' + esc(res.new) + '/wp-login.php">' + esc(res.new) + '/wp-login.php</a></p>' +
					'<p><button class="button" id="ss-rollback">' + esc(cfg.i18n.rollback) + '</button></p>';
				$('ss-rollback').addEventListener('click', function (ev) {
					ev.preventDefault();
					ev.target.disabled = true;
					window.SiteShifterJobs.run('rollback', { job: job.id }, $('ss-address-progress'));
				});
			});
		});
	});

	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
})();

/* Export screen */
(function () {
	'use strict';
	var cfg = window.siteshifterJobs || {};
	var $ = function (id) { return document.getElementById(id); };
	if (!$('ss-export')) { return; }
	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function human(n) { var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return n.toFixed(i ? 1 : 0) + ' ' + u[i]; }
	var J = window.SiteShifterJobs;

	function scopeParams() {
		var p = { profile: document.querySelector('input[name=ss-profile]:checked').value };
		if (p.profile === 'custom') {
			var types = [].map.call(document.querySelectorAll('.ss-type:checked'), function (c) { return c.value; });
			p.types = types.length === document.querySelectorAll('.ss-type').length ? 'all' : types.join(',');
			[].forEach.call(document.querySelectorAll('.ss-part'), function (c) { p[c.getAttribute('data-part')] = c.checked ? 1 : 0; });
		}
		return p;
	}
	[].forEach.call(document.querySelectorAll('input[name=ss-profile]'), function (r) {
		r.addEventListener('change', function () { $('ss-custom').style.display = document.querySelector('input[name=ss-profile]:checked').value === 'custom' ? 'block' : 'none'; });
	});

	$('ss-export-start').addEventListener('click', function (e) {
		e.preventDefault();
		var btn = e.target, box = $('ss-export-report');
		btn.disabled = true;
		box.textContent = cfg.i18n.checking;
		J.call('checks', { params: scopeParams() }, J.ui(box)).then(function (r) {
			if (!r.ok) { box.textContent = r.message; return null; }
			J.renderReport(box, r.results);
			if (r.blockers) { return null; }
			var scope = document.querySelector('input[name=ss-scope]:checked').value;
			var params = scopeParams();
			params.scope = scope;
			params.all_tables = $('ss-all-tables').checked ? 1 : 0;
			return J.run('export', params, $('ss-export-progress'));
		}).then(function (job) {
			btn.disabled = false;
			if (!job || job.status !== 'done' || !job.result) { return; }
			var out = $('ss-export-result'), r = job.result;
			out.innerHTML = '<p><strong>' + esc(cfg.i18n.packageReady.replace('%1$s', human(r.bytes)).replace('%2$s', r.files).replace('%3$s', r.tables)) + '</strong></p>' +
				'<p>' + esc(cfg.i18n.packageNote) + '</p>' +
				'<p><button class="button button-primary" id="ss-download">' + esc(cfg.i18n.download) + '</button> <button class="button" id="ss-delete-package">' + esc(cfg.i18n.deletePackage) + '</button></p>';
			$('ss-download').addEventListener('click', function (ev) {
				ev.preventDefault();
				J.call('download_link', { id: job.id }, J.ui(out)).then(function (d) {
					if (!d.ok) { out.textContent = d.message; return; }
					window.location.href = cfg.ajaxUrl + '?action=' + encodeURIComponent(cfg.downloadAction) + '&job=' + job.id + '&token=' + d.token;
				});
			});
			$('ss-delete-package').addEventListener('click', function (ev) {
				ev.preventDefault();
				J.call('delete', { id: job.id }, J.ui(out)).then(function () { out.textContent = cfg.i18n.deleted; });
			});
		}).catch(function (err) { btn.disabled = false; box.textContent = cfg.i18n.failed + ' ' + err.message; });
	});
})();

/* Packages screen */
(function () {
	'use strict';
	var cfg = window.siteshifterJobs || {};
	var $ = function (id) { return document.getElementById(id); };
	var root = $('ss-packages');
	if (!root) { return; }
	var J = window.SiteShifterJobs;
	function esc(t) { var d = document.createElement('div'); d.textContent = String(t); return d.innerHTML; }
	function human(n) { var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return n.toFixed(i ? 1 : 0) + ' ' + u[i]; }

	function draw(list) {
		if (!list.length) { root.textContent = cfg.i18n.noPackages; return; }
		var rows = list.map(function (p) {
			var scope = (cfg.i18n.profiles[p.profile] || p.profile) + (p.manual_uploads ? ' (' + cfg.i18n.manualUploads + ')' : '');
			return '<tr><th class="check-column"><input type="checkbox" class="ss-pick" value="' + p.id + '"></th>' +
				'<td>' + esc(new Date(p.created * 1000).toLocaleString()) + '</td><td>' + esc(human(p.bytes)) + '</td><td>' + esc(scope) + '</td>' +
				'<td>' + esc(p.source) + '</td><td>' + (p.valid ? esc(cfg.i18n.checkedOk) : '<strong>' + esc(cfg.i18n.checkedBad) + '</strong>') + '</td>' +
				'<td><button class="button ss-dl" data-id="' + p.id + '">' + esc(cfg.i18n.download) + '</button> <button class="button ss-del" data-id="' + p.id + '">' + esc(cfg.i18n.deletePackage) + '</button></td></tr>';
		}).join('');
		root.innerHTML = '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>' + esc(cfg.i18n.colDate) + '</th><th>' + esc(cfg.i18n.colSize) + '</th><th>' + esc(cfg.i18n.colScope) + '</th><th>' + esc(cfg.i18n.colSource) + '</th><th>' + esc(cfg.i18n.colStatus) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>' +
			'<p><button class="button" id="ss-del-selected">' + esc(cfg.i18n.deleteSelected) + '</button></p>';
	}

	function load() {
		J.call('packages', { params: {} }, J.ui(root)).then(function (r) {
			if (!r.ok) { root.textContent = r.message; return; }
			draw(r.packages);
			if (r.retention) { $('ss-ret-on').checked = r.retention.enabled; $('ss-ret-count').value = r.retention.max_count; $('ss-ret-days').value = r.retention.max_days; }
		});
	}
	function remove(ids) {
		if (!ids.length || !window.confirm(cfg.i18n.confirmDelete)) { return; }
		J.call('delete_many', { params: { ids: ids.join(',') } }, J.ui(root)).then(load);
	}

	root.addEventListener('click', function (e) {
		var t = e.target;
		if (t.classList.contains('ss-del')) { e.preventDefault(); remove([t.getAttribute('data-id')]); }
		if (t.id === 'ss-del-selected') { e.preventDefault(); remove([].map.call(root.querySelectorAll('.ss-pick:checked'), function (c) { return c.value; })); }
		if (t.classList.contains('ss-dl')) {
			e.preventDefault();
			var id = t.getAttribute('data-id');
			J.call('download_link', { id: id }, J.ui(root)).then(function (d) {
				if (d.ok) { window.location.href = cfg.ajaxUrl + '?action=' + encodeURIComponent(cfg.downloadAction) + '&job=' + id + '&token=' + d.token; }
			});
		}
	});
	$('ss-ret-save').addEventListener('click', function (e) {
		e.preventDefault();
		J.call('retention', { params: { enabled: $('ss-ret-on').checked ? 1 : 0, max_count: $('ss-ret-count').value, max_days: $('ss-ret-days').value } }, J.ui($('ss-ret-note'))).then(function (r) {
			$('ss-ret-note').textContent = r.ok ? cfg.i18n.saved : r.message;
			if (r.ok) { load(); }
		});
	});
	load();
})();
