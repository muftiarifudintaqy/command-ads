<?php
/* [katalog-produk 19 Nov, Mufti] Breakdown PRODUK katalog dari Meta (insights level=ad, breakdowns=product_id, harian) untuk iklan yang
   produknya "Katalog (multi produk)" (tanpa kode produk, adset promosi product set) → tabel ins_ad_produk.
   Dipakai untuk memecah iklan katalog per produk di export Naming/Fix, Winning content, Konten per Kreator, filter produk.
   Iklan tanpa breakdown dari Meta tetap "Katalog (multi produk)". Total per akun tidak berubah (sisa breakdown tetap Katalog).
   Pakai: php sync-produk.php [dari=8 hari lalu] [sampai=hari ini] [--acc=ID,ID]   (CLI saja) */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__) . '/produk_lib.php';
require_once '/opt/montera-ads/lib.php';
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => strpos($a, '--') !== 0));
$dari = $args[0] ?? date('Y-m-d', strtotime('-8 day')); $sampai = $args[1] ?? date('Y-m-d');
$hanya = []; foreach ($argv as $a) if (strpos($a, '--acc=') === 0) $hanya = array_filter(explode(',', substr($a, 6)));
$say = function (string $s) { echo '[' . date('Y-m-d H:i:s') . "] $s\n"; };
$db = kr_db(); $P = pl_daftar(); $kat = pl_set_katalog($db); $peta = ma_peta_token();
// iklan berlabel Katalog per akun di rentang ini
$q = $db->prepare("SELECT x.act_id, x.ad_id, MAX(x.ad_name) a, MAX(x.adset_name) s, MAX(x.campaign_name) c, MAX(x.adset_id) sid FROM ins_ad x WHERE x.tanggal BETWEEN ? AND ? GROUP BY x.act_id, x.ad_id");
$q->execute([$dari, $sampai]);
$per = [];
foreach ($q->fetchAll() as $r) if (pl_produk((string)$r['c'], (string)$r['s'], (string)$r['a'], $P, isset($kat[(string)$r['sid']])) === PL_KATALOG) $per[$r['act_id']][] = (string)$r['ad_id'];
if ($hanya) $per = array_intersect_key($per, array_flip($hanya));
$say("mulai $dari..$sampai · " . array_sum(array_map('count', $per)) . ' iklan Katalog di ' . count($per) . ' akun');
$tot = ['baris' => 0, 'iklan' => 0, 'gagal' => 0];
$hapus = $db->prepare("DELETE FROM ins_ad_produk WHERE ad_id = ? AND tanggal BETWEEN ? AND ?");
$isi = $db->prepare("INSERT INTO ins_ad_produk (ad_id, tanggal, product_id, act_id, nama, produk, spend, purch, value, diambil_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE nama = VALUES(nama), produk = VALUES(produk), spend = VALUES(spend), purch = VALUES(purch), value = VALUES(value), diambil_at = NOW()");
foreach ($per as $act => $ids) {
    $tok = ma_token(ma_token_akun('act_' . $act, null, $peta) ?? '');
    if (!$tok) { $say("act_$act: token tidak ada, dilewati"); $tot['gagal']++; continue; }
    foreach (array_chunk($ids, 50) as $pot) {
        $rows = []; $ok = true;
        $url = "https://graph.facebook.com/v21.0/act_$act/insights?" . http_build_query(['level' => 'ad', 'breakdowns' => 'product_id', 'time_increment' => 1,
            'fields' => 'ad_id,spend,catalog_segment_value,catalog_segment_actions', 'time_range' => json_encode(['since' => $dari, 'until' => $sampai]),
            'action_attribution_windows' => json_encode(['7d_click', '1d_view']), 'filtering' => json_encode([['field' => 'ad.id', 'operator' => 'IN', 'value' => $pot]]), 'limit' => 500, 'access_token' => $tok]);
        while ($url) {
            $j = ma_graph($url);
            if (isset($j['error'])) { $say("act_$act: GAGAL " . ($j['error']['message'] ?? '')); $ok = false; break; }
            foreach ($j['data'] ?? [] as $r) $rows[] = $r;
            $url = $j['paging']['next'] ?? null;
        }
        if (!$ok) { $tot['gagal']++; continue; }
        // data 1 potongan iklan diganti utuh (hapus rentang lalu isi) dalam 1 transaksi
        $db->beginTransaction();
        foreach ($pot as $id) $hapus->execute([$id, $dari, $sampai]);
        foreach ($rows as $r) {
            $pid = (string)($r['product_id'] ?? ''); if ($pid === '') continue;
            [$kode, $nama] = array_pad(explode(',', $pid, 2), 2, '');
            $nama = trim($nama); $prod = pl_teks($nama, $P);
            $isi->execute([(string)$r['ad_id'], $r['date_start'], substr(trim($kode), 0, 40), $act, mb_substr($nama, 0, 255), $prod !== '' ? $prod : PL_KATALOG,
                (float)($r['spend'] ?? 0), (int)ma_aksi($r['catalog_segment_actions'] ?? [], ['omni_purchase', 'purchase']), ma_aksi($r['catalog_segment_value'] ?? [], ['omni_purchase', 'purchase'])]);
            $tot['baris']++;
        }
        $db->commit();
        $tot['iklan'] += count(array_unique(array_column($rows, 'ad_id')));
    }
}
$say("selesai: {$tot['iklan']} iklan ber-breakdown · {$tot['baris']} baris produk · gagal {$tot['gagal']}");
