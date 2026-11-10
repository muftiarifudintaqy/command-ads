<?php
/* [10 Nov, Mufti] AUDIT akun iklan Meta per merek (READ-ONLY, tidak menulis apa pun ke sheet / DB / Apps Script).
   Tab akun di file sheet bulanan "<Bulan> <Tahun> - Prepare/Skinlyfe All Platform" dibandingkan dengan:
     dashboard (config.php accounts) · Data Ads (tabel akun) · ERP (ads_meta_account status=1) · ACCOUNTS Apps Script (salinan .gs lokal)
     · rollup "SHOPEE X CPAS" (referensi tab di rumus CPAS M: cost/omset/purchase/ATC, dibaca lewat cek_rollup = pratinjau tanpa simpan)
     · token yang benar-benar punya akses (peta /me/adaccounts).
   Daftar tab = tab dari tabel akun (gid + gid-bulan, dicek baca_tab) + tab yang disebut di rumus rollup.
   Tab yang tidak ada di keduanya tidak terlihat dari sini (Apps Script belum punya daftar tab) → cocokkan dengan daftar tab di Drive.
   Pakai: php tools/audit-akun.php [prepare|skinlyfe] [bulan] [tahun] [--gs=/root/apps-script-prepare-domainbaru.gs] [--json] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require '/opt/montera-ads/lib.php';
date_default_timezone_set('Asia/Jakarta');
$arg = array_values(array_filter(array_slice($argv, 1), fn($x) => strpos($x, '--') !== 0));
$opt = []; foreach ($argv as $x) if (preg_match('/^--(\w+)(?:=(.*))?$/', $x, $m)) $opt[$m[1]] = $m[2] ?? true;
$mk = ($arg[0] ?? 'prepare') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
$bl = (int) ($arg[1] ?? date('n')); $th = (int) ($arg[2] ?? date('Y'));
$gs = (string) ($opt['gs'] ?? "/root/apps-script-$mk-domainbaru.gs");
$num = fn($act) => preg_replace('/^act_/', '', (string) $act);

// sumber pembanding
$C = require '/var/www/montera-ads/config.php';
$cfg = []; foreach ($C['accounts'] as $a) if (strtolower($a['brand'] ?? '') === $mk) $cfg[$num($a['id'])] = $a;
$db = []; foreach (ma_db()->query("SELECT * FROM akun WHERE merek = " . ma_db()->quote($mk)) as $a) $db[$a['nama']] = $a;
$erp = []; foreach (ma_erp()->query("SELECT account_id, account_name, brand FROM ads_meta_account WHERE status = 1") as $a) $erp[$a['account_id']] = $a;
$as = []; if (is_file($gs) && preg_match_all("/\{\s*name:\s*'([^']+)',\s*adAccountId:\s*'(act_\d+)',\s*gid:\s*(\d+)([^}]*)\}/", (string) file_get_contents($gs), $mm, PREG_SET_ORDER))
    foreach ($mm as $m) $as[$num($m[2])] = ['nama' => $m[1], 'gid' => (int) $m[3], 'token' => preg_match("/token:\s*'(\w+)'/", $m[4], $t) ? $t[1] : 'prepare'];
$peta = ma_peta_token(21600)['akun'] ?? [];

// tab: dari tabel akun (gid bulan itu) → nama tab asli di file bulan itu
$tab = [];
foreach ($db as $a) { if (!$a['sheet_gid']) continue; $g = ma_gid_bulan($a, $th, $bl); $t = ma_baca_tab($mk, $g, $th, $bl);
    $tab[$a['nama']] = ['gid' => !empty($t['ok']) ? $g : null, 'nama_tab' => $t['nama'] ?? null, 'galat' => $t['error'] ?? null]; }
// rumus rollup (pratinjau cek_rollup dengan tab yang sudah ada di rumus → semua sel "sudah ada", rumusnya ikut dikirim)
$contoh = array_key_first(array_filter($tab, fn($t) => $t['nama_tab'])) ?? '';
$r = ma_post_sheet_raw(['jenis' => 'cek_rollup', 'merk' => $mk, 'bulan' => $bl, 'tahun' => $th, 'tabs' => [$contoh]]);
$rumus = array_merge($r['gagal'] ?? [], $r['sel'] ?? []); $diRollup = []; $nSel = [];
foreach ($rumus as $s) { if (empty($s['kolom'])) continue; $nSel[$s['kolom']] = ($nSel[$s['kolom']] ?? 0) + 1;
    preg_match_all("/'([^']+)'!/", (string) ($s['rumus'] ?? $s['lama'] ?? ''), $m);
    foreach (array_unique($m[1]) as $t) $diRollup[$t][$s['kolom']] = ($diRollup[$t][$s['kolom']] ?? 0) + 1; }
foreach (array_keys($diRollup) as $t) if (!isset($tab[$t])) $tab[$t] = ['gid' => null, 'nama_tab' => $t, 'galat' => 'tidak ada di tabel akun'];

$baris = [];
foreach ($tab as $nama => $t) {
    $a = $db[$nama] ?? null; $act = $a ? $num($a['act_id']) : '';
    if (!$act) foreach ($as + $cfg as $id => $x) if (($x['nama'] ?? $x['name'] ?? '') === $nama) $act = (string) $id;
    $roll = $diRollup[$nama] ?? []; $penuh = $nSel && count($roll) === count($nSel) && !array_diff_assoc($nSel, $roll);
    $baris[] = ['tab' => $nama, 'gid' => $t['gid'], 'act_id' => $act ? "act_$act" : '', 'dashboard' => isset($cfg[$act]), 'data_ads' => (bool) $a, 'erp' => isset($erp[$act]),
        'apps_script' => isset($as[$act]), 'gid_as' => $as[$act]['gid'] ?? null, 'rollup' => $penuh ? 'ya' : ($roll ? 'sebagian' : 'belum'),
        'token_akses' => implode(',', $peta[$act] ?? []), 'catatan' => $t['galat'] ?? ''];
}
if (!empty($opt['json'])) { echo json_encode(['merek' => $mk, 'bulan' => "$th-$bl", 'sel_rollup' => $nSel, 'baris' => $baris], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; exit; }
$v = fn($b) => $b ? 'ya' : 'BELUM';
printf("Audit akun %s %02d/%d · sel rollup %s · Apps Script: %s\n", $mk, $bl, $th, json_encode($nSel), is_file($gs) ? $gs : 'salinan .gs tidak ada');
printf("%-36s %-11s %-22s %-9s %-8s %-6s %-11s %-9s %s\n", 'tab', 'gid', 'act_id', 'dashboard', 'DataAds', 'ERP', 'AppsScript', 'rollup', 'token / catatan');
foreach ($baris as $b) printf("%-36s %-11s %-22s %-9s %-8s %-6s %-11s %-9s %s\n", $b['tab'], $b['gid'] ?? '-', $b['act_id'] ?: '-', $v($b['dashboard']), $v($b['data_ads']), $v($b['erp']),
    $v($b['apps_script']) . ($b['apps_script'] && $b['gid'] && $b['gid_as'] !== $b['gid'] ? '*' : ''), $b['rollup'], trim($b['token_akses'] . ' ' . $b['catatan']));
echo "* = gid di ACCOUNTS beda dengan gid file bulan ini (Apps Script mencari tab dari nama → tetap terisi)\n";
