<?php
/* ADS COMMAND — halaman utama */
declare(strict_types=1);
session_start();
$C = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');   // laptop: config.php · server: env var
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$v = @filemtime(__DIR__ . '/app.js') . @filemtime(__DIR__ . '/style.css');   // versi file (anti-cache)

// ---- login (aktif kalau app_password ATAU login_users diisi di config.php) ----
$loginUsers = $C['login_users'] ?? [];
$loginOn = ($C['app_password'] ?? '') !== '' || !empty($loginUsers);
if ($loginOn) {
    if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)$_POST['password'];
        $reason = '';
        if ($loginUsers) {                                   // login per orang (email tidak peka huruf besar/kecil)
            $found = null;
            foreach ($loginUsers as $user => $pw) if (strcasecmp(trim((string)$user), $u) === 0) { $found = (string)$pw; break; }
            if ($found === null) $reason = 'user';
            $ok = $found !== null && hash_equals($found, $p);
            if ($found !== null && !$ok) $reason = 'password';
        } else {                                             // 1 password bersama
            $ok = hash_equals((string)$C['app_password'], $p);
            if (!$ok) $reason = 'password';
        }
        if ($ok) { session_regenerate_id(true); $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $u !== '' ? strtolower($u) : 'admin'; }
        else usleep(400000);   // perlambat tebak-tebakan password
        if (!empty($_SERVER['HTTP_X_LOGIN_AJAX'])) { header('Content-Type: application/json'); echo json_encode(['ok' => $ok, 'reason' => $reason, 'user' => $ok ? ($_SESSION['ac_user'] ?? '') : '']); exit; }
        if ($ok) { header('Location: index.php'); exit; }
        $loginError = $reason === 'user' ? 'Email tidak terdaftar.' : 'Password salah.';
    }
    if (empty($_SESSION['ac_ok'])) { ?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk — Montera Ads</title><link rel="icon" type="image/png" href="assets/montera-icon.png"><link rel="stylesheet" href="style.css?v=<?= $v ?>"></head>
<body class="login-body">
<div class="login rive-login">
  <img class="login-logo" src="assets/montera-ads.png" alt="Montera Ads" width="116" height="70">
  <div class="teddy-wrap"><canvas id="teddy" width="340" height="250" aria-label="Animasi beruang login"></canvas></div>
  <form id="loginForm" method="post" autocomplete="on">
    <label class="fld-l">Email<input id="lu" name="username" type="email" inputmode="email" autocomplete="username" placeholder="<?= $loginUsers ? 'nama@montera.id' : 'Email (opsional)' ?>" <?= $loginUsers ? 'required' : '' ?>></label>
    <label class="fld-l">Password
      <span class="pw-row"><input id="lp" type="password" name="password" autocomplete="current-password" placeholder="Password" required>
      <button type="button" id="eye" class="eye" aria-label="Lihat password">👁</button></span>
    </label>
    <p class="err" id="lerr" <?= empty($loginError) ? 'hidden' : '' ?>><?= htmlspecialchars($loginError ?? '') ?></p>
    <button class="btn primary login-btn" id="lbtn">Masuk</button>
  </form>
</div>
<div class="login-pop" id="okPop" hidden role="alertdialog" aria-live="assertive">
  <div class="login-pop-card">
    <div class="ok-ring"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg></div>
    <h2>Anda berhasil masuk 🎉</h2>
    <p id="okUser"></p>
    <small>Mengalihkan ke dashboard…</small>
  </div>
</div>
<script src="assets/rive/rive.js"></script>
<script>   // semua file Rive ada di folder assets/rive → tetap jalan walau OFFLINE
  if (window.rive) { rive.RuntimeLoader.setWasmUrl("assets/rive/rive.wasm"); if (rive.RuntimeLoader.setWasmFallbackUrl) rive.RuntimeLoader.setWasmFallbackUrl(null); }
</script>
<script>
// Animasi beruang = file Rive yang sama dengan aplikasi Flutter (assets/rive/auth_teddy.riv, state machine "Login Machine")
(() => {
  const SM = "Login Machine", I = {}, $ = id => document.getElementById(id);
  const lu = $("lu"), lp = $("lp"), form = $("loginForm"), err = $("lerr"), btn = $("lbtn");
  const set = (n, v) => { if (I[n]) I[n].value = v; };
  const fire = k => { const t = I[k + "Trigger"] || I[k]; if (t && t.fire) t.fire(); };
  const reset = () => { ["isPrivateField", "isPrivateFieldShow", "Hands_up", "isFocus"].forEach(n => set(n, false)); };
  let r = null;
  try {
    r = new rive.Rive({ src: "assets/rive/auth_teddy.riv", canvas: $("teddy"), stateMachines: SM, autoplay: true,
      onLoad: () => { r.resizeDrawingSurfaceToCanvas(); (r.stateMachineInputs(SM) || []).forEach(i => I[i.name] = i); if (!err.hidden) { reset(); setTimeout(() => fire("fail"), 60); } } });
    addEventListener("resize", () => r && r.resizeDrawingSurfaceToCanvas());
  } catch (e) { document.querySelector(".teddy-wrap").hidden = true; }
  lu.addEventListener("focus", () => set("isFocus", true));
  lu.addEventListener("blur", () => set("isFocus", false));
  lu.addEventListener("input", () => { set("numLook", lu.value.length * 1.5); lu.classList.remove("bad"); });
  lp.addEventListener("input", () => lp.classList.remove("bad"));            // mata mengikuti ketikan
  lp.addEventListener("focus", () => { set("isPrivateField", true); set("Hands_up", true); });   // tutup mata
  lp.addEventListener("blur", () => { set("isPrivateField", false); set("Hands_up", lp.value !== ""); });
  lp.addEventListener("input", () => set("Hands_up", document.activeElement === lp || lp.value !== ""));
  $("eye").addEventListener("click", () => { const show = lp.type === "password"; lp.type = show ? "text" : "password"; set("isPrivateFieldShow", show); $("eye").textContent = show ? "🙈" : "👁"; lp.focus(); });
  form.addEventListener("submit", async e => {
    e.preventDefault(); err.hidden = true; btn.disabled = true; btn.textContent = "Memeriksa…";
    lu.blur(); lp.blur(); set("isChecking", true);
    let ok = false, res = {};
    try { const r0 = await fetch("index.php", { method: "POST", headers: { "X-Login-Ajax": "1" }, body: new FormData(form) }); res = await r0.json(); ok = !!res.ok; } catch { res = { reason: "net" }; }
    set("isChecking", false); reset(); await new Promise(z => setTimeout(z, 60));
    if (ok) {
      fire("success"); btn.textContent = "Berhasil ✓";
      $("okUser").textContent = res.user ? `Selamat datang, ${res.user}` : "Selamat datang!";
      setTimeout(() => { $("okPop").hidden = false; }, 350);
      setTimeout(() => location.replace("index.php"), 2000);
    } else {
      fire("fail");
      const isUser = res.reason === "user";
      err.textContent = res.reason === "net" ? "Server tidak bisa dihubungi. Pastikan server PHP masih jalan." : isUser ? "Email tidak terdaftar. Cek lagi email kamu." : "Password salah. Coba lagi.";
      err.hidden = false; btn.disabled = false; btn.textContent = "Masuk";
      lu.classList.toggle("bad", isUser); lp.classList.toggle("bad", !isUser);
      (isUser ? lu : lp).focus(); if (!isUser) lp.select();
      form.classList.remove("shake"); void form.offsetWidth; form.classList.add("shake");
    }
  });
})();
</script>
</body></html>
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
    'loginEnabled' => $loginOn,
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Montera Ads — Campaigns</title>
  <link rel="icon" type="image/png" href="assets/montera-icon.png">
  <link rel="stylesheet" href="style.css?v=<?= $v ?>">
</head>
<body>
  <!-- LEFT RAIL -->
  <aside class="rail">
    <div class="rail-logo" title="Montera Ads"><img src="assets/montera-icon.png" alt="Montera Ads" width="34" height="29"></div>
    <nav class="rail-nav">
      <button class="rail-btn" title="Iklan yang harus dimatikan" data-goto="kill"><svg viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0-6 6v4l-2 3h16l-2-3V9a6 6 0 0 0-6-6Zm-2 15a2 2 0 0 0 4 0"/></svg><span class="rail-badge" id="railBadge" hidden>0</span></button>
      <hr>
      <button class="rail-btn" title="Campaigns" data-goto="all"><svg viewBox="0 0 24 24"><path d="M3 5h18v14H3zM3 10h18M3 15h18M9 5v14"/></svg></button>
      <button class="rail-btn" title="Montera AI" data-goto="ai"><img class="mlogo" src="assets/montera-icon.png" alt="" width="20" height="17"></button>
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
      <h1 class="brand-title"><img src="assets/montera-ads.png" alt="Montera Ads" width="83" height="50"></h1>
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
  <button class="chat-fab" id="chatFab" title="Montera AI"><img class="mlogo" src="assets/montera-icon.png" alt="" width="20" height="17"> Montera AI</button>
  <aside class="chat" id="chat" aria-label="AI asisten iklan">
    <header class="chat-h">
      <div><b><img class="mlogo" src="assets/montera-icon.png" alt="" width="20" height="17"> Montera AI</b><small id="chatScope"></small></div>
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