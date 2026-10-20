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
            if (!in_array($kol, ['cost', 'omset', 'pesanan', 'roas', 'catatan'], true)) $jawab(['ok' => false, 'pesan' => 'Kolom tidak valid']);
            $raw = trim((string)($in['nilai'] ?? ''));
            $nilai = $kol === 'roas' ? ($raw === '' || !is_numeric(str_replace(',', '.', $raw)) ? null : round((float)str_replace(',', '.', $raw), 2)) : ($kol === 'catatan' ? ($raw === '' ? null : mb_substr($raw, 0, 250)) : ($raw === '' ? null : (int)preg_replace('/\D/', '', $raw)));
            // [sheet-patokan] angka ditulis ke sheet dulu (doPost, hanya kolom ini); gagal = tidak disimpan, error ditampilkan; sukses = baca ulang dari sheet
            $dariSheet = null;
            if ($kol !== 'catatan') {
                $j = ma_post_sheet_raw(['merek' => $mk, 'tanggal' => $tgl, $kol => $nilai]);
                if (empty($j['ok'])) $jawab(['ok' => false, 'pesan' => 'Gagal menulis ke sheet: ' . ($j['error'] ?? 'sheet tidak menjawab') . '. Angka tidak disimpan.']);
                $ls = ma_sheet_cache($mk, (int)substr($tgl, 0, 4), (int)substr($tgl, 5, 2), true);
                $dariSheet = $ls['data'][$tgl]['SHOPEE|' . $kol] ?? null;
            }
            $q = $db->prepare("SELECT $kol FROM shopee_harian WHERE merek = ? AND tanggal = ?"); $q->execute([$mk, $tgl]); $lama = $q->fetchColumn();
            $db->prepare("INSERT INTO shopee_harian (merek, tanggal, $kol, sumber, diubah_oleh, diubah_at) VALUES (?,?,?,'sistem',?,NOW())
                ON DUPLICATE KEY UPDATE $kol = VALUES($kol), sumber = 'sistem', diubah_oleh = VALUES(diubah_oleh), diubah_at = NOW()")->execute([$mk, $tgl, $nilai, $user]);
            $db->prepare("INSERT INTO log_ubah (pengguna, merek, tanggal, kolom, lama, baru) VALUES (?,?,?,?,?,?)")
               ->execute([$user, $mk, $tgl, $kol, $lama === false ? null : (string)$lama, $nilai === null ? null : (string)$nilai]);
            $jawab(['ok' => true, 'oleh' => $user, 'jam' => date('H:i'), 'sheet' => $kol === 'catatan' ? '' : 'masuk sheet ✓', 'nilai_sheet' => $dariSheet]);
        }
        if ($aksi === 'meta') {
            $aid = (int)($in['akun'] ?? 0); $tgl = (string)($in['tanggal'] ?? ''); $kol = (string)($in['kolom'] ?? '');
            if (!$aid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || $tgl > date('Y-m-d')) $jawab(['ok' => false, 'pesan' => 'Tanggal tidak valid']);
            if (!in_array($kol, ['spend', 'omset', 'purchase', 'vc', 'atc'], true)) $jawab(['ok' => false, 'pesan' => 'Kolom tidak valid']);
            $raw = trim((string)($in['nilai'] ?? '')); $nilai = $raw === '' ? null : (int)preg_replace('/\D/', '', $raw);
            // [sheet-patokan] baris tanggal ini dibaca dari tab sheet, kolom yang diubah diganti, lalu ditulis ke tab (doPost); gagal = tidak disimpan
            $q = $db->prepare("SELECT * FROM akun WHERE id = ?"); $q->execute([$aid]); $ak = $q->fetch();
            if (!$ak || !$ak['sheet_gid']) $jawab(['ok' => false, 'pesan' => 'Akun ini belum punya tab di sheet.']);
            $thT = (int)substr($tgl, 0, 4); $blT = (int)substr($tgl, 5, 2);
            $tb = ma_tab_banyak([$ak], $thT, $blT, true)[$aid];
            if ($tb['galat']) $jawab(['ok' => false, 'pesan' => 'Tab sheet tidak terbaca: ' . $tb['galat'] . '. Angka tidak disimpan.']);
            $baris = ($tb['data'][$tgl] ?? []) + ['spend' => null, 'omset' => null, 'purchase' => null, 'vc' => null, 'atc' => null]; $baris[$kol] = $nilai;
            $j = ma_post_sheet_raw(['jenis' => 'akun', 'merek' => $ak['merek'], 'gid' => (int)$ak['sheet_gid'], 'nama_tab' => $ak['nama'], 'tanggal' => $tgl] + $baris);
            if (empty($j['ok'])) $jawab(['ok' => false, 'pesan' => 'Gagal menulis ke sheet: ' . ($j['error'] ?? 'sheet tidak menjawab') . '. Angka tidak disimpan.']);
            $dariSheet = ma_tab_banyak([$ak], $thT, $blT, true)[$aid]['data'][$tgl][$kol] ?? null;
            $db->prepare("INSERT INTO meta_harian (akun_id, tanggal, $kol, manual) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE $kol = VALUES($kol), manual = 1")->execute([$aid, $tgl, $nilai]);
            $db->prepare("INSERT INTO log_ubah (pengguna, merek, tanggal, kolom, lama, baru) VALUES (?,?,?,?,?,?)")->execute([$user, 'akun#' . $aid, $tgl, $kol, null, (string)$nilai]);
            $db->prepare("DELETE FROM meta_harian WHERE akun_id = ? AND tanggal = ? AND spend IS NULL AND omset IS NULL AND purchase IS NULL AND vc IS NULL AND atc IS NULL")->execute([$aid, $tgl]);
            $jawab(['ok' => true, 'jam' => date('H:i'), 'sheet' => 'masuk sheet ✓', 'nilai_sheet' => $dariSheet]);
        }
        if ($aksi === 'hapus_akun') {
            $q = $db->prepare("SELECT * FROM akun WHERE id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $a = $q->fetch();
            if (!$a) $jawab(['ok' => false, 'pesan' => 'Akun tidak ditemukan']);
            // [sheet-patokan] tab di sheet dihapus dulu (doPost langsung); gagal = akun tidak dihapus
            if (!empty($a['sheet_gid'])) { $j = ma_post_sheet_raw(['jenis' => 'hapus_tab', 'merek' => $a['merek'], 'gid' => (int)$a['sheet_gid'], 'nama_lama' => $a['nama']]);
                if (empty($j['ok'])) $jawab(['ok' => false, 'pesan' => 'Gagal menghapus tab di sheet: ' . ($j['error'] ?? 'sheet tidak menjawab') . '. Akun tidak dihapus.']); }
            $db->prepare("DELETE FROM meta_harian WHERE akun_id = ?")->execute([$a['id']]);
            $db->prepare("DELETE FROM akun WHERE id = ?")->execute([$a['id']]);
            $db->prepare("INSERT INTO log_ubah (pengguna, merek, tanggal, kolom, lama, baru) VALUES (?,?,CURDATE(),'hapus_akun',?,NULL)")->execute([$user, $a['merek'], substr($a['nama'] . ' ' . $a['act_id'], 0, 40)]);
            $jawab(['ok' => true, 'sheet' => !empty($a['sheet_gid']) ? 'tab sheet dihapus ✓' : '']);
        }
        if ($aksi === 'tab_baru') {
            $mk = ($in['merek'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
            $q = $db->prepare("SELECT nama FROM akun WHERE merek = ? AND nama LIKE 'Akun Baru%'"); $q->execute([$mk]); $ada = array_column($q->fetchAll(), 'nama');
            $nama = 'Akun Baru'; for ($i = 2; in_array($nama, $ada, true); $i++) $nama = 'Akun Baru ' . $i;
            $db->prepare("INSERT INTO akun (merek, nama, act_id, token, metode, grup, aktif, urutan) VALUES (?,?,NULL,'prepare',?,NULL,1,99)")->execute([$mk, $nama, $mk === 'skinlyfe' ? 'skinlyfe' : 'prepare']);
            // [sheet-patokan] tab sheet dibuat langsung; gagal = akun dibatalkan & error ditampilkan
            $id = (int)$db->lastInsertId(); $bt = ma_buat_tab($id);
            if (empty($bt['ok'])) { $db->prepare("DELETE FROM akun WHERE id = ?")->execute([$id]); $jawab(['ok' => false, 'pesan' => 'Gagal membuat tab di sheet: ' . ($bt['pesan'] ?? '') . '. Akun tidak ditambahkan.']); }
            $jawab(['ok' => true, 'id' => $id, 'tab' => 'tab di sheet sudah dibuat ✓']);
        }
        if ($aksi === 'akun') {
            $id = (int)($in['id'] ?? 0); $mk = ($in['merek'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
            $nama = trim((string)($in['nama'] ?? '')); $act = trim((string)($in['act_id'] ?? ''));
            if (strpos($act, 'act_') !== 0 && ctype_digit($act)) $act = 'act_' . $act;
            if ($nama === '' || ($act !== '' && !preg_match('/^act_\d{6,25}$/', $act))) $jawab(['ok' => false, 'pesan' => 'Isi nama, dan ID akun iklan berupa angka (contoh act_1234567890).']);
            if ($act === '') $act = null;
            $tok = in_array($in['token'] ?? '', ['prepare', 'marketBeauty', 'glowingCantik', 'glowyGlow'], true) ? $in['token'] : 'prepare';
            $v = [$mk, $nama, $act, $tok, $mk === 'skinlyfe' ? 'skinlyfe' : 'prepare', empty($in['cpas_only']) ? 0 : 1, trim((string)($in['grup'] ?? '')) ?: null, empty($in['aktif']) ? 0 : 1, ((int)($in['sheet_gid'] ?? 0)) ?: null];
            if ($id) {
                $q = $db->prepare("SELECT nama, sheet_gid, isi_sheet FROM akun WHERE id = ?"); $q->execute([$id]); $lama = $q->fetch() ?: [];
                // [sheet-patokan] ganti nama tab di sheet dulu (doPost langsung); gagal = tidak disimpan
                if (!empty($lama['sheet_gid']) && $lama['nama'] !== $nama) { $j = ma_post_sheet_raw(['jenis' => 'ganti_nama', 'merek' => $mk, 'gid' => (int)$lama['sheet_gid'], 'nama_lama' => $lama['nama'], 'nama' => $nama, 'judul' => (int)$lama['isi_sheet']]);
                    if (empty($j['ok'])) $jawab(['ok' => false, 'pesan' => 'Gagal mengganti nama tab di sheet: ' . ($j['error'] ?? 'sheet tidak menjawab') . '. Belum disimpan.']); }
                $v[] = $id; $db->prepare("UPDATE akun SET merek=?, nama=?, act_id=?, token=?, metode=?, cpas_only=?, grup=?, aktif=?, sheet_gid=? WHERE id=?")->execute($v);
            }
            else { $db->prepare("INSERT INTO akun (merek, nama, act_id, token, metode, cpas_only, grup, aktif, sheet_gid, urutan) VALUES (?,?,?,?,?,?,?,?,?,99)")->execute($v); $id = (int)$db->lastInsertId();
                if (empty($v[8])) { $bt = ma_buat_tab($id);   // gid belum diisi → tab dibuat langsung; gagal = akun dibatalkan
                    if (empty($bt['ok'])) { $db->prepare("DELETE FROM akun WHERE id = ?")->execute([$id]); $jawab(['ok' => false, 'pesan' => 'Gagal membuat tab di sheet: ' . ($bt['pesan'] ?? '') . '. Akun tidak ditambahkan.']); } }
                $jawab(['ok' => true, 'id' => $id, 'tab' => empty($v[8]) ? 'Tab di sheet sudah dibuat ✓' : 'Tersambung ke tab sheet gid ' . $v[8]]); }
            $jawab(['ok' => true, 'id' => $id]);
        }
        if ($aksi === 'tarik') {
            set_time_limit(300);
            $q = $db->prepare("SELECT * FROM akun WHERE id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $a = $q->fetch();
            if (!$a) $jawab(['ok' => false, 'pesan' => 'Akun tidak ditemukan']);
            if (empty($a['act_id'])) $jawab(['ok' => false, 'pesan' => 'Isi ID akun iklan dulu di Pengaturan akun.']);
            $db->prepare("UPDATE akun SET status_tarik = 'Menarik data Meta bulan ini…' WHERE id = ?")->execute([$a['id']]);
            ma_latar('tarik', ['id' => (int)$a['id']]);
            $jawab(['ok' => true, 'pesan' => 'Data Meta bulan ini sedang ditarik. Tabel akan terisi sendiri dalam beberapa detik.']);
        }
        $jawab(['ok' => false, 'pesan' => 'Aksi tidak dikenal']);
    } catch (Throwable $e) { $jawab(['ok' => false, 'pesan' => $e->getMessage()]); }
}

// [data-live] tanda perubahan terakhir, dipakai halaman untuk memperbarui diri sendiri
if (isset($_GET['ping'])) {
    header('Content-Type: application/json');
    $mk = ($_GET['merk'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
    // [sheet-patokan] berubah kalau isi sheet (cache tab SHOPEE X CPAS bulan ini) berubah, ada isian baru, atau daftar akun berubah
    $q = $db->prepare("SELECT CONCAT(IFNULL((SELECT MAX(diubah_at) FROM shopee_harian WHERE merek = ?), ''), '|', (SELECT CONCAT(COUNT(*), '-', IFNULL(SUM(sheet_gid),0), '-', IFNULL(MAX(CHAR_LENGTH(IFNULL(status_tarik,''))),0)) FROM akun))");
    $q->execute([$mk]); $c = json_decode((string)@file_get_contents('/var/lib/montera-ads/cache/lap_' . $mk . '_' . date('Y') . '_' . date('n') . '.json'), true);
    echo json_encode(['v' => $q->fetchColumn() . '|' . md5(json_encode($c['data'] ?? null))]); exit;
}
/* ===================== DATA ===================== */
$merk = ($_GET['merk'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
$tab = in_array($_GET['tab'] ?? '', ['laporan', 'tahun', 'input', 'akun'], true) ? $_GET['tab'] : 'laporan';
$bln = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['bulan'] ?? '')) ? (string)$_GET['bulan'] : date('Y-m');
[$th, $bl] = array_map('intval', explode('-', $bln));
$awal = sprintf('%04d-%02d-01', $th, $bl); $jml = (int)date('t', strtotime($awal)); $akhir = sprintf('%04d-%02d-%02d', $th, $bl, $jml);
$NB = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$NH = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$label = function (string $t) use ($NB, $NH) { $u = strtotime($t); return $NH[(int)date('w', $u)] . ', ' . date('d', $u) . ' ' . $NB[(int)date('n', $u)]; };

$q = $db->prepare("SELECT * FROM shopee_harian WHERE merek = ? AND tanggal BETWEEN ? AND ?"); $q->execute([$merk, $awal, $akhir]);
$SH = []; foreach ($q->fetchAll() as $r) $SH[$r['tanggal']] = $r;
/* [sheet-patokan] angka Laporan, kartu, total & Input Shopee = PERSIS tab SHOPEE X CPAS di sheet (cache maks 5 menit, ?segar=1 baca ulang).
   Catatan & riwayat ubah tetap dari server. Bulan yang tidak punya file sheet: angka server, diberi tanda. */
$segar = isset($_GET['segar']);
$LS = ma_sheet_cache($merk, $th, $bl, $segar);
$sheetAda = is_array($LS['data']); $dataPer = $LS['waktu'] ? (int)$LS['waktu'] : null; $galatSheet = $LS['galat'];
if ($sheetAda) {
    foreach ($SH as $t => $r) foreach (['cost', 'omset', 'pesanan', 'roas'] as $k) $SH[$t][$k] = null;
    $ME = [];
    foreach ($LS['data'] as $t => $o) {
        $SH[$t] = array_merge($SH[$t] ?? [], ['cost' => $o['SHOPEE|cost'] ?? null, 'omset' => $o['SHOPEE|omset'] ?? null, 'pesanan' => $o['SHOPEE|pesanan'] ?? null, 'roas' => $o['SHOPEE|roas'] ?? null]);
        $ME[$t] = ['s' => $o['CPAS M|cost'] ?? null, 'o' => $o['CPAS M|omset'] ?? null, 'p' => $o['CPAS M|purchases with shared items'] ?? $o['CPAS M|pesanan'] ?? null, 'a' => $o['CPAS M|adds to cart with shared items'] ?? null];
    }
} else {
    $q = $db->prepare("SELECT m.tanggal, SUM(m.spend) s, SUM(m.omset) o, SUM(m.purchase) p, SUM(m.atc) a FROM meta_harian m JOIN akun k ON k.id = m.akun_id
        WHERE k.merek = ? AND m.tanggal BETWEEN ? AND ? GROUP BY m.tanggal"); $q->execute([$merk, $awal, $akhir]);
    $ME = []; foreach ($q->fetchAll() as $r) $ME[$r['tanggal']] = $r;
}

$HARI = []; $TOT = ['sc' => 0, 'so' => 0, 'sp' => 0, 'mc' => 0, 'mo' => 0, 'mp' => 0, 'ma' => 0, 'ca' => 0, 'n' => 0];
for ($d = 1; $d <= $jml; $d++) {
    $t = sprintf('%04d-%02d-%02d', $th, $bl, $d); $s = $SH[$t] ?? []; $m = $ME[$t] ?? [];
    $r = ['t' => $t, 'sc' => (float)($s['cost'] ?? 0), 'so' => (float)($s['omset'] ?? 0), 'sp' => (float)($s['pesanan'] ?? 0), 'sr' => (isset($s['roas']) && $s['roas'] !== null) ? (float)$s['roas'] : null,
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
$q = $db->prepare("SELECT id, nama, aktif FROM akun WHERE merek = ? ORDER BY aktif DESC, urutan, id"); $q->execute([$merk]); $AKB = $q->fetchAll(); $akunAktif = (int)($_GET['akun'] ?? 0);
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
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* [sebulan] tanggal tanpa data & status isian */
.kosong{color:#cbd5e1}.baris-kosong td{color:#94a3b8}.baris-kosong td:first-child{color:#94a3b8!important;font-weight:500}
.lenc-w{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:#fef3c7;color:#b45309;font-size:10.5px;font-weight:600;vertical-align:1px}
.sd{display:inline-block;margin-left:8px;padding:2px 10px;border-radius:999px;background:color-mix(in srgb,var(--w) 10%,#fff);color:#334155}
/* [muat-layar] */
*:has(> table.lap){max-height:none!important;overflow-x:hidden!important}
table.lap th{white-space:normal!important;max-width:118px;line-height:1.25;vertical-align:bottom}
table.lap td{padding-left:9px!important;padding-right:9px!important}
.mode-report .atas,.mode-report .lembar,.mode-report #bReport,.mode-report #bSalin{display:none!important}
.mode-report{background:#fff!important}.mode-report .isi{max-width:none!important;margin:0!important;padding:14px 18px!important;animation:none!important}
.mode-report .tabel{overflow:visible!important;max-height:none!important}.mode-report tr.baris-kosong{display:none}
/* [warna-romi] sama dengan sheet: <2,5 merah, 2,5-2,99 hijau, >=3 biru */
.rw{display:inline-block;min-width:46px;padding:2px 8px;border-radius:7px;font-weight:700;text-align:center}
.rw-m{background:#fde2e2;color:#b91c1c}.rw-h{background:#dcf5ea;color:#047857}.rw-b{background:#dbeafe;color:#1d4ed8}
/* [pas-semua] */
table.lap thead tr:first-child>*:nth-child(3),table.lap thead tr:first-child>*:nth-child(4),
table.lap thead tr:last-child>*:nth-child(8),table.lap thead tr:last-child>*:nth-child(14),
table.lap tbody tr>*:nth-child(8),table.lap tbody tr>*:nth-child(14),
table.lap tfoot tr>*:nth-child(8),table.lap tfoot tr>*:nth-child(14){border-left:10px solid #3b82f6!important}
table.lap td,table.lap2 td{padding-top:4px!important;padding-bottom:4px!important}
table.lap2 input,table.lap2 .sel{padding-top:3px!important;padding-bottom:3px!important;min-height:0!important}
table.lap2 th{white-space:normal!important;max-width:118px;line-height:1.25;vertical-align:bottom}
/* [segar] tampilan modern */
body{font-family:'Inter',-apple-system,Segoe UI,Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased;
  background:radial-gradient(1100px 520px at 0% -10%,color-mix(in srgb,var(--w) 9%,transparent),transparent 70%),radial-gradient(900px 480px at 100% 0%,color-mix(in srgb,var(--w) 5%,transparent),transparent 70%),#f6f7fb;background-attachment:fixed}
.atas{background:rgba(255,255,255,.82)!important;backdrop-filter:saturate(180%) blur(14px);-webkit-backdrop-filter:saturate(180%) blur(14px);border-bottom:1px solid rgba(15,23,42,.06)!important}
.atas h1{font-weight:800;letter-spacing:-.02em}
.tg a,.tab a,.btn,.lembar a{transition:background .18s,color .18s,box-shadow .18s,transform .18s}
.btn{box-shadow:0 1px 2px rgba(15,23,42,.08)}.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px color-mix(in srgb,var(--w) 25%,transparent)}.btn.abu:hover{background:#e6eaf1;box-shadow:0 2px 8px rgba(15,23,42,.08)}
.k,.kt2,.lc-kt{transition:transform .2s ease,box-shadow .2s ease}.k:hover,.kt2:hover{transform:translateY(-2px);box-shadow:0 2px 4px rgba(16,24,40,.05),0 14px 30px rgba(16,24,40,.09)}
.k .b{letter-spacing:-.02em}
.tabel{border-radius:16px!important;box-shadow:0 1px 2px rgba(16,24,40,.04),0 12px 32px rgba(16,24,40,.06)!important}
th{letter-spacing:.01em}
.form{border:1px solid rgba(15,23,42,.06)}
input:focus,select:focus{outline:none;border-color:var(--w)!important;box-shadow:0 0 0 3px color-mix(in srgb,var(--w) 18%,transparent)}
.isi{animation:muncul .45s cubic-bezier(.2,.8,.2,1) both}
@keyframes muncul{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.lembar{background:rgba(255,255,255,.88)!important;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}
.alat{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin:2px 2px 12px}.alat-kanan{display:flex;gap:8px}
</style></head><body>
<div class="atas">
  <a class="kembali" href="index.php">← Dashboard</a><h1>Data Ads</h1>
  <div class="tg"><a href="<?= e('?' . http_build_query(['merk' => 'prepare', 'tab' => $tab, 'bulan' => $bln])) ?>" class="<?= $merk === 'prepare' ? 'on' : '' ?>">Prepare</a><a href="<?= e('?' . http_build_query(['merk' => 'skinlyfe', 'tab' => $tab, 'bulan' => $bln])) ?>" class="<?= $merk === 'skinlyfe' ? 'on' : '' ?>">Skinlyfe</a></div>
  <div class="tab"><a href="<?= e($url(['tab' => 'laporan'])) ?>" class="<?= $tab === 'laporan' ? 'on' : '' ?>">Laporan</a><a href="<?= e($url(['tab' => 'tahun'])) ?>" class="<?= $tab === 'tahun' ? 'on' : '' ?>">Tahunan</a><a href="<?= e($url(['tab' => 'input'])) ?>" class="<?= $tab === 'input' ? 'on' : '' ?>">Input Shopee</a><a href="<?= e($url(['tab' => 'akun', 'akun' => null])) ?>" class="<?= $tab === 'akun' ? 'on' : '' ?>">Akun CPAS</a></div>
  <form method="get"><input type="hidden" name="merk" value="<?= e($merk) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="month" name="bulan" value="<?= e($bln) ?>" onchange="this.form.submit()"></form>
  <span class="kanan">Masuk sebagai <b><?= e($user) ?></b> · <a href="laporan.php?merk=<?= e($merk) ?>&bulan=<?= e($bln) ?>">versi sheet</a></span>
  <span class="kanan" id="dataSheet" style="margin-left:10px"></span>
</div>
<div class="isi">
<?php if ($tab === 'laporan'):
  $isiS = array_values(array_filter($HARI, function ($r) { return $r['sc'] || $r['so']; }));
  $isiM = array_values(array_filter($HARI, function ($r) { return (bool)$r['mc']; }));
  $a = $isiS ? $isiS[count($isiS) - 1] : null; $b = count($isiS) > 1 ? $isiS[count($isiS) - 2] : null;
  $sdS = $isiS ? date('d', strtotime($a['t'])) . ' ' . substr($NB[$bl], 0, 3) : '–'; $sdM = $isiM ? date('d', strtotime($isiM[count($isiM) - 1]['t'])) . ' ' . substr($NB[$bl], 0, 3) : '–';
  $dl = function ($x, $y, $balik = false) { if ($x === null || !$y) return ''; $p = ($x - $y) / abs($y) * 100; $baik = $balik ? $p < 0 : $p >= 0; return '<span class="' . ($baik ? 'up' : 'dn') . '">' . ($p >= 0 ? '▲' : '▼') . ' ' . number_format(abs($p), 1, ',', '.') . '%</span>'; };
  $romi = function ($r) { return $r ? bagi($r['so'], $r['ca']) : null; };
  $avgS = bagi($TOT['so'], $TOT['sc']); $avgM = bagi($TOT['mo'], $TOT['mc']); $avgA = bagi($TOT['so'], $TOT['ca']);
  $pl = function (?float $v, ?float $rata) { if ($v === null || !$rata) return rs($v); $c = $v >= $rata ? 'g1' : ($v >= $rata * 0.85 ? 'g2' : 'g3'); return '<span class="pl ' . $c . '">' . rs($v) . '</span>'; };
?>
  <?php if ($a): ?>
  <?php $rk = '*Laporan ' . ucfirst($merk) . ' — ' . $label($a['t']) . "*\n"
      . 'Omset Shopee: ' . rp($a['so']) . "\n" . 'Pesanan: ' . an($a['sp']) . "\n" . 'ROAS Shopee: ' . rs(($a['sr'] ?? bagi($a['so'], $a['sc']))) . "\n"
      . 'Omset CPAS Meta: ' . rp($a['mo']) . ' · ROAS ' . rs(bagi($a['mo'], $a['mc'])) . "\n"
      . 'Daily Cost All: ' . rp($a['ca']) . "\n" . 'Daily CPR All: ' . rp(bagi($a['ca'], $a['sp'])) . "\n" . 'Daily Roas All / ROMI: ' . rs($romi($a)) . "\n\n"
      . '*Total ' . $NB[$bl] . ' ' . $th . "*\n" . 'Omset Shopee: ' . rp($TOT['so']) . "\n" . 'Pesanan: ' . an($TOT['sp']) . "\n"
      . 'Daily Cost All: ' . rp($TOT['ca']) . "\n" . 'Daily Roas All / ROMI: ' . rs($avgA); ?>
  <div class="alat"><div class="ket" style="margin:0">Laporan lengkap terakhir: <b><?= e($label($a['t'])) ?></b> · dibanding hari sebelumnya <span class="sd">Shopee terisi s/d <b><?= e($sdS) ?></b> · CPAS Meta s/d <b><?= e($sdM) ?></b></span></div>
    <div class="alat-kanan"><button class="btn abu" id="bSalin" type="button">📋 Salin ringkasan</button></div></div>
  <script>
  (function () {
    var RK = <?= json_encode($rk, JSON_UNESCAPED_UNICODE) ?>, NAMA = <?= json_encode('Laporan ' . ucfirst($merk) . ' ' . $NB[$bl] . ' ' . $th) ?>;
    // [muat-layar] tabel pas selebar layar + mode report satu layar penuh
    var tblL = Array.prototype.find.call(document.querySelectorAll('table'), function (t) { return /Daily Roas All/.test((t.tHead || t).textContent); }), tabelL = tblL && tblL.parentElement, isiL = tabelL && tabelL.parentElement;
    if (tblL) tblL.classList.add('lap');
    function pas() {
      if (!tblL) {
        tblL = Array.prototype.find.call(document.querySelectorAll('table'), function (t) { return /Daily Roas All/.test((t.tHead || t).textContent); });
        if (!tblL) return;
        tabelL = tblL.parentElement; isiL = tabelL.parentElement;
      }
      tblL.classList.add('lap');
      if (document.body.classList.contains('mode-report')) {
        tblL.style.zoom = 1; isiL.style.zoom = 1;
        var r = Math.min(window.innerWidth / isiL.scrollWidth, window.innerHeight / isiL.scrollHeight, 1);
        isiL.style.zoom = Math.max(0.4, r); return;
      }
      isiL.style.zoom = 1; tblL.style.zoom = 1; for (var el = isiL; el && el !== document.body; el = el.parentElement) { el.style.maxWidth = 'none'; el.style.marginLeft = el.style.marginRight = '0'; }
      var hA = (document.querySelector('.atas') || { offsetHeight: 64 }).offsetHeight, hB = (document.querySelector('.lembar') || { offsetHeight: 56 }).offsetHeight;
      var muatTinggi = (window.innerHeight - hA - hB - 12) / (isiL.scrollHeight + 8), muatLebar = tabelL.clientWidth / tblL.scrollWidth;
      isiL.style.zoom = Math.max(0.5, Math.min(1, muatTinggi, muatLebar));
      if (isiL.getBoundingClientRect().top < hA) window.scrollTo(0, 0);
    }
    window.addEventListener('resize', pas); window.addEventListener('load', pas); setTimeout(pas, 60); setTimeout(pas, 500);
    function keluarReport() { document.body.classList.remove('mode-report'); pas(); }
    var bR = document.getElementById('bReport'); if (bR) bR.onclick = function () {
      document.body.classList.add('mode-report'); window.scrollTo(0, 0);
      var fs = document.documentElement.requestFullscreen ? document.documentElement.requestFullscreen() : null;
      (fs || Promise.resolve()).catch(function () {}).then(function () { setTimeout(pas, 200); });
    };
    document.addEventListener('fullscreenchange', function () { if (!document.fullscreenElement) keluarReport(); else setTimeout(pas, 200); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.body.classList.contains('mode-report') && !document.fullscreenElement) keluarReport(); });
    document.getElementById('bSalin').onclick = function () {
      (navigator.clipboard ? navigator.clipboard.writeText(RK) : Promise.reject()).then(function () { kabar('Ringkasan sudah disalin. Tinggal tempel di WhatsApp.', 'Tersalin'); }, function () { kabar(RK, 'Salin teks ini'); });
    };
  })();
  </script>
  <div class="kartu">
    <div class="k"><div class="t">Omset Shopee</div><div class="b"><?= rp($a['so']) ?><?= $dl($a['so'], $b['so'] ?? null) ?></div><div class="s"><?= an($a['sp']) ?> pesanan · ROAS <?= rs(($a['sr'] ?? bagi($a['so'], $a['sc']))) ?></div></div>
    <div class="k"><div class="t">Omset CPAS Meta</div><div class="b"><?= rp($a['mo']) ?><?= $dl($a['mo'], $b['mo'] ?? null) ?></div><div class="s"><?= an($a['mp']) ?> purchase · ROAS <?= rs(bagi($a['mo'], $a['mc'])) ?></div></div>
    <div class="k"><div class="t">Daily Cost All</div><div class="b"><?= rp($a['ca']) ?><?= $dl($a['ca'], $b['ca'] ?? null, true) ?></div><div class="s">Shopee <?= rp($a['sc']) ?> · Meta <?= rp($a['mc']) ?></div></div>
    <div class="k"><div class="t">Daily Roas All / ROMI</div><div class="b"><?= rs($romi($a)) ?><?= $dl($romi($a), $romi($b)) ?></div><div class="s">Daily CPR All <?= rp(bagi($a['ca'], $a['sp'])) ?></div></div>
  </div>
  <div class="ket">Total <?= e($NB[$bl] . ' ' . $th) ?>: Omset Shopee <b><?= rp($TOT['so']) ?></b> · Omset Meta <b><?= rp($TOT['mo']) ?></b> · Cost <b><?= rp($TOT['ca']) ?></b> · Daily Roas All / ROMI <b><?= rs($avgA) ?></b> · <?= an($TOT['sp']) ?> pesanan · rata-rata omset Shopee/hari <b><?= rp(bagi($TOT['so'], (float)$TOT['n'])) ?></b></div>
  <?php else: ?><div class="ket">Belum ada data bulan ini.</div><?php endif; ?>
  <div class="tabel"><table>
    <thead><tr><th></th><th colspan="6">SHOPEE</th><th colspan="6">CPAS META</th><th colspan="3">Daily All Platform</th></tr>
    <tr><th>Tanggal</th><th>Cost</th><th>Presentase Shopee Ads</th><th>Omset</th><th>Pesanan</th><th>CPR</th><th>ROAS</th><th>Cost</th><th>Omset</th><th>Purchases with shared items</th><th>CPR</th><th>ROAS</th><th>Adds to cart with shared items</th><th>Daily Cost All</th><th>Daily CPR All</th><th>Daily Roas All / ROMI</th></tr></thead>
    <?php $romiW = function ($v) { if ($v === null) return '–'; $k = $v < 2.5 ? 'rw-m' : ($v < 3 ? 'rw-h' : 'rw-b'); return '<span class="rw ' . $k . '">' . rs($v) . '</span>'; }; ?>
    <tbody><?php foreach ($HARI as $r): $sh = ($r['sc'] || $r['so']); $mt = (bool)$r['mc']; $lewat = $r['t'] <= date('Y-m-d');
      $kos = function ($ok, $v) { return $ok ? $v : '<span class="kosong">—</span>'; }; ?>
    <tr class="<?= !$sh && !$mt ? 'baris-kosong' : '' ?>">
      <td><?= e($label($r['t'])) ?><?= ($mt && !$sh && $lewat) ? ' <span class="lenc-w">Shopee belum diisi</span>' : '' ?></td>
      <td><?= $kos($sh, rp($r['sc'])) ?></td><td><?= $kos($sh, ps(bagi($r['sc'], $r['ca']))) ?></td><td><?= $kos($sh, rp($r['so'])) ?></td><td><?= $kos($sh, an($r['sp'])) ?></td><td><?= $kos($sh, rp(bagi($r['sc'], $r['sp']))) ?></td><td><?= $kos($sh, rs($r['sr'] ?? bagi($r['so'], $r['sc']))) ?></td>
      <td><?= $kos($mt, rp($r['mc'])) ?></td><td><?= $kos($mt, rp($r['mo'])) ?></td><td><?= $kos($mt, an($r['mp'])) ?></td><td><?= $kos($mt, rp(bagi($r['mc'], $r['mp']))) ?></td><td><?= $kos($mt, rs(bagi($r['mo'], $r['mc']))) ?></td><td><?= $kos($mt, an($r['ma'])) ?></td>
      <td><?= $kos($sh || $mt, rp($r['ca'])) ?></td><td><?= $kos($sh, rp(bagi($r['ca'], $r['sp']))) ?></td><td><?= $kos($sh, $romiW(bagi($r['so'], $r['ca']))) ?></td>
    </tr><?php endforeach; ?></tbody>
    <?php if ($isi): ?><tfoot><tr><td><?= e($NB[$bl] . ' ' . $th) ?></td><td><?= rp($TOT['sc']) ?></td><td><?= ps(bagi($TOT['sc'], $TOT['ca'])) ?></td><td><?= rp($TOT['so']) ?></td><td><?= an($TOT['sp']) ?></td><td><?= rp(bagi($TOT['sc'], $TOT['sp'])) ?></td><td><?= rs($avgS) ?></td>
      <td><?= rp($TOT['mc']) ?></td><td><?= rp($TOT['mo']) ?></td><td><?= an($TOT['mp']) ?></td><td><?= rp(bagi($TOT['mc'], $TOT['mp'])) ?></td><td><?= rs($avgM) ?></td><td><?= an($TOT['ma']) ?></td>
      <td><?= rp($TOT['ca']) ?></td><td><?= rp(bagi($TOT['ca'], $TOT['sp'])) ?></td><td><?= rs($avgA) ?></td></tr></tfoot><?php endif; ?>
  </table></div>

<?php elseif ($tab === 'tahun'):
  $thn = (int)($_GET['tahun'] ?? date('Y')); if ($thn < 2020 || $thn > (int)date('Y')) $thn = (int)date('Y');
  $ambilTahun = function ($y) use ($db, $merk) {
    $o = []; for ($i = 1; $i <= 12; $i++) $o[$i] = ['sc' => 0, 'so' => 0, 'sp' => 0, 'mc' => 0, 'mo' => 0, 'mp' => 0];
    $q = $db->prepare("SELECT MONTH(tanggal) b, SUM(cost) sc, SUM(omset) so, SUM(pesanan) sp FROM shopee_harian WHERE merek = ? AND YEAR(tanggal) = ? GROUP BY b"); $q->execute([$merk, $y]);
    foreach ($q->fetchAll() as $r) { $o[(int)$r['b']]['sc'] = (float)$r['sc']; $o[(int)$r['b']]['so'] = (float)$r['so']; $o[(int)$r['b']]['sp'] = (float)$r['sp']; }
    $q = $db->prepare("SELECT MONTH(m.tanggal) b, SUM(m.spend) mc, SUM(m.omset) mo, SUM(m.purchase) mp FROM meta_harian m JOIN akun k ON k.id = m.akun_id WHERE k.merek = ? AND YEAR(m.tanggal) = ? GROUP BY b"); $q->execute([$merk, $y]);
    foreach ($q->fetchAll() as $r) { $o[(int)$r['b']]['mc'] = (float)$r['mc']; $o[(int)$r['b']]['mo'] = (float)$r['mo']; $o[(int)$r['b']]['mp'] = (float)$r['mp']; }
    // [sheet-patokan] bulan yang punya file sheet: angka = jumlah harian tab SHOPEE X CPAS; bulan tanpa file sheet tetap angka server
    global $segar, $bulanServer, $waktuTahun;
    $daftar = []; for ($i = 1; $i <= 12; $i++) if (sprintf('%04d-%02d', $y, $i) <= date('Y-m')) $daftar[] = [$y, $i];
    foreach (ma_sheet_banyak($merk, $daftar, $segar) as $k => $hari) {
        $i = (int)explode('-', $k)[1];
        if (!is_array($hari)) { if ($o[$i]['sc'] || $o[$i]['so'] || $o[$i]['mc']) $bulanServer[] = substr($GLOBALS['NB'][$i], 0, 3) . ' ' . $y; continue; }
        $o[$i] = ['sc' => 0, 'so' => 0, 'sp' => 0, 'mc' => 0, 'mo' => 0, 'mp' => 0];
        foreach ($hari as $x) foreach (['sc' => 'SHOPEE|cost', 'so' => 'SHOPEE|omset', 'sp' => 'SHOPEE|pesanan', 'mc' => 'CPAS M|cost', 'mo' => 'CPAS M|omset', 'mp' => 'CPAS M|purchases with shared items'] as $kk => $kol) $o[$i][$kk] += (float)($x[$kol] ?? ($kk === 'mp' ? ($x['CPAS M|pesanan'] ?? 0) : 0));
        $f = "/var/lib/montera-ads/cache/lap_{$merk}_{$y}_{$i}.json"; if (is_file($f)) $waktuTahun = min($waktuTahun ?? PHP_INT_MAX, (int)filemtime($f));
    }
    return $o;
  };
  $bulanServer = []; $waktuTahun = null;
  $Y = $ambilTahun($thn); $P = $ambilTahun($thn - 1); $sampai = $thn == (int)date('Y') ? (int)date('n') : 12;
  $jumlah = function ($arr) use ($sampai) { $t = ['sc' => 0, 'so' => 0, 'sp' => 0, 'mc' => 0, 'mo' => 0, 'mp' => 0]; for ($i = 1; $i <= $sampai; $i++) foreach ($t as $k => $_) $t[$k] += $arr[$i][$k]; return $t; };
  $tY = $jumlah($Y); $tP = $jumlah($P);
  $dlt = function ($x, $y) { if (!$y || $x === null) return '<span class="kosong">—</span>'; $p = ($x - $y) / abs($y) * 100; return '<span class="' . ($p >= 0 ? 'up' : 'dn') . '" style="margin:0">' . ($p >= 0 ? '▲' : '▼') . ' ' . number_format(abs($p), 1, ',', '.') . '%</span>'; };
  $romi = function ($r) { return bagi($r['so'], $r['sc'] + $r['mc']); };
  $per = $sampai < 12 ? 'Jan–' . substr($NB[$sampai], 0, 3) : 'setahun';
?>
  <div class="alat"><div class="ket" style="margin:0">Ringkasan <b><?= e(ucfirst($merk)) ?> <?= $thn ?></b> dibanding <b><?= $thn - 1 ?></b> (periode sama: <?= e($per) ?>)</div>
    <form method="get" class="alat-kanan"><input type="hidden" name="merk" value="<?= e($merk) ?>"><input type="hidden" name="tab" value="tahun">
      <select name="tahun" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--garis);border-radius:10px;font:inherit"><?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?><option value="<?= $y ?>" <?= $y === $thn ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></form></div>
  <div class="kartu">
    <div class="k"><div class="t">Omset Shopee <?= $thn ?></div><div class="b"><?= rp($tY['so']) ?></div><div class="s"><?= $thn - 1 ?>: <?= rp($tP['so']) ?> · <?= $dlt($tY['so'], $tP['so']) ?></div></div>
    <div class="k"><div class="t">Pesanan Shopee</div><div class="b"><?= an($tY['sp']) ?></div><div class="s"><?= $thn - 1 ?>: <?= an($tP['sp']) ?> · <?= $dlt($tY['sp'], $tP['sp']) ?></div></div>
    <div class="k"><div class="t">Daily Cost All</div><div class="b"><?= rp($tY['sc'] + $tY['mc']) ?></div><div class="s"><?= $thn - 1 ?>: <?= rp($tP['sc'] + $tP['mc']) ?> · <?= $dlt($tY['sc'] + $tY['mc'], $tP['sc'] + $tP['mc']) ?></div></div>
    <div class="k"><div class="t">Daily Roas All / ROMI</div><div class="b"><?= rs($romi($tY)) ?></div><div class="s"><?= $thn - 1 ?>: <?= rs($romi($tP)) ?> · <?= $dlt($romi($tY), $romi($tP)) ?></div></div>
  </div>
  <div class="tabel"><table>
    <thead><tr><th></th><th colspan="4">SHOPEE</th><th colspan="3">CPAS META</th><th colspan="2">Daily All Platform</th><th colspan="2">vs <?= $thn - 1 ?></th></tr>
    <tr><th>Bulan</th><th>Cost</th><th>Omset</th><th>Pesanan</th><th>ROAS</th><th>Cost</th><th>Omset</th><th>ROAS</th><th>Daily Cost All</th><th>Daily Roas All / ROMI</th><th>Omset Shopee <?= $thn - 1 ?></th><th>Perubahan</th></tr></thead>
    <tbody><?php for ($i = 1; $i <= 12; $i++): $r = $Y[$i]; $p = $P[$i]; $ada = $r['sc'] || $r['so'] || $r['mc']; $kos = function ($ok, $v) { return $ok ? $v : '<span class="kosong">—</span>'; }; ?>
    <tr class="<?= $ada ? '' : 'baris-kosong' ?>">
      <td><a class="akun" href="<?= e('?' . http_build_query(['merk' => $merk, 'tab' => 'laporan', 'bulan' => sprintf('%04d-%02d', $thn, $i)])) ?>"><?= e($NB[$i]) ?></a></td>
      <td><?= $kos($ada, rp($r['sc'])) ?></td><td><?= $kos($ada, rp($r['so'])) ?></td><td><?= $kos($ada, an($r['sp'])) ?></td><td><?= $kos($ada, rs(bagi($r['so'], $r['sc']))) ?></td>
      <td><?= $kos($ada, rp($r['mc'])) ?></td><td><?= $kos($ada, rp($r['mo'])) ?></td><td><?= $kos($ada, rs(bagi($r['mo'], $r['mc']))) ?></td>
      <td><?= $kos($ada, rp($r['sc'] + $r['mc'])) ?></td><td><?= $kos($ada, rs($romi($r))) ?></td>
      <td><?= $kos((bool)$p['so'], rp($p['so'])) ?></td><td><?= $ada ? $dlt($r['so'], $p['so']) : '<span class="kosong">—</span>' ?></td>
    </tr><?php endfor; ?></tbody>
    <tfoot><tr><td>Total <?= e($per) ?></td><td><?= rp($tY['sc']) ?></td><td><?= rp($tY['so']) ?></td><td><?= an($tY['sp']) ?></td><td><?= rs(bagi($tY['so'], $tY['sc'])) ?></td><td><?= rp($tY['mc']) ?></td><td><?= rp($tY['mo']) ?></td><td><?= rs(bagi($tY['mo'], $tY['mc'])) ?></td><td><?= rp($tY['sc'] + $tY['mc']) ?></td><td><?= rs($romi($tY)) ?></td><td><?= rp($tP['so']) ?></td><td><?= $dlt($tY['so'], $tP['so']) ?></td></tr></tfoot>
  </table></div>

<?php elseif ($tab === 'input'): ?>
  <div class="ket">Isi <b>Cost, Omset, Pesanan</b> dari Seller Center di kotak putih. Presentase Shopee Ads, CPR, dan ROAS dihitung otomatis seperti di sheet. Tersimpan otomatis dan ikut ke sheet.</div>
  <style>
  .lsh td{padding:3px 6px!important}.lsh .inp{border:1px solid #dfe3ea;border-radius:8px;padding:7px 9px;width:140px}.lsh .inp.cat{width:230px}
  .lsh td.h{color:#64748b;background:#fafbfd}.lsh .inp::placeholder{color:#cbd5e1}
  </style>
  <div class="tabel"><table class="lsh">
    <thead><tr><th></th><th colspan="6">SHOPEE</th><th colspan="2"></th></tr>
    <tr><th>Tanggal</th><th>Cost</th><th>Presentase Shopee Ads</th><th>Omset</th><th>Pesanan</th><th>CPR</th><th>ROAS</th><th>Catatan</th><th>Terakhir diubah</th></tr></thead>
    <tbody><?php foreach ($HARI as $t => $r): $s = $SH[$t] ?? []; $depan = $t > date('Y-m-d') ? 'disabled' : '';
      $nilai = function ($k) use ($s) { return isset($s[$k]) && $s[$k] !== null ? e(number_format((float)$s[$k], 0, ',', '.')) : ''; }; ?>
    <tr data-t="<?= e($t) ?>" data-mc="<?= (float)$r['mc'] ?>">
      <td><?= e($label($t)) ?></td>
      <td><input class="inp" data-k="cost" inputmode="numeric" placeholder="—" value="<?= $nilai('cost') ?>" <?= $depan ?>></td>
      <td class="h h-pct"></td>
      <td><input class="inp" data-k="omset" inputmode="numeric" placeholder="—" value="<?= $nilai('omset') ?>" <?= $depan ?>></td>
      <td><input class="inp" data-k="pesanan" inputmode="numeric" placeholder="—" value="<?= $nilai('pesanan') ?>" <?= $depan ?>></td>
      <td class="h h-cpr"></td><td><input class="inp" data-k="roas" inputmode="decimal" placeholder="—" style="width:84px" value="<?= isset($s['roas']) && $s['roas'] !== null ? e(number_format((float)$s['roas'], 2, ',', '')) : '' ?>" <?= $depan ?>></td>
      <td><input class="inp cat" data-k="catatan" placeholder="—" value="<?= e($s['catatan'] ?? '') ?>" <?= $depan ?>></td>
      <td class="st"><?= !empty($s['diubah_at']) ? (($s['sumber'] === 'sheet' ? '<span class="lenc">sheet</span> ' : e($s['diubah_oleh']) . ' · ') . e(date('d/m H:i', strtotime($s['diubah_at'])))) : '' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <script>
  (function () {
    function n(v) { v = String(v || '').replace(/\D/g, ''); return v === '' ? null : +v; }
    function rp(x) { return 'Rp' + Math.round(x).toLocaleString('id-ID'); }
    function hitung(tr) {
      var c = n(tr.querySelector('[data-k="cost"]').value), o = n(tr.querySelector('[data-k="omset"]').value), p = n(tr.querySelector('[data-k="pesanan"]').value), mc = +tr.dataset.mc || 0;
      tr.querySelector('.h-pct').textContent = c != null && (c + mc) ? (c / (c + mc) * 100).toFixed(2).replace('.', ',') + '%' : '';
      tr.querySelector('.h-cpr').textContent = c != null && p ? rp(c / p) : '';
      
    }
    document.querySelectorAll('table.lsh tbody tr').forEach(function (tr) { hitung(tr); tr.addEventListener('input', function () { hitung(tr); }); });
  })();
  </script>

<?php else:
  $akunId = (int)($_GET['akun'] ?? 0);
  $q = $db->prepare("SELECT k.* FROM akun k WHERE k.merek = ? ORDER BY k.aktif DESC, k.urutan, k.id"); $q->execute([$merk]); $AK = $q->fetchAll();
  if (!$akunId) {   // [sheet-patokan] spend/omset/purchase bulan ini per akun = jumlah tab akun di sheet
    $TBS = ma_tab_banyak($AK, $th, $bl, $segar); $galatTab = [];
    foreach ($AK as $n => $r) { $x = $TBS[$r['id']] ?? null; $AK[$n]['s'] = $AK[$n]['o'] = $AK[$n]['p'] = 0;
      if (!empty($x['galat'])) $galatTab[] = $r['nama'] . ': ' . $x['galat'];
      if ($x && $x['waktu']) $dataPer = min($dataPer ?? PHP_INT_MAX, (int)$x['waktu']);
      foreach ((array)($x['data'] ?? []) as $h) { $AK[$n]['s'] += (float)$h['spend']; $AK[$n]['o'] += (float)$h['omset']; $AK[$n]['p'] += (float)$h['purchase']; } }
    if ($galatTab) $galatSheet = implode('; ', $galatTab);
  }
  if ($akunId):
    $q = $db->prepare("SELECT * FROM akun WHERE id = ?"); $q->execute([$akunId]); $ak = $q->fetch() ?: ['nama' => '', 'act_id' => '', 'sheet_gid' => null];
    // [sheet-patokan] isi tabel akun = tab akun ini di sheet (cache maks 5 menit); akun tanpa tab: data server
    $DHm = []; $TB = !empty($ak['sheet_gid']) ? ma_tab_banyak([$ak + ['id' => $akunId]], $th, $bl, $segar)[$akunId] : null;
    if ($TB && is_array($TB['data'])) { $dataPer = $TB['waktu'] ? (int)$TB['waktu'] : null; $galatSheet = $TB['galat'];
      foreach ($TB['data'] as $tg => $x) if (array_filter($x, function ($v) { return $v !== null; })) $DHm[$tg] = $x; }
    else { $sheetAda = false; $galatSheet = $TB['galat'] ?? 'akun belum punya tab di sheet';
      $q = $db->prepare("SELECT * FROM meta_harian WHERE akun_id = ? AND tanggal BETWEEN ? AND ?"); $q->execute([$akunId, $awal, $akhir]);
      foreach ($q->fetchAll() as $x) $DHm[$x['tanggal']] = $x; }
    $tt = ['spend' => 0, 'omset' => 0, 'purchase' => 0, 'vc' => 0, 'atc' => 0]; $akhirIsi = null;
    foreach ($DHm as $tg => $x) { foreach ($tt as $k => $_) $tt[$k] += (float)$x[$k]; if ((float)$x['spend'] > 0 && ($akhirIsi === null || $tg > $akhirIsi)) $akhirIsi = $tg; }
    $kotak = function ($judul, $sub, $sp, $om, $pu) { return '<div class="kt2"><div class="h">' . e($judul) . '<small>' . e($sub) . '</small></div><table>'
        . '<tr><td>Cost :</td><td>' . rp($sp) . '</td></tr><tr><td>Omset :</td><td>' . rp($om) . '</td></tr><tr><td>Pesanan :</td><td>' . an($pu) . '</td></tr>'
        . '<tr><td>CPR :</td><td>' . rp(bagi($sp, $pu)) . '</td></tr><tr><td>ROAS :</td><td>' . rs(bagi($om, $sp)) . '</td></tr></table></div>'; };
?>
  <style>
  table.lak th{white-space:normal;min-width:110px;font-size:11px;line-height:1.25}
  table.lak thead tr:first-child th{background:var(--w)!important;color:#fff;font-size:13px;letter-spacing:.06em;border-bottom:0}
  table.lak thead tr:nth-child(2) th{top:34px}
  input.sel{width:100%;min-width:105px;border:1px solid transparent;background:transparent;text-align:right;font:inherit;padding:4px 6px;border-radius:6px;font-variant-numeric:tabular-nums;color:inherit}
  input.sel:hover:not(:disabled){border-color:#e2e8f0;background:#fff}
  input.sel:focus{outline:none;border-color:var(--w);background:#fff;box-shadow:0 0 0 3px color-mix(in srgb,var(--w) 18%,transparent)}
  input.sel.simpan{background:#fffbeb}input.sel.oke{background:#dcfce7}input.sel.gagal{background:#fee2e2}
  .kotak2{display:flex;gap:14px;flex-wrap:wrap;margin-top:14px}
  .kt2{background:#fff;border-radius:14px;overflow:hidden;min-width:250px;box-shadow:0 1px 2px rgba(16,24,40,.05),0 6px 18px rgba(16,24,40,.06)}
  .kt2 .h{background:linear-gradient(135deg,var(--w),color-mix(in srgb,var(--w) 65%,#000));color:#fff;font-weight:700;padding:9px 14px;font-size:13px}.kt2 .h small{display:block;font-weight:500;opacity:.85;font-size:11px}
  .kt2 table{width:100%;font-size:13px}.kt2 td{position:static!important;padding:6px 14px;text-align:left;color:#64748b;border-bottom:1px dashed #eef0f4;font-weight:400!important;border-right:0!important}.kt2 td:last-child{text-align:right;color:#0f172a;font-weight:700!important}
  /* [sel-jelas] sel terlihat seperti kotak sheet */
  table.lak tbody td{padding:3px 5px!important}
  table.lak input.sel{border:1px solid #dfe3ea;background:#fff;border-radius:8px;padding:7px 9px;min-width:115px;box-shadow:inset 0 1px 1px rgba(15,23,42,.03)}
  table.lak input.sel:hover:not(:disabled){border-color:#b9c2d0;background:#fff}
  table.lak input.sel::placeholder{color:#cbd5e1}
  table.lak input.sel:disabled{background:#f8fafc;border-color:#f1f3f7;color:#cbd5e1}
  table.lak input.sel.oke{background:#dcfce7;border-color:#86efac}table.lak input.sel.gagal{background:#fee2e2;border-color:#fca5a5}table.lak input.sel.simpan{background:#fffbeb;border-color:#fcd34d}
  table.lak td.h-cpp,table.lak td.h-roas,table.lak td.h-cvc,table.lak td.h-catc{color:#64748b;background:#fafbfd}
  table.lak tbody tr:hover td{background:#f5f8ff}
  .toast{position:fixed;right:20px;bottom:74px;z-index:50;padding:10px 16px;border-radius:12px;font-size:13px;font-weight:600;color:#fff;background:#16a34a;box-shadow:0 10px 30px rgba(0,0,0,.18);opacity:0;transform:translateY(8px);transition:.2s;pointer-events:none}
  .toast.on{opacity:1;transform:none}.toast.salah{background:#dc2626}
  </style>
  <div class="toast" id="toast"></div>
  <div class="form" id="fSet" style="margin-bottom:10px">
    <b style="margin-right:4px">Pengaturan akun</b>
    <input id="sNama" value="<?= e($ak['nama']) ?>" title="Nama akun, juga nama tab di sheet" style="min-width:250px;font-weight:700">
    <input id="sAct" value="<?= e($ak['act_id'] ?? '') ?>" placeholder="ID akun iklan (act_123…)" style="min-width:210px">
    <select id="sTok"><?php foreach (['prepare' => 'Token Prepare / Skinlyfe', 'glowingCantik' => 'Token Glowing Cantik', 'marketBeauty' => 'Token Market Beauty', 'glowyGlow' => 'Token Glowy Glow'] as $k => $l): ?><option value="<?= $k ?>" <?= ($ak['token'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <input id="sGrup" value="<?= e($ak['grup'] ?? '') ?>" placeholder="Grup (opsional)" style="width:150px">
    <label><input type="checkbox" id="sCpas" <?= !empty($ak['cpas_only']) ? 'checked' : '' ?>> hanya katalog</label>
    <label><input type="checkbox" id="sAktif" <?= !isset($ak['aktif']) || $ak['aktif'] ? 'checked' : '' ?>> aktif</label>
    <button class="btn" id="sSimpan" type="button">Simpan</button><button class="btn abu" id="sHapus" type="button" style="color:#b91c1c">Hapus akun</button>
    <span class="st" id="sPesan"><?= empty($ak['act_id']) ? 'Isi ID akun iklan supaya data Meta terisi otomatis.' : e($ak['status_tarik'] ?? '') ?></span>
  </div>
  <script>
  (function () {
    var $ = function (i) { return document.getElementById(i); }, actLama = <?= json_encode((string)($ak['act_id'] ?? '')) ?>;
    if (/[?&]baru=1/.test(location.search)) { $('sNama').focus(); $('sNama').select(); }
    $('sHapus').onclick = function () {
      tanya({ judul: 'Hapus akun ini?', pesan: '"' + $('sNama').value + '" beserta tab-nya di sheet akan dihapus.\nData Meta bisa ditarik ulang kapan saja kalau akun ditambahkan lagi.', ok: 'Hapus', bahaya: true }).then(function (ya) {
        if (!ya) return;
        $('sPesan').textContent = 'menghapus…'; var isi = document.querySelector('.isi'); isi.style.transition = 'opacity .35s, transform .35s';
        kirim({ aksi: 'hapus_akun', id: <?= (int)$akunId ?> }).then(function (h) {
          if (!h.ok) { $('sPesan').textContent = h.pesan; return; }
          isi.style.opacity = 0; isi.style.transform = 'translateY(8px)';
          setTimeout(function () { location.href = location.pathname + '?merk=' + MERK + '&tab=akun'; }, 330);
        });
      });
    };
    $('sSimpan').onclick = function () {
      $('sPesan').textContent = 'menyimpan…';
      kirim({ aksi: 'akun', id: <?= (int)$akunId ?>, merek: MERK, nama: $('sNama').value, act_id: $('sAct').value, grup: $('sGrup').value, token: $('sTok').value,
              cpas_only: $('sCpas').checked ? 1 : 0, aktif: $('sAktif').checked ? 1 : 0, sheet_gid: <?= (int)($ak['sheet_gid'] ?? 0) ?> }).then(function (h) {
        if (!h.ok) { $('sPesan').textContent = h.pesan; return; }
        var act = $('sAct').value.trim();
        if (act && act !== actLama) { $('sPesan').textContent = 'Tersimpan, menarik data Meta bulan ini…'; kirim({ aksi: 'tarik', id: <?= (int)$akunId ?> }).then(function (x) { location.href = location.href.replace(/&baru=1/, ''); }); }
        else location.href = location.href.replace(/&baru=1/, '');
      });
    };
  })();
  </script>
  <div class="ket"><a href="<?= e($url(['akun' => null])) ?>">← Semua akun</a> · <?= e($ak['act_id']) ?> · <?= $ak['sheet_gid'] ? 'tersambung ke tab sheet' : '<b>belum tersambung ke tab sheet</b>' ?> · klik angka untuk mengubah, tersimpan otomatis dan ikut ke sheet</div>
  <div class="tabel"><table class="lak">
    <thead><tr><th colspan="10"><?= e(strtoupper($ak['nama'])) ?></th></tr>
    <tr><th>Tanggal</th><th>Amount Spent</th><th>Purchases conversion value for shared items only</th><th>Purchases with shared items</th><th>CPP</th><th>Purchase ROAS for shared item</th><th>Content views with shared items</th><th>COST per VC</th><th>Adds to cart with shared items</th><th>COST per ATC</th></tr></thead>
    <tbody>
    <?php foreach (array_keys($HARI) as $tg): $r = $DHm[$tg] ?? null; $depan = $tg > date('Y-m-d') ? 'disabled' : '';
      $sel = function ($k, $f) use ($r, $depan) { return '<td><input class="sel" data-k="' . $k . '" data-f="' . $f . '" placeholder="—" value="' . ($r && $r[$k] !== null ? e($f === 'rp' ? rp((float)$r[$k]) : an((float)$r[$k])) : '') . '" ' . $depan . '></td>'; }; ?>
    <tr data-t="<?= e($tg) ?>"><td><?= e($label($tg)) ?></td><?= $sel('spend', 'rp') . $sel('omset', 'rp') . $sel('purchase', 'an') ?><td class="h-cpp"></td><td class="h-roas"></td><?= $sel('vc', 'an') ?><td class="h-cvc"></td><?= $sel('atc', 'an') ?><td class="h-catc"></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td><?= e($NB[$bl] . ' ' . $th) ?></td><td><?= rp($tt['spend']) ?></td><td><?= rp($tt['omset']) ?></td><td><?= an($tt['purchase']) ?></td><td><?= rp(bagi($tt['spend'], $tt['purchase'])) ?></td><td><?= rs(bagi($tt['omset'], $tt['spend'])) ?></td><td><?= an($tt['vc']) ?></td><td><?= rp(bagi($tt['spend'], $tt['vc'])) ?></td><td><?= an($tt['atc']) ?></td><td><?= rp(bagi($tt['spend'], $tt['atc'])) ?></td></tr></tfoot>
  </table></div>
  <div class="kotak2"><?= $kotak('MONTHLY', $NB[$bl] . ' ' . $th, $tt['spend'], $tt['omset'], $tt['purchase']) ?><?php if ($akhirIsi): $x = $DHm[$akhirIsi]; echo $kotak('Daily Report CPAS', $label($akhirIsi), (float)$x['spend'], (float)$x['omset'], (float)$x['purchase']); endif; ?></div>
  <script>
  (function () {
    var AKUN = <?= (int)$akunId ?>, tt = null;
    function toast(m, ok) { var t = document.getElementById('toast'); t.textContent = m; t.className = 'toast on' + (ok ? '' : ' salah'); clearTimeout(tt); tt = setTimeout(function () { t.className = 'toast'; }, 2600); }
    function n(s) { s = String(s || '').replace(/\D/g, ''); return s === '' ? null : +s; }
    function rp(x) { return x == null || !isFinite(x) ? '' : 'Rp' + Math.round(x).toLocaleString('id-ID'); }
    function an(x) { return x == null ? '' : Math.round(x).toLocaleString('id-ID'); }
    function rs(x) { return x == null || !isFinite(x) ? '' : x.toFixed(2).replace('.', ','); }
    function hitung(tr) {
      var g = function (k) { var el = tr.querySelector('[data-k="' + k + '"]'); return el ? n(el.value) : null; }, s = g('spend'), o = g('omset'), p = g('purchase'), v = g('vc'), a = g('atc');
      tr.querySelector('.h-cpp').textContent = s != null && p ? rp(s / p) : ''; tr.querySelector('.h-roas').textContent = s && o != null ? rs(o / s) : '';
      tr.querySelector('.h-cvc').textContent = s != null && v ? rp(s / v) : ''; tr.querySelector('.h-catc').textContent = s != null && a ? rp(s / a) : '';
    }
    document.querySelectorAll('table.lak tbody tr').forEach(hitung);
    document.querySelectorAll('input.sel').forEach(function (el) {
      var awal = el.value;
      el.addEventListener('focus', function () { var x = n(el.value); el.value = x == null ? '' : String(x); el.select(); });
      el.addEventListener('blur', function () { var x = n(el.value); el.value = el.dataset.f === 'rp' ? rp(x) : an(x); });
      el.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); var tr = el.closest('tr').nextElementSibling, nx = tr && tr.querySelector('[data-k="' + el.dataset.k + '"]'); el.blur(); if (nx && !nx.disabled) nx.focus(); } });
      el.addEventListener('input', function () { hitung(el.closest('tr')); });
      el.addEventListener('change', function () {
        var tr = el.closest('tr'), x = n(el.value); el.classList.add('simpan');
        kirim({ aksi: 'meta', akun: AKUN, tanggal: tr.dataset.t, kolom: el.dataset.k, nilai: x == null ? '' : x }).then(function (h) {
          el.classList.remove('simpan'); el.classList.add(h.ok ? 'oke' : 'gagal'); el.title = h.ok ? 'Tersimpan ' + h.jam + ' · ' + h.sheet : h.pesan; toast(h.ok ? '✓ Tersimpan ' + h.jam + ' · ' + h.sheet : '✗ ' + h.pesan, h.ok);
          if (h.ok && h.nilai_sheet !== undefined) el.value = h.nilai_sheet === null ? '' : (el.dataset.f === 'rp' ? rp(h.nilai_sheet) : an(h.nilai_sheet));   // [sheet-patokan] nilai dibaca ulang dari sheet
          setTimeout(function () { el.classList.remove('oke'); }, 1500); if (h.ok) awal = el.value; else { el.value = awal; } hitung(tr);
        });
      });
    });
  })();
  </script>
  <?php else: ?>
  <div class="form" id="fAkun">
    <b style="margin-right:6px">Tambah / ubah akun</b>
    <input type="hidden" id="aId" value="0">
    <input id="aNama" placeholder="Nama akun (mis. PREPARE CPAS - HK 6)" style="min-width:260px">
    <input id="aAct" placeholder="ID akun iklan (act_123…)" style="min-width:200px">
    <input id="aGrup" placeholder="Grup (mis. Prepare CPAS)"><input id="aGid" placeholder="gid tab di sheet (opsional)" style="width:190px">
    <select id="aTok"><option value="prepare">Token Prepare / Skinlyfe</option><option value="glowingCantik">Token Glowing Cantik</option><option value="marketBeauty">Token Market Beauty</option><option value="glowyGlow">Token Glowy Glow</option></select>
    <label><input type="checkbox" id="aCpas"> hanya katalog</label><label><input type="checkbox" id="aAktif" checked> aktif</label>
    <button class="btn" id="aSimpan">Simpan</button><button class="btn abu" id="aBatal" type="button">Batal</button><span class="st" id="aPesan"></span>
  </div>
  <script>if (/[?&]baru=1/.test(location.search)) { var n = document.getElementById('aNama'); n.focus(); n.closest('.form').style.boxShadow = '0 0 0 3px color-mix(in srgb,var(--w) 35%,transparent)'; }</script>
  <div class="tabel"><table><thead><tr><th>Akun</th><th>Grup</th><th>ID akun</th><th>Spend bulan ini</th><th>Omset</th><th>Purchase</th><th>ROAS</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($AK as $r): ?><tr class="<?= $r['aktif'] ? '' : 'mati' ?>" data-a='<?= e(json_encode(['id' => (int)$r['id'], 'nama' => $r['nama'], 'act_id' => $r['act_id'], 'grup' => $r['grup'], 'token' => $r['token'], 'cpas_only' => (int)$r['cpas_only'], 'aktif' => (int)$r['aktif'], 'sheet_gid' => $r['sheet_gid']])) ?>'>
    <td><a class="akun" href="<?= e($url(['akun' => $r['id']])) ?>"><?= e($r['nama']) ?></a></td><td style="text-align:left"><?= e($r['grup']) ?></td><td style="text-align:left"><?= e($r['act_id']) ?></td>
    <td><?= rp((float)$r['s']) ?></td><td><?= rp((float)$r['o']) ?></td><td><?= an((float)$r['p']) ?></td><td><?= rs(bagi((float)$r['o'], (float)$r['s'])) ?></td>
    <td><?= $r['aktif'] ? '<span class="pl g1">aktif</span>' : '<span class="pl g3">nonaktif</span>' ?></td>
    <td><button class="btn abu ubah" type="button">Ubah</button> <button class="btn abu tarik" type="button">Tarik data bulan ini</button></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
<?php endif; ?>
</div>
<!-- [lembar-tab] tab bawah seperti sheet -->
<style>
body{padding-bottom:60px}
.lembar{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;gap:4px;overflow-x:auto;padding:8px 12px;background:#f8fafc;border-top:1px solid var(--garis);scrollbar-width:thin}
.lembar a{flex:0 0 auto;padding:7px 13px;border-radius:9px;font-size:12px;font-weight:600;color:#334155;text-decoration:none;background:#fff;border:1px solid var(--garis);white-space:nowrap}
.lembar a:hover{border-color:var(--w);color:var(--w)}
.lembar a.on{background:var(--w);border-color:var(--w);color:#fff}
.lembar a.mati{opacity:.45}.lembar .sep{flex:0 0 1px;background:var(--garis);margin:2px 4px}
</style>
<nav class="lembar" id="lembar">
  <a href="<?= e($url(['tab' => 'laporan', 'akun' => null])) ?>" class="<?= $tab === 'laporan' ? 'on' : '' ?>">📊 <?= e(strtoupper($merk)) ?> SHOPEE × CPAS</a>
  <a href="<?= e($url(['tab' => 'input', 'akun' => null])) ?>" class="<?= $tab === 'input' ? 'on' : '' ?>">✏️ Input Shopee</a>
  <span class="sep"></span>
  <?php foreach ($AKB as $x): ?><a href="<?= e($url(['tab' => 'akun', 'akun' => $x['id']])) ?>" class="<?= ($tab === 'akun' && $akunAktif === (int)$x['id']) ? 'on' : '' ?> <?= $x['aktif'] ? '' : 'mati' ?>"><?= e($x['nama']) ?></a><?php endforeach; ?>
  <a id="tabBaru" onclick="tabBaru()" title="Tambah tab akun baru" style="font-size:15px;padding:5px 13px">＋</a>
  <span class="sep"></span>
  <a href="<?= e($url(['tab' => 'akun', 'akun' => null])) ?>" class="<?= ($tab === 'akun' && !$akunAktif) ? 'on' : '' ?>">⚙️ Kelola akun</a>
</nav>
<style>.lembar a{cursor:pointer;user-select:none}
/* [garis-geser] penggeser tab: garis biru tipis, jelas hanya saat disentuh/digeser */
.lembar{scrollbar-width:thin;scrollbar-color:transparent transparent;padding-bottom:9px!important}
.lembar:hover,.lembar.geser{scrollbar-color:color-mix(in srgb,var(--w) 70%,transparent) transparent}
.lembar::-webkit-scrollbar{height:3px}
.lembar::-webkit-scrollbar-track{background:transparent}
.lembar::-webkit-scrollbar-thumb{background:transparent;border-radius:3px}
.lembar:hover::-webkit-scrollbar-thumb,.lembar.geser::-webkit-scrollbar-thumb{background:color-mix(in srgb,var(--w) 70%,transparent)}
</style>
<script>(function () {
  var l = document.getElementById('lembar'), t = null; if (!l) return;
  // roda mouse biasa menggeser tab ke kiri/kanan
  l.addEventListener('wheel', function (ev) { if (Math.abs(ev.deltaY) > Math.abs(ev.deltaX)) { l.scrollLeft += ev.deltaY; ev.preventDefault(); } }, { passive: false });
  // garis biru tampil sebentar saat digeser
  l.addEventListener('scroll', function () { l.classList.add('geser'); clearTimeout(t); t = setTimeout(function () { l.classList.remove('geser'); }, 900); });
})();</script>
<script>(function () {
  // tab bawah jadi tombol: tanpa pratinjau alamat dari browser
  document.querySelectorAll('#lembar a[href]').forEach(function (x) {
    var tujuan = x.getAttribute('href'); x.removeAttribute('href'); x.setAttribute('role', 'button'); x.tabIndex = 0;
    x.addEventListener('click', function (ev) { if (ev.metaKey || ev.ctrlKey) window.open(tujuan, '_blank'); else location.href = tujuan; });
    x.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') location.href = tujuan; });
  });
  var on = document.querySelector('#lembar a.on'); if (on) on.scrollIntoView({ inline: 'center', block: 'nearest' });
})();</script>
<!-- [laser-geser] penggeser jadi garis laser tipis warna merek, muncul hanya saat digeser -->
<style>
html,body,.tabel,.lembar{scrollbar-width:none!important}
html::-webkit-scrollbar,body::-webkit-scrollbar,.tabel::-webkit-scrollbar,.lembar::-webkit-scrollbar{display:none!important;width:0!important;height:0!important}
.laser{position:fixed;z-index:60;pointer-events:none;border-radius:2px;opacity:0;
  background:linear-gradient(90deg,var(--w),color-mix(in srgb,var(--w) 35%,#fff),var(--w));background-size:220% 100%;
  box-shadow:0 0 5px color-mix(in srgb,var(--w) 80%,transparent),0 0 12px color-mix(in srgb,var(--w) 40%,transparent);
  transition:opacity .35s ease;animation:laserJalan 1.4s linear infinite}
.laser.h{height:2px}.laser.v{width:2px;background:linear-gradient(180deg,var(--w),color-mix(in srgb,var(--w) 35%,#fff),var(--w));background-size:100% 220%}
.laser.on{opacity:.95}
@keyframes laserJalan{from{background-position:0 0}to{background-position:-220% -220%}}
</style>
<script>(function () {
  var halaman = document.scrollingElement || document.documentElement;
  function laser(box, sumbu) {
    var g = document.createElement('div'), t = null; g.className = 'laser ' + sumbu; document.body.appendChild(g);
    function ukur(tampil) {
      var r = box === halaman ? { left: 0, top: 0, width: innerWidth, height: innerHeight, right: innerWidth, bottom: innerHeight } : box.getBoundingClientRect();
      var total = sumbu === 'h' ? box.scrollWidth : box.scrollHeight, lihat = sumbu === 'h' ? box.clientWidth : box.clientHeight;
      if (total <= lihat + 2 || r.width === 0) { g.classList.remove('on'); return; }
      if (sumbu === 'h') {
        var w = Math.max(40, r.width * lihat / total);
        g.style.width = w + 'px'; g.style.left = (r.left + (r.width - w) * box.scrollLeft / (total - lihat)) + 'px'; g.style.top = (r.bottom - 3) + 'px';
      } else {
        var h = Math.max(40, r.height * lihat / total);
        g.style.height = h + 'px'; g.style.top = (r.top + (r.height - h) * box.scrollTop / (total - lihat)) + 'px'; g.style.left = (r.right - 3) + 'px';
      }
      if (tampil) { g.classList.add('on'); clearTimeout(t); t = setTimeout(function () { g.classList.remove('on'); }, 1000); }
    }
    (box === halaman ? window : box).addEventListener('scroll', function () { ukur(true); }, { passive: true });
    if (box !== halaman) { window.addEventListener('scroll', function () { ukur(false); }, { passive: true }); box.addEventListener('mouseenter', function () { ukur(true); }); }
    window.addEventListener('resize', function () { ukur(false); });
    ukur(false);
  }
  function pasang() {
    laser(halaman, 'v');
    document.querySelectorAll('.tabel').forEach(function (el) { laser(el, 'h'); laser(el, 'v'); });
    var l = document.getElementById('lembar'); if (l) laser(l, 'h');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', pasang); else pasang();
})();</script>
<div class="mdl" id="mdl" hidden><div class="mdl-bg"></div><div class="mdl-kotak" role="dialog" aria-modal="true">
  <div class="mdl-ikon" id="mdlIkon"></div><h3 id="mdlJudul"></h3><p id="mdlPesan"></p>
  <div class="mdl-tombol"><button class="btn abu" id="mdlBatal" type="button">Batal</button><button class="btn" id="mdlOk" type="button">OK</button></div></div></div>
<style>
.mdl{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center}.mdl[hidden]{display:none}
.mdl-bg{position:absolute;inset:0;background:rgba(15,23,42,.32);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);animation:mdlBg .2s ease both}
.mdl-kotak{position:relative;width:min(430px,92vw);background:#fff;border-radius:20px;padding:22px 22px 18px;box-shadow:0 30px 70px rgba(15,23,42,.28);animation:mdlIn .26s cubic-bezier(.2,.9,.3,1.15) both}
.mdl-ikon{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;background:color-mix(in srgb,var(--w) 12%,#fff);margin-bottom:12px}
.mdl.bahaya .mdl-ikon{background:#fee2e2}.mdl.bahaya #mdlOk{background:#dc2626}
.mdl h3{margin:0 0 6px;font-size:17px;font-weight:700;letter-spacing:-.01em}.mdl p{margin:0 0 18px;color:#475569;font-size:14px;line-height:1.55}
.mdl-tombol{display:flex;gap:8px;justify-content:flex-end}
.mdl.tutup .mdl-kotak{animation:mdlOut .16s ease forwards}.mdl.tutup .mdl-bg{animation:mdlBgOut .16s ease forwards}
@keyframes mdlIn{from{opacity:0;transform:translateY(12px) scale(.95)}to{opacity:1;transform:none}}@keyframes mdlOut{to{opacity:0;transform:translateY(8px) scale(.97)}}
@keyframes mdlBg{from{opacity:0}}@keyframes mdlBgOut{to{opacity:0}}
</style>
<script>
(function () {
  var m = document.getElementById('mdl');
  function buka(o) {
    return new Promise(function (res) {
      m.className = 'mdl' + (o.bahaya ? ' bahaya' : ''); m.hidden = false;
      document.getElementById('mdlIkon').textContent = o.ikon || (o.bahaya ? '🗑️' : '✅');
      document.getElementById('mdlJudul').textContent = o.judul || '';
      document.getElementById('mdlPesan').innerHTML = String(o.pesan || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>');
      var ok = document.getElementById('mdlOk'), bt = document.getElementById('mdlBatal');
      ok.textContent = o.ok || 'OK'; bt.style.display = o.batal === false ? 'none' : ''; bt.textContent = o.batal || 'Batal';
      function selesai(v) { m.classList.add('tutup'); document.removeEventListener('keydown', kunci); setTimeout(function () { m.hidden = true; m.classList.remove('tutup'); }, 160); res(v); }
      function kunci(e) { if (e.key === 'Escape') selesai(false); else if (e.key === 'Enter') { e.preventDefault(); selesai(true); } }
      ok.onclick = function () { selesai(true); }; bt.onclick = function () { selesai(false); }; m.querySelector('.mdl-bg').onclick = function () { selesai(false); };
      document.addEventListener('keydown', kunci); setTimeout(function () { ok.focus(); }, 60);
    });
  }
  window.tanya = buka;
  window.kabar = function (pesan, judul) { return buka({ judul: judul || 'Selesai', pesan: pesan, batal: false, ikon: '💬' }); };
})();
</script>
<script>
var CSRF = <?= json_encode($csrf) ?>, MERK = <?= json_encode($merk) ?>;
function tabBaru() {
  var b = document.getElementById('tabBaru'); if (b.dataset.jalan) return; b.dataset.jalan = 1; b.textContent = '…';
  kirim({ aksi: 'tab_baru', merek: MERK }).then(function (h) {
    if (!h.ok) { kabar(h.pesan, 'Belum bisa'); b.textContent = '＋'; delete b.dataset.jalan; return; }
    location.href = location.pathname + '?merk=' + MERK + '&tab=akun&bulan=<?= e($bln) ?>&akun=' + h.id + '&baru=1';
  }).catch(function () { b.textContent = '＋'; delete b.dataset.jalan; });
}
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
  if (el.dataset.k !== 'catatan' && el.dataset.k !== 'roas') el.addEventListener('input', function () { var d = el.value.replace(/\D/g, ''); el.value = d ? Number(d).toLocaleString('id-ID') : ''; });
  el.addEventListener('change', function () {
    var tr = el.closest('tr'), st = tr.querySelector('.st');
    st.className = 'st'; st.textContent = 'menyimpan…';
    kirim({ aksi: 'shopee', merek: MERK, tanggal: tr.dataset.t, kolom: el.dataset.k, nilai: el.value }).then(function (h) {
      if (h.ok) { if (h.nilai_sheet !== undefined && el.dataset.k !== 'catatan') el.value = h.nilai_sheet === null ? '' : (el.dataset.k === 'roas' ? Number(h.nilai_sheet).toFixed(2).replace('.', ',') : Math.round(h.nilai_sheet).toLocaleString('id-ID'));   // [sheet-patokan] nilai dibaca ulang dari sheet
        st.className = 'st ok'; st.textContent = '✓ ' + h.oleh + ' · ' + h.jam + (h.sheet ? ' · ' + h.sheet : ''); awal = el.value; }
      else { st.className = 'st salah'; st.textContent = h.pesan; el.value = awal; }
    }).catch(function () { st.className = 'st salah'; st.textContent = 'gagal, coba lagi'; });
  });
});
var f = document.getElementById('fAkun');
if (f) {
  var $ = function (i) { return document.getElementById(i); }, isiForm = function (a) { $('aId').value = a.id || 0; $('aNama').value = a.nama || ''; $('aAct').value = a.act_id || ''; $('aGrup').value = a.grup || ''; $('aTok').value = a.token || 'prepare'; $('aCpas').checked = !!a.cpas_only; $('aAktif').checked = a.aktif === undefined ? true : !!a.aktif; $('aGid').value = a.sheet_gid || ''; };
  document.querySelectorAll('button.ubah').forEach(function (b) { b.onclick = function () { isiForm(JSON.parse(b.closest('tr').dataset.a)); window.scrollTo(0, 0); }; });
  $('aBatal').onclick = function () { isiForm({}); $('aPesan').textContent = ''; };
  $('aSimpan').onclick = function () {
    $('aPesan').textContent = 'menyimpan…';
    kirim({ aksi: 'akun', id: +$('aId').value, merek: MERK, nama: $('aNama').value, act_id: $('aAct').value, grup: $('aGrup').value, token: $('aTok').value, cpas_only: $('aCpas').checked ? 1 : 0, aktif: $('aAktif').checked ? 1 : 0, sheet_gid: $('aGid').value })
      .then(function (h) { if (!h.ok) { $('aPesan').textContent = h.pesan; return; } var baru = !+$('aId').value; $('aPesan').textContent = baru ? 'Tersimpan, menarik data bulan ini…' : 'Tersimpan';
        if (baru) kirim({ aksi: 'tarik', id: h.id }).then(function (x) { kabar((h.tab ? h.tab + '\n' : '') + x.pesan + '\n\nJangan lupa: tambahkan tab "' + $('aNama').value + '" ke rumus total CPAS di tab SHOPEE X CPAS.').then(function () { location.href = location.pathname + '?merk=' + MERK + '&tab=akun&akun=' + h.id; }); }); else location.reload(); });
  };
  document.querySelectorAll('button.tarik').forEach(function (b) { b.onclick = function () { b.textContent = 'menarik…'; b.disabled = true; kirim({ aksi: 'tarik', id: JSON.parse(b.closest('tr').dataset.a).id }).then(function (x) { kabar(x.pesan, x.ok ? 'Data ditarik' : 'Belum bisa').then(function () { location.reload(); }); }); }; });
}
</script>
<script>
/* [pas-semua] semua tab selain Laporan: tabel utama pas satu layar */
(function () {
  var hariIni = '<?= date('Y-m-d') ?>';
  function pilih() {
    return Array.prototype.slice.call(document.querySelectorAll('table')).filter(function (t) { return !t.classList.contains('lap') && t.rows.length > 2; })
      .sort(function (a, b) { return b.offsetWidth * b.rows.length - a.offsetWidth * a.rows.length; })[0];
  }
  function pas() {
    if (document.querySelector('table.lap')) return;   // tab Laporan punya pengatur sendiri
    var tb = pilih(); if (!tb) return;
    var wd = tb.parentElement, isi = wd.parentElement;
    tb.classList.add('lap2'); wd.style.maxHeight = 'none'; wd.style.overflowX = 'hidden';
    isi.style.zoom = 1; tb.style.zoom = 1; for (var el = isi; el && el !== document.body; el = el.parentElement) { el.style.maxWidth = 'none'; el.style.marginLeft = el.style.marginRight = '0'; }
    var hA = (document.querySelector('.atas') || { offsetHeight: 64 }).offsetHeight, hB = (document.querySelector('.lembar') || { offsetHeight: 56 }).offsetHeight;
    var tinggi = tb.getBoundingClientRect().bottom - isi.getBoundingClientRect().top + 8;
    isi.style.zoom = Math.max(0.5, Math.min(1, (window.innerHeight - hA - hB - 12) / tinggi, wd.clientWidth / tb.scrollWidth));
  }
  window.addEventListener('load', pas); window.addEventListener('resize', pas); setTimeout(pas, 300);
})();
</script>
<?php
// [sheet-patokan] label sumber data + tombol muat ulang
$wkt = $tab === 'tahun' ? $waktuTahun : $dataPer;
$lbl = $sheetAda ? 'data sheet per <b>' . ($wkt ? date('H:i', (int)$wkt) : '–') . '</b>' : '<b style="color:#b45309">data server</b> (' . e($galatSheet && stripos((string)$galatSheet, 'tidak ditemukan') !== false ? 'file sheet bulan ini tidak ada' : (string)$galatSheet) . ')';
if ($sheetAda && $galatSheet) $lbl .= ' · <b style="color:#dc2626">sheet tidak terbaca, ditampilkan data terakhir</b>';
if ($tab === 'tahun' && !empty($bulanServer)) $lbl .= ' · bulan tanpa file sheet pakai data server: ' . e(implode(', ', $bulanServer));
// [21 Okt] "Meta per HH:MM · Shopee diisi s/d tanggal": Meta = putaran tarik terakhir yang menarik hari ini, Shopee = tanggal terakhir di sheet yang terisi
$tmS = json_decode((string)@file_get_contents('/var/lib/montera-ads/tarik-meta-status.json'), true) ?: [];
$shSd = null; foreach (($LS['data'] ?? []) as $t => $o) if ((float)($o['SHOPEE|cost'] ?? 0) || (float)($o['SHOPEE|omset'] ?? 0)) $shSd = max($shSd ?? $t, $t);
$lbl = 'Meta per <b>' . (!empty($tmS['meta_per']) ? date('H:i', strtotime($tmS['meta_per'])) . (date('Y-m-d', strtotime($tmS['meta_per'])) !== date('Y-m-d') ? ' (' . date('d/m', strtotime($tmS['meta_per'])) . ')' : '') : '–') . '</b>'
    . (!empty($tmS['meta_per_gagal']) ? ' <b style="color:#dc2626">(' . (int)$tmS['meta_per_gagal'] . ' akun gagal)</b>' : '')
    . ' · Shopee ' . ucfirst($merk) . ' diisi s/d <b>' . ($shSd ? date('d/m', strtotime($shSd)) : '–') . '</b> · ' . $lbl;
$lbl .= ' · <a href="' . e($url(['segar' => 1, 'akun' => $_GET['akun'] ?? null, 'tahun' => $_GET['tahun'] ?? null])) . '">⟳ Muat ulang</a>';
?>
<script>
document.getElementById('dataSheet').innerHTML = <?= json_encode($lbl, JSON_UNESCAPED_UNICODE) ?>;
if (/[?&]segar=1/.test(location.search)) history.replaceState(null, '', location.href.replace(/([?&])segar=1&?/, '$1').replace(/[?&]$/, ''));
</script>
</body></html>
