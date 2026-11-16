/* SiteShifter job runner: drives a job slice by slice and shows live progress.
 * by Serhan Demirel — https://serhandemirel.com */
(function () {
	'use strict';

	var cfg = window.siteshifterJobs || {};
	var MAX_RETRIES = 5;
	var transport = 'rest';

	function post(op, payload) {
		if (transport === 'rest') {
			var path = { checks: 'checks', site_check: 'site-check', packages: 'packages', delete_many: 'packages/delete', retention: 'retention',
				cloud_status: 'cloud/status', cloud_connect: 'cloud/connect', cloud_poll: 'cloud/poll', cloud_disconnect: 'cloud/disconnect',
				cloud_client: 'cloud/client', cloud_items: 'cloud/items', cloud_delete: 'cloud/delete' }[op];
			var inc = { upload_begin: 'incoming/begin', incoming: 'incoming', upload_end: 'end', incoming_delete: 'delete', import_check: 'check' }[op];
			var imp = { imports: 'imports', import_undo: 'imports/' + payload.id + '/undo', import_discard: 'imports/' + payload.id + '/discard' }[op];
			if (imp) { return fetch(cfg.restUrl + imp, { method: 'POST', headers: { 'X-WP-Nonce': cfg.restNonce, 'Content-Type': 'application/json' }, credentials: 'same-origin', cache: 'no-store', body: JSON.stringify(payload) }); }
			var url = cfg.restUrl + (inc ? (payload.id && inc.indexOf('/') < 0 && inc !== 'incoming' ? 'incoming/' + payload.id + '/' + inc : inc) : (path ? path : 'jobs' + (payload.id ? '/' + payload.id + '/' + op : '')));
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

	/* A paused job: the known cause and fix when there is a stable code, else the raw message. */
	function explain(job) {
		var p = job.code && cfg.i18n.problems ? cfg.i18n.problems[job.code] : null;
		if (!p) { return cfg.i18n.paused + ' ' + (job.error || ''); }
		return p.title + ' [' + job.code + ']. ' + p.fix + (job.error ? ' ' + cfg.i18n.technical + ' ' + job.error : '');
	}

	function bar(root) {
		root.innerHTML = '<div class="ss-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="background:#dcdcde;border-radius:3px;height:22px;max-width:520px"><div class="ss-fill" style="background:#2271b1;height:100%;width:0;border-radius:3px;transition:width .3s"></div></div><p class="ss-line" aria-live="polite"></p>';
		var fill = root.querySelector('.ss-fill'), line = root.querySelector('.ss-line'), barEl = root.querySelector('.ss-bar');
		return {
			set: function (job) {
				fill.style.width = job.percent + '%';
				barEl.setAttribute('aria-valuenow', String(job.percent));
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
				if (job.status === 'paused') { ui.text(explain(job)); return job; }
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

	window.SiteShifterJobs = { run: run, bar: bar, sleep: sleep, call: resilient, renderReport: renderReport, ui: function (root) { return { note: function (t) { root.textContent = t; } }; } };

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

	/* Measure first; warn about a huge uploads folder or too little disk and offer the "without media" way. */
	function advise(box, rows) {
		var progress = $('ss-export-progress'), choice = $('ss-export-choice');
		choice.innerHTML = '';
		box.insertAdjacentHTML('beforeend', '<p>' + esc(cfg.i18n.measuring) + '</p>');
		return J.run('measure', {}, progress).then(function (m) {
			if (!m || m.status !== 'done') { return false; }
			var p = scopeParams();
			p.threshold_mb = Math.round((parseFloat($('ss-threshold').value) || 2) * 1024);
			try { window.localStorage.setItem('ssThreshold', $('ss-threshold').value); } catch (e) { /* optional */ }
			return J.call('estimate', { id: m.id, params: p }, J.ui(progress)).then(function (e) {
				J.call('delete', { id: m.id }, J.ui(progress));
				if (!e.ok) { box.textContent = e.message; return false; }
				J.renderReport(box, rows.concat(e.rows));
				if (e.rows.some(function (r) { return r.level === 'block'; }) && !e.recommend_no_media) { return false; }
				if (!e.recommend_no_media) { return true; }
				return new Promise(function (resolve) {
					choice.innerHTML = '<p><button class="button button-primary" id="ss-no-media">' + esc(cfg.i18n.useNoMedia) + '</button> <button class="button" id="ss-go-anyway">' + esc(cfg.i18n.continueAnyway) + '</button></p>';
					$('ss-no-media').addEventListener('click', function (ev) {
						ev.preventDefault();
						document.querySelector('input[name=ss-profile][value=custom]').checked = true;
						$('ss-custom').style.display = 'block';
						var m2 = document.querySelector('.ss-part[data-part=media]'); if (m2) { m2.checked = false; }
						choice.innerHTML = '<p>' + esc(cfg.i18n.mediaOffNote) + '</p>';
						resolve(false);
					});
					$('ss-go-anyway').addEventListener('click', function (ev) {
						ev.preventDefault();
						choice.innerHTML = '';
						if (e.rows.some(function (r) { return r.level === 'block'; })) { resolve(false); } else { resolve(true); }
					});
				});
			});
		});
	}
	try { var saved = window.localStorage.getItem('ssThreshold'); if (saved) { $('ss-threshold').value = saved; } } catch (err) { /* optional */ }

	$('ss-export-start').addEventListener('click', function (e) {
		e.preventDefault();
		var btn = e.target, box = $('ss-export-report');
		btn.disabled = true;
		box.textContent = cfg.i18n.checking;
		var rows = [];
		J.call('checks', { params: scopeParams() }, J.ui(box)).then(function (r) {
			if (!r.ok) { box.textContent = r.message; return null; }
			rows = r.results;
			J.renderReport(box, rows);
			if (r.blockers) { return null; }
			return advise(box, rows).then(function (go) {
				if (!go) { return null; }
				var scope = document.querySelector('input[name=ss-scope]:checked').value;
				var params = scopeParams();
				params.scope = scope;
				params.all_tables = $('ss-all-tables').checked ? 1 : 0;
				return J.run('export', params, $('ss-export-progress'));
			});
		}).then(function (job) {
			btn.disabled = false;
			if (!job || job.status !== 'done' || !job.result) { return; }
			var out = $('ss-export-result'), r = job.result;
			out.innerHTML = '<p><strong>' + esc(cfg.i18n.packageReady.replace('%1$s', human(r.bytes)).replace('%2$s', r.files).replace('%3$s', r.tables)) + '</strong></p>' +
				'<p>' + esc(cfg.i18n.packageNote) + '</p>' +
				'<p><button class="button button-primary" id="ss-download">' + esc(cfg.i18n.download) + '</button> <button class="button" id="ss-pull">' + esc(cfg.i18n.pullLink) + '</button> <button class="button" id="ss-delete-package">' + esc(cfg.i18n.deletePackage) + '</button></p><div id="ss-pull-out" aria-live="polite"></div>';
			$('ss-pull').addEventListener('click', function (ev) {
				ev.preventDefault();
				J.call('pull_link', { id: job.id }, J.ui($('ss-pull-out'))).then(function (d) {
					if (!d.ok) { $('ss-pull-out').textContent = d.message; return; }
					var link = cfg.ajaxUrl + '?action=' + encodeURIComponent(cfg.pullAction) + '&job=' + job.id + '&token=' + d.token;
					$('ss-pull-out').innerHTML = '<p>' + esc(cfg.i18n.pullNote) + '</p><p><input type="text" readonly class="large-text code" id="ss-pull-url" value="' + esc(link) + '"></p>';
					$('ss-pull-url').addEventListener('focus', function () { this.select(); });
				});
			});
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

/* Import screen: chunked upload, list, dry-run check */
(function () {
	'use strict';
	var cfg = window.siteshifterJobs || {};
	var $ = function (id) { return document.getElementById(id); };
	if (!$('ss-upload')) { return; }
	var J = window.SiteShifterJobs;
	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function human(n) { var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return n.toFixed(i ? 1 : 0) + ' ' + u[i]; }

	/* One piece: REST first, admin-ajax if REST is blocked; retried with back-off. */
	function sendPiece(id, offset, blob, useAjax, attempt) {
		var fd = new FormData();
		fd.append('piece', blob, 'piece.bin');
		fd.append('offset', offset);
		var req;
		if (useAjax) {
			fd.append('action', cfg.uploadAction); fd.append('_ajax_nonce', cfg.ajaxNonce); fd.append('id', id);
			req = fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
		} else {
			req = fetch(cfg.restUrl + 'incoming/' + id + '/piece', { method: 'POST', headers: { 'X-WP-Nonce': cfg.restNonce }, body: fd, credentials: 'same-origin' });
		}
		return req.then(function (res) { return res.text(); }).then(function (text) {
			var d; try { d = JSON.parse(text); } catch (e) { d = null; }
			if (!d || typeof d.ok === 'undefined') { throw new Error('transport'); }
			return d;
		}).catch(function (err) {
			if (attempt >= 5) { throw err; }
			return J.sleep(1000 * Math.pow(2, attempt)).then(function () { return sendPiece(id, offset, blob, useAjax || attempt === 0, attempt + 1); });
		});
	}

	function upload(file, ui) {
		return J.call('upload_begin', { params: { name: file.name, total: file.size } }, ui).then(function (b) {
			if (!b.ok) { throw new Error(b.message); }
			var id = b.id, offset = 0;
			function next() {
				if (offset >= file.size) { return J.call('upload_end', { id: id }, ui); }
				var end = Math.min(file.size, offset + cfg.chunkBytes);
				return sendPiece(id, offset, file.slice(offset, end), false, 0).then(function (d) {
					if (!d.ok) { throw new Error(d.message); }
					offset = d.size; // a repeated or lost piece makes the server report where it really is
					ui.set({ percent: Math.floor(100 * offset / file.size), detail: human(offset) + ' / ' + human(file.size) });
					return next();
				});
			}
			return next();
		});
	}

	document.addEventListener('siteshifter:incoming', function () { list(); });
	function list() {
		J.call('incoming', { params: {} }, J.ui($('ss-incoming'))).then(function (r) {
			var box = $('ss-incoming');
			if (!r.ok) { box.textContent = r.message; return; }
			if (!r.packages.length) { box.textContent = cfg.i18n.noIncoming; return; }
			box.innerHTML = '<table class="widefat striped"><tbody>' + r.packages.map(function (p) {
				return '<tr><td>' + esc(p.name) + '</td><td>' + esc(human(p.bytes)) + '</td><td>' + esc(new Date(p.created * 1000).toLocaleString()) + '</td><td>' +
					'<button class="button ss-chk" data-id="' + p.id + '">' + esc(cfg.i18n.check) + '</button> <button class="button ss-rm" data-id="' + p.id + '">' + esc(cfg.i18n.deletePackage) + '</button></td></tr>';
			}).join('') + '</tbody></table>';
		});
	}

	/* Replace-mode import: confirmation, live progress, result, undo. */
	function importControls(id) {
		var wrap = document.createElement('div');
		var t = cfg.i18n.merge, sel = function (key) {
			return '<label>' + esc(t.labels[key]) + ' <select class="ss-pol" data-key="' + key + '">' + t.choices[key].map(function (c) { return '<option value="' + c + '">' + esc(t.names[c]) + '</option>'; }).join('') + '</select></label> ';
		};
		wrap.innerHTML = '<p><label><input type="radio" name="ss-mode-' + id + '" class="ss-mode" value="replace" checked> ' + esc(t.replace) + '</label><br>' +
			'<label><input type="radio" name="ss-mode-' + id + '" class="ss-mode" value="merge"> ' + esc(t.merge) + '</label></p>' +
			'<div class="ss-merge" hidden><p>' + ['themes', 'plugins', 'media', 'other_files', 'settings', 'users', 'content', 'posts'].map(sel).join('<br>') + '</p><p class="description">' + esc(t.note) + '</p></div>' +
			'<p><label><input type="checkbox" class="ss-ok"> ' + esc(cfg.i18n.confirmReplace) + '</label></p>' +
			'<p><button class="button button-primary ss-go" disabled>' + esc(cfg.i18n.importNow) + '</button></p><div class="ss-imp-progress"></div><div class="ss-imp-result"></div>';
		var modes = wrap.querySelectorAll('.ss-mode');
		function mode() { return wrap.querySelector('.ss-mode:checked').value; }
		Array.prototype.forEach.call(modes, function (m) { m.addEventListener('change', function () { wrap.querySelector('.ss-merge').hidden = mode() !== 'merge'; }); });
		var ok = wrap.querySelector('.ss-ok'), go = wrap.querySelector('.ss-go');
		ok.addEventListener('change', function () { go.disabled = !ok.checked; });
		go.addEventListener('click', function (e) {
			e.preventDefault();
			go.disabled = true; ok.disabled = true;
			var res = wrap.querySelector('.ss-imp-result');
			var policy = {};
			Array.prototype.forEach.call(wrap.querySelectorAll('.ss-pol'), function (el) { policy[el.getAttribute('data-key')] = el.value; });
			J.run('import', { package: id, mode: mode(), policy: policy }, wrap.querySelector('.ss-imp-progress')).then(function (job) {
				if (!job || job.status !== 'done') { return; }
				var r = job.result || {}, html = '<div class="notice notice-success inline"><p><strong>' + esc(cfg.i18n.importDone) + '</strong></p></div>';
				if (r.leftovers && r.leftovers.length) { html += '<p>' + esc(cfg.i18n.importLeftovers.replace('%d', r.leftovers.length)) + '</p>'; }
				if (r.row_mismatches && r.row_mismatches.length) { html += '<p>' + esc(cfg.i18n.importMismatch.replace('%d', r.row_mismatches.length)) + '</p>'; }
				if (r.merge) {
					html += '<p>' + esc(cfg.i18n.mergeResult) + ' <code>' + esc(JSON.stringify(r.merge.files)) + '</code> <code>' + esc(JSON.stringify(r.merge.database)) + '</code></p>';
				}
				if (r.merge && r.merge.flags && r.merge.flags.length) {
					html += '<p><strong>' + esc(cfg.i18n.mergeFlags) + '</strong></p><ul>' + r.merge.flags.slice(0, 50).map(function (f) { return '<li>' + esc(f.kind + ' ' + f.id + ': ' + f.what) + '</li>'; }).join('') + '</ul>';
				}
				html += '<p>' + esc(cfg.i18n.importLogin) + ' <a href="' + esc(cfg.loginUrl) + '">' + esc(cfg.loginUrl) + '</a></p>';
				res.innerHTML = html;
				imports();
			});
		});
		return wrap;
	}

	function imports() {
		var box = $('ss-imports');
		if (!box) { return; }
		J.call('imports', { params: {} }, J.ui(box)).then(function (r) {
			if (!r.ok || !r.imports.length) { box.textContent = ''; return; }
			box.innerHTML = '<h2>' + esc(cfg.i18n.appliedImports) + '</h2><table class="widefat striped"><tbody>' + r.imports.map(function (i) {
				return '<tr><td>' + esc(new Date(i.created * 1000).toLocaleString()) + '</td><td>' + esc(cfg.i18n.importStates[i.state] || i.state) + '</td><td>' +
					(i.can_undo ? '<button class="button ss-undo" data-imp="' + i.id + '">' + esc(cfg.i18n.undoImport) + '</button> ' : '') +
					(i.can_discard ? '<button class="button ss-discard" data-imp="' + i.id + '">' + esc(cfg.i18n.discardBackup) + '</button>' : '') + '</td></tr>';
			}).join('') + '</tbody></table>';
		});
	}

	if ($('ss-imports')) {
		$('ss-imports').addEventListener('click', function (e) {
			var t = e.target, id = t.getAttribute && t.getAttribute('data-imp');
			if (!id) { return; }
			e.preventDefault();
			var undo = t.classList.contains('ss-undo');
			if (!window.confirm(undo ? cfg.i18n.confirmUndo : cfg.i18n.confirmDiscard)) { return; }
			t.disabled = true;
			J.call(undo ? 'import_undo' : 'import_discard', { id: id }, J.ui($('ss-imports'))).then(function (r) {
				if (!r.ok) { window.alert(r.message); }
				imports();
			});
		});
	}

	if ($('ss-fetch')) {
		$('ss-fetch').addEventListener('click', function (e) {
			e.preventDefault();
			var url = $('ss-fetch-url').value.trim(), btn = e.target;
			if (!url) { $('ss-fetch-progress').textContent = cfg.i18n.fetchEmpty; return; }
			btn.disabled = true;
			J.run('fetch', { url: url }, $('ss-fetch-progress')).then(function (job) {
				btn.disabled = false;
				if (job && job.status === 'done') { $('ss-fetch-url').value = ''; list(); }
			}).catch(function (err) { btn.disabled = false; $('ss-fetch-progress').textContent = cfg.i18n.failed + ' ' + err.message; });
		});
	}

	$('ss-upload').addEventListener('click', function (e) {
		e.preventDefault();
		var f = $('ss-file').files[0], btn = e.target;
		if (!f) { $('ss-upload-progress').textContent = cfg.i18n.chooseFile; return; }
		btn.disabled = true;
		var ui = J.bar($('ss-upload-progress'));
		upload(f, ui).then(function (r) {
			btn.disabled = false;
			if (!r.ok) { throw new Error(r.message); }
			ui.set({ percent: 100, detail: cfg.i18n.uploaded });
			list();
		}).catch(function (err) { btn.disabled = false; ui.text(cfg.i18n.failed + ' ' + err.message); });
	});

	$('ss-incoming').addEventListener('click', function (e) {
		var t = e.target, id = t.getAttribute && t.getAttribute('data-id');
		if (!id) { return; }
		e.preventDefault();
		if (t.classList.contains('ss-rm')) {
			if (window.confirm(cfg.i18n.confirmDelete)) { J.call('incoming_delete', { id: id }, J.ui($('ss-incoming'))).then(list); }
		}
		if (t.classList.contains('ss-chk')) {
			var box = $('ss-import-report');
			box.textContent = cfg.i18n.checking;
			J.call('import_check', { id: id }, J.ui(box)).then(function (r) {
				if (!r.ok) { box.textContent = r.message; return; }
				var s = r.package, head = '';
				if (s) {
					head = '<p><strong>' + esc(cfg.i18n.summary.replace('%1$s', s.source).replace('%2$s', human(s.bytes)).replace('%3$s', s.wp_version || '?').replace('%4$s', s.tables).replace('%5$s', (cfg.i18n.profiles[s.profile] || s.profile))) + '</strong></p>';
				}
				var holder = document.createElement('div');
				J.renderReport(holder, r.rows);
				box.innerHTML = head;
				box.appendChild(holder);
				if (!r.blockers && s) { box.appendChild(importControls(id)); }
			});
		}
	});
	list();
	imports();
})();

/* Cloud screen: connect Google Drive (device code), upload packages, bring them back. */
(function () {
	'use strict';
	var cfg = window.siteshifterJobs || {};
	var $ = function (id) { return document.getElementById(id); };
	var root = $('ss-cloud');
	if (!root) { return; }
	var J = window.SiteShifterJobs, t = cfg.i18n.cloud;
	function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
	function human(n) { var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return n.toFixed(i ? 1 : 0) + ' ' + u[i]; }
	function fmt(s) { var a = [].slice.call(arguments, 1); return s.replace(/%(\d)\$s|%s/g, function (m, n) { return esc(n ? a[n - 1] : a.shift()); }); }
	var polling = false;

	function status() {
		J.call('cloud_status', { params: {} }, J.ui(root)).then(function (r) {
			if (!r.ok) { root.textContent = r.message; return; }
			$('ss-cloud-own').checked = !!r.own_client;
			if (!r.configured) {
				root.innerHTML = '<p>' + esc(t.notConfigured) + '</p>';
				$('ss-cloud-advanced').open = true;
				return;
			}
			if (r.connected) { connected(r.account); return; }
			if (r.pending) { showCode(r.pending); return; }
			root.innerHTML = '<p>' + esc(t.intro) + '</p><p><button class="button button-primary" id="ss-cloud-connect">' + esc(t.connect) + '</button></p>';
		});
	}

	function showCode(p) {
		root.innerHTML = '<ol><li>' + esc(t.step1) + ' <a href="' + esc(p.verification_url) + '" target="_blank" rel="noopener">' + esc(p.verification_url) + '</a></li>' +
			'<li>' + esc(t.step2) + ' <code style="font-size:1.6em;padding:4px 10px;user-select:all">' + esc(p.user_code) + '</code></li>' +
			'<li>' + esc(t.step3) + '</li></ol><p id="ss-cloud-wait" aria-live="polite">' + esc(t.waiting) + '</p>' +
			'<p><button class="button" id="ss-cloud-cancel">' + esc(t.cancel) + '</button></p>';
		if (!polling) { poll(p.interval || 5); }
	}

	function poll(interval) {
		polling = true;
		J.sleep(interval * 1000).then(function () {
			if (!$('ss-cloud-wait')) { polling = false; return; }
			return J.call('cloud_poll', { params: {} }, J.ui($('ss-cloud-wait'))).then(function (r) {
				if (!r.ok) { polling = false; $('ss-cloud-wait').textContent = r.message; return; }
				if (r.status === 'pending') { return poll(interval); }
				polling = false;
				if (r.status === 'approved') { status(); return; }
				root.innerHTML = '<p>' + esc(r.status === 'denied' ? t.denied : t.expired) + '</p><p><button class="button button-primary" id="ss-cloud-connect">' + esc(t.connect) + '</button></p>';
			});
		});
	}

	function connected(account) {
		root.innerHTML = '<p>' + fmt(t.connectedAs, account || '?') + ' <button class="button-link" id="ss-cloud-disconnect">' + esc(t.disconnect) + '</button></p>' +
			'<h2>' + esc(t.uploadTitle) + '</h2><div id="ss-cloud-local"></div><div id="ss-cloud-up-progress"></div>' +
			'<h2>' + esc(t.driveTitle) + '</h2><p class="description">' + esc(t.sameAccount) + '</p><div id="ss-cloud-items"></div><div id="ss-cloud-fetch-progress"></div>';
		local();
		items();
	}

	function local() {
		var box = $('ss-cloud-local');
		J.call('packages', { params: {} }, J.ui(box)).then(function (r) {
			if (!r.ok) { box.textContent = r.message; return; }
			var ok = r.packages.filter(function (p) { return p.valid; });
			if (!ok.length) { box.textContent = t.noLocal; return; }
			box.innerHTML = '<table class="widefat striped"><tbody>' + ok.map(function (p) {
				return '<tr><td>' + esc(new Date(p.created * 1000).toLocaleString()) + '</td><td>' + esc(human(p.bytes)) + '</td><td>' + esc(cfg.i18n.profiles[p.profile] || p.profile) + '</td>' +
					'<td><button class="button ss-cloud-up" data-id="' + esc(p.id) + '">' + esc(t.upload) + '</button></td></tr>';
			}).join('') + '</tbody></table>';
		});
	}

	function items() {
		var box = $('ss-cloud-items');
		J.call('cloud_items', { params: {} }, J.ui(box)).then(function (r) {
			if (!r.ok) { box.textContent = r.message; return; }
			var space = r.space == null ? '' : '<p class="description">' + fmt(t.free, human(r.space)) + '</p>';
			if (!r.items.length) { box.innerHTML = '<p>' + esc(t.noItems) + '</p>' + space; return; }
			box.innerHTML = '<table class="widefat striped"><thead><tr><th>' + esc(t.colName) + '</th><th>' + esc(cfg.i18n.colDate) + '</th><th>' + esc(cfg.i18n.colSize) + '</th><th>' + esc(t.colChecksum) + '</th><th></th></tr></thead><tbody>' + r.items.map(function (i) {
				return '<tr><td>' + esc(i.name) + '</td><td>' + esc(new Date(i.created * 1000).toLocaleString()) + '</td><td>' + esc(human(i.size)) + '</td><td>' + esc(i.digest ? t.hasChecksum : t.noChecksum) + '</td>' +
					'<td><button class="button button-primary ss-cloud-get" data-id="' + esc(i.id) + '">' + esc(t.bring) + '</button> <button class="button ss-cloud-del" data-id="' + esc(i.id) + '">' + esc(t.remove) + '</button></td></tr>';
			}).join('') + '</tbody></table>' + space;
		});
	}

	root.addEventListener('click', function (e) {
		var el = e.target, id = el.getAttribute('data-id');
		if (el.id === 'ss-cloud-connect') {
			e.preventDefault();
			el.disabled = true;
			J.call('cloud_connect', { params: {} }, J.ui(root)).then(function (r) {
				if (!r.ok) { root.textContent = r.message; return; }
				showCode(r.pending);
			});
		}
		if (el.id === 'ss-cloud-cancel' || el.id === 'ss-cloud-disconnect') {
			e.preventDefault();
			if (el.id === 'ss-cloud-disconnect' && !window.confirm(t.confirmDisconnect)) { return; }
			J.call('cloud_disconnect', { params: {} }, J.ui(root)).then(status);
		}
		if (el.classList.contains('ss-cloud-up')) {
			e.preventDefault();
			[].forEach.call(root.querySelectorAll('.ss-cloud-up'), function (b) { b.disabled = true; });
			J.run('cloud_upload', { job: id }, $('ss-cloud-up-progress')).then(function (job) {
				[].forEach.call(root.querySelectorAll('.ss-cloud-up'), function (b) { b.disabled = false; });
				if (job && job.status === 'done') { $('ss-cloud-up-progress').querySelector('.ss-line').textContent = t.uploaded; items(); }
			});
		}
		if (el.classList.contains('ss-cloud-get')) {
			e.preventDefault();
			[].forEach.call(root.querySelectorAll('.ss-cloud-get'), function (b) { b.disabled = true; });
			J.run('cloud_fetch', { item: id }, $('ss-cloud-fetch-progress')).then(function (job) {
				[].forEach.call(root.querySelectorAll('.ss-cloud-get'), function (b) { b.disabled = false; });
				if (job && job.status === 'done') {
					$('ss-cloud-fetch-progress').querySelector('.ss-line').innerHTML = esc(t.fetched) + ' <a href="' + esc(cfg.importUrl) + '">' + esc(t.goImport) + '</a>';
				}
			});
		}
		if (el.classList.contains('ss-cloud-del')) {
			e.preventDefault();
			if (!window.confirm(t.confirmDelete)) { return; }
			J.call('cloud_delete', { params: { item: id } }, J.ui($('ss-cloud-items'))).then(function (r) {
				if (!r.ok) { $('ss-cloud-items').textContent = r.message; return; }
				items();
			});
		}
	});

	$('ss-cloud-save-client').addEventListener('click', function (e) {
		e.preventDefault();
		var own = $('ss-cloud-own').checked;
		J.call('cloud_client', { params: { client_id: own ? $('ss-cloud-id').value.trim() : '', client_secret: own ? $('ss-cloud-secret').value.trim() : '' } }, J.ui($('ss-cloud-client-note'))).then(function (r) {
			$('ss-cloud-client-note').textContent = r.ok ? cfg.i18n.saved : r.message;
			$('ss-cloud-secret').value = '';
			if (r.ok) { status(); }
		});
	});
	status();
})();
