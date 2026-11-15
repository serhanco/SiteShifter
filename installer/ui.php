<?php
/**
 * SiteShifter installer — web front end.
 * by Serhan Demirel — https://serhandemirel.com
 *
 * This file is concatenated after the library classes by
 * bin/build-installer.php into a single dist/installer.php.
 *
 * Safety:
 *  - The first browser that opens the installer claims it (a random key
 *    in a cookie); every other visitor is refused.
 *  - Optionally set SITESHIFTER_PASSWORD below before uploading.
 *  - The installer stops working 6 hours after it was uploaded.
 *  - Secrets are stored in siteshifter-work/*.php files that cannot be
 *    downloaded, and everything is deleted at the end.
 */

use SiteShifter\Installer\Installer;
use SiteShifter\Preflight\Preflight;
use SiteShifter\Preflight\Result;

if (!defined('SITESHIFTER_PASSWORD')) {
    define('SITESHIFTER_PASSWORD', ''); // optional: set a password before uploading
}
const SS_VERSION = '0.2.0';
const SS_MAX_AGE = 6 * 3600;

error_reporting(E_ALL);
ini_set('display_errors', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(0);

$ssDir = str_replace('\\', '/', __DIR__);
$ssWork = $ssDir . '/siteshifter-work';

function ss_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Stores data in a .php file that prints nothing if requested over HTTP. */
function ss_store(string $file, ?array $data = null)
{
    if ($data === null) {
        if (!is_file($file)) {
            return null;
        }
        $raw = (string) file_get_contents($file);

        return json_decode(substr($raw, strpos($raw, "\n") + 1), true);
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.php', '<?php // Silence.');
    }
    file_put_contents($file, "<?php exit; ?>\n" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $data;
}

function ss_guard(string $work): void
{
    if (time() - (int) @filemtime(__FILE__) > SS_MAX_AGE && !is_file($work . '/lock.php')) {
        ss_deny('This installer has expired (older than 6 hours). Upload it again to use it.');
    }
    $lock = ss_store($work . '/lock.php');
    $cookie = $_COOKIE['siteshifter_key'] ?? '';
    if ($lock === null) {
        if (SITESHIFTER_PASSWORD !== '' && !hash_equals(SITESHIFTER_PASSWORD, (string) ($_POST['password'] ?? ''))) {
            ss_login();
        }
        $key = bin2hex(random_bytes(24));
        ss_store($work . '/lock.php', ['key' => hash('sha256', $key), 'created' => time()]);
        setcookie('siteshifter_key', $key, ['expires' => time() + SS_MAX_AGE, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
        $_COOKIE['siteshifter_key'] = $key;

        return;
    }
    if ($cookie === '' || !hash_equals($lock['key'], hash('sha256', $cookie))) {
        ss_deny('This installer is already in use from another browser. To start over, delete the siteshifter-work folder by FTP / File Manager.');
    }
}

function ss_deny(string $message): void
{
    if (isset($_POST['action'])) {
        ss_json(['error' => $message], 403);
    }
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>SiteShifter</title><body style="font:16px system-ui;padding:40px;max-width:640px;margin:auto"><h1>SiteShifter</h1><p>' . htmlspecialchars($message) . '</p>' . ss_credit();
    exit;
}

function ss_credit(): string
{
    return '<p style="color:#888;font-size:13px;margin-top:32px">SiteShifter by <a href="https://serhandemirel.com" target="_blank" rel="noopener">Serhan Demirel</a></p>';
}

function ss_login(): void
{
    if (isset($_POST['action'])) {
        ss_json(['error' => 'Password required.'], 403);
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>SiteShifter</title><body style="font:16px system-ui;padding:40px;max-width:480px;margin:auto"><h1>SiteShifter</h1><form method="post"><p><label>Installer password<br><input type="password" name="password" autofocus style="font-size:16px;padding:8px;width:100%"></label></p><button style="font-size:16px;padding:8px 16px">Open</button></form>' . ss_credit();
    exit;
}

/** Finds packages, zips and SQL files next to the installer. */
function ss_sources(string $dir): array
{
    $out = ['zips' => [], 'sqls' => []];
    foreach ((array) scandir($dir) as $f) {
        if (!is_file("{$dir}/{$f}")) {
            continue;
        }
        if (preg_match('/\.zip$/i', $f)) {
            $out['zips'][] = ['name' => $f, 'size' => (int) filesize("{$dir}/{$f}")];
        } elseif (preg_match('/\.sql(\.gz)?$/i', $f)) {
            $out['sqls'][] = ['name' => $f, 'size' => (int) filesize("{$dir}/{$f}")];
        }
    }

    return $out;
}

function ss_settings(string $dir, array $in): array
{
    $file = static function ($name) use ($dir) {
        $name = basename((string) $name);

        return $name !== '' && is_file("{$dir}/{$name}") ? "{$dir}/{$name}" : null;
    };

    return [
        'dir' => $dir,
        'zip' => $file($in['zip'] ?? ''),
        'sql' => $file($in['sql'] ?? ''),
        'db' => ['host' => trim($in['db_host'] ?? 'localhost') ?: 'localhost', 'user' => trim($in['db_user'] ?? ''), 'pass' => (string) ($in['db_pass'] ?? ''), 'name' => trim($in['db_name'] ?? '')],
        'new_url' => rtrim(trim($in['new_url'] ?? ''), '/'),
        'old_url' => trim($in['old_url'] ?? '') ?: null,
        'old_path' => trim($in['old_path'] ?? '') ?: null,
        'proxy_https' => ($in['proxy_https'] ?? '1') !== '0',
    ];
}

function ss_deadline(): float
{
    $limit = (int) ini_get('max_execution_time');
    // Short requests keep the percentages moving on screen; long enough to make real progress.
    $budget = $limit > 0 ? max(1, min(3, $limit * 0.5)) : 3;

    return microtime(true) + $budget;
}

function ss_current_url(): string
{
    $https = \SiteShifter\Support\Https::isSecureRequest($_SERVER);
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}

// ------------------------------------------------------------------ router

ss_guard($ssWork);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    try {
        switch ($action) {
            case 'check':
                $settings = ss_settings($ssDir, $_POST);
                $installer = new Installer($settings);
                $inspection = $installer->inspect();
                $report = $installer->preflight($inspection);
                ss_store($ssWork . '/session.php', ['settings' => $settings, 'inspection' => $inspection]);
                ss_json([
                    'type' => $inspection['type'],
                    'old_url' => $settings['old_url'] ?: ($inspection['manifest']['site']['home'] ?? $inspection['manifest']['site']['url'] ?? null),
                    'old_path' => $inspection['manifest']['site']['abspath'] ?? null,
                    'prefix' => $inspection['manifest']['site']['table_prefix'] ?? null,
                    'report' => $report->toArray(),
                    'blocked' => $report->hasBlockers(),
                ]);
                // no break
            case 'start':
                $session = ss_store($ssWork . '/session.php');
                if (!$session) {
                    ss_json(['error' => 'Run the check first.'], 400);
                }
                $state = Installer::initialState($session['inspection']);
                ss_store($ssWork . '/state.php', $state);
                ss_json(['ok' => true]);
                // no break
            case 'run':
                $session = ss_store($ssWork . '/session.php');
                $state = ss_store($ssWork . '/state.php');
                if (!$session || !$state) {
                    ss_json(['error' => 'Nothing to run.'], 400);
                }
                $installer = new Installer($session['settings']);
                try {
                    $state = $installer->run($state, ss_deadline());
                } catch (\Throwable $e) {
                    ss_store($ssWork . '/state.php', $state);
                    ss_json(['error' => $e->getMessage(), 'step' => $state['step'], 'can_rollback' => !empty($state['backup_tables']) || is_file($ssWork . '/wp-config.backup.php')], 500);
                }
                ss_store($ssWork . '/state.php', $state);
                [$step, $percent, $stepPercent, $detail] = Installer::progress($state);
                ss_json(['done' => $state['done'], 'step' => $step, 'percent' => $percent, 'step_percent' => $stepPercent, 'detail' => $detail, 'step_index' => array_search($step, Installer::STEPS, true) + 1, 'step_count' => count(Installer::STEPS) - 1, 'result' => $state['done'] ? $state['result'] : null, 'log' => array_slice($state['log'], -5)]);
                // no break
            case 'rollback':
                $session = ss_store($ssWork . '/session.php');
                $state = ss_store($ssWork . '/state.php');
                if ($session && $state) {
                    (new Installer($session['settings']))->rollback($state);
                }
                ss_json(['ok' => true]);
                // no break
            case 'sitecheck':
                $session = ss_store($ssWork . '/session.php');
                $target = (string) ($session['settings']['new_url'] ?? ($_POST['new_url'] ?? ''));
                if (!preg_match('#^https?://#i', $target)) {
                    ss_json(['error' => 'No address to check.'], 400);
                }
                ss_json(['url' => $target, 'notes' => \SiteShifter\Support\SiteCheck::check($target)]);
                // no break
            case 'cleanup':
                $session = ss_store($ssWork . '/session.php');
                if ($session) {
                    (new Installer($session['settings']))->cleanup(!empty($_POST['remove_uploads']));
                }
                Installer::removeTree($ssWork);
                @unlink(__FILE__);
                ss_json(['ok' => true]);
        }
        ss_json(['error' => 'Unknown action'], 400);
    } catch (\Throwable $e) {
        ss_json(['error' => $e->getMessage()], 500);
    }
}

$ssSources = ss_sources($ssDir);
$ssDefaultUrl = ss_current_url();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SiteShifter Installer</title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--ink:#1d2330;--muted:#5d6676;--line:#e2e5ea;--accent:#2f5bea;--ok:#1f8a4c;--warn:#b26a00;--block:#c62828;--info:#4a5568}
@media (prefers-color-scheme:dark){:root{--bg:#12151b;--card:#1b1f27;--ink:#e8ebf0;--muted:#9aa3b2;--line:#2c323d;--accent:#7b9cff;--ok:#4cc38a;--warn:#f0b54a;--block:#ff6b6b;--info:#a0aec0}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:760px;margin:0 auto;padding:24px 16px 64px}h1{font-size:22px;margin:0 0 4px}h2{font-size:17px;margin:0 0 12px}
.sub{color:var(--muted);margin:0 0 24px}.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px;margin-bottom:16px}
label.check{display:flex;gap:8px;align-items:flex-start;font-weight:400;margin-top:14px}label.check input{width:auto;margin-top:3px}
label{display:block;font-weight:600;margin:12px 0 4px;font-size:14px}input,select{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line);border-radius:7px;background:var(--bg);color:var(--ink)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media (max-width:560px){.row{grid-template-columns:1fr}}
.hint{color:var(--muted);font-size:13px;font-weight:400}button{font:inherit;font-weight:600;padding:10px 18px;border-radius:7px;border:0;background:var(--accent);color:#fff;cursor:pointer}
button.secondary{background:transparent;color:var(--ink);border:1px solid var(--line)}button:disabled{opacity:.5;cursor:default}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.item{display:flex;gap:10px;padding:10px 0;border-top:1px solid var(--line)}.item:first-child{border-top:0}
.badge{flex:0 0 58px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;border-radius:5px;padding:2px 0;height:fit-content;color:#fff}
.b-ok{background:var(--ok)}.b-warn{background:var(--warn)}.b-block{background:var(--block)}.b-info{background:var(--info)}
.item p{margin:2px 0 0;color:var(--muted);font-size:14px}.fix{color:var(--ink)!important}.bar{height:10px;background:var(--line);border-radius:5px;overflow:hidden}.bar div{height:100%;width:0;background:var(--accent);transition:width .3s}.bar-sm div{background:var(--ok)}
.bar-sm{height:6px}.progress-head{display:flex;justify-content:space-between;gap:8px;margin:0 0 6px}
.err{color:var(--block);font-weight:600}.hidden{display:none}details summary{cursor:pointer;color:var(--muted);margin-top:12px}code{font-size:13px;word-break:break-all}
ul.check{padding-left:20px}ul.check li{margin:6px 0}
.sub a,footer a{color:inherit}footer{color:var(--muted);font-size:13px;text-align:center;margin-top:28px}
</style>
</head>
<body>
<main>
  <h1>SiteShifter</h1>
  <p class="sub">Shift WordPress sites safely · v<?= SS_VERSION ?> · by <a href="https://serhandemirel.com" target="_blank" rel="noopener">Serhan Demirel</a></p>

  <section class="card" id="s-setup">
    <h2>1. What to install</h2>
    <label>Site files <span class="hint">— a SiteShifter package, or a zip of public_html / wp-content</span></label>
    <select id="zip"><option value="">(no zip — files are already in this folder)</option>
      <?php foreach ($ssSources['zips'] as $z): ?><option value="<?= htmlspecialchars($z['name']) ?>"><?= htmlspecialchars($z['name']) ?> (<?= Preflight::human($z['size']) ?>)</option><?php endforeach; ?>
    </select>
    <label>Database export <span class="hint">— not needed for a SiteShifter package</span></label>
    <select id="sql"><option value="">(inside the zip)</option>
      <?php foreach ($ssSources['sqls'] as $s): ?><option value="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?> (<?= Preflight::human($s['size']) ?>)</option><?php endforeach; ?>
    </select>
    <?php if (!$ssSources['zips'] && !$ssSources['sqls']): ?><p class="err">No .zip or .sql files found next to installer.php. Upload them to this folder by FTP or File Manager, then reload.</p><?php endif; ?>

    <h2 style="margin-top:24px">2. New database</h2>
    <p class="hint">Create an empty database and a user with all privileges in your hosting panel (cPanel: MySQL Databases, hPanel: Databases).</p>
    <div class="row"><div><label>Database name</label><input id="db_name" autocomplete="off"></div><div><label>Database user</label><input id="db_user" autocomplete="off"></div></div>
    <div class="row"><div><label>Password</label><input id="db_pass" type="password" autocomplete="new-password"></div><div><label>Host</label><input id="db_host" value="localhost"></div></div>

    <h2 style="margin-top:24px">3. New address</h2>
    <label>Site URL</label><input id="new_url" value="<?= htmlspecialchars($ssDefaultUrl) ?>">
    <details><summary>Advanced</summary>
      <label>Old site URL <span class="hint">— read from the database when empty</span></label><input id="old_url" placeholder="https://old-domain.com">
      <label>Old server path <span class="hint">— guessed when empty, e.g. /home/user/public_html</span></label><input id="old_path">
      <label class="check"><input type="checkbox" id="proxy_https" checked> Trust a proxy's https header in wp-config.php <span class="hint">— needed behind Cloudflare or a load balancer; only added for https addresses</span></label>
    </details>
    <div class="actions"><button id="btn-check">Check everything</button></div>
    <p id="setup-error" class="err"></p>
  </section>

  <section class="card hidden" id="s-report">
    <h2>Pre-flight report</h2>
    <p class="hint" id="report-summary"></p>
    <div id="report"></div>
    <label class="hidden" id="ack-wrap" style="font-weight:400"><input type="checkbox" id="ack" style="width:auto"> I have read the warnings and want to continue.</label>
    <div class="actions"><button id="btn-start" disabled>Start the move</button><button class="secondary" id="btn-recheck">Check again</button></div>
  </section>

  <section class="card hidden" id="s-run">
    <h2>Moving the site</h2>
    <p class="progress-head"><strong id="run-overall">0%</strong> <span class="hint">overall</span></p>
    <div class="bar"><div id="bar"></div></div>
    <p class="progress-head" style="margin-top:16px"><span id="run-step">Starting…</span> <strong id="run-step-pct"></strong></p>
    <div class="bar bar-sm"><div id="bar-step"></div></div>
    <p id="run-detail" class="hint"></p>
    <p id="run-error" class="err"></p>
    <div class="actions hidden" id="run-fail"><button class="secondary" id="btn-retry">Try this step again</button><button class="secondary" id="btn-rollback">Restore the previous state</button></div>
  </section>

  <section class="card hidden" id="s-done">
    <h2>Done</h2>
    <div id="result"></div>
    <div class="actions"><a id="lnk-site" target="_blank"><button>Open the site</button></a><a id="lnk-admin" target="_blank"><button class="secondary">Open wp-admin</button></a><button class="secondary" id="btn-sitecheck">Check the live site</button></div>
    <div id="sitecheck" class="hint"></div>
    <h2 style="margin-top:24px">Clean up</h2>
    <p class="hint">Removes installer.php and its work folder. Do this now: the installer must not stay on a live site.</p>
    <label style="font-weight:400"><input type="checkbox" id="rm-uploads" style="width:auto" checked> Also delete the uploaded zip and SQL files</label>
    <div class="actions"><button id="btn-cleanup">Remove installer files</button></div>
  </section>
  <footer>SiteShifter by <a href="https://serhandemirel.com" target="_blank" rel="noopener">Serhan Demirel</a> · GPL-2.0-or-later</footer>
</main>
<script>
(function(){
  const $ = id => document.getElementById(id);
  const fields = ['zip','sql','db_name','db_user','db_pass','db_host','new_url','old_url','old_path'];
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  let lastReport = null;

  async function post(action, data) {
    const body = new URLSearchParams(Object.assign({action}, data || {}));
    const res = await fetch(location.pathname, {method:'POST', body, credentials:'same-origin'});
    let json; try { json = await res.json(); } catch (e) { throw new Error('The server returned an invalid response (HTTP ' + res.status + '). Check the PHP error log.'); }
    if (!res.ok || json.error) { const err = new Error(json.error || ('HTTP ' + res.status)); err.data = json; throw err; }
    return json;
  }
  function values(){ const o = {}; fields.forEach(f => o[f] = $(f).value); o.proxy_https = $('proxy_https').checked ? '1' : '0'; return o; }
  $('new_url').addEventListener('input', () => { $('new_url').dataset.touched = '1'; });

  async function check(){
    $('setup-error').textContent = ''; $('btn-check').disabled = true; $('btn-check').textContent = 'Checking…';
    try {
      const r = await post('check', values());
      // The page may have been opened over http; keep the site's https unless the user typed an address.
      const cur = $('new_url').value;
      if (!$('new_url').dataset.touched && r.old_url && /^https:\/\//i.test(r.old_url) && /^http:\/\//i.test(cur)
          && cur.replace(/^http:\/\//i, '').split('/')[0].toLowerCase() === r.old_url.replace(/^https:\/\//i, '').split('/')[0].toLowerCase()) {
        $('new_url').value = cur.replace(/^http:/i, 'https:');
        $('btn-check').disabled = false; $('btn-check').textContent = 'Check everything';
        return check();
      }
      lastReport = r;
      if (!$('old_url').value && r.old_url) $('old_url').placeholder = r.old_url;
      if (!$('old_path').value && r.old_path) $('old_path').placeholder = r.old_path;
      renderReport(r);
    } catch (e) { $('setup-error').textContent = e.message; }
    $('btn-check').disabled = false; $('btn-check').textContent = 'Check everything';
  }

  function renderReport(r){
    const order = {block:0, warn:1, info:2, ok:3};
    const items = r.report.slice().sort((a,b) => order[a.level]-order[b.level]);
    const counts = {block:0,warn:0,info:0,ok:0}; items.forEach(i => counts[i.level]++);
    $('report-summary').textContent = (r.type === 'package' ? 'SiteShifter package' : 'Manual export (zip + SQL)') + ' · ' + counts.block + ' blocking, ' + counts.warn + ' warnings, ' + counts.ok + ' passed. Old address: ' + (r.old_url || 'unknown') + '.';
    $('report').innerHTML = items.map(i => '<div class="item"><span class="badge b-'+i.level+'">'+i.level+'</span><div><strong>'+esc(i.title)+'</strong>'+(i.message?'<p>'+esc(i.message)+'</p>':'')+(i.fix && i.level!=='ok'?'<p class="fix">→ '+esc(i.fix)+'</p>':'')+'</div></div>').join('');
    $('s-report').classList.remove('hidden');
    $('ack-wrap').classList.toggle('hidden', counts.warn === 0 || r.blocked);
    $('ack').checked = false;
    $('btn-start').disabled = r.blocked || counts.warn > 0;
    $('s-report').scrollIntoView({behavior:'smooth'});
  }

  async function start(){
    $('btn-start').disabled = true; $('btn-recheck').disabled = true;
    try { await post('start'); } catch (e) { alert(e.message); return; }
    $('s-run').classList.remove('hidden'); $('s-run').scrollIntoView({behavior:'smooth'});
    loop();
  }

  const stepNames = {backup:'Backing up existing tables', extract:'Extracting files', core:'Installing WordPress core', config:'Writing wp-config.php', import:'Importing the database', replace:'Replacing the old address', finalize:'Clearing caches and fixing server files', verify:'Verifying the result', done:'Finished'};
  async function loop(){
    $('run-error').textContent = ''; $('run-fail').classList.add('hidden');
    try {
      while (true) {
        const r = await post('run');
        $('bar').style.width = r.percent + '%';
        $('run-overall').textContent = r.percent + '%';
        $('run-step').textContent = 'Step ' + Math.min(r.step_index, r.step_count) + ' of ' + r.step_count + ': ' + (stepNames[r.step] || r.step);
        $('run-step-pct').textContent = r.step_percent + '%';
        $('bar-step').style.width = r.step_percent + '%';
        $('run-detail').textContent = r.detail || '';
        document.title = r.percent + '% · SiteShifter Installer';
        if (r.done) { showResult(r.result); return; }
      }
    } catch (e) {
      $('run-error').textContent = 'Stopped: ' + e.message;
      $('run-fail').classList.remove('hidden');
      $('btn-rollback').classList.toggle('hidden', !(e.data && e.data.can_rollback));
    }
  }

  function showResult(res){
    const url = $('new_url').value.replace(/\/+$/, '');
    $('lnk-site').href = url + '/'; $('lnk-admin').href = url + '/wp-admin/';
    const left = res.leftovers.filter(l => l.kind !== 'email'), emails = res.leftovers.filter(l => l.kind === 'email');
    let h = '<ul class="check">';
    h += res.row_mismatches.length ? '<li><strong>Row counts differ:</strong> ' + res.row_mismatches.map(m => esc(m.table)+' ('+(m.source??'–')+' → '+(m.target??'–')+')').join(', ') + '</li>' : '<li>Every table has the same number of rows as the source.</li>';
    h += left.length ? '<li><strong>The old address is still stored in:</strong><br>' + left.map(l => '<code>'+esc(l.table+'.'+l.column)+'</code> ('+esc(l.kind)+', '+l.count+' rows) — <code>…'+esc(l.sample)+'…</code>').join('<br>') + '</li>' : '<li>No trace of the old address is left in the database.</li>';
    emails.forEach(l => h += '<li>'+esc(l.table+'.'+l.column)+': '+l.count+' e-mail address(es) on the old domain were left unchanged.</li>');
    res.disabled_dropins.forEach(d => h += '<li><code>'+esc(d)+'</code> was disabled (renamed to <code>.siteshifter-disabled</code>). Re-enable it once its cache service is set up on this server.</li>');
    if (res.needs_db_upgrade) h += '<li><strong>WordPress is newer than the database:</strong> open <a target="_blank" href="'+esc(url)+'/wp-admin/upgrade.php">wp-admin/upgrade.php</a> once.</li>';
    h += '</ul>';
    const lic = res.licences.filter(l => l.id !== 'domain_services');
    const addr = (res.address_notes || []).map(n => ({title: n.title, fix: n.fix || n.message}));
    h += '<h2 style="margin-top:20px">To do on the new domain</h2><ul class="check">' + addr.concat(lic, res.licences.filter(l => l.id === 'domain_services')).map(l => '<li><label style="font-weight:400;display:inline"><input type="checkbox" style="width:auto"> <strong>'+esc(l.title)+':</strong> '+esc(l.fix)+'</label></li>').join('') + '</ul>';
    $('result').innerHTML = h;
    $('s-run').classList.add('hidden'); $('s-done').classList.remove('hidden'); $('s-done').scrollIntoView({behavior:'smooth'});
  }

  async function cleanup(){
    $('btn-cleanup').disabled = true;
    try { await post('cleanup', {remove_uploads: $('rm-uploads').checked ? '1' : ''}); $('btn-cleanup').textContent = 'Installer removed'; }
    catch (e) { alert(e.message); $('btn-cleanup').disabled = false; }
  }

  $('btn-check').onclick = check; $('btn-recheck').onclick = check; $('btn-start').onclick = start;
  $('ack').onchange = () => { $('btn-start').disabled = !$('ack').checked || (lastReport && lastReport.blocked); };
  $('btn-retry').onclick = loop;
  $('btn-sitecheck').onclick = async () => {
    const box = $('sitecheck'); box.textContent = 'Checking the live address…';
    try {
      const r = await post('sitecheck');
      box.innerHTML = r.notes.length ? '<ul class="check">' + r.notes.map(n => '<li><strong>'+esc(n.title)+':</strong> '+esc(n.message)+(n.fix?'<br><em>'+esc(n.fix)+'</em>':'')+'</li>').join('') + '</ul>' : 'No problems found at ' + esc(r.url) + '.';
    } catch (e) { box.textContent = e.message; }
  };
  $('btn-rollback').onclick = async () => { if (!confirm('Restore the tables and wp-config.php that were here before?')) return; try { await post('rollback'); $('run-error').textContent = 'The previous state was restored.'; } catch (e) { $('run-error').textContent = e.message; } };
  $('btn-cleanup').onclick = cleanup;
})();
</script>
</body>
</html>
