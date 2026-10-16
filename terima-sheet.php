<?php
/* Menerima isian Shopee dari Google Sheet (onEdit) secara langsung. Dilindungi kunci. */
declare(strict_types=1);
require '/opt/montera-ads/lib.php';
header('Content-Type: application/json; charset=utf-8');
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$env = ma_env('/etc/montera-laporan.env');
if (($env['LAPORAN_KEY'] ?? '') === '' || !hash_equals($env['LAPORAN_KEY'], (string)($in['key'] ?? ''))) { http_response_code(403); echo '{"ok":false}'; exit; }
$mk = ($in['merek'] ?? '') === 'skinlyfe' ? 'skinlyfe' : 'prepare';
$db = ma_db(); $n = 0;
// tab akun dihapus di sheet → akun ikut dihapus di sistem (hanya file bulan berjalan, maksimal 3 sekaligus)
if (($in['jenis'] ?? '') === 'tab_dihapus') {
    $BUL = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    if (($in['bulan_file'] ?? '') !== $BUL[(int)date('n')] . ' ' . date('Y')) { echo json_encode(['ok' => true, 'dihapus' => 0, 'pesan' => 'bukan file bulan ini']); exit; }
    $gid = []; $nm = [];
    foreach ((array)($in['tab'] ?? []) as $x) { $gid[] = (int)($x['gid'] ?? 0); $nm[] = (string)($x['nama'] ?? ''); }
    if (count($gid) < 3) { echo json_encode(['ok' => false, 'pesan' => 'daftar tab tidak wajar']); exit; }
    $q = $db->prepare("SELECT * FROM akun WHERE merek = ? AND sheet_gid IS NOT NULL"); $q->execute([$mk]);
    $hilang = array_values(array_filter($q->fetchAll(), function ($a) use ($gid, $nm) { return !in_array((int)$a['sheet_gid'], $gid, true) && !in_array($a['nama'], $nm, true); }));
    if (count($hilang) > 3) { echo json_encode(['ok' => false, 'pesan' => count($hilang) . ' akun hilang sekaligus, tidak dihapus (pengaman)']); exit; }
    foreach ($hilang as $a) {
        $db->prepare("DELETE FROM meta_harian WHERE akun_id = ?")->execute([$a['id']]);
        $db->prepare("DELETE FROM akun WHERE id = ?")->execute([$a['id']]);
        $db->prepare("INSERT INTO log_ubah (pengguna, merek, tanggal, kolom, lama, baru) VALUES ('sheet',?,CURDATE(),'hapus_akun',?,NULL)")->execute([$mk, substr($a['nama'] . ' ' . $a['act_id'], 0, 40)]);
    }
    echo json_encode(['ok' => true, 'dihapus' => count($hilang)]); exit;
}
// isian dari tab AKUN di sheet
if (($in['jenis'] ?? '') === 'akun') {
    $q = $db->prepare("SELECT id FROM akun WHERE merek = ? AND (sheet_gid = ? OR nama = ?) ORDER BY (sheet_gid = ?) DESC LIMIT 1"); $q->execute([$mk, (int)($in['gid'] ?? 0), (string)($in['nama_tab'] ?? ''), (int)($in['gid'] ?? 0)]); $aid = (int)$q->fetchColumn();
    if (!$aid) { echo json_encode(['ok' => false, 'pesan' => 'tab belum tersambung ke akun']); exit; }
    $up = $db->prepare("INSERT INTO meta_harian (akun_id, tanggal, spend, omset, purchase, vc, atc, manual) VALUES (?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE spend=VALUES(spend), omset=VALUES(omset), purchase=VALUES(purchase), vc=VALUES(vc), atc=VALUES(atc), manual=1");
    foreach ((array)($in['baris'] ?? []) as $b) {
        $tgl = (string)($b['tanggal'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) continue;
        $v = []; foreach (['spend', 'omset', 'purchase', 'vc', 'atc'] as $k) $v[$k] = ma_angka($b[$k] ?? '');
        if (!array_filter($v, function ($x) { return $x !== null; })) continue;
        $bulat = function ($x) { return $x === null ? null : (int)round($x); };
        $up->execute([$aid, $tgl, $bulat($v['spend']), $bulat($v['omset']), $bulat($v['purchase']), $bulat($v['vc']), $bulat($v['atc'])]);
        $n++;
    }
    echo json_encode(['ok' => true, 'disimpan' => $n]); exit;
}
$ada = $db->prepare("SELECT 1 FROM shopee_harian WHERE merek = ? AND tanggal = ?");
$simpan = $db->prepare("INSERT INTO shopee_harian (merek, tanggal, cost, omset, pesanan, sumber, diubah_oleh, diubah_at) VALUES (?,?,?,?,?,'sheet',?,NOW())
    ON DUPLICATE KEY UPDATE cost = VALUES(cost), omset = VALUES(omset), pesanan = VALUES(pesanan), sumber = 'sheet', diubah_oleh = VALUES(diubah_oleh), diubah_at = NOW()");
$log = $db->prepare("INSERT INTO log_ubah (pengguna, merek, tanggal, kolom, lama, baru) VALUES (?,?,?,?,?,?)");
foreach ((array)($in['baris'] ?? []) as $b) {
    $tgl = (string)($b['tanggal'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) continue;
    $c = ma_angka($b['cost'] ?? ''); $o = ma_angka($b['omset'] ?? ''); $p = ma_angka($b['pesanan'] ?? '');
    $ada->execute([$mk, $tgl]);
    if ($c === null && $o === null && $p === null && !$ada->fetchColumn()) continue;
    $oleh = 'sheet' . (!empty($in['email']) ? ':' . substr((string)$in['email'], 0, 60) : '');
    $simpan->execute([$mk, $tgl, $c, $o, $p, $oleh]);
    $rr = str_replace(['.', ','], ['', '.'], trim((string)($b['roas'] ?? '')));
    if ($rr !== '' && is_numeric($rr)) $db->prepare("INSERT INTO shopee_harian (merek, tanggal, roas, sumber, diubah_oleh, diubah_at) VALUES (?,?,?,'sheet',?,NOW()) ON DUPLICATE KEY UPDATE roas = VALUES(roas)")->execute([$mk, $tgl, round((float)$rr, 2), $oleh]);
    $log->execute([$oleh, $mk, $tgl, 'sheet', null, json_encode(['cost' => $c, 'omset' => $o, 'pesanan' => $p])]);
    $n++;
}
echo json_encode(['ok' => true, 'disimpan' => $n]);
