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
