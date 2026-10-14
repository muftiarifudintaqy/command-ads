<?php
/* LAPORAN HARIAN — tab "SHOPEE X CPAS" dari sheet Prepare & Skinlyfe (lewat Apps Script, cache 5 menit) */
declare(strict_types=1);
require __DIR__ . '/auth.php';
ma_session_start();
$C = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');
$loginOn = ($C['app_password'] ?? '') !== '' || !empty($C['login_users']);
if ($loginOn) {
    if (empty($_SESSION['ac_ok']) && ($ru = ma_remember_check($C)) !== null) { $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $ru; }
    if (empty($_SESSION['ac_ok'])) { header('Location: index.php'); exit; }
}
session_write_close();

$env = [];
foreach (@file('/etc/montera-laporan.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    if (strpos($l, '=') !== false) { [$k, $val] = explode('=', $l, 2); $env[trim($k)] = trim($val); }
}
$URL = $env['LAPORAN_URL'] ?? ''; $KEY = $env['LAPORAN_KEY'] ?? '';

$merk = (($_GET['merk'] ?? '') === 'skinlyfe') ? 'skinlyfe' : 'prepare';
$tz = new DateTimeZone('Asia/Jakarta');
$bln = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['bulan'] ?? '')) ? (string)$_GET['bulan'] : (new DateTime('now', $tz))->format('Y-m');
[$th, $bl] = array_map('intval', explode('-', $bln));
$NB = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

$cache = __DIR__ . "/logs/laporan_{$merk}_{$bln}.json";
$res = null; $err = '';
if (!isset($_GET['segar']) && is_file($cache) && time() - filemtime($cache) < 300) $res = json_decode((string)file_get_contents($cache), true);
if (!$res) {
    if ($URL === '' || $KEY === '') $err = 'Penghubung sheet belum diatur.';
    else {
        $ch = curl_init($URL . '?' . http_build_query(['key' => $KEY, 'merk' => $merk, 'bulan' => $bl, 'tahun' => $th]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40]);
        $raw = (string)curl_exec($ch); curl_close($ch);
        $j = json_decode($raw, true);
        if (!is_array($j)) $err = 'Sheet sedang tidak bisa dibaca, coba muat ulang sebentar lagi.';
        elseif (!empty($j['error'])) $err = 'Sheet: ' . $j['error'];
        else { $res = $j; @file_put_contents($cache, json_encode($j)); }
    }
    if (!$res && is_file($cache)) $res = json_decode((string)file_get_contents($cache), true);
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function bersih($s): string { $s = trim((string)$s); return ($s === '' || $s[0] === '#') ? '–' : $s; }

$rows = $res['data'] ?? [];
$h1 = $rows[0] ?? []; $h2 = $rows[1] ?? [];
$kol = []; $grp = '';
for ($c = 0; $c < count($h2); $c++) {
    if (trim((string)($h1[$c] ?? '')) !== '') $grp = trim((string)$h1[$c]);
    $h = trim(preg_replace('/\s+/', ' ', (string)$h2[$c]));
    if ($h === '') continue;
    $kol[] = ['i' => $c, 'g' => $c === 0 ? '' : $grp, 'h' => $h];
}
$isi = []; $total = null;
for ($r = 2; $r < count($rows); $r++) {
    $row = $rows[$r]; $a = trim((string)($row[0] ?? ''));
    if ($a === '') continue;
    if (preg_match('/\b20\d\d\b/', $a)) { $total = $row; break; }
    $n = 0;
    foreach ($kol as $k) if ($k['i'] > 0) { $v = trim((string)($row[$k['i']] ?? '')); if ($v !== '' && $v[0] !== '#' && preg_match('/[1-9]/', $v)) $n++; }
    if ($n >= 3) $isi[] = $row;
}
$akhir = $isi ? $isi[count($isi) - 1] : null;
function ambil(?array $row, array $kol, string $g, string $h): string {
    if (!$row) return '–';
    foreach ($kol as $k) if (stripos($k['g'], $g) === 0 && strcasecmp($k['h'], $h) === 0) return bersih($row[$k['i']] ?? '');
    return '–';
}
$KARTU = [['Shopee', 'SHOPEE', [['Cost', 'Cost'], ['Omset', 'Omset'], ['Pesanan', 'Pesanan'], ['ROAS', 'ROAS']]],
          ['CPAS Meta', 'CPAS', [['Cost', 'Cost'], ['Omset', 'Omset'], ['ROAS', 'ROAS']]],
          ['All Platform', 'Daily', [['Cost', 'Daily Cost All'], ['CPR', 'Daily CPR All'], ['ROAS', 'Daily Roas All / ROMI']]]];
$warna = $merk === 'skinlyfe' ? '#e0483e' : '#3b6fe0';
?><!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Harian — Montera Ads</title><link rel="icon" type="image/png" href="assets/montera-icon.png">
<style>
:root{--w:<?= $warna ?>;--bg:#f4f5f7;--kartu:#fff;--teks:#1c1e21;--redup:#65676b;--garis:#e4e6eb}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--teks)}
.atas{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:14px 18px;background:#fff;border-bottom:1px solid var(--garis);position:sticky;top:0;z-index:5}
.atas a.kembali{color:var(--redup);text-decoration:none;font-size:14px}.atas h1{font-size:18px;margin:0 8px 0 0}
.tg{display:inline-flex;border:1px solid var(--garis);border-radius:8px;overflow:hidden}.tg a{padding:7px 16px;text-decoration:none;color:var(--teks);font-weight:600;font-size:14px}
.tg a.aktif{background:var(--w);color:#fff}input[type=month]{padding:6px 8px;border:1px solid var(--garis);border-radius:8px;font:inherit}
.kecil{font-size:12px;color:var(--redup);margin-left:auto}.kecil a{color:var(--w)}
.isi{padding:16px 18px;max-width:1600px;margin:auto}.galat{background:#fff3cd;border:1px solid #ffe08a;padding:10px 14px;border-radius:8px;margin-bottom:14px}
h2{font-size:15px;margin:18px 0 8px;color:var(--redup);font-weight:600}
.kartu{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
.k{background:var(--kartu);border:1px solid var(--garis);border-radius:10px;padding:12px 14px}.k b{display:block;font-size:13px;color:var(--w);margin-bottom:8px}
.k dl{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:0;font-size:14px}.k dt{color:var(--redup)}.k dd{margin:0;text-align:right;font-weight:600}
.tabel{overflow-x:auto;background:#fff;border:1px solid var(--garis);border-radius:10px;margin-top:8px}
table{border-collapse:collapse;width:100%;font-size:13px;white-space:nowrap}th,td{padding:7px 10px;border-bottom:1px solid var(--garis);text-align:right}
th{background:var(--w);color:#fff;font-weight:600;text-align:center}thead tr:first-child th{background:color-mix(in srgb,var(--w) 80%,#000)}
td:first-child,th:first-child{text-align:left;position:sticky;left:0;background:#fff}th:first-child{background:var(--w)}
tfoot td{font-weight:700;background:#f0f2f5}tfoot td:first-child{background:#f0f2f5}tbody tr:hover td{background:#f7f8fa}
</style></head><body>
<div class="atas">
  <a class="kembali" href="index.php">← Dashboard</a><h1>Laporan Harian</h1>
  <div class="tg"><a href="?merk=prepare&bulan=<?= e($bln) ?>" class="<?= $merk === 'prepare' ? 'aktif' : '' ?>">Prepare</a><a href="?merk=skinlyfe&bulan=<?= e($bln) ?>" class="<?= $merk === 'skinlyfe' ? 'aktif' : '' ?>">Skinlyfe</a></div>
  <form method="get"><input type="hidden" name="merk" value="<?= e($merk) ?>"><input type="month" name="bulan" value="<?= e($bln) ?>" onchange="this.form.submit()"></form>
  <span class="kecil"><?= $res ? 'Diperbarui ' . e((new DateTime($res['diambil'] ?? 'now'))->setTimezone($tz)->format('d/m H:i')) . ' · ' : '' ?><a href="?merk=<?= e($merk) ?>&bulan=<?= e($bln) ?>&segar=1">Muat ulang</a></span>
</div>
<div class="isi">
<?php if ($err): ?><div class="galat"><?= e($err) ?><?= $res ? ' Menampilkan data terakhir yang tersimpan.' : '' ?></div><?php endif; ?>
<?php if ($res): ?>
  <h2><?= $akhir ? 'Hari terakhir terisi: ' . e($akhir[0]) : 'Belum ada data bulan ini' ?></h2>
  <div class="kartu"><?php foreach ($KARTU as [$jud, $g, $isiK]): ?><div class="k"><b><?= e($jud) ?></b><dl><?php foreach ($isiK as [$lbl, $hd]): ?><dt><?= e($lbl) ?></dt><dd><?= e(ambil($akhir, $kol, $g, $hd)) ?></dd><?php endforeach; ?></dl></div><?php endforeach; ?></div>
  <h2>Total <?= e($NB[$bl] . ' ' . $th) ?></h2>
  <div class="kartu"><?php foreach ($KARTU as [$jud, $g, $isiK]): ?><div class="k"><b><?= e($jud) ?></b><dl><?php foreach ($isiK as [$lbl, $hd]): ?><dt><?= e($lbl) ?></dt><dd><?= e(ambil($total, $kol, $g, $hd)) ?></dd><?php endforeach; ?></dl></div><?php endforeach; ?></div>
  <h2>Per hari · <?= e($res['nama'] ?? '') ?></h2>
  <div class="tabel"><table>
    <thead><tr><?php $i = 0; while ($i < count($kol)) { $g = $kol[$i]['g']; $n = 1; while ($i + $n < count($kol) && $kol[$i + $n]['g'] === $g) $n++; echo '<th colspan="' . $n . '">' . e($g) . '</th>'; $i += $n; } ?></tr>
      <tr><?php foreach ($kol as $k) echo '<th>' . e($k['h']) . '</th>'; ?></tr></thead>
    <tbody><?php foreach ($isi as $row) { echo '<tr>'; foreach ($kol as $k) echo '<td>' . e(bersih($row[$k['i']] ?? '')) . '</td>'; echo '</tr>'; } ?></tbody>
    <?php if ($total): ?><tfoot><tr><?php foreach ($kol as $k) echo '<td>' . e(bersih($total[$k['i']] ?? '')) . '</td>'; ?></tr></tfoot><?php endif; ?>
  </table></div>
<?php endif; ?>
</div></body></html>
