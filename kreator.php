<?php
/* [kreator 18 Nov, Mufti/Adila] KONTEN PER KREATOR — klasifikasi iklan (level iklan) per kreator dari data Meta yang sudah ditarik (ins_ad).
   Logika & aturan: kreator_lib.php. Set kreator manual (per iklan / nama iklan / campaign) & tabel kode dari web, tercatat di kreator_log. */
declare(strict_types=1);
$UJI = PHP_SAPI === 'cli' && getenv('KREATOR_UJI') === '1';
require __DIR__ . '/kreator_lib.php';
if (!$UJI) {
    require __DIR__ . '/auth.php';
    require_once '/opt/montera-ads/lib.php';
    ma_session_start();
    $C = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');
    if (($C['app_password'] ?? '') !== '' || !empty($C['login_users'])) {
        if (empty($_SESSION['ac_ok']) && ($ru = ma_remember_check($C)) !== null) { $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $ru; }
        if (empty($_SESSION['ac_ok'])) { header('Location: index.php'); exit; }
    }
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $csrf = (string)$_SESSION['csrf']; $user = (string)($_SESSION['ac_user'] ?? 'admin');
    session_write_close();
} else { $csrf = 'uji'; $user = (string)(getenv('KREATOR_USER') ?: 'uji'); }
date_default_timezone_set('Asia/Jakarta');
$db = kr_db();
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rp(float $x): string { return 'Rp' . number_format($x, 0, ',', '.'); }

/* ===================== AKSI ===================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || ($UJI && getenv('KREATOR_POST'))) {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode($UJI ? (string)getenv('KREATOR_POST') : (string)file_get_contents('php://input'), true) ?: [];
    $jawab = function (array $x) { echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; };
    if (!$UJI && !hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) $jawab(['ok' => false, 'pesan' => 'Sesi habis, muat ulang halaman.']);
    $kini = date('Y-m-d H:i:s');
    try {
        $aksi = (string)($in['aksi'] ?? '');
        // satu baris set manual (dipakai set satu-satu & set banyak sekaligus); FALSE = kunci tidak valid
        $simpan = function (string $lv, string $kunci, string $label, ?int $kid, ?string $baru) use ($db, $user, $kini) {
            if (!in_array($lv, ['ad', 'konten', 'campaign'], true)) return FALSE;
            $kunci = trim($kunci); $label = mb_substr(trim($label), 0, 500);
            if ($lv === 'konten') $kunci = kr_konten_kunci($kunci);
            if ($kunci === '' || $kunci === kr_konten_kunci('(tanpa naming)') || ($lv !== 'konten' && !preg_match('/^\d{5,32}$/', $kunci))) return FALSE;
            $md5 = md5($kunci);
            $q = $db->prepare("SELECT k.nama FROM kreator_manual m LEFT JOIN kreator k ON k.id = m.kreator_id WHERE m.level = ? AND m.kunci_md5 = ? FOR UPDATE"); $q->execute([$lv, $md5]);
            $lama = $q->fetchColumn();
            $db->prepare("INSERT INTO kreator_manual (level, kunci, kunci_md5, label, kreator_id, oleh, waktu) VALUES (?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE label = VALUES(label), kreator_id = VALUES(kreator_id), oleh = VALUES(oleh), waktu = VALUES(waktu)")
               ->execute([$lv, $kunci, $md5, $label, $kid, $user, $kini]);
            $db->prepare("INSERT INTO kreator_log (jenis, level, kunci, label, lama, baru, oleh, waktu) VALUES ('manual',?,?,?,?,?,?,?)")
               ->execute([$lv, $kunci, $label, $lama ?: '(otomatis)', $baru ?: '(otomatis)', $user, $kini]);
            return TRUE;
        };
        if ($aksi === 'set' || $aksi === 'set_banyak') {
            $kid = ($in['kreator_id'] ?? '') === '' || $in['kreator_id'] === null ? null : (int)$in['kreator_id'];
            $baru = null;
            if ($kid !== null) { $q = $db->prepare("SELECT nama FROM kreator WHERE id = ? AND aktif = 1"); $q->execute([$kid]); $baru = $q->fetchColumn(); if ($baru === false) $jawab(['ok' => false, 'pesan' => 'Kreator tidak ditemukan']); }
            $item = $aksi === 'set' ? [['level' => $in['level'] ?? '', 'kunci' => $in['kunci'] ?? '', 'label' => $in['label'] ?? '']] : (array)($in['item'] ?? []);
            if (!$item || count($item) > 500) $jawab(['ok' => false, 'pesan' => 'Pilih 1-500 iklan/konten']);
            $db->beginTransaction();
            foreach ($item as $it) if (!$simpan((string)($it['level'] ?? ''), (string)($it['kunci'] ?? ''), (string)($it['label'] ?? ''), $kid, $baru ?: null)) { $db->rollBack(); $jawab(['ok' => false, 'pesan' => 'Iklan / konten / campaign tidak valid: ' . mb_substr((string)($it['label'] ?? ''), 0, 60)]); }
            $db->commit();
            $jawab(['ok' => true, 'pesan' => ($baru ? 'Disimpan: ' . $baru : 'Dikembalikan ke baca otomatis') . (count($item) > 1 ? ' (' . count($item) . ' konten/iklan)' : '') . '.']);
        }
        if ($aksi === 'kode') {
            $kode = strtoupper(trim((string)($in['kode'] ?? ''))); $nama = mb_substr(trim((string)($in['nama'] ?? '')), 0, 60); $aktif = empty($in['aktif']) ? 0 : 1;
            if (!preg_match('/^[A-Z]{1,2}$/', $kode)) $jawab(['ok' => false, 'pesan' => 'Kode = 1-2 huruf (mis. D, TP)']);
            if ($nama === '') $jawab(['ok' => false, 'pesan' => 'Nama kreator wajib diisi']);
            $id = (int)($in['id'] ?? 0);
            $db->beginTransaction();
            $q = $db->prepare("SELECT id, CONCAT(kode, ' = ', nama, IF(aktif = 1, '', ' (nonaktif)')) l FROM kreator WHERE " . ($id ? 'id = ?' : 'kode = ?') . " FOR UPDATE");
            $q->execute([$id ?: $kode]); $ada = $q->fetch();
            if ($id && !$ada) { $db->rollBack(); $jawab(['ok' => false, 'pesan' => 'Kreator tidak ditemukan']); }
            if (!$id && $ada) { $db->rollBack(); $jawab(['ok' => false, 'pesan' => "Kode $kode sudah dipakai ({$ada['l']})"]); }
            if ($id) {
                $q = $db->prepare("SELECT 1 FROM kreator WHERE kode = ? AND id <> ?"); $q->execute([$kode, $id]);
                if ($q->fetchColumn()) { $db->rollBack(); $jawab(['ok' => false, 'pesan' => "Kode $kode sudah dipakai kreator lain"]); }
                $db->prepare("UPDATE kreator SET kode = ?, nama = ?, aktif = ?, diubah_oleh = ?, diubah_at = ? WHERE id = ?")->execute([$kode, $nama, $aktif, $user, $kini, $id]);
            } else {
                $db->prepare("INSERT INTO kreator (kode, nama, aktif, diubah_oleh, diubah_at) VALUES (?,?,?,?,?)")->execute([$kode, $nama, $aktif, $user, $kini]);
            }
            $db->prepare("INSERT INTO kreator_log (jenis, lama, baru, oleh, waktu) VALUES ('kode',?,?,?,?)")
               ->execute([$ada ? $ada['l'] : '(baru)', "$kode = $nama" . ($aktif ? '' : ' (nonaktif)'), $user, $kini]);
            $db->commit();
            $jawab(['ok' => true, 'pesan' => "Kode $kode = $nama disimpan."]);
        }
        $jawab(['ok' => false, 'pesan' => 'Aksi tidak dikenal']);
    } catch (Throwable $ex) {
        if ($db->inTransaction()) $db->rollBack();
        $jawab(['ok' => false, 'pesan' => 'Gagal menyimpan: ' . $ex->getMessage()]);
    }
}

/* ===================== FILTER ===================== */
$G = $UJI ? (json_decode((string)getenv('KREATOR_GET'), true) ?: []) : $_GET;
$kemarin = date('Y-m-d', strtotime('-1 day'));
$PRESET = ['7' => '7 hari terakhir', '14' => '14 hari terakhir', '30' => '30 hari terakhir', 'bulan' => 'Bulan ini', 'lalu' => 'Bulan lalu', 'kustom' => 'Pilih tanggal'];
$pr = isset($PRESET[$G['p'] ?? '']) ? (string)$G['p'] : '7';
$tgl = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
switch ($pr) {
    case '14': $dari = date('Y-m-d', strtotime('-14 day')); $sampai = $kemarin; break;
    case '30': $dari = date('Y-m-d', strtotime('-30 day')); $sampai = $kemarin; break;
    case 'bulan': $dari = date('Y-m-01'); $sampai = date('Y-m-d'); break;
    case 'lalu': $dari = date('Y-m-01', strtotime('first day of last month')); $sampai = date('Y-m-t', strtotime('first day of last month')); break;
    case 'kustom': $dari = $tgl($G['dari'] ?? null) ?: date('Y-m-d', strtotime('-7 day')); $sampai = $tgl($G['sampai'] ?? null) ?: $kemarin; if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari]; break;
    default: $dari = date('Y-m-d', strtotime('-7 day')); $sampai = $kemarin;   // = "Last 7 days" Ads Manager (tanpa hari ini)
}
$merek = in_array($G['merek'] ?? '', ['prepare', 'skinlyfe'], true) ? (string)$G['merek'] : '';
$fk = (string)($G['kreator'] ?? '');
$per = ($G['per'] ?? '') === 'iklan' ? 'iklan' : 'konten';   // konten = nama konten digabung (bawaan); iklan = per ad ID (= baris Ads Manager)

$daftar = kr_daftar($db);
$iklan = kr_iklan($db, $dari, $sampai, $merek);
$jmlIklan = count($iklan);
if ($per === 'konten') $iklan = kr_per_konten($iklan);
$semuaKreator = [];
foreach ($iklan as $a) $semuaKreator[$a['kreator']['nama']] = true;
$semuaBaris = $iklan;   // sebelum filter kreator (halaman Belum ada kreator & validasi)
if ($fk !== '') $iklan = array_values(array_filter($iklan, function ($a) use ($fk) { return $a['kreator']['nama'] === $fk; }));

// rekap per kreator: urut kreator terdaftar (abjad), lalu kode tidak dikenal, terakhir "Belum ada kode"
$rekap = [];
foreach ($iklan as $a) {
    $n = $a['kreator']['nama'];
    $r = &$rekap[$n];
    if (!$r) $r = ['nama' => $n, 'sumber' => $a['kreator']['sumber'], 'jml' => 0, 'kat' => array_fill_keys(KR_KATEGORI, 0), 'spend' => 0.0, 'omset' => 0.0, 'purch' => 0];
    $r['jml']++; $r['kat'][$a['kategori']]++; $r['spend'] += $a['spend']; $r['omset'] += $a['omset']; $r['purch'] += $a['purch'];
    unset($r);
}
uksort($rekap, function ($x, $y) use ($rekap) {
    $u = function ($r) { return $r['nama'] === KR_BELUM ? 2 : ($r['sumber'] === 'tidak-dikenal' ? 1 : 0); };
    return [$u($rekap[$x]), $x] <=> [$u($rekap[$y]), $y];
});
$total = ['jml' => 0, 'kat' => array_fill_keys(KR_KATEGORI, 0), 'spend' => 0.0, 'omset' => 0.0, 'purch' => 0];
foreach ($rekap as $r) { $total['jml'] += $r['jml']; foreach (KR_KATEGORI as $k) $total['kat'][$k] += $r['kat'][$k]; $total['spend'] += $r['spend']; $total['omset'] += $r['omset']; $total['purch'] += $r['purch']; }
$tanpaKode = kr_tanpa_kode($db);
$riwayat = $db->query("SELECT * FROM kreator_log ORDER BY id DESC LIMIT 30")->fetchAll();
$WARNA = ['TESTING' => '#64748b', 'OPPORTUNITY' => '#0891b2', 'HIGH POTENTIAL' => '#7c3aed', 'NOT PERFORMING' => '#dc2626', 'PERFORMING' => '#d97706', 'WINNING' => '#16a34a', 'TOP WINNING' => '#15803d'];
$SUMBER = ['manual-iklan' => 'manual (iklan ini)', 'manual-konten' => 'manual (konten)', 'manual-campaign' => 'manual (campaign)', 'kode' => 'kode di nama iklan', 'tidak-dikenal' => 'kode belum terdaftar', 'belum' => 'tanpa kode'];
$roasTxt = function (float $sp, float $om) { return $sp > 0 ? number_format($om / $sp, 2, ',', '.') : '–'; };
$q = function (array $x) use ($pr, $dari, $sampai, $merek, $fk, $per) { return '?' . http_build_query(array_merge(['p' => $pr, 'dari' => $dari, 'sampai' => $sampai, 'merek' => $merek, 'kreator' => $fk, 'per' => $per], $x)); };
$aktifK = array_values(array_filter($daftar, function ($k) { return (int)$k['aktif'] === 1; }));
$hal = ($G['hal'] ?? '') === 'belum' ? 'belum' : 'rekap';
$tk = !empty($G['tk']);   // Belum ada kreator: ikut tampilkan kode tidak dikenal
$belum = array_values(array_filter($semuaBaris, function ($a) use ($tk) { return $a['kreator']['sumber'] === 'belum' || ($tk && $a['kreator']['sumber'] === 'tidak-dikenal'); }));
$nBelum = count(array_filter($semuaBaris, function ($a) { return $a['kreator']['sumber'] === 'belum'; }));
$winPlus = $total['kat']['WINNING'] + $total['kat']['TOP WINNING'];
$val = kr_validasi($db, $dari, $sampai, $merek);
$jamTarik = kr_jam_tarik($db);
// penyebab baris akun × tanggal yang tidak sama
$pindah = []; foreach ($val['beda'] as $b) $pindah[$b['merek'] . '|' . $b['tanggal']][] = $b['pu_i'] - $b['pu_a'];
$sebab = function (array $b) use ($pindah, $kemarin) {
    $o = []; $dsp = $b['sp_i'] - $b['sp_a']; $dpu = $b['pu_i'] - $b['pu_a'];
    if (round($dsp) != 0) $o[] = abs($dsp) < 1000 && $b['tanggal'] >= date('Y-m-d', strtotime('-2 day', strtotime($kemarin)))
        ? 'spend selisih kecil: level iklan & level akun ditarik beda menit, data hari itu masih berubah di Meta (sinkron berikutnya menyamakan)'
        : ($dsp < 0 ? 'ada iklan yang belum tertarik di level iklan (kurang ' . rp(-$dsp) . ') — tarik ulang data' : 'spend level iklan lebih besar ' . rp($dsp) . ' — tarik ulang data');
    if ($dpu != 0 || round($b['om_i']) != round($b['om_a'])) $o[] = in_array(-$dpu, $pindah[$b['merek'] . '|' . $b['tanggal']] ?? [], true) && $dpu != 0
        ? 'purchase/omset dihitung Meta di akun lain pada tanggal yang sama (atribusi CPAS lintas akun; total brand tetap)'
        : 'purchase/omset level iklan ≠ level akun (atribusi Meta)';
    return implode(' · ', $o);
};
if (($G['export'] ?? '') === 'xlsx') { require __DIR__ . '/kreator_xlsx.php'; exit; }
?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Konten per Kreator</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--w:#2563eb;--line:#e2e8f0;--muted:#64748b;--text:#0f172a;--bg:#f6f7fb}
*{box-sizing:border-box}
body{margin:0;font:14px/1.4 'Inter',-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--text);background:var(--bg);-webkit-font-smoothing:antialiased}
.atas{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;padding:12px 20px;background:rgba(255,255,255,.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.atas h1{font-size:19px;margin:0;font-weight:800;letter-spacing:-.02em}
.kembali{color:var(--w);text-decoration:none;font-weight:600}
.atas .kanan{margin-left:auto;color:var(--muted);font-size:13px}
.isi{max-width:1280px;margin:0 auto;padding:16px 20px 60px}
.kartu{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.kartu h2{font-size:15px;margin:0 0 10px}
.ket{color:var(--muted);font-size:12.5px}
form.filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
form.filter label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--muted)}
select,input{height:36px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font:inherit;background:#fff;color:var(--text)}
button,.tombol{height:36px;padding:0 14px;border-radius:8px;border:1px solid var(--w);background:var(--w);color:#fff;font:inherit;font-weight:600;cursor:pointer}
button.abu{background:#fff;color:var(--text);border-color:var(--line)}
.tabel{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 9px;border-bottom:1px solid #eef2f7;text-align:left;white-space:nowrap}
th{font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.02em;background:#fafbfd;position:sticky;top:0}
td.n,th.n{text-align:right}
tfoot td{font-weight:700;background:#fafbfd}
.kat{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;color:#fff}
a.jml{display:inline-block;min-width:30px;text-align:center;padding:2px 8px;border-radius:7px;text-decoration:none;font-weight:700;color:#fff}
a.jml.nol{background:#f1f5f9!important;color:#cbd5e1;pointer-events:none}
.peringatan{border-color:#fca5a5;background:#fff5f5}
.peringatan h2{color:#b91c1c}
.sumber{font-size:11px;color:var(--muted)}
.nama-iklan{max-width:320px;overflow:hidden;text-overflow:ellipsis}
.set{display:flex;gap:6px;align-items:center}
.set select{height:30px;font-size:12.5px}
.set button{height:30px;padding:0 10px;font-size:12.5px}
#pesan{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 16px;border-radius:10px;display:none;z-index:9}
.chip-f{display:inline-flex;gap:6px;align-items:center;background:#eef2ff;color:#3730a3;border-radius:999px;padding:4px 10px;font-size:12.5px;font-weight:600}
/* [kreator 18 Nov] tab, kartu ringkasan, validasi, halaman Belum ada kreator */
.tab{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}
.tab a{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:999px;border:1px solid var(--line);background:#fff;color:var(--text);text-decoration:none;font-weight:600}
.tab a.on{background:var(--text);color:#fff;border-color:var(--text)}
.tab a.ekspor{margin-left:auto;background:#16a34a;color:#fff;border-color:#16a34a}
.tab .bdg{background:#fee2e2;color:#b91c1c;border-radius:999px;padding:0 8px;font-size:12px}
.tab a.on .bdg{background:#fff;color:#b91c1c}
.ring{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:0 0 14px}
.ring a,.ring div{display:block;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;text-decoration:none;color:var(--text);box-shadow:0 1px 2px rgba(16,24,40,.04)}
.ring b{display:block;font-size:22px;font-weight:800;letter-spacing:-.02em;margin-top:4px}
.ring span{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.ring a.win{border-top:3px solid #16a34a}
.ok{color:#15803d;font-weight:700}.no{color:#b91c1c;font-weight:700}
.val-baris{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;font-size:13.5px}
.val-baris .pil{display:inline-flex;flex-wrap:wrap;gap:4px 6px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:6px 10px;background:#fafbfd;max-width:100%;white-space:normal}
.bulk{position:sticky;bottom:12px;z-index:4;display:none;align-items:center;gap:10px;flex-wrap:wrap;background:#0f172a;color:#fff;border-radius:12px;padding:10px 14px;margin-top:10px}
.bulk.ada{display:flex}
.bulk select{height:34px}
@media (max-width:900px){.ring{grid-template-columns:repeat(2,minmax(0,1fr))}.tab a.ekspor{margin-left:0}}
@media (max-width:500px){.ring b{font-size:16px;overflow-wrap:anywhere}.ring a,.ring div{padding:12px}.val-baris input#cari{min-width:0;width:100%}}
@media (max-width:700px){.atas{padding:10px 14px}.isi{padding:12px 14px 50px}.atas .kanan{margin-left:0;width:100%}}
</style></head><body>
<div class="atas">
  <a class="kembali" href="index.php">← Dashboard</a><h1>Konten per Kreator</h1>
  <span class="kanan">Masuk sebagai <b><?= e($user) ?></b> · data Meta level iklan (tanpa pajak), periode <?= e(date('d/m/Y', strtotime($dari))) ?> – <?= e(date('d/m/Y', strtotime($sampai))) ?></span>
</div>
<div class="isi">
<nav class="tab">
  <a href="<?= e($q(['hal' => 'rekap'])) ?>" class="<?= $hal === 'rekap' ? 'on' : '' ?>">Rekap per kreator</a>
  <a href="<?= e($q(['hal' => 'belum'])) ?>" class="<?= $hal === 'belum' ? 'on' : '' ?>">Belum ada kreator <span class="bdg"><?= $nBelum ?></span></a>
  <a class="ekspor" href="<?= e($q(['hal' => $hal, 'export' => 'xlsx', 'tk' => $tk ? 1 : null])) ?>">⬇ Export Excel</a>
</nav>

<?php if ($tanpaKode && $hal === 'rekap'): ?>
<div class="kartu peringatan" id="tanpa-kode">
  <h2>⚠️ Iklan tanpa kode kreator: <?= count($tanpaKode) ?> iklan aktif baru</h2>
  <p class="ket" style="margin:0 0 8px">Iklan yang mulai belanja 7 hari terakhir dan masih belanja kemarin/hari ini, tapi nama iklannya tanpa kode kreator (format <b>&lt;seri&gt; &lt;nomor&gt;&lt;KODE&gt;</b>, mis. <b>Stitch 04R</b>). Benerin naming-nya di Ads Manager, atau set kreatornya manual di bawah.</p>
  <details id="dTk"><summary style="cursor:pointer;font-weight:600">Lihat daftar &amp; set kreator (<?= count($tanpaKode) ?>)</summary>
  <div class="tabel" style="max-height:420px;overflow:auto;margin-top:8px"><table><thead><tr><th>Iklan</th><th>Campaign</th><th>Akun</th><th>Mulai belanja</th><th>Set kreator</th></tr></thead><tbody>
  <?php foreach ($tanpaKode as $t): ?>
    <tr><td class="nama-iklan" title="<?= e($t['ad_name']) ?>"><?= e($t['ad_name']) ?></td><td class="nama-iklan" title="<?= e($t['campaign_name']) ?>"><?= e($t['campaign_name']) ?></td>
      <td><?= e(ucfirst((string)$t['merek'])) ?> · <?= e($t['akun']) ?></td><td><?= e(date('d/m', strtotime($t['pertama']))) ?></td>
      <td><div class="set" data-ad="<?= e($t['ad_id']) ?>" data-nama="<?= e($t['ad_name']) ?>" data-kk="<?= e(kr_konten((string)$t['ad_name'], (string)$t['campaign_name'])) ?>" data-cmp="<?= e($t['campaign_id']) ?>" data-cmpn="<?= e($t['campaign_name']) ?>"></div></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></details>
</div>
<?php endif; ?>

<div class="kartu">
  <form class="filter" method="get">
    <label>Periode<select name="p" onchange="document.getElementById('kustom').style.display=this.value==='kustom'?'flex':'none'">
      <?php foreach ($PRESET as $k => $v): ?><option value="<?= e($k) ?>" <?= $pr === (string)$k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <span id="kustom" style="display:<?= $pr === 'kustom' ? 'flex' : 'none' ?>;gap:10px">
      <label>Dari<input type="date" name="dari" value="<?= e($dari) ?>"></label><label>Sampai<input type="date" name="sampai" value="<?= e($sampai) ?>" max="<?= e(date('Y-m-d')) ?>"></label></span>
    <label>Brand<select name="merek"><option value="">Semua</option><option value="prepare" <?= $merek === 'prepare' ? 'selected' : '' ?>>Prepare</option><option value="skinlyfe" <?= $merek === 'skinlyfe' ? 'selected' : '' ?>>Skinlyfe</option></select></label>
    <label>Kreator<select name="kreator"><option value="">Semua</option>
      <?php foreach (array_keys($semuaKreator + ($fk !== '' ? [$fk => true] : [])) as $n): ?><option <?= $fk === $n ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
    <label>Hitung per<select name="per"><option value="konten" <?= $per === 'konten' ? 'selected' : '' ?>>Konten (iklan bernama sama digabung)</option><option value="iklan" <?= $per === 'iklan' ? 'selected' : '' ?>>Iklan (ad ID, = Ads Manager)</option></select></label>
    <input type="hidden" name="hal" value="<?= e($hal) ?>">
    <?php if ($hal === 'belum'): ?><label style="flex-direction:row;align-items:center;gap:6px;height:36px"><input type="checkbox" name="tk" value="1" <?= $tk ? 'checked' : '' ?> style="height:auto"> ikut kode tidak dikenal</label><?php endif; ?>
    <button type="submit">Tampilkan</button>
  </form>
  <p class="ket" style="margin:10px 0 0">Kategori per konten (level iklan) dari Amount Spent &amp; Purchase ROAS periode yang sama:
    <?= $per === 'konten' ? 'konten = semua iklan dengan nama konten sama (varian B2/B3, Copy, Shopee/VATC digabung, sama dengan sheet Naming) · ' : '' ?>spent &lt; 1 jt → ROAS &lt; 3 <b>Testing</b>, 3–&lt;8 <b>Opportunity</b>, ≥ 8 <b>High Potential</b> ·
    spent ≥ 1 jt → ROAS &lt; 2 <b>Not Performing</b>, 2–&lt;3 <b>Performing</b>, ≥ 3 <b>Winning</b> (spent 1–5 jt) / <b>Top Winning</b> (&gt; 5 jt).
    Omset &amp; purchase = katalog CPAS (shared items), atribusi 7 hari klik / 1 hari lihat. Hanya iklan yang belanja di periode ini (<?= number_format($jmlIklan, 0, ',', '.') ?> iklan<?= $per === 'konten' ? ' → ' . number_format(count($iklan), 0, ',', '.') . ' konten' : '' ?>).</p>
</div>

<?php if ($hal === 'rekap'): ?>
<div class="ring">
  <a href="#daftar" class="jml-kartu" data-k="" data-c=""><span>Konten</span><b><?= number_format($total['jml'], 0, ',', '.') ?></b></a>
  <div><span>Spent</span><b><?= rp($total['spend']) ?></b></div>
  <div><span>Omset</span><b><?= rp($total['omset']) ?></b></div>
  <div><span>ROAS</span><b><?= $roasTxt($total['spend'], $total['omset']) ?></b></div>
  <a href="#daftar" class="win jml-kartu" data-k="" data-c="WINNING|TOP WINNING"><span>Winning + Top Winning</span><b><?= $winPlus ?></b></a>
</div>

<?php /* validasi: total semua iklan (dasar klasifikasi) = total level akun Meta (Ads Manager) */ $v = $val; $ik = function ($t) { return $t['ok'] ? '<span class="ok">✅</span>' : '<span class="no">❌</span>'; };
  $sel = function (float $a, float $b, bool $rp = true) { $d = $a - $b; return round($d) == 0 ? '' : ' <span class="no">(' . ($d > 0 ? '+' : '−') . ($rp ? rp(abs($d)) : number_format(abs($d), 0, ',', '.')) . ')</span>'; }; ?>
<div class="kartu" id="validasi">
  <h2><?= $v['ok'] ? '✅' : '❌' ?> Validasi: total semua kreator = total Ads Manager (level akun) <span class="ket" style="font-weight:400">· data ditarik <?= $jamTarik ? e(date('d/m H:i', strtotime($jamTarik))) : '-' ?></span></h2>
  <div class="val-baris">
    <?php foreach ($v['brand'] + ['_' => $v['total']] as $t): ?>
    <span class="pil"><?= $ik($t) ?> <b><?= e($t['nama']) ?></b> spent <?= rp($t['sp_i']) ?><?= $sel($t['sp_i'], $t['sp_a']) ?> · omset <?= rp($t['om_i']) ?><?= $sel($t['om_i'], $t['om_a']) ?> · purchase <?= number_format($t['pu_i'], 0, ',', '.') ?><?= $sel((float)$t['pu_i'], (float)$t['pu_a'], false) ?></span>
    <?php endforeach; ?>
  </div>
  <p class="ket" style="margin:8px 0 0">Kiri = jumlah semua iklan (dasar klasifikasi), angka merah dalam kurung = selisih terhadap total akun yang sama dari Meta. Spend tanpa pajak.</p>
  <details style="margin-top:8px" <?= $v['ok'] ? '' : 'open' ?>><summary style="cursor:pointer;font-weight:600">Per akun (<?= count(array_filter($v['akun'], function ($a) { return !$a['ok']; })) ?> tidak cocok dari <?= count($v['akun']) ?>)</summary>
  <div class="tabel" style="max-height:360px;overflow:auto;margin-top:8px"><table><thead><tr><th></th><th>Akun</th><th>Brand</th><th class="n">Spent iklan</th><th class="n">Spent akun</th><th class="n">Omset iklan</th><th class="n">Omset akun</th><th class="n">Purchase iklan</th><th class="n">Purchase akun</th></tr></thead><tbody>
  <?php foreach ($v['akun'] as $t): ?><tr><td><?= $ik($t) ?></td><td><?= e($t['nama']) ?></td><td><?= e(ucfirst((string)$t['merek'])) ?></td><td class="n"><?= rp($t['sp_i']) ?></td><td class="n"><?= rp($t['sp_a']) ?></td><td class="n"><?= rp($t['om_i']) ?></td><td class="n"><?= rp($t['om_a']) ?></td><td class="n"><?= $t['pu_i'] ?></td><td class="n"><?= $t['pu_a'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($v['beda']): ?>
  <h2 style="margin:12px 0 6px;font-size:14px">Penyebab (akun × tanggal yang tidak sama)</h2>
  <div class="tabel"><table><thead><tr><th>Akun</th><th>Tanggal</th><th class="n">Selisih spent</th><th class="n">Selisih omset</th><th class="n">Selisih purchase</th><th>Penyebab</th></tr></thead><tbody>
  <?php foreach ($v['beda'] as $b): ?><tr><td><?= e($b['nama']) ?></td><td><?= e(date('d/m', strtotime($b['tanggal']))) ?></td><td class="n"><?= rp($b['sp_i'] - $b['sp_a']) ?></td><td class="n"><?= rp($b['om_i'] - $b['om_a']) ?></td><td class="n"><?= $b['pu_i'] - $b['pu_a'] ?></td><td style="white-space:normal;min-width:280px"><?= e($sebab($b)) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  </details>
</div>

<div class="kartu">
  <h2>Rekap per kreator</h2>
  <div class="tabel"><table>
    <thead><tr><th>Kreator</th><th class="n"><?= $per === 'konten' ? 'Konten' : 'Iklan' ?></th><?php foreach (KR_KATEGORI as $k): ?><th class="n"><?= e(ucwords(strtolower($k))) ?></th><?php endforeach; ?><th class="n">Spent</th><th class="n">Omset</th><th class="n">ROAS</th><th class="n">Purchase</th><th class="n">CPA</th></tr></thead>
    <tbody>
    <?php foreach ($rekap as $r): ?>
      <tr><td><b><?= e($r['nama']) ?></b></td><td class="n"><a href="#daftar" class="jml" style="background:#334155" data-k="<?= e($r['nama']) ?>" data-c=""><?= $r['jml'] ?></a></td>
      <?php foreach (KR_KATEGORI as $k): ?><td class="n"><a href="#daftar" class="jml <?= $r['kat'][$k] ? '' : 'nol' ?>" style="background:<?= $WARNA[$k] ?>" data-k="<?= e($r['nama']) ?>" data-c="<?= e($k) ?>"><?= $r['kat'][$k] ?></a></td><?php endforeach; ?>
      <td class="n"><?= rp($r['spend']) ?></td><td class="n"><?= rp($r['omset']) ?></td><td class="n"><?= $roasTxt($r['spend'], $r['omset']) ?></td><td class="n"><?= number_format($r['purch'], 0, ',', '.') ?></td><td class="n"><?= $r['purch'] ? rp($r['spend'] / $r['purch']) : '–' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rekap): ?><tr><td colspan="14" class="ket">Tidak ada iklan yang belanja di periode ini.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="n"><a href="#daftar" class="jml" style="background:#0f172a" data-k="" data-c=""><?= $total['jml'] ?></a></td>
      <?php foreach (KR_KATEGORI as $k): ?><td class="n"><a href="#daftar" class="jml <?= $total['kat'][$k] ? '' : 'nol' ?>" style="background:<?= $WARNA[$k] ?>" data-k="" data-c="<?= e($k) ?>"><?= $total['kat'][$k] ?></a></td><?php endforeach; ?>
      <td class="n"><?= rp($total['spend']) ?></td><td class="n"><?= rp($total['omset']) ?></td><td class="n"><?= $roasTxt($total['spend'], $total['omset']) ?></td><td class="n"><?= number_format($total['purch'], 0, ',', '.') ?></td><td class="n"><?= $total['purch'] ? rp($total['spend'] / $total['purch']) : '–' ?></td></tr></tfoot>
  </table></div>
</div>

<div class="kartu" id="daftar">
  <h2>Daftar konten <span id="judulF"></span></h2>
  <p class="ket" style="margin:-4px 0 10px">Klik angka di rekap untuk menyaring. Kreator manual menang atas kode di nama iklan; tiap perubahan tercatat (siapa &amp; kapan).</p>
  <div class="tabel"><table id="tblIklan">
    <thead><tr><th><?= $per === 'konten' ? 'Konten' : 'Iklan' ?></th><th>Kreator</th><th>Kategori</th><th class="n">Spent</th><th class="n">Omset</th><th class="n">ROAS</th><th class="n">Purchase</th><th class="n">CPA</th><th>Campaign / akun</th><th>Set kreator manual</th></tr></thead>
    <tbody>
    <?php foreach ($iklan as $a): $kr = $a['kreator']; ?>
      <tr data-k="<?= e($kr['nama']) ?>" data-c="<?= e($a['kategori']) ?>">
        <?php if ($per === 'konten'): ?>
        <td><details><summary class="nama-iklan" style="cursor:pointer;display:list-item" title="<?= e($a['konten']) ?>"><?= e($a['konten']) ?> <span class="sumber">· <?= e($a['produk']) ?> · <?= count($a['iklan']) ?> iklan</span></summary>
          <div class="sumber" style="white-space:normal;max-width:420px"><?php foreach (array_slice($a['iklan'], 0, 25) as $x): ?><?= e($x['ad_name']) ?> · <?= e($x['akun']) ?> · <?= rp($x['spend']) ?> <span style="color:#94a3b8">(ID <?= e($x['ad_id']) ?>)</span><br><?php endforeach; ?><?= count($a['iklan']) > 25 ? '… +' . (count($a['iklan']) - 25) . ' iklan' : '' ?></div></details></td>
        <?php else: ?>
        <td class="nama-iklan" title="<?= e($a['ad_name']) ?> (ID <?= e($a['ad_id']) ?>)"><?= e($a['ad_name']) ?></td>
        <?php endif; ?>
        <td><b><?= e($kr['nama']) ?></b><br><span class="sumber"><?= e($SUMBER[$kr['sumber']]) ?><?= $kr['oleh'] ? ' · ' . e($kr['oleh']) . ' ' . e(date('d/m H:i', strtotime((string)$kr['waktu']))) : '' ?></span></td>
        <td><span class="kat" style="background:<?= $WARNA[$a['kategori']] ?>"><?= e($a['kategori']) ?></span></td>
        <td class="n"><?= rp($a['spend']) ?></td><td class="n"><?= rp($a['omset']) ?></td><td class="n"><?= (is_infinite($a['roas']) ? '∞' : number_format($a['roas'], 2, ',', '.')) ?></td>
        <td class="n"><?= number_format($a['purch'], 0, ',', '.') ?></td><td class="n"><?= $a['cpa'] !== null ? rp($a['cpa']) : '–' ?></td>
        <td class="nama-iklan" title="<?= e($a['campaign_name']) ?>"><?= e($a['campaign_name']) ?><br><span class="sumber"><?= e(ucfirst((string)$a['merek'])) ?> · <?= e($a['akun']) ?></span></td>
        <td><div class="set" data-ad="<?= $per === 'iklan' ? e($a['ad_id']) : '' ?>" data-nama="<?= e($a['ad_name']) ?>" data-kk="<?= e($a['konten']) ?>" data-cmp="<?= e($a['campaign_id']) ?>" data-cmpn="<?= e($a['campaign_name']) ?>"></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="kartu" id="kode">
  <h2>Kode kreator</h2>
  <p class="ket" style="margin:-4px 0 10px">Kode = huruf yang menempel di belakang nomor konten di akhir nama iklan (mis. <b>04R</b> → R). Kode nonaktif tidak dibaca.</p>
  <div class="tabel"><table><thead><tr><th>Kode</th><th>Nama kreator</th><th>Aktif</th><th>Diubah</th><th></th></tr></thead><tbody>
  <?php foreach ($daftar as $k): ?>
    <tr><td><input value="<?= e($k['kode']) ?>" maxlength="2" style="width:60px" data-f="kode"></td><td><input value="<?= e($k['nama']) ?>" maxlength="60" data-f="nama"></td>
      <td><input type="checkbox" <?= (int)$k['aktif'] === 1 ? 'checked' : '' ?> data-f="aktif" style="height:auto"></td>
      <td class="sumber"><?= e((string)($k['diubah_oleh'] ?? '')) ?> <?= $k['diubah_at'] ?? null ? e(date('d/m H:i', strtotime((string)$k['diubah_at']))) : '' ?></td>
      <td><button class="abu simpan-kode" data-id="<?= (int)$k['id'] ?>">Simpan</button></td></tr>
  <?php endforeach; ?>
    <tr><td><input placeholder="mis. TP" maxlength="2" style="width:60px" data-f="kode"></td><td><input placeholder="Nama kreator" maxlength="60" data-f="nama"></td><td><input type="checkbox" checked data-f="aktif" style="height:auto"></td><td></td><td><button class="simpan-kode" data-id="0">Tambah</button></td></tr>
  </tbody></table></div>
</div>

<div class="kartu">
  <h2>Riwayat perubahan</h2>
  <div class="tabel"><table><thead><tr><th>Waktu</th><th>Oleh</th><th>Apa</th><th>Lama → Baru</th></tr></thead><tbody>
  <?php foreach ($riwayat as $l): ?>
    <tr><td><?= e(date('d/m/Y H:i', strtotime($l['waktu']))) ?></td><td><?= e($l['oleh']) ?></td>
      <td class="nama-iklan" title="<?= e((string)$l['label']) ?>"><?= $l['jenis'] === 'kode' ? 'Kode kreator' : e(['ad' => 'Iklan', 'konten' => 'Konten', 'campaign' => 'Campaign'][$l['level']] ?? $l['level']) . ': ' . e((string)$l['label']) ?></td>
      <td><?= e((string)$l['lama']) ?> → <b><?= e((string)$l['baru']) ?></b></td></tr>
  <?php endforeach; ?>
  <?php if (!$riwayat): ?><tr><td colspan="4" class="ket">Belum ada perubahan.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php else: /* ===== halaman Belum ada kreator ===== */ ?>
<div class="kartu">
  <h2>Belum ada kreator: <?= count($belum) ?> <?= $per === 'konten' ? 'konten' : 'iklan' ?></h2>
  <p class="ket" style="margin:-4px 0 10px">Iklan tanpa kode kreator di nama & belum di-set manual<?= $tk ? ', plus kode yang belum terdaftar' : '' ?>. Centang lalu pilih kreator → <b>Set</b> (tercatat siapa &amp; kapan). Periode, brand &amp; hitung per mengikuti filter di atas.</p>
  <div class="val-baris" style="margin-bottom:10px">
    <input id="cari" type="search" placeholder="Cari nama iklan / campaign / akun…" style="min-width:260px;flex:1">
    <select id="fAkun"><option value="">Semua akun</option><?php $akunDari = function ($a) { return isset($a['iklan']) ? array_values(array_unique(array_column($a['iklan'], 'akun'))) : [$a['akun']]; };
      $ak = []; foreach ($belum as $a) foreach ($akunDari($a) as $x) $ak[$x] = 1; $ak = array_keys($ak); sort($ak); foreach ($ak as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select>
    <select id="fAktif"><option value="">Aktif &amp; tidak</option><option value="1">Aktif</option><option value="0">Tidak aktif</option></select>
    <select id="fUrut"><option value="sp">Urut spent terbesar</option><option value="sp-">Urut spent terkecil</option><option value="mulai">Urut mulai tayang terbaru</option></select>
  </div>
  <div class="tabel"><table id="tblBelum">
    <thead><tr><th><input type="checkbox" id="semua" style="height:auto"></th><th><?= $per === 'konten' ? 'Konten' : 'Iklan' ?></th><th>Campaign</th><th>Akun</th><th>Brand</th><th>Mulai tayang</th><th>Status</th><th class="n">Spent</th><th class="n">Omset</th><th class="n">ROAS</th><th class="n">Purchase</th></tr></thead>
    <tbody>
    <?php foreach ($belum as $a): $aktif = $a['status'] === 'ACTIVE'; $kk = $per === 'konten' ? $a['konten'] : $a['ad_id']; ?>
      <tr data-cari="<?= e(mb_strtolower($a['ad_name'] . ' ' . $a['campaign_name'] . ' ' . $a['akun'] . ' ' . implode(' ', array_column($a['iklan'] ?? [], 'ad_name')))) ?>" data-akun="|<?= e(implode('|', $akunDari($a))) ?>|" data-aktif="<?= $aktif ? 1 : 0 ?>" data-sp="<?= round($a['spend']) ?>" data-mulai="<?= e((string)$a['mulai']) ?>">
        <td><input type="checkbox" class="pilih" style="height:auto" data-level="<?= $per === 'konten' ? 'konten' : 'ad' ?>" data-kunci="<?= e($kk) ?>" data-label="<?= e($a['ad_name']) ?>"></td>
        <td class="nama-iklan" title="<?= e($a['ad_name']) ?>"><?= e($a['ad_name']) ?><?= $per === 'konten' ? ' <span class="sumber">· ' . count($a['iklan']) . ' iklan</span>' : '' ?><?= $a['kreator']['sumber'] === 'tidak-dikenal' ? ' <span class="sumber">· ' . e($a['kreator']['nama']) . '</span>' : '' ?></td>
        <td class="nama-iklan" title="<?= e($a['campaign_name']) ?>"><?= e($a['campaign_name']) ?></td><td title="<?= e(implode(', ', $akunDari($a))) ?>"><?= e($a['akun']) ?></td><td><?= e(ucfirst((string)$a['merek'])) ?></td>
        <td><?= $a['mulai'] ? e(date('d/m/Y', strtotime($a['mulai']))) : '-' ?></td><td><?= $aktif ? '<span class="ok">Aktif</span>' : e(kr_status_teks($a['status'])) ?></td>
        <td class="n"><?= rp($a['spend']) ?></td><td class="n"><?= rp($a['omset']) ?></td><td class="n"><?= (is_infinite($a['roas']) ? '∞' : number_format($a['roas'], 2, ',', '.')) ?></td><td class="n"><?= number_format($a['purch'], 0, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$belum): ?><tr><td colspan="11" class="ket">Semua konten di periode ini sudah punya kreator. 🎉</td></tr><?php endif; ?>
    </tbody></table></div>
  <p class="ket" style="margin:8px 0 0">Mulai tayang = tanggal pertama belanja (data sejak 01/09/2026). Status dari Meta (diperbarui tiap ±10 menit).</p>
  <div class="bulk" id="bulk"><b id="nPilih">0 dipilih</b>
    <select id="bulkK"><option value="">— pilih kreator —</option><?php foreach ($aktifK as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?> (<?= e($k['kode']) ?>)</option><?php endforeach; ?></select>
    <button id="bulkSet" style="background:#16a34a;border-color:#16a34a">Set kreator</button><button id="bulkBatal" class="abu">Batal</button></div>
</div>
<?php endif; ?>
</div>
<div id="pesan"></div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const KREATOR = <?= json_encode(array_map(function ($k) { return ['id' => (int)$k['id'], 'nama' => $k['nama'], 'kode' => $k['kode']]; }, $aktifK), JSON_UNESCAPED_UNICODE) ?>;
function pesan(t) { const p = document.getElementById('pesan'); p.textContent = t; p.style.display = 'block'; clearTimeout(pesan.t); pesan.t = setTimeout(() => p.style.display = 'none', 3500); }
async function kirim(data) {
  const r = await fetch(location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(data) });
  return r.json();
}
// isian set kreator manual (dibuat di browser supaya halaman tetap ringan)
document.querySelectorAll('.set').forEach((el) => {
  const pilih = document.createElement('select');
  pilih.innerHTML = '<option value="">— kreator —</option>' + KREATOR.map((k) => `<option value="${k.id}">${k.nama} (${k.kode})</option>`).join('') + '<option value="otomatis">Kembalikan ke otomatis</option>';
  const lv = document.createElement('select');
  lv.innerHTML = '<option value="konten">konten ini (semua iklannya)</option>' + (el.dataset.ad ? '<option value="ad">iklan ini saja</option>' : '') + '<option value="campaign">campaign ini</option>';
  const b = document.createElement('button'); b.textContent = 'Set';
  b.onclick = async () => {
    if (!pilih.value) { pesan('Pilih kreator dulu'); return; }
    const level = lv.value, kunci = level === 'ad' ? el.dataset.ad : level === 'konten' ? el.dataset.kk : el.dataset.cmp;
    const label = level === 'campaign' ? el.dataset.cmpn : level === 'konten' ? el.dataset.kk : el.dataset.nama;
    b.disabled = true;
    try {
      const j = await kirim({ aksi: 'set', level, kunci, label, kreator_id: pilih.value === 'otomatis' ? null : pilih.value });
      pesan(j.pesan || (j.ok ? 'Tersimpan' : 'Gagal')); if (j.ok) setTimeout(() => location.reload(), 700);
    } catch (e) { pesan('Gagal: ' + e.message); } finally { b.disabled = false; }
  };
  el.append(pilih, lv, b);
});
document.querySelectorAll('.simpan-kode').forEach((b) => b.onclick = async () => {
  const tr = b.closest('tr'), f = (n) => tr.querySelector(`[data-f="${n}"]`);
  b.disabled = true;
  try {
    const j = await kirim({ aksi: 'kode', id: +b.dataset.id, kode: f('kode').value, nama: f('nama').value, aktif: f('aktif').checked });
    pesan(j.pesan || (j.ok ? 'Tersimpan' : 'Gagal')); if (j.ok) setTimeout(() => location.reload(), 700);
  } catch (e) { pesan('Gagal: ' + e.message); } finally { b.disabled = false; }
});
if (location.hash === '#tanpa-kode' && document.getElementById('dTk')) document.getElementById('dTk').open = true;
// klik angka rekap / kartu → saring daftar konten (c boleh beberapa kategori dipisah |)
document.querySelectorAll('a.jml, a.jml-kartu').forEach((a) => a.onclick = () => {
  const k = a.dataset.k, c = a.dataset.c, cs = c ? c.split('|') : []; let n = 0;
  document.querySelectorAll('#tblIklan tbody tr').forEach((tr) => { const ok = (!k || tr.dataset.k === k) && (!cs.length || cs.includes(tr.dataset.c)); tr.style.display = ok ? '' : 'none'; if (ok) n++; });
  setTimeout(() => document.getElementById('daftar').scrollIntoView({ behavior: 'smooth' }), 0);
  document.getElementById('judulF').innerHTML = (k || c) ? `<span class="chip-f">${[k, c].filter(Boolean).join(' · ')} · ${n} konten <a href="#daftar" onclick="document.querySelectorAll('#tblIklan tbody tr').forEach(t=>t.style.display='');this.parentNode.remove();return false" style="color:inherit">✕</a></span>` : '';
});
// halaman Belum ada kreator: cari, saring akun/aktif, urut, centang banyak → set kreator sekali klik
(function () {
  const tb = document.querySelector('#tblBelum tbody'); if (!tb) return;
  const rows = [...tb.querySelectorAll('tr[data-cari]')], cari = document.getElementById('cari'), fA = document.getElementById('fAkun'), fS = document.getElementById('fAktif'), fU = document.getElementById('fUrut');
  const bulk = document.getElementById('bulk'), nP = document.getElementById('nPilih');
  function saring() {
    const t = cari.value.trim().toLowerCase(), a = fA.value, s = fS.value, u = fU.value;
    rows.sort((x, y) => u === 'mulai' ? (y.dataset.mulai || '').localeCompare(x.dataset.mulai || '') : (u === 'sp-' ? x.dataset.sp - y.dataset.sp : y.dataset.sp - x.dataset.sp)).forEach((r) => tb.appendChild(r));
    rows.forEach((r) => { const ok = (!t || r.dataset.cari.includes(t)) && (!a || r.dataset.akun.includes('|' + a + '|')) && (s === '' || r.dataset.aktif === s); r.style.display = ok ? '' : 'none'; if (!ok) r.querySelector('.pilih').checked = false; });
    hitung();
  }
  function dipilih() { return rows.filter((r) => r.style.display !== 'none' && r.querySelector('.pilih').checked).map((r) => r.querySelector('.pilih')); }
  function hitung() { const n = dipilih().length; nP.textContent = n + ' dipilih'; bulk.classList.toggle('ada', n > 0); }
  [cari, fA, fS, fU].forEach((el) => el.addEventListener('input', saring));
  tb.addEventListener('change', hitung);
  document.getElementById('semua').onchange = (e) => { rows.forEach((r) => { if (r.style.display !== 'none') r.querySelector('.pilih').checked = e.target.checked; }); hitung(); };
  document.getElementById('bulkBatal').onclick = () => { rows.forEach((r) => r.querySelector('.pilih').checked = false); document.getElementById('semua').checked = false; hitung(); };
  document.getElementById('bulkSet').onclick = async (e) => {
    const k = document.getElementById('bulkK').value, p = dipilih(); if (!k) { pesan('Pilih kreator dulu'); return; } if (!p.length) return;
    if (!confirm('Set ' + p.length + ' konten/iklan ke ' + document.getElementById('bulkK').selectedOptions[0].text + '?')) return;
    e.target.disabled = true;
    try { const j = await kirim({ aksi: 'set_banyak', kreator_id: k, item: p.map((c) => ({ level: c.dataset.level, kunci: c.dataset.kunci, label: c.dataset.label })) });
      pesan(j.pesan || (j.ok ? 'Tersimpan' : 'Gagal')); if (j.ok) setTimeout(() => location.reload(), 800); } catch (er) { pesan('Gagal: ' + er.message); } finally { e.target.disabled = false; }
  };
  saring();
})();
</script>
</body></html>
