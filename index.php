<?php
/* ADS COMMAND — halaman utama */
declare(strict_types=1);
session_start();
$C = require __DIR__ . '/config.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$v = @filemtime(__DIR__ . '/app.js') . @filemtime(__DIR__ . '/style.css');   // versi file (anti-cache)

// ---- login (aktif kalau app_password diisi di config.php) ----
if (($C['app_password'] ?? '') !== '') {
    if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
    if (isset($_POST['password'])) {
        if (hash_equals($C['app_password'], (string)$_POST['password'])) { session_regenerate_id(true); $_SESSION['ac_ok'] = true; header('Location: index.php'); exit; }
        $loginError = 'Password salah.';
    }
    if (empty($_SESSION['ac_ok'])) { ?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Masuk — Ads Command</title><link rel="stylesheet" href="style.css?v=<?= $v ?>"></head>
<body class="login-body"><form class="login" method="post">
  <svg viewBox="0 0 36 24" width="44" height="30"><path d="M8.5 2C4.4 2 1.5 6.9 1.5 12.4c0 5.6 2.3 9.6 6.2 9.6 2.9 0 4.8-2.2 7.4-6.6l1.9-3.2 1.7 2.8c2.9 4.9 5 7 8.1 7 3.8 0 6.1-3.9 6.1-9.4C32.9 6.6 30 2 25.6 2c-2.8 0-4.9 2-7.5 5.8C15.6 4 13.4 2 8.5 2Z" fill="#0866FF"/></svg>
  <h1>Ads Command</h1><p class="muted">Masuk untuk melihat dashboard iklan.</p>
  <?php if (!empty($loginError)) echo '<p class="err">' . htmlspecialchars($loginError) . '</p>'; ?>
  <input type="password" name="password" placeholder="Password" autofocus required>
  <button class="btn primary">Masuk</button>
</form></body></html>
<?php exit; }
}

// ---- config publik untuk browser (TANPA token & API key) ----
$public = [
    'api' => 'api.php',
    'csrf' => $_SESSION['csrf'],
    'graphVersion' => $C['graph_version'],
    'attribution' => $C['attribution'],
    'autoRefreshMinutes' => $C['auto_refresh_minutes'],
    'spendSource' => $C['spend_source'],
    'funnels' => $C['funnels'],
    'accounts' => array_map(fn($a) => ['name' => $a['name'], 'id' => $a['id'], 'group' => $a['group'], 'token' => $a['token']], $C['accounts']),
    'ai' => ['provider' => $C['ai']['provider']],
    'loginEnabled' => ($C['app_password'] ?? '') !== '',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Montera Ads — Campaigns</title>
  <link rel="stylesheet" href="style.css?v=<?= $v ?>">
</head>
<body>
  <!-- LEFT RAIL -->
  <aside class="rail">
    <div class="rail-logo" title="Ads Command">
      <svg viewBox="0 0 36 24" width="30" height="20"><path d="M8.5 2C4.4 2 1.5 6.9 1.5 12.4c0 5.6 2.3 9.6 6.2 9.6 2.9 0 4.8-2.2 7.4-6.6l1.9-3.2 1.7 2.8c2.9 4.9 5 7 8.1 7 3.8 0 6.1-3.9 6.1-9.4C32.9 6.6 30 2 25.6 2c-2.8 0-4.9 2-7.5 5.8C15.6 4 13.4 2 8.5 2Z" fill="#0866FF"/></svg>
    </div>
    <nav class="rail-nav">
      <button class="rail-btn" title="Iklan yang harus dimatikan" data-goto="kill"><svg viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0-6 6v4l-2 3h16l-2-3V9a6 6 0 0 0-6-6Zm-2 15a2 2 0 0 0 4 0"/></svg><span class="rail-badge" id="railBadge" hidden>0</span></button>
      <hr>
      <button class="rail-btn" title="Campaigns" data-goto="all"><svg viewBox="0 0 24 24"><path d="M3 5h18v14H3zM3 10h18M3 15h18M9 5v14"/></svg></button>
      <button class="rail-btn" title="Montera AI" data-goto="ai"><svg class="mlogo" viewBox="0 0 26 24" aria-hidden="true"><defs><linearGradient id="mlgR" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7b4dff"/><stop offset="1" stop-color="#4b2cc9"/></linearGradient></defs><g fill="url(#mlgR)" transform="skewX(-14) translate(5 0)"><rect x="1" y="11" width="4.2" height="11" rx="2.1"/><rect x="7.4" y="6" width="4.2" height="16" rx="2.1"/><rect x="13.8" y="1.5" width="4.2" height="20.5" rx="2.1"/></g></svg></button>
      <button class="rail-btn" title="Winning ads" data-goto="winning"><svg viewBox="0 0 24 24"><path d="M7 4h10v4a5 5 0 0 1-10 0zM7 6H4a3 3 0 0 0 3 4M17 6h3a3 3 0 0 1-3 4M12 13v4M8 21h8l-1-4H9z"/></svg></button>
      <button class="rail-btn" title="Winning content" data-goto="content"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM10 9l5 3-5 3z"/></svg></button>
      <button class="rail-btn" title="Rekap harian" data-goto="daily"><svg viewBox="0 0 24 24"><path d="M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M8 14h3M8 17h6"/></svg></button>
    </nav>
    <nav class="rail-nav bottom">
      <button class="rail-btn" title="Sync ulang semua akun" id="railSync"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/></svg></button>
    </nav>
  </aside>

  <div class="app">
    <header class="head">
      <h1>Campaigns</h1>
      <div class="scope" id="scopeLabel"></div>
      <button class="src" id="srcBadge"></button>
      <div class="head-right">
        <span class="muted" id="updated"></span>
        <button class="icon-btn" id="syncBtn" title="Sync ulang"><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5"/></svg></button>
        <?php if ($public['loginEnabled']): ?><a class="btn sm-btn" href="?logout=1">Keluar</a><?php endif; ?>
        <div class="avatar" title="Mufti Arifudin Taqy">MT</div>
      </div>
    </header>

    <!-- AKUN (nyamping) -->
    <section class="card acc-card">
      <div class="acc-top">
        <div class="pills" id="pfPills"></div>
        <label class="acc-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input id="accSearch" placeholder="Search for an ad account" autocomplete="off"></label>
      </div>
      <div class="acc-strip" id="accStrip"></div>
    </section>

    <section class="card main-card">
      <div class="views" id="views"></div>

      <div class="funnels" id="funnels"></div>

      <div class="q-wrap">
        <label class="filter-bar">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
          <input id="q" placeholder="Search to filter by: nama campaign, ad set, iklan, akun atau ID" autocomplete="off" role="combobox" aria-controls="qDrop">
          <button class="x" id="qClear" title="Hapus pencarian" hidden>✕</button>
        </label>
        <div class="q-drop" id="qDrop" role="listbox" hidden></div>
      </div>

      <div class="filters" id="filters"></div>
      <div id="qSummary"></div>
      <div id="aiBox"></div>

      <div class="level-row">
        <div class="level-tabs" id="levelTabs">
          <button data-level="campaign"><svg viewBox="0 0 24 24"><path d="M4 6h6l2 2h8v10H4z"/></svg>Campaigns</button>
          <button data-level="adset"><svg viewBox="0 0 24 24"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></svg>Ad sets</button>
          <button data-level="ad"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM4 9h16"/></svg>Ads</button>
        </div>
        <div class="dp" id="dp">
          <button class="dp-btn" id="dpBtn" aria-haspopup="dialog">
            <svg viewBox="0 0 24 24"><path d="M4 6h16v14H4zM4 10h16M8 3v4M16 3v4"/></svg>
            <b id="dpLabel">Hari ini</b><span id="periodRange"></span><span class="caret">▾</span>
          </button>
          <div class="dp-pop" id="dpPop" hidden role="dialog" aria-label="Pilih tanggal">
            <div class="dp-presets" id="dpPresets"></div>
            <div class="dp-cals" id="dpCals"></div>
            <div class="dp-foot"><span id="dpSel"></span><button class="btn" id="dpCancel">Batal</button><button class="btn primary" id="dpApply">Terapkan</button></div>
          </div>
        </div>
      </div>

      <div class="toolbar">
        <button class="btn" id="btnOff" disabled>Matikan</button>
        <button class="btn" id="btnOn" disabled>Nyalakan</button>
        <span class="muted" id="selInfo"></span>
        <div class="spacer"></div>
        <button class="btn" id="btnExport">⤓ Export Excel</button>
      </div>

      <div class="table-wrap" id="tableWrap">
        <div class="loading" id="loading" hidden></div>
        <table class="grid">
          <thead id="thead"></thead>
          <tbody id="tbody"></tbody>
          <tfoot id="tfoot"></tfoot>
        </table>
      </div>
    </section>
  </div>

  <!-- PANEL DETAIL -->
  <div class="overlay" id="overlay"></div>
  <aside class="panel" id="panel" aria-hidden="true">
    <button class="icon-btn panel-close" id="panelClose" title="Tutup">✕</button>
    <div id="panelBody"></div>
  </aside>

  <!-- AI ASISTEN -->
  <button class="chat-fab" id="chatFab" title="Montera AI"><svg class="mlogo" viewBox="0 0 26 24" aria-hidden="true"><g fill="#fff" transform="skewX(-14) translate(5 0)"><rect x="1" y="11" width="4.2" height="11" rx="2.1"/><rect x="7.4" y="6" width="4.2" height="16" rx="2.1"/><rect x="13.8" y="1.5" width="4.2" height="20.5" rx="2.1"/></g></svg> Montera AI</button>
  <aside class="chat" id="chat" aria-label="AI asisten iklan">
    <header class="chat-h">
      <div><b><svg class="mlogo" viewBox="0 0 26 24" aria-hidden="true"><defs><linearGradient id="mlgH" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7b4dff"/><stop offset="1" stop-color="#4b2cc9"/></linearGradient></defs><g fill="url(#mlgH)" transform="skewX(-14) translate(5 0)"><rect x="1" y="11" width="4.2" height="11" rx="2.1"/><rect x="7.4" y="6" width="4.2" height="16" rx="2.1"/><rect x="13.8" y="1.5" width="4.2" height="20.5" rx="2.1"/></g></svg> Montera AI</b><small id="chatScope"></small></div>
      <div class="aimode" title="Mendalam = analisis paling teliti (±20–40 dtk) · Cepat = ±5–10 dtk"><button data-aimode="deep">Mendalam</button><button data-aimode="fast">Cepat</button></div>
      <button class="icon-btn" id="chatReset" title="Hapus riwayat">⟲</button>
      <button class="icon-btn" id="chatClose" title="Tutup">✕</button>
    </header>
    <div class="chat-msgs" id="chatMsgs"></div>
    <div class="chat-sugg" id="chatSugg"></div>
    <form class="chat-in" id="chatForm"><textarea id="chatInput" rows="2" placeholder="Tanya apa saja… cth: campaign mana yang bikin rugi minggu ini?"></textarea><button class="btn primary">Kirim</button></form>
  </aside>

  <script>window.ADS_CONFIG = <?= json_encode($public, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <script src="app.js?v=<?= $v ?>"></script>
</body>
</html>