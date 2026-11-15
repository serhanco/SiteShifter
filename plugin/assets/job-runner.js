/* SiteShifter job runner: drives a job slice by slice and shows live progress.
 * by Serhan Demirel — https://serhandemirel.com */
(function () {
	'use strict';

	var cfg = window.siteshifterJobs || {};
	var MAX_RETRIES = 5;
	var transport = 'rest';

	function post(op, payload) {
		if (transport === 'rest') {
			var url = cfg.restUrl + 'jobs' + (payload.id ? '/' + payload.id + '/' + op : '');
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

	function run(type, params, root) {
		var ui = bar(root);
		return resilient('start', { type: type, params: params || {} }, ui).then(function (r) {
			if (!r.ok) { throw new Error(r.message); }
			var id = r.job.id;
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

	window.SiteShifterJobs = { run: run };

	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-ss-host-test]');
		if (!b) { return; }
		e.preventDefault();
		b.disabled = true;
		run('host_test', { mb: 32 }, document.getElementById('ss-host-test-progress')).then(function () { b.disabled = false; });
	});
})();
