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
// isian dari tab AKUN di sheet
if (($in['jenis'] ?? '') === 'akun') {
    $q = $db->prepare("SELECT id FROM akun WHERE merek = ? AND sheet_gid = ?"); $q->execute([$mk, (int)($in['gid'] ?? 0)]); $aid = (int)$q->fetchColumn();
    if (!$aid) { echo json_encode(['ok' => false, 'pesan' => 'tab belum tersambung ke akun']); exit; }
    $up = $db->prepare("INSERT INTO meta_harian (akun_id, tanggal, spend, omset, purchase, vc, atc, manual) VALUES (?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE spend=VALUES(spend), omset=VALUES(omset), purchase=VALUES(purchase), vc=VALUES(vc), atc=VALUES(atc), manual=1");
    foreach ((array)($in['baris'] ?? []) as $b) {
        $tgl = (string)($b['tanggal'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) continue;
        $v = []; foreach (['spend', 'omset', 'purchase', 'vc', 'atc'] as $k) $v[$k] = ma_angka($b[$k] ?? '');
        if (!array_filter($v, function ($x) { return $x !== null; })) continue;
        $up->execute([$aid, $tgl, (int)round($v['spend'] ?? 0), (int)round($v['omset'] ?? 0), (int)round($v['purchase'] ?? 0), (int)round($v['vc'] ?? 0), (int)round($v['atc'] ?? 0)]);
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
    $log->execute([$oleh, $mk, $tgl, 'sheet', null, json_encode(['cost' => $c, 'omset' => $o, 'pesanan' => $p])]);
    $n++;
}
echo json_encode(['ok' => true, 'disimpan' => $n]);
