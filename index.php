<?php
/* ADS COMMAND — halaman utama */
declare(strict_types=1);
require __DIR__ . '/auth.php';
date_default_timezone_set('Asia/Jakarta');   // [jam-wib 16 Nov] tampilan jam = WIB (PHP di Apache bawaannya UTC); data yang disimpan tidak diubah
ma_session_start();
$C = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');   // laptop: config.php · server: env var
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$v = @filemtime(__DIR__ . '/app.js') . @filemtime(__DIR__ . '/style.css');   // versi file (anti-cache)

// ---- login (aktif kalau app_password ATAU login_users diisi di config.php) ----
$loginUsers = $C['login_users'] ?? [];
$loginOn = ($C['app_password'] ?? '') !== '' || !empty($loginUsers);
if ($loginOn) {
    if (isset($_GET['logout'])) { ma_remember_clear(); session_destroy(); header('Location: index.php'); exit; }
    if (empty($_SESSION['ac_ok']) && ($ru = ma_remember_check($C)) !== null) { $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $ru; }   // masih ingat → langsung masuk
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
        if ($ok) { session_regenerate_id(true); $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $u !== '' ? strtolower($u) : 'admin'; ma_remember_set($C, $u !== '' ? $u : 'admin'); }
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
    'products' => $C['products'] ?? [],
    'productSets' => $C['product_sets'] ?? [],   // product set CPAS → produk (langkah 3 kolom Produk export)
    'accounts' => array_map(fn($a) => ['name' => $a['name'], 'id' => $a['id'], 'group' => $a['group'], 'token' => $a['token'], 'brand' => $a['brand'] ?? '', 'product' => $a['product'] ?? ''], $C['accounts']),   // [30 Okt] brand eksplisit (token portfolio bukan brand)
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
      <a class="rail-btn" title="Laporan Harian (Shopee x CPAS)" href="laporan.php"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></a>
      <a class="rail-btn" title="Konten per Kreator (klasifikasi SOP)" href="kreator.php"><svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0"/></svg></a>
      <a class="rail-btn" title="Data Ads (input harian & akun CPAS)" href="data.php"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4zM4 9h16M9 9v11M13 13h4M13 16h4"/></svg></a>
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
      <span class="status-akun" id="statusAkun" title="Status data akun iklan"></span>
      <span class="status-total" id="statusTotal" title="Validasi harian total: ERP & ads.prepareofficial.com = sumber asli"></span>
      <div class="head-right">
        <span class="muted" id="updated"></span>
        <button class="icon-btn" id="syncBtn" title="Sync ulang"><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5"/></svg></button>
        <?php if ($public['loginEnabled']): ?><a class="btn sm-btn" href="?logout=1">Keluar</a><?php endif; ?>
        <div class="avatar" title="Mufti Arifudin Taqy">MT</div>
      </div>
    </header>
<?php /* [status-data 19 Nov, Mufti] semua peringatan data (akun Meta/ERP, saldo Shopee kurang, naming & produk, total akun ≠ Ads Manager, validasi harian)
          TIDAK tampil di dashboard: ditangkap ke <template id="stExtra"> lalu dipindah ke panel tombol "Status Data · N" (kuning). Dashboard hanya banner kreator. */
    ob_start(); ?>
<?php
    /* peringatan brand iklan Meta di ERP: file status ditulis /root/meta_ads_sync.php (cron tiap 30 menit) */
    $bc = json_decode((string)@file_get_contents('/var/lib/montera-ads/meta-brand-cek.json'), true);
    $bcPesan = [];
    if (is_array($bc)) {
        foreach ($bc['tanpa_brand'] ?? [] as $a)
            $bcPesan[] = htmlspecialchars((string)($a['account_name'])) . ' (' . htmlspecialchars((string)($a['account_id'])) . ') — brand ' . htmlspecialchars((string)($a['brand'] ?: 'kosong')) . ', spend 35 hari Rp' . number_format((float)$a['spend'], 0, ',', '.') . ', terakhir ' . htmlspecialchars((string)($a['terakhir']));
        foreach ($bc['gagal'] ?? [] as $a)
            $bcPesan[] = htmlspecialchars((string)($a['account_name'])) . ' (' . htmlspecialchars((string)($a['account_id'])) . ') — GAGAL ditarik ke ERP jam ' . date('H:i', strtotime($bc['waktu'])) . ', angka lama dipertahankan: ' . htmlspecialchars((string)($a['pesan']));
        try {
            $e = []; foreach (@file('/etc/montera-ads-db.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) if (strpos($l, '=') !== false) { [$k, $x] = explode('=', $l, 2); $e[trim($k)] = trim($x, " \t\"'"); }
            $pdo = new PDO('mysql:host=' . ($e['DB_HOST'] ?? 'localhost') . ';dbname=' . ($e['DB_NAME'] ?? 'montera_ads') . ';charset=utf8mb4', $e['DB_USER'] ?? '', $e['DB_PASS'] ?? '');
            $erp = array_flip($bc['erp_akun'] ?? []);
            foreach ($pdo->query("SELECT nama, act_id FROM akun WHERE aktif = 1 AND act_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $a)
                if (!isset($erp[preg_replace('/^act_/', '', $a['act_id'])])) $bcPesan[] = htmlspecialchars((string)($a['nama'])) . ' (' . htmlspecialchars((string)($a['act_id'])) . ') — belum ada di ERP Akun Meta, biaya iklannya belum masuk laba';
        } catch (Throwable $ex) { }
    }
    // [sheet-patokan] akun yang gagal ditarik Meta / gagal ditulis ke tab sheet oleh tarik-meta (angka lama di sheet dipertahankan)
    $tm = json_decode((string)@file_get_contents('/var/lib/montera-ads/tarik-meta-status.json'), true);
    foreach (array_merge($tm['gagal_meta'] ?? [], $tm['gagal_sheet'] ?? []) as $a)
        $bcPesan[] = htmlspecialchars((string)($a['nama'])) . ' — GAGAL diisi ke sheet jam ' . date('H:i', strtotime($tm['waktu'])) . ' (' . htmlspecialchars($tm['rentang']) . '), angka lama dipertahankan: ' . htmlspecialchars((string)($a['pesan']));
    if ($bcPesan): ?>
    <div class="brand-warn" role="alert"><b>Cek akun Meta di ERP</b> (menu Akun Meta → Brand), biaya iklan akun ini belum masuk / belum segar di laba:
      <ul><?php foreach ($bcPesan as $p) echo "<li>$p</li>"; ?></ul></div>
<?php endif; ?>
<?php /* [iklan-shopee 17 Nov, Mufti] saldo iklan Shopee < biaya kemarin (1 hari penuh) → banner merah; data /root/shopee_ads_jam.php tiap jam */
    $sa = json_decode((string)@file_get_contents('/var/lib/montera-status/shopee-ads.json'), true); $saK = [];
    if (is_array($sa) && time() - strtotime((string)$sa['waktu']) < 3 * 3600) foreach ($sa['toko'] ?? [] as $t) if (!empty($t['saldo_kurang']))
        $saK[] = htmlspecialchars((string)$t['nama']) . ': saldo <b>Rp' . number_format((float)$t['saldo'], 0, ',', '.') . '</b> &lt; biaya kemarin Rp' . number_format((float)$t['biaya_kemarin'], 0, ',', '.')
            . ' (hari ini s/d ' . date('H:i', strtotime($sa['waktu'])) . ': Rp' . number_format((float)($t['hari_ini']['biaya'] ?? 0), 0, ',', '.') . ')';
    if ($saK): ?>
    <div class="brand-warn" role="alert" style="background:#B91C1C;color:#fff;border-color:#991B1B"><b>Saldo iklan Shopee hampir habis — iklan bisa berhenti.</b> Isi saldo di Seller Center → Iklan Shopee:
      <ul><?php foreach ($saK as $p) echo "<li>$p</li>"; ?></ul></div>
<?php endif; ?>
<?php $stExtra = ob_get_clean(); ?>
<?php /* [saldo-live 19 Nov, Mufti] saldo iklan Shopee LIVE (saldo-shopee.php: API get_total_balance, cache maks. 2 menit, tanpa cron) + biaya iklan hari ini,
          diambil saat dashboard dibuka / tombol ↻ / tombol sync. API gagal → angka terakhir + "gagal update, terakhir HH:MM" (abu). */
    $otomatis = []; if (is_array($sa)) foreach ($sa['toko'] ?? [] as $sid => $t) if (isset($t['isi_otomatis'])) $otomatis[(string)$sid] = (bool)$t['isi_otomatis']; ?>
    <div id="saldoShopee" data-otomatis="<?= htmlspecialchars(json_encode($otomatis)) ?>" style="font-size:12.5px;color:#64748b;margin:0 0 10px;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center">
      <span>Saldo iklan Shopee:</span><span id="saldoIsi">memuat…</span>
      <button id="saldoBtn" type="button" title="Ambil saldo terbaru dari Shopee" style="border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:0 8px;font-size:12px;line-height:20px;cursor:pointer;color:#475569">↻</button>
    </div>
    <script>
    (function () {
      const box = document.getElementById('saldoIsi'), btn = document.getElementById('saldoBtn');
      const oto = JSON.parse(document.getElementById('saldoShopee').dataset.otomatis || '{}');
      const rp = x => 'Rp' + Math.round(x).toLocaleString('id-ID');
      const jam = (ts, detik) => { const d = new Date(ts * 1000), p = n => String(n).padStart(2, '0'); return p(d.getHours()) + ':' + p(d.getMinutes()) + (detik ? ':' + p(d.getSeconds()) : ''); };
      const esc = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
      let jalan = false;
      async function saldo(segar) {
        if (jalan) return; jalan = true; btn.disabled = true; btn.textContent = '…';
        try {
          const r = await fetch('saldo-shopee.php' + (segar ? '?segar=1' : ''), {credentials: 'same-origin', cache: 'no-store'});
          const d = await r.json();
          if (!d.toko) throw new Error(d.pesan || 'tidak terbaca');
          box.innerHTML = Object.entries(d.toko).map(([sid, t]) => {
            if (t.saldo === undefined) return `<span style="color:#94a3b8">${esc(t.nama)}: gagal update (belum ada angka)</span>`;
            const ket = t.ok ? `dicek ${jam(t.waktu, true)}` : `<span style="color:#94a3b8">gagal update, terakhir ${jam(t.waktu, false)}</span>`;
            const biaya = t.biaya_hari_ini !== undefined ? ` · biaya iklan hari ini ${rp(t.biaya_hari_ini)}${t.biaya_lama ? ' <span style="color:#94a3b8">(terakhir ' + jam(t.biaya_waktu, false) + ')</span>' : ''}` : '';
            return `<span title="${esc(t.galat || '')}"><b style="color:${t.ok ? '#0f172a' : '#94a3b8'}">${esc(t.nama)} ${rp(t.saldo)}</b>${biaya} · ${ket}${oto[sid] ? ' · isi otomatis aktif' : ''}</span>`;
          }).join('<span style="color:#cbd5e1">|</span>');
        } catch (e) {
          if (!box.dataset.ada) box.innerHTML = '<span style="color:#94a3b8">gagal update saldo, coba ↻</span>';
          else box.insertAdjacentHTML('beforeend', ' <span style="color:#94a3b8">(gagal update ' + jam(Date.now() / 1000, false) + ')</span>');
        } finally { box.dataset.ada = '1'; jalan = false; btn.disabled = false; btn.textContent = '↻'; }
      }
      btn.addEventListener('click', () => saldo(true));
      ['syncBtn', 'railSync'].forEach(id => { const b = document.getElementById(id); if (b) b.addEventListener('click', () => saldo(true)); });
      document.addEventListener('visibilitychange', () => { if (!document.hidden) saldo(false); });
      setInterval(() => { if (!document.hidden) saldo(false); }, 120000);
      saldo(false);
    })();
    </script>
<?php /* [kreator 18 Nov, Adila] iklan aktif baru tanpa kode kreator → peringatan (detail & set manual di kreator.php); cache 10 menit */
    $krTk = null; $krF = __DIR__ . '/logs/kreator-tanpa-kode.json';
    if (is_file($krF) && time() - filemtime($krF) < 600) $krTk = json_decode((string)@file_get_contents($krF), true);
    if (!is_array($krTk)) { try { require_once __DIR__ . '/kreator_lib.php'; $krTk = array_slice(array_column(kr_tanpa_kode(kr_db()), 'ad_name'), 0, 200); @file_put_contents($krF, json_encode($krTk)); } catch (Throwable $e) { $krTk = []; } }
    if ($krTk): ?>
    <div class="brand-warn" role="alert"><b>Ada <?= count($krTk) ?> iklan baru yang belum punya kode kreator.</b> Tambahkan kode kreator di nama iklan (contoh: <b>Stitch 04R</b>), atau pilih kreatornya di
      <a href="kreator.php#tanpa-kode" style="font-weight:700">Konten per Kreator</a>.
      <span style="display:block;font-size:12px;opacity:.8;margin-top:4px"><?= htmlspecialchars(implode(' · ', array_slice(array_unique($krTk), 0, 8))) ?><?= count(array_unique($krTk)) > 8 ? ' …' : '' ?></span></div>
<?php endif; ?>
<?php ob_start(); ?>
<?php /* [naming-produk 19 Nov, Mufti] pengaman otomatis setelah tiap sync (sync-insights.php → produk_lib.php pl_simpan_cek → naming-cek.json):
          kuning = naming dipakai 2+ produk / iklan produk "Belum diketahui" / nomor naming iklan ≠ campaign (60 hari terakhir; data tidak diubah otomatis)
          merah  = total per akun level iklan (dasar export) ≠ level akun Ads Manager (bulan lalu s/d kemarin), dengan angka selisihnya */
    $nc = json_decode((string)@file_get_contents('/var/lib/montera-status/naming-cek.json'), true);
    if (is_array($nc)):
        $ncRp = function ($x) { return 'Rp' . number_format((float)$x, 0, ',', '.'); }; $ncE = function ($x) { return htmlspecialchars((string)$x); };
        $ncJam = htmlspecialchars(date('d/m H:i', strtotime((string)$nc['dibuat'])));
        if (!empty($nc['n_campur']) || !empty($nc['n_belum']) || !empty($nc['n_nomor'])): ?>
    <div class="brand-warn" role="alert"><b>Cek naming &amp; produk iklan (60 hari terakhir, dicek <?= $ncJam ?>):</b>
      <?= (int)$nc['n_campur'] ?> naming dipakai di 2+ produk (sudah dipisah per produk di export, naming-nya sebaiknya dibedakan) ·
      <?= (int)$nc['n_belum'] ?> iklan produk <b>Belum diketahui</b> (tidak ada kode produk di nama campaign/ad set/iklan) ·
      <?= (int)$nc['n_nomor'] ?> iklan perlu dicek naming (nomor di nama iklan ≠ nomor di nama campaign)
      <details style="margin-top:4px;font-size:12px"><summary style="cursor:pointer">rincian</summary>
        <?php if ($nc['campur']): ?><div style="margin-top:4px"><b>Naming 2+ produk:</b><?php foreach (array_slice($nc['campur'], 0, 30) as $g) echo '<br>' . $ncE(ucfirst($g['merek']) . ' · ' . $g['naming']) . ' → ' . implode(' | ', array_map(function ($p, $x) use ($ncRp, $ncE) { return $ncE($p) . ' ' . $ncRp($x['sp']) . ' · ' . (int)$x['pu'] . ' purchase · ' . $ncE(implode(', ', array_unique(array_column($x['iklan'], 'akun')))); }, array_keys($g['produk']), $g['produk'])); ?></div><?php endif; ?>
        <?php if ($nc['nomor']): ?><div style="margin-top:6px"><b>Perlu dicek naming (tidak diubah otomatis):</b><?php foreach (array_slice($nc['nomor'], 0, 30) as $x) echo '<br>' . $ncE($x['akun']) . ' · iklan "' . $ncE($x['iklan']) . '" di campaign "' . $ncE($x['campaign']) . '" · ' . $ncRp($x['sp']); ?></div><?php endif; ?>
        <?php if ($nc['belum']): ?><div style="margin-top:6px"><b>Produk Belum diketahui (spend terbesar):</b><?php foreach (array_slice($nc['belum'], 0, 20) as $x) echo '<br>' . $ncE($x['akun']) . ' · ' . $ncE($x['iklan']) . ' · campaign "' . $ncE($x['campaign']) . '" · ' . $ncRp($x['sp']); ?><?= $nc['n_belum'] > 20 ? '<br>… +' . ($nc['n_belum'] - 20) . ' iklan' : '' ?></div><?php endif; ?>
      </details></div>
<?php   endif;
        $ncK = ['cek' => [], 'atribusi' => [], 'berjalan' => []]; foreach ($nc['akun'] ?? [] as $x) $ncK[$x['kategori'] ?? 'cek'][] = $x;
        $ncBaris = function ($x) use ($ncE, $ncRp) { return $ncE($x['akun'] . ' ' . date('d/m', strtotime($x['tanggal']))) . ': spend ' . ($x['sp'] > 0 ? '+' : '') . $ncRp($x['sp']) . ' · purchase ' . ($x['pu'] > 0 ? '+' : '') . (int)$x['pu'] . ' · value ' . ($x['om'] > 0 ? '+' : '') . $ncRp($x['om']) . ' (iklan ' . (int)$x['pu_i'] . ' / ' . $ncRp($x['om_i']) . ' vs akun ' . (int)$x['pu_a'] . ' / ' . $ncRp($x['om_a']) . ')'; };
        if ($ncK['cek']): ?>
    <div class="brand-warn" role="alert"><b>Total iklan ≠ total akun (Ads Manager): <?= count($ncK['cek']) ?> akun-hari perlu dicek</b> (<?= htmlspecialchars(date('d/m', strtotime($nc['akun_periode'][0])) . '–' . date('d/m', strtotime($nc['akun_periode'][1]))) ?>, dicek <?= $ncJam ?>)
      <?php foreach (array_slice($ncK['cek'], 0, 12) as $x) echo '<br>' . $ncBaris($x); ?></div>
<?php   endif;
        if ($ncK['atribusi'] || $ncK['berjalan']): ?>
    <div class="validasi-terakhir ok"><details><summary style="cursor:pointer"><?= $ncK['atribusi'] ? count($ncK['atribusi']) . ' akun-hari selisih atribusi Meta ±1 purchase, spend sama & total brand sama' : '' ?><?= $ncK['atribusi'] && $ncK['berjalan'] ? ' · ' : '' ?><?= $ncK['berjalan'] ? count($ncK['berjalan']) . ' akun-hari data kemarin masih berubah di Meta' : '' ?> ▸</summary>
      <?php foreach (array_merge($ncK['atribusi'], $ncK['berjalan']) as $x) echo $ncBaris($x) . '<br>'; ?></details></div>
<?php   endif;
    endif; ?>
<?php /* [validasi-harian] hasil validasi data iklan terakhir (cron 08:00, juga dikirim ke WA Mufti) */
    $vt = json_decode((string)@file_get_contents('/var/lib/montera-ads/validasi-terakhir.json'), true);
    if (is_array($vt)): ?>
    <div class="validasi-terakhir <?= $vt['ok'] ? 'ok' : 'beda' ?>">Validasi terakhir <?= htmlspecialchars(date('d/m H:i', strtotime($vt['waktu']))) ?><?= !empty($vt['uji']) ? ' (uji)' : '' ?>: <b><?= htmlspecialchars(preg_replace('/^\[UJI\] /', '', $vt['ringkas'])) ?></b> · rentang <?= htmlspecialchars($vt['rentang']) ?> · selisih <?= (int)$vt['selisih'] ?>, gagal baca <?= (int)$vt['gagal'] ?></div>
<?php endif; ?>
<?php $stExtra .= ob_get_clean(); ?>
<template id="stExtra"><?= $stExtra ?></template>

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