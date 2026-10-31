<?php
/* [31 Okt, Mufti] Masa transisi ads.skinlyfe.id → ads.prepareofficial.com. Cron 23:55 tiap hari:
   hitung request ke domain lama yang MASIH DILAYANI (API/POST, bukan redirect 301) dari /var/log/apache2/ads-domain-lama.log
   (+ .log.1 hasil rotasi), simpan per tanggal di /var/lib/montera-ads/domain-lama.json.
   Setelah 7 hari berturut-turut TANPA request yang dilayani → 1 WA ke Mufti: siap redirect penuh (TIDAK diubah otomatis).
   Request dari server ini sendiri (uji) tidak dihitung. Pakai: php cek-domain-lama.php [--tanpa-wa] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require '/opt/montera-ads/lib.php';
date_default_timezone_set('Asia/Jakarta');
const MULAI = '2026-10-31';
$F = '/var/lib/montera-ads/domain-lama.json'; $st = json_decode((string) @file_get_contents($F), true) ?: ['hari' => []];
$hari = [];
foreach (['/var/log/apache2/ads-domain-lama.log.1', '/var/log/apache2/ads-domain-lama.log'] as $f)
    foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) \S+ (\S+) (\S+) (\S+) (\d{3}) "(.*)"$/', $l, $m)) continue;
        [, $tgl, $ip, $met, $uri, $kode, $ua] = $m;
        if ($tgl < MULAI || $ip === '72.61.215.30' || $ip === '127.0.0.1' || $kode === '301') continue;
        $hari[$tgl]['n'] = ($hari[$tgl]['n'] ?? 0) + 1;
        $k = $met . ' ' . preg_replace('/\?.*/', '', $uri); $hari[$tgl]['jalur'][$k] = ($hari[$tgl]['jalur'][$k] ?? 0) + 1;
        $hari[$tgl]['ua'][substr($ua, 0, 60)] = 1;
    }
for ($t = strtotime(MULAI); $t <= strtotime('today'); $t += 86400) { $d = date('Y-m-d', $t);
    $st['hari'][$d] = ['n' => $hari[$d]['n'] ?? 0, 'jalur' => $hari[$d]['jalur'] ?? [], 'ua' => array_keys($hari[$d]['ua'] ?? [])]; }
ksort($st['hari']);
$kosong = 0; foreach (array_reverse($st['hari'], true) as $d => $x) { if ($x['n'] > 0) break; $kosong++; }
$st['hari_kosong_berturut'] = $kosong; $st['dicek'] = date('c');
$hariIni = $st['hari'][date('Y-m-d')] ?? ['n' => 0];
echo '[' . date('Y-m-d H:i:s') . "] domain lama hari ini: {$hariIni['n']} request dilayani (API/POST) · $kosong hari berturut-turut kosong\n";
if ($kosong >= 7 && empty($st['wa_siap']) && !in_array('--tanpa-wa', $argv, true)) {
    if (ma_wa_mufti('ads-domain-lama-siap', "✅ ads.skinlyfe.id: 7 hari berturut-turut tanpa request API/POST (sejak " . MULAI . "). Siap di-redirect penuh ke ads.prepareofficial.com — menunggu persetujuan Mufti, belum diubah.")) $st['wa_siap'] = date('c');
}
file_put_contents($F, json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
