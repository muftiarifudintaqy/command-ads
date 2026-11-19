<?php
/* [saldo-live 19 Nov, Mufti] Saldo iklan Shopee LIVE dari API (get_total_balance) + biaya iklan hari ini (get_all_cpc_ads_daily_performance hari ini)
   per toko, dipanggil dashboard saat dibuka / tombol refresh. Cache maks. 2 menit (TANPA cron). ?segar=1 = ambil ulang sekarang (min. jeda 10 dtk).
   API gagal → angka terakhir yang berhasil tetap dikirim dengan ok=false + jam terakhir berhasil (dashboard menulis "gagal update, terakhir HH:MM"). */
declare(strict_types=1);
require __DIR__ . '/auth.php';
ma_session_start();
$C = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($C['app_password'] ?? '') !== '' || !empty($C['login_users'])) {
    if (empty($_SESSION['ac_ok']) && ($ru = ma_remember_check($C)) !== null) { $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $ru; }
    if (empty($_SESSION['ac_ok'])) { http_response_code(401); echo json_encode(['ok' => false, 'pesan' => 'login']); exit; }
}
session_write_close();
date_default_timezone_set('Asia/Jakarta');

const SALDO_CACHE = '/var/lib/montera-status/shopee-saldo-live.json';
const SALDO_UMUR = 120;      // detik
const SALDO_JEDA = 10;       // ?segar=1 paling cepat tiap 10 detik
$TOKO = ['1037551931' => 'Prepare', '1218331278' => 'Skinlyfe'];

$lama = json_decode((string) @file_get_contents(SALDO_CACHE), true) ?: [];
$umur = isset($lama['dicek']) ? time() - (int) $lama['dicek'] : PHP_INT_MAX;
$segar = !empty($_GET['segar']);
if ($umur < ($segar ? SALDO_JEDA : SALDO_UMUR)) { echo json_encode($lama + ['dari_cache' => true, 'umur' => $umur], JSON_UNESCAPED_UNICODE); exit; }

$kunci = fopen(SALDO_CACHE . '.lock', 'c');
if (!$kunci || !flock($kunci, LOCK_EX | LOCK_NB)) {   // sedang diambil oleh pembuka lain → kirim yang ada
    echo json_encode($lama + ['dari_cache' => true, 'umur' => $umur, 'sedang' => true], JSON_UNESCAPED_UNICODE); exit;
}
define('VT', 1);
require '/opt/montera-status/lib_mp.php';
$hari = date('d-m-Y');
$out = ['dicek' => time(), 'toko' => []];
foreach ($TOKO as $shop => $nm) {
    $shop = (string) $shop;
    $t = ['nama' => $nm, 'ok' => false, 'galat' => ''];
    $prev = $lama['toko'][$shop] ?? [];
    try {
        $c = cfg('shopee', $shop);
        $b = shopee_multi($c, $shop, '/api/v2/ads/get_total_balance', ['b' => []], 1)['b'] ?? [];
        $d = shopee_multi($c, $shop, '/api/v2/ads/get_all_cpc_ads_daily_performance', ['d' => ['start_date' => $hari, 'end_date' => $hari]], 1)['d'] ?? [];
        if (isset($b['response']['total_balance'])) {
            $t['saldo'] = (float) $b['response']['total_balance'];
            $t['waktu'] = (int) ($b['response']['data_timestamp'] ?? time());
            $t['ok'] = true;
        } else $t['galat'] = 'saldo: ' . ($b['error'] ?? 'tidak terbaca') . (isset($b['message']) ? ' ' . $b['message'] : '');
        if (isset($d['response'])) { $t['biaya_hari_ini'] = round((float) ($d['response'][0]['expense'] ?? 0)); $t['biaya_waktu'] = time(); }
        else $t['galat'] .= ($t['galat'] ? ' · ' : '') . 'biaya hari ini: ' . ($d['error'] ?? 'tidak terbaca');
    } catch (Throwable $e) { $t['galat'] = 'API: ' . $e->getMessage(); }
    if (!$t['ok']) {   // angka terakhir yang berhasil, dengan jamnya
        foreach (['saldo', 'waktu'] as $k) if (isset($prev[$k])) $t[$k] = $prev[$k];
    }
    if (!isset($t['biaya_hari_ini']) && isset($prev['biaya_hari_ini']) && date('Y-m-d', (int) ($prev['biaya_waktu'] ?? 0)) === date('Y-m-d')) {
        $t['biaya_hari_ini'] = $prev['biaya_hari_ini']; $t['biaya_waktu'] = $prev['biaya_waktu']; $t['biaya_lama'] = true;
    }
    $out['toko'][$shop] = $t;
}
$tmp = SALDO_CACHE . '.' . getmypid();
file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE)); @chmod($tmp, 0664); rename($tmp, SALDO_CACHE);
flock($kunci, LOCK_UN); fclose($kunci);
echo json_encode($out + ['dari_cache' => false, 'umur' => 0], JSON_UNESCAPED_UNICODE);
