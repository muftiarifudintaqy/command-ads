<?php
/* DATA ADS — pendataan harian Shopee x CPAS (pengganti sheet). Tab: Laporan · Input Shopee · Akun CPAS */
declare(strict_types=1);
require __DIR__ . '/auth.php';
require '/opt/montera-ads/lib.php';
ma_session_start();
$C = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');
if (($C['app_password'] ?? '') !== '' || !empty($C['login_users'])) {
    if (empty($_SESSION['ac_ok']) && ($ru = ma_remember_check($C)) !== null) { $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $ru; }
    if (empty($_SESSION['ac_ok'])) { header('Location: index.php'); exit; }
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf']; $user = (string)($_SESSION['ac_user'] ?? 'admin');
session_write_close();
$db = ma_db();

/* ===================== AKSI (simpan) ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $jawab = function (array $x) { echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; };
    if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) $jawab(['ok' => false, 'pesan' => 'Sesi habis, muat ulang halaman.']);
    try {
        $aksi = (string)($in['aksi'] ?? '');
        if ($aksi === 'shopee') {
            $mk = ($in['merek'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
            $tgl = (string)($in['tanggal'] ?? '');
            $kol = (string)($in['kolom'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || $tgl > date('Y-m-d')) $jawab(['ok' => false, 'pesan' => 'Tanggal tidak valid']);
            if (!in_array($kol, ['cost', 'omset', 'pesanan', 'catatan'], true)) $jawab(['ok' => false, 'pesan' => 'Kolom tidak valid']);
            $raw = trim((string)($in['nilai'] ?? ''));
            $nilai = $kol === 'catatan' ? ($raw === '' ? null : mb_substr($raw, 0, 250)) : ($raw === '' ? null : (int)preg_replace('/\D/', '', $raw));
            $q = $db->prepare("SELECT $kol FROM shopee_harian WHERE merek = ? AND tanggal = ?"); $q->execute([$mk, $tgl]); $lama = $q->fetchColumn();
            $db->prepare("INSERT INTO shopee_harian (merek, tanggal, $kol, sumber, diubah_oleh, diubah_at) VALUES (?,?,?,'sistem',?,NOW())
                ON DUPLICATE KEY UPDATE $kol = VALUES($kol), sumber = 'sistem', diubah_oleh = VALUES(diubah_oleh), diubah_at = NOW()")->execute([$mk, $tgl, $nilai, $user]);
            $db->prepare("INSERT INTO log_ubah (pengguna, merek, tanggal, kolom, lama, baru) VALUES (?,?,?,?,?,?)")
               ->execute([$user, $mk, $tgl, $kol, $lama === false ? null : (string)$lama, $nilai === null ? null : (string)$nilai]);
            $jawab(['ok' => true, 'oleh' => $user, 'jam' => date('H:i')]);
        }
        if ($aksi === 'akun') {
            $id = (int)($in['id'] ?? 0); $mk = ($in['merek'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
            $nama = trim((string)($in['nama'] ?? '')); $act = trim((string)($in['act_id'] ?? ''));
            if (strpos($act, 'act_') !== 0 && ctype_digit($act)) $act = 'act_' . $act;
            if ($nama === '' || !preg_match('/^act_\d{6,25}$/', $act)) $jawab(['ok' => false, 'pesan' => 'Isi nama dan ID akun iklan (angka, contoh act_1234567890).']);
            $tok = in_array($in['token'] ?? '', ['prepare', 'marketBeauty', 'glowingCantik'], true) ? $in['token'] : 'prepare';
            $v = [$mk, $nama, $act, $tok, $mk === 'skinlyfe' ? 'skinlyfe' : 'prepare', empty($in['cpas_only']) ? 0 : 1, trim((string)($in['grup'] ?? '')) ?: null, empty($in['aktif']) ? 0 : 1];
            if ($id) { $v[] = $id; $db->prepare("UPDATE akun SET merek=?, nama=?, act_id=?, token=?, metode=?, cpas_only=?, grup=?, aktif=? WHERE id=?")->execute($v); }
            else { $db->prepare("INSERT INTO akun (merek, nama, act_id, token, metode, cpas_only, grup, aktif, urutan) VALUES (?,?,?,?,?,?,?,?,99)")->execute($v); $id = (int)$db->lastInsertId(); }
            $jawab(['ok' => true, 'id' => $id]);
        }
        if ($aksi === 'tarik') {
            set_time_limit(300);
            $q = $db->prepare("SELECT * FROM akun WHERE id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $a = $q->fetch();
            if (!$a) $jawab(['ok' => false, 'pesan' => 'Akun tidak ditemukan']);
            $n = 0; $t = date('Y-m-01'); $kemarin = date('Y-m-d', strtotime('-1 day'));
            for (; $t <= $kemarin; $t = date('Y-m-d', strtotime($t . ' +1 day'))) { $m = ma_meta_hari($a, $t); if (array_filter($m)) { ma_simpan_meta((int)$a['id'], $t, $m); $n++; } }
            $jawab(['ok' => true, 'pesan' => "$n hari terisi dari Meta."]);
        }
        $jawab(['ok' => false, 'pesan' => 'Aksi tidak dikenal']);
    } catch (Throwable $e) { $jawab(['ok' => false, 'pesan' => $e->getMessage()]); }
}

// [data-live] tanda perubahan terakhir, dipakai halaman untuk memperbarui diri sendiri
if (isset($_GET['ping'])) {
    header('Content-Type: application/json');
    $mk = ($_GET['merk'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
    $q = $db->prepare("SELECT CONCAT(IFNULL((SELECT MAX(diubah_at) FROM shopee_harian WHERE merek = ?), ''), '|',
        IFNULL((SELECT MAX(m.diambil_at) FROM meta_harian m JOIN akun k ON k.id = m.akun_id WHERE k.merek = ?), ''), '|', (SELECT COUNT(*) FROM akun))");
    $q->execute([$mk, $mk]); echo json_encode(['v' => $q->fetchColumn()]); exit;
}
/* ===================== DATA ===================== */
$merk = ($_GET['merk'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
$tab = in_array($_GET['tab'] ?? '', ['laporan', 'input', 'akun'], true) ? $_GET['tab'] : 'laporan';
$bln = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['bulan'] ?? '')) ? (string)$_GET['bulan'] : date('Y-m');
[$th, $bl] = array_map('intval', explode('-', $bln));
$awal = sprintf('%04d-%02d-01', $th, $bl); $jml = (int)date('t', strtotime($awal)); $akhir = sprintf('%04d-%02d-%02d', $th, $bl, $jml);
$NB = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$NH = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$label = function (string $t) use ($NB, $NH) { $u = strtotime($t); return $NH[(int)date('w', $u)] . ', ' . date('d', $u) . ' ' . $NB[(int)date('n', $u)]; };

$q = $db->prepare("SELECT * FROM shopee_harian WHERE merek = ? AND tanggal BETWEEN ? AND ?"); $q->execute([$merk, $awal, $akhir]);
$SH = []; foreach ($q->fetchAll() as $r) $SH[$r['tanggal']] = $r;
$q = $db->prepare("SELECT m.tanggal, SUM(m.spend) s, SUM(m.omset) o, SUM(m.purchase) p, SUM(m.atc) a FROM meta_harian m JOIN akun k ON k.id = m.akun_id
    WHERE k.merek = ? AND m.tanggal BETWEEN ? AND ? GROUP BY m.tanggal"); $q->execute([$merk, $awal, $akhir]);
$ME = []; foreach ($q->fetchAll() as $r) $ME[$r['tanggal']] = $r;

$HARI = []; $TOT = ['sc' => 0, 'so' => 0, 'sp' => 0, 'mc' => 0, 'mo' => 0, 'mp' => 0, 'ma' => 0, 'ca' => 0, 'n' => 0];
for ($d = 1; $d <= $jml; $d++) {
    $t = sprintf('%04d-%02d-%02d', $th, $bl, $d); $s = $SH[$t] ?? []; $m = $ME[$t] ?? [];
    $r = ['t' => $t, 'sc' => (float)($s['cost'] ?? 0), 'so' => (float)($s['omset'] ?? 0), 'sp' => (float)($s['pesanan'] ?? 0),
          'mc' => (float)($m['s'] ?? 0), 'mo' => (float)($m['o'] ?? 0), 'mp' => (float)($m['p'] ?? 0), 'ma' => (float)($m['a'] ?? 0)];
    $r['ca'] = $r['sc'] + $r['mc']; $r['isi'] = ($r['sc'] || $r['so'] || $r['mc']);
    $HARI[$t] = $r;
    if ($r['isi']) { foreach (['sc', 'so', 'sp', 'mc', 'mo', 'mp', 'ma', 'ca'] as $k) $TOT[$k] += $r[$k]; $TOT['n']++; }
}
function bagi(float $a, float $b): ?float { return $b ? $a / $b : null; }
function rp(?float $n): string { return $n === null ? '–' : 'Rp' . number_format(round($n), 0, ',', '.'); }
function an(?float $n): string { return $n === null ? '–' : number_format(round($n), 0, ',', '.'); }
function rs(?float $n): string { return $n === null ? '–' : number_format($n, 2, ',', '.'); }
function ps(?float $n): string { return $n === null ? '–' : number_format($n * 100, 2, ',', '.') . '%'; }
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$isi = array_values(array_filter($HARI, function ($r) { return $r['isi']; }));
$warna = $merk === 'skinlyfe' ? '#e0483e' : '#3b6fe0';
$url = function (array $x) use ($merk, $tab, $bln) { return '?' . http_build_query(array_merge(['merk' => $merk, 'tab' => $tab, 'bulan' => $bln], $x)); };
?><!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Ads — Montera</title><link rel="icon" type="image/png" href="assets/montera-icon.png">
<style>
:root{--w:<?= $warna ?>;--bg:#f4f6fa;--teks:#0f172a;--redup:#64748b;--garis:#e8ebf1}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--teks);font-size:14px}
.atas{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 20px;background:#fff;border-bottom:1px solid var(--garis);position:sticky;top:0;z-index:20}
.atas a.kembali{color:var(--redup);text-decoration:none}.atas h1{font-size:18px;margin:0 6px 0 0}
.tg{display:inline-flex;border:1px solid var(--garis);border-radius:10px;overflow:hidden}.tg a{padding:7px 14px;text-decoration:none;color:var(--teks);font-weight:600;font-size:13px}.tg a.on{background:var(--w);color:#fff}
.tab{display:inline-flex;gap:4px;background:#eef1f6;padding:4px;border-radius:12px}.tab a{padding:6px 14px;border-radius:9px;text-decoration:none;color:var(--redup);font-weight:600;font-size:13px}.tab a.on{background:#fff;color:var(--teks);box-shadow:0 1px 3px rgba(0,0,0,.08)}
input[type=month]{padding:6px 8px;border:1px solid var(--garis);border-radius:10px;font:inherit}
.kanan{margin-left:auto;font-size:12px;color:var(--redup)}.kanan a{color:var(--w)}
.isi{padding:18px 20px;max-width:1700px;margin:auto}
.kartu{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px}
.k{background:#fff;border-radius:14px;padding:12px 16px;box-shadow:0 1px 2px rgba(16,24,40,.05),0 6px 18px rgba(16,24,40,.06);position:relative;overflow:hidden}.k:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--w)}
.k .t{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--w)}.k .b{font-size:22px;font-weight:800;margin:4px 0 2px}.k .s{font-size:12px;color:var(--redup)}
.up{color:#16a34a;font-size:12px;font-weight:700;margin-left:6px}.dn{color:#dc2626;font-size:12px;font-weight:700;margin-left:6px}
.ket{font-size:12px;color:var(--redup);margin:4px 2px 12px}.ket b{color:var(--teks)}
.tabel{overflow:auto;background:#fff;border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.05),0 6px 18px rgba(16,24,40,.06);max-height:72vh}
table{border-collapse:separate;border-spacing:0;width:100%;white-space:nowrap;font-size:12.5px}
th,td{padding:6px 10px;text-align:right;border-bottom:1px solid #f1f5f9;font-variant-numeric:tabular-nums}
thead th{position:sticky;top:0;z-index:3;background:#1e293b;color:#cbd5e1;font-weight:600;text-align:center}
thead tr:first-child th{background:#0f172a;color:#fff;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;border-bottom:2px solid var(--w)}
thead tr:nth-child(2) th{top:29px}
td:first-child,th:first-child{position:sticky;left:0;text-align:left;z-index:2;background:#fff}thead th:first-child{z-index:5;background:#0f172a}thead tr:nth-child(2) th:first-child{background:#1e293b}
tbody td:first-child{font-weight:600;border-right:1px solid #eef0f4}tbody tr:hover td{background:#f5f8ff}
tfoot td{position:sticky;bottom:0;background:color-mix(in srgb,var(--w) 9%,#fff);font-weight:800;border-top:2px solid var(--w)}
.pl{display:inline-block;min-width:44px;text-align:center;padding:2px 8px;border-radius:999px;font-weight:700}.g1{background:#dcfce7;color:#15803d}.g2{background:#fef3c7;color:#b45309}.g3{background:#fee2e2;color:#b91c1c}
.inp{width:130px;padding:6px 8px;border:1px solid var(--garis);border-radius:8px;text-align:right;font:inherit;background:#fff}.inp:focus{outline:2px solid color-mix(in srgb,var(--w) 40%,#fff);border-color:var(--w)}
.inp.cat{width:220px;text-align:left}.inp:disabled{background:#f8fafc;color:#cbd5e1}
.st{font-size:11px;color:var(--redup);text-align:left}.st.ok{color:#16a34a}.st.salah{color:#dc2626}.lenc{display:inline-block;padding:1px 7px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:10.5px;font-weight:700}
.btn{border:0;background:var(--w);color:#fff;padding:7px 14px;border-radius:9px;font-weight:600;cursor:pointer;font:inherit}.btn.abu{background:#eef1f6;color:var(--teks)}
.form{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#fff;padding:12px 14px;border-radius:14px;margin-bottom:12px;box-shadow:0 1px 2px rgba(16,24,40,.05),0 6px 18px rgba(16,24,40,.06)}
.form input,.form select{padding:7px 9px;border:1px solid var(--garis);border-radius:9px;font:inherit}
a.akun{color:var(--teks);text-decoration:none;border-bottom:1px dashed #cbd5e1}.mati{opacity:.45}
</style></head><body>
<div class="atas">
  <a class="kembali" href="index.php">← Dashboard</a><h1>Data Ads</h1>
  <div class="tg"><a href="<?= e('?' . http_build_query(['merk' => 'prepare', 'tab' => $tab, 'bulan' => $bln])) ?>" class="<?= $merk === 'prepare' ? 'on' : '' ?>">Prepare</a><a href="<?= e('?' . http_build_query(['merk' => 'skinlyfe', 'tab' => $tab, 'bulan' => $bln])) ?>" class="<?= $merk === 'skinlyfe' ? 'on' : '' ?>">Skinlyfe</a></div>
  <div class="tab"><a href="<?= e($url(['tab' => 'laporan'])) ?>" class="<?= $tab === 'laporan' ? 'on' : '' ?>">Laporan</a><a href="<?= e($url(['tab' => 'input'])) ?>" class="<?= $tab === 'input' ? 'on' : '' ?>">Input Shopee</a><a href="<?= e($url(['tab' => 'akun', 'akun' => null])) ?>" class="<?= $tab === 'akun' ? 'on' : '' ?>">Akun CPAS</a></div>
  <form method="get"><input type="hidden" name="merk" value="<?= e($merk) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="month" name="bulan" value="<?= e($bln) ?>" onchange="this.form.submit()"></form>
  <span class="kanan">Masuk sebagai <b><?= e($user) ?></b> · <a href="laporan.php?merk=<?= e($merk) ?>&bulan=<?= e($bln) ?>">versi sheet</a></span>
</div>
<div class="isi">
<?php if ($tab === 'laporan'):
  $a = $isi ? $isi[count($isi) - 1] : null; $b = count($isi) > 1 ? $isi[count($isi) - 2] : null;
  $dl = function ($x, $y, $balik = false) { if ($x === null || !$y) return ''; $p = ($x - $y) / abs($y) * 100; $baik = $balik ? $p < 0 : $p >= 0; return '<span class="' . ($baik ? 'up' : 'dn') . '">' . ($p >= 0 ? '▲' : '▼') . ' ' . number_format(abs($p), 1, ',', '.') . '%</span>'; };
  $romi = function ($r) { return $r ? bagi($r['so'], $r['ca']) : null; };
  $avgS = bagi($TOT['so'], $TOT['sc']); $avgM = bagi($TOT['mo'], $TOT['mc']); $avgA = bagi($TOT['so'], $TOT['ca']);
  $pl = function (?float $v, ?float $rata) { if ($v === null || !$rata) return rs($v); $c = $v >= $rata ? 'g1' : ($v >= $rata * 0.85 ? 'g2' : 'g3'); return '<span class="pl ' . $c . '">' . rs($v) . '</span>'; };
?>
  <?php if ($a): ?>
  <div class="ket">Hari terakhir terisi: <b><?= e($label($a['t'])) ?></b> · dibanding hari sebelumnya</div>
  <div class="kartu">
    <div class="k"><div class="t">Omset Shopee</div><div class="b"><?= rp($a['so']) ?><?= $dl($a['so'], $b['so'] ?? null) ?></div><div class="s"><?= an($a['sp']) ?> pesanan · ROAS <?= rs(bagi($a['so'], $a['sc'])) ?></div></div>
    <div class="k"><div class="t">Omset CPAS Meta</div><div class="b"><?= rp($a['mo']) ?><?= $dl($a['mo'], $b['mo'] ?? null) ?></div><div class="s"><?= an($a['mp']) ?> purchase · ROAS <?= rs(bagi($a['mo'], $a['mc'])) ?></div></div>
    <div class="k"><div class="t">Total Cost Iklan</div><div class="b"><?= rp($a['ca']) ?><?= $dl($a['ca'], $b['ca'] ?? null, true) ?></div><div class="s">Shopee <?= rp($a['sc']) ?> · Meta <?= rp($a['mc']) ?></div></div>
    <div class="k"><div class="t">ROAS All (ROMI)</div><div class="b"><?= rs($romi($a)) ?><?= $dl($romi($a), $romi($b)) ?></div><div class="s">CPR all <?= rp(bagi($a['ca'], $a['sp'])) ?></div></div>
  </div>
  <div class="ket">Total <?= e($NB[$bl] . ' ' . $th) ?>: Omset Shopee <b><?= rp($TOT['so']) ?></b> · Omset Meta <b><?= rp($TOT['mo']) ?></b> · Cost <b><?= rp($TOT['ca']) ?></b> · ROAS All <b><?= rs($avgA) ?></b> · <?= an($TOT['sp']) ?> pesanan · rata-rata omset Shopee/hari <b><?= rp(bagi($TOT['so'], (float)$TOT['n'])) ?></b></div>
  <?php else: ?><div class="ket">Belum ada data bulan ini.</div><?php endif; ?>
  <div class="tabel"><table>
    <thead><tr><th></th><th colspan="6">Shopee</th><th colspan="6">CPAS Meta</th><th colspan="3">Daily All Platform</th></tr>
    <tr><th>Tanggal</th><th>Cost</th><th>% Shopee Ads</th><th>Omset</th><th>Pesanan</th><th>CPR</th><th>ROAS</th><th>Cost</th><th>Omset</th><th>Purchase</th><th>CPR</th><th>ROAS</th><th>Add to cart</th><th>Cost All</th><th>CPR All</th><th>ROAS All / ROMI</th></tr></thead>
    <tbody><?php foreach ($isi as $r): ?><tr>
      <td><?= e($label($r['t'])) ?></td><td><?= rp($r['sc']) ?></td><td><?= ps(bagi($r['sc'], $r['ca'])) ?></td><td><?= rp($r['so']) ?></td><td><?= an($r['sp']) ?></td><td><?= rp(bagi($r['sc'], $r['sp'])) ?></td><td><?= $pl(bagi($r['so'], $r['sc']), $avgS) ?></td>
      <td><?= rp($r['mc']) ?></td><td><?= rp($r['mo']) ?></td><td><?= an($r['mp']) ?></td><td><?= rp(bagi($r['mc'], $r['mp'])) ?></td><td><?= $pl(bagi($r['mo'], $r['mc']), $avgM) ?></td><td><?= an($r['ma']) ?></td>
      <td><?= rp($r['ca']) ?></td><td><?= rp(bagi($r['ca'], $r['sp'])) ?></td><td><?= $pl(bagi($r['so'], $r['ca']), $avgA) ?></td></tr><?php endforeach; ?></tbody>
    <?php if ($isi): ?><tfoot><tr><td><?= e($NB[$bl] . ' ' . $th) ?></td><td><?= rp($TOT['sc']) ?></td><td><?= ps(bagi($TOT['sc'], $TOT['ca'])) ?></td><td><?= rp($TOT['so']) ?></td><td><?= an($TOT['sp']) ?></td><td><?= rp(bagi($TOT['sc'], $TOT['sp'])) ?></td><td><?= rs($avgS) ?></td>
      <td><?= rp($TOT['mc']) ?></td><td><?= rp($TOT['mo']) ?></td><td><?= an($TOT['mp']) ?></td><td><?= rp(bagi($TOT['mc'], $TOT['mp'])) ?></td><td><?= rs($avgM) ?></td><td><?= an($TOT['ma']) ?></td>
      <td><?= rp($TOT['ca']) ?></td><td><?= rp(bagi($TOT['ca'], $TOT['sp'])) ?></td><td><?= rs($avgA) ?></td></tr></tfoot><?php endif; ?>
  </table></div>

<?php elseif ($tab === 'input'): ?>
  <div class="ket">Isi angka dari <b>Seller Center</b>. Tersimpan otomatis saat pindah kotak. Kalau hari itu sudah diisi di sheet, angkanya ikut tampil (bertanda <span class="lenc">sheet</span>); begitu diubah di sini, angka sistem yang dipakai.</div>
  <div class="tabel"><table>
    <thead><tr><th></th><th colspan="3">Shopee (isi manual)</th><th colspan="2">Otomatis</th><th colspan="2"></th></tr>
    <tr><th>Tanggal</th><th>Cost iklan Shopee</th><th>Omset</th><th>Pesanan</th><th>Cost CPAS Meta</th><th>ROAS All</th><th>Catatan</th><th>Terakhir diubah</th></tr></thead>
    <tbody><?php foreach ($HARI as $t => $r): $s = $SH[$t] ?? []; $depan = $t > date('Y-m-d'); ?><tr data-t="<?= e($t) ?>">
      <td><?= e($label($t)) ?></td>
      <?php foreach (['cost', 'omset', 'pesanan'] as $k): ?><td><input class="inp" data-k="<?= $k ?>" inputmode="numeric" value="<?= isset($s[$k]) && $s[$k] !== null ? e(number_format((float)$s[$k], 0, ',', '.')) : '' ?>" <?= $depan ? 'disabled' : '' ?>></td><?php endforeach; ?>
      <td><?= $r['mc'] ? rp($r['mc']) : '–' ?></td><td><?= rs(bagi($r['so'], $r['ca'])) ?></td>
      <td><input class="inp cat" data-k="catatan" value="<?= e($s['catatan'] ?? '') ?>" <?= $depan ? 'disabled' : '' ?>></td>
      <td class="st"><?= !empty($s['diubah_at']) ? (($s['sumber'] === 'sheet' ? '<span class="lenc">sheet</span> ' : e($s['diubah_oleh']) . ' · ') . e(date('d/m H:i', strtotime($s['diubah_at'])))) : '' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>

<?php else:
  $akunId = (int)($_GET['akun'] ?? 0);
  $q = $db->prepare("SELECT k.*, SUM(m.spend) s, SUM(m.omset) o, SUM(m.purchase) p FROM akun k LEFT JOIN meta_harian m ON m.akun_id = k.id AND m.tanggal BETWEEN ? AND ?
      WHERE k.merek = ? GROUP BY k.id ORDER BY k.aktif DESC, k.urutan, k.id"); $q->execute([$awal, $akhir, $merk]); $AK = $q->fetchAll();
  if ($akunId):
    $q = $db->prepare("SELECT * FROM akun WHERE id = ?"); $q->execute([$akunId]); $ak = $q->fetch();
    $q = $db->prepare("SELECT * FROM meta_harian WHERE akun_id = ? AND tanggal BETWEEN ? AND ? ORDER BY tanggal"); $q->execute([$akunId, $awal, $akhir]); $DH = $q->fetchAll(); $tt = ['spend' => 0, 'omset' => 0, 'purchase' => 0, 'vc' => 0, 'atc' => 0];
?>
  <div class="ket"><a href="<?= e($url(['akun' => null])) ?>">← Semua akun</a> · <b><?= e($ak['nama'] ?? '') ?></b> · <?= e($ak['act_id'] ?? '') ?></div>
  <div class="tabel"><table><thead><tr><th>Tanggal</th><th>Amount spent</th><th>Omset (shared items)</th><th>Purchase</th><th>CPR</th><th>ROAS</th><th>Content views</th><th>Add to cart</th><th>Diambil</th></tr></thead><tbody>
  <?php foreach ($DH as $r): foreach ($tt as $k => $_) $tt[$k] += $r[$k]; ?><tr><td><?= e($label($r['tanggal'])) ?></td><td><?= rp((float)$r['spend']) ?></td><td><?= rp((float)$r['omset']) ?></td><td><?= an((float)$r['purchase']) ?></td><td><?= rp(bagi((float)$r['spend'], (float)$r['purchase'])) ?></td><td><?= rs(bagi((float)$r['omset'], (float)$r['spend'])) ?></td><td><?= an((float)$r['vc']) ?></td><td><?= an((float)$r['atc']) ?></td><td class="st"><?= e($r['diambil_at'] ? date('d/m H:i', strtotime($r['diambil_at'])) : '') ?></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><td>Total</td><td><?= rp($tt['spend']) ?></td><td><?= rp($tt['omset']) ?></td><td><?= an($tt['purchase']) ?></td><td><?= rp(bagi($tt['spend'], $tt['purchase'])) ?></td><td><?= rs(bagi($tt['omset'], $tt['spend'])) ?></td><td><?= an($tt['vc']) ?></td><td><?= an($tt['atc']) ?></td><td></td></tr></tfoot></table></div>
  <?php else: ?>
  <div class="form" id="fAkun">
    <b style="margin-right:6px">Tambah / ubah akun</b>
    <input type="hidden" id="aId" value="0">
    <input id="aNama" placeholder="Nama akun (mis. PREPARE CPAS - HK 6)" style="min-width:260px">
    <input id="aAct" placeholder="ID akun iklan (act_123…)" style="min-width:200px">
    <input id="aGrup" placeholder="Grup (mis. Prepare CPAS)">
    <select id="aTok"><option value="prepare">Token Prepare / Skinlyfe</option><option value="glowingCantik">Token Glowing Cantik</option><option value="marketBeauty">Token Market Beauty</option></select>
    <label><input type="checkbox" id="aCpas"> hanya katalog</label><label><input type="checkbox" id="aAktif" checked> aktif</label>
    <button class="btn" id="aSimpan">Simpan</button><button class="btn abu" id="aBatal" type="button">Batal</button><span class="st" id="aPesan"></span>
  </div>
  <div class="tabel"><table><thead><tr><th>Akun</th><th>Grup</th><th>ID akun</th><th>Spend bulan ini</th><th>Omset</th><th>Purchase</th><th>ROAS</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($AK as $r): ?><tr class="<?= $r['aktif'] ? '' : 'mati' ?>" data-a='<?= e(json_encode(['id' => (int)$r['id'], 'nama' => $r['nama'], 'act_id' => $r['act_id'], 'grup' => $r['grup'], 'token' => $r['token'], 'cpas_only' => (int)$r['cpas_only'], 'aktif' => (int)$r['aktif']])) ?>'>
    <td><a class="akun" href="<?= e($url(['akun' => $r['id']])) ?>"><?= e($r['nama']) ?></a></td><td style="text-align:left"><?= e($r['grup']) ?></td><td style="text-align:left"><?= e($r['act_id']) ?></td>
    <td><?= rp((float)$r['s']) ?></td><td><?= rp((float)$r['o']) ?></td><td><?= an((float)$r['p']) ?></td><td><?= rs(bagi((float)$r['o'], (float)$r['s'])) ?></td>
    <td><?= $r['aktif'] ? '<span class="pl g1">aktif</span>' : '<span class="pl g3">nonaktif</span>' ?></td>
    <td><button class="btn abu ubah" type="button">Ubah</button> <button class="btn abu tarik" type="button">Tarik data bulan ini</button></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
<?php endif; ?>
</div>
<script>
var CSRF = <?= json_encode($csrf) ?>, MERK = <?= json_encode($merk) ?>;
// [data-live] perbarui otomatis saat ada isian baru dari sheet / orang lain (tidak saat sedang mengetik)
(function () {
  var versi = null, sedangIsi = false;
  document.addEventListener('focusin', function (e) { if (e.target.matches('input,select,textarea')) sedangIsi = true; });
  document.addEventListener('focusout', function () { sedangIsi = false; });
  var y = sessionStorage.getItem('dataScroll'); if (y) { window.scrollTo(0, +y); sessionStorage.removeItem('dataScroll'); }
  setInterval(function () {
    if (document.hidden) return;
    fetch('data.php?ping=1&merk=' + MERK, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (versi === null) { versi = j.v; return; }
      if (j.v !== versi && !sedangIsi) { sessionStorage.setItem('dataScroll', window.scrollY); location.reload(); }
    }).catch(function () {});
  }, 4000);
})();
function kirim(data) { return fetch('data.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(data) }).then(function (r) { return r.json(); }); }
document.querySelectorAll('input.inp').forEach(function (el) {
  var awal = el.value;
  if (el.dataset.k !== 'catatan') el.addEventListener('input', function () { var d = el.value.replace(/\D/g, ''); el.value = d ? Number(d).toLocaleString('id-ID') : ''; });
  el.addEventListener('change', function () {
    var tr = el.closest('tr'), st = tr.querySelector('.st');
    st.className = 'st'; st.textContent = 'menyimpan…';
    kirim({ aksi: 'shopee', merek: MERK, tanggal: tr.dataset.t, kolom: el.dataset.k, nilai: el.value }).then(function (h) {
      if (h.ok) { st.className = 'st ok'; st.textContent = '✓ ' + h.oleh + ' · ' + h.jam; awal = el.value; }
      else { st.className = 'st salah'; st.textContent = h.pesan; el.value = awal; }
    }).catch(function () { st.className = 'st salah'; st.textContent = 'gagal, coba lagi'; });
  });
});
var f = document.getElementById('fAkun');
if (f) {
  var $ = function (i) { return document.getElementById(i); }, isiForm = function (a) { $('aId').value = a.id || 0; $('aNama').value = a.nama || ''; $('aAct').value = a.act_id || ''; $('aGrup').value = a.grup || ''; $('aTok').value = a.token || 'prepare'; $('aCpas').checked = !!a.cpas_only; $('aAktif').checked = a.aktif === undefined ? true : !!a.aktif; };
  document.querySelectorAll('button.ubah').forEach(function (b) { b.onclick = function () { isiForm(JSON.parse(b.closest('tr').dataset.a)); window.scrollTo(0, 0); }; });
  $('aBatal').onclick = function () { isiForm({}); $('aPesan').textContent = ''; };
  $('aSimpan').onclick = function () {
    $('aPesan').textContent = 'menyimpan…';
    kirim({ aksi: 'akun', id: +$('aId').value, merek: MERK, nama: $('aNama').value, act_id: $('aAct').value, grup: $('aGrup').value, token: $('aTok').value, cpas_only: $('aCpas').checked ? 1 : 0, aktif: $('aAktif').checked ? 1 : 0 })
      .then(function (h) { if (!h.ok) { $('aPesan').textContent = h.pesan; return; } var baru = !+$('aId').value; $('aPesan').textContent = baru ? 'Tersimpan, menarik data bulan ini…' : 'Tersimpan';
        if (baru) kirim({ aksi: 'tarik', id: h.id }).then(function (x) { alert(x.pesan); location.reload(); }); else location.reload(); });
  };
  document.querySelectorAll('button.tarik').forEach(function (b) { b.onclick = function () { b.textContent = 'menarik…'; b.disabled = true; kirim({ aksi: 'tarik', id: JSON.parse(b.closest('tr').dataset.a).id }).then(function (x) { alert(x.ok ? x.pesan : x.pesan); location.reload(); }); }; });
}
</script>
</body></html>
