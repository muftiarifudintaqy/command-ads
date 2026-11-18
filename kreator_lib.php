<?php
/* [kreator 18 Nov, Mufti/Adila] Klasifikasi konten iklan per kreator (SOP Content Performance Classification).
   - Data: montera_ads.ins_ad (level iklan, per hari, ditarik tools/sync-insights.php; TIDAK ada tarikan baru).
     spend = Amount Spent Meta (tanpa pajak); omset/purchase = katalog CPAS (catalog_segment_*), atribusi config (7d_click,1d_view).
   - Kreator per iklan, urutan: manual per iklan > manual per konten > manual per campaign > kode di nama iklan > "Belum ada kode".
   - Konten = nama konten asli (sama dengan sheet Naming di app.js namingOf): varian B2/B3, Copy/Salin, Shopee/VATC, SQ, (1), ID angka
     digabung; "selvia 01" ≠ "selvia 02". Tampilan bisa per konten (bawaan) atau per iklan (ad ID, = baris Ads Manager).
     Kode = huruf yang menempel di belakang nomor konten di AKHIR nama iklan: "<seri> <nomor><KODE>[ B2][ - Copy]",
     mis. "Stitch 04R", "FS 09L", "LF 002D". Huruf di tempat lain tidak dibaca (pola ketat).
   - Kategori (spent & Purchase ROAS periode & sumber yang sama):
       spent < 1 jt : ROAS < 3 TESTING · 3 - <8 OPPORTUNITY · >= 8 HIGH POTENTIAL
       spent >= 1 jt: ROAS < 2 NOT PERFORMING · 2 - <3 PERFORMING · >= 3: spent <= 5 jt WINNING, > 5 jt TOP WINNING */
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }

const KR_KATEGORI = ['TESTING', 'OPPORTUNITY', 'HIGH POTENTIAL', 'NOT PERFORMING', 'PERFORMING', 'WINNING', 'TOP WINNING'];
const KR_BELUM = 'Belum ada kode';
const KR_POLA = '/^([A-Za-z#@][A-Za-z0-9_.#@]*(?: [A-Za-z#@][A-Za-z0-9_.#@]*)*) (\d{1,3})([A-Za-z]{1,2})(?: B\d+)?(?:-\d+)?(?: - (?:Copy|Salin)(?: \d+)?)*\s*$/';

/** DB: produksi (lib.php ma_db). Uji CLI: KREATOR_UJI=1 → montera_ads_uji lewat socket root. */
function kr_db(): PDO {
    static $p = null; if ($p) return $p;
    if (PHP_SAPI === 'cli' && getenv('KREATOR_UJI') === '1') {
        $p = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=montera_ads_uji;charset=utf8mb4', 'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $p->exec("SET time_zone = '+07:00'");
        return $p;
    }
    if (!function_exists('ma_db')) require_once '/opt/montera-ads/lib.php';
    return $p = ma_db();
}

/** Kode kreator dari nama iklan (huruf besar), NULL kalau polanya tidak ada. */
function kr_kode(string $nama): ?string {
    return preg_match(KR_POLA, trim($nama), $m) ? strtoupper($m[3]) : null;
}

function kr_kategori(float $spend, float $roas): string {
    if ($spend < 1000000) return $roas >= 8 ? 'HIGH POTENTIAL' : ($roas >= 3 ? 'OPPORTUNITY' : 'TESTING');
    if ($roas < 2) return 'NOT PERFORMING';
    if ($roas < 3) return 'PERFORMING';
    return $spend > 5000000 ? 'TOP WINNING' : 'WINNING';
}

/** = app.js namingOf(n, true): nama konten dari nama iklan */
function kr_naming(string $n): string {
    $segs = array_values(array_filter(array_map('trim', preg_split('/\s+-\s+/', $n)), 'strlen'));
    $JUNK = '/^(copy(\s*\d+)?|salin(\s*\d+)?|salinan(\s*\d+)?|\d{5,}|b\d{1,2}|sq|square|\[square\]|\(\d+\)|done\b.*|ori\s*copy.*)$/i';
    $TAIL = '/^(tofu|mofu|bofu|shopee|vatc|cpas|catalog(ue)?|ig|fb|reels?|story|stories|feed|sq|square|new|baru|rev(isi)?|test|tes)$/i';
    while (count($segs) > 1 && (preg_match($JUNK, end($segs)) || preg_match($TAIL, end($segs)) || preg_match('/^[a-z]{1,4}$/', end($segs)))) array_pop($segs);
    $x = implode(' - ', $segs);
    for ($k = 0; $k < 3; $k++) $x = trim(preg_replace('/\s+/', ' ', preg_replace(
        ['/^sq[_\s-]+/i', '/\[\s*square\s*\]/i', '/\(\s*\d+\s*\)/', '/\b(shopee|vatc|catalog(ue)?|cpas)\b/i', '/[\s_-]+copy(\s*\d+)?$/i', '/[\s_-]+salin(an)?(\s*\d+)?$/i', '/[\s_-]+b\d{1,2}$/i', '/[\s_-]+ori$/i'],
        '', $x)));
    return $x !== '' ? $x : '(tanpa naming)';
}

/** = app.js namingOfEnt untuk iklan: nama iklan cuma nomor/kode ("12 - Copy") → segmen terakhir nama campaign kalau berakhiran kode itu */
function kr_konten(string $ad, string $cmp): string {
    $x = kr_naming($ad);
    if (preg_match('/^\d+[A-Z]?$/i', $x)) {
        $segs = array_values(array_filter(array_map('trim', preg_split('/\s+-\s+/', kr_naming($cmp))), 'strlen'));
        $y = (string) end($segs);
        if ($y !== '' && !preg_match('/^\d+[A-Z]?$/i', $y) && strpos($y, ',') === false && preg_match('/(^|[^0-9A-Za-z])' . preg_quote($x, '/') . '$/i', $y)) return $y;
    }
    return $x;
}
function kr_konten_kunci(string $konten): string { return strtolower(preg_replace('/[\s_.]+/', '', $konten)); }

/** [kode => ['id','kode','nama','aktif']] */
function kr_daftar(PDO $db): array {
    $o = [];
    foreach ($db->query("SELECT id, kode, nama, aktif FROM kreator ORDER BY nama") as $r) $o[strtoupper($r['kode'])] = $r;
    return $o;
}

/** manual: ['ad' => [kunci => row], 'nama' => [...], 'campaign' => [...]] (kreator_id NULL = dikembalikan ke otomatis, diabaikan) */
function kr_manual(PDO $db): array {
    $o = ['ad' => [], 'konten' => [], 'campaign' => []];
    foreach ($db->query("SELECT m.level, m.kunci, m.kreator_id, m.oleh, m.waktu, k.nama, k.kode FROM kreator_manual m JOIN kreator k ON k.id = m.kreator_id WHERE m.kreator_id IS NOT NULL") as $r)
        $o[$r['level']][$r['kunci']] = $r;
    return $o;
}

/** Kreator 1 iklan: ['nama','kode','sumber' (manual-iklan|manual-konten|manual-campaign|kode|tidak-dikenal|belum),'oleh','waktu'] */
function kr_tentukan(array $ad, array $daftar, array $manual): array {
    $kk = kr_konten_kunci(kr_konten((string)$ad['ad_name'], (string)($ad['campaign_name'] ?? '')));
    foreach ([['ad', (string)$ad['ad_id'], 'manual-iklan'], ['konten', $kk, 'manual-konten'], ['campaign', (string)$ad['campaign_id'], 'manual-campaign']] as [$lv, $k, $src])
        if (isset($manual[$lv][$k])) { $m = $manual[$lv][$k]; return ['nama' => $m['nama'], 'kode' => $m['kode'], 'sumber' => $src, 'oleh' => $m['oleh'], 'waktu' => $m['waktu']]; }
    $kode = kr_kode((string)$ad['ad_name']);
    if ($kode !== null && isset($daftar[$kode]) && (int)$daftar[$kode]['aktif'] === 1) return ['nama' => $daftar[$kode]['nama'], 'kode' => $kode, 'sumber' => 'kode', 'oleh' => null, 'waktu' => null];
    if ($kode !== null) return ['nama' => 'Kode tidak dikenal: ' . $kode, 'kode' => $kode, 'sumber' => 'tidak-dikenal', 'oleh' => null, 'waktu' => null];
    return ['nama' => KR_BELUM, 'kode' => null, 'sumber' => 'belum', 'oleh' => null, 'waktu' => null];
}

/** Iklan dengan spend > 0 di periode, sudah dijumlah per ad_id + kreator + kategori. $merek: '' | prepare | skinlyfe */
function kr_iklan(PDO $db, string $dari, string $sampai, string $merek = ''): array {
    $daftar = kr_daftar($db); $manual = kr_manual($db);
    $sql = "SELECT x.ad_id, MAX(x.ad_name) ad_name, MAX(x.campaign_id) campaign_id, MAX(x.campaign_name) campaign_name, MAX(x.adset_name) adset_name,
                   MAX(k.merek) merek, MAX(k.nama) akun, SUM(x.spend) spend, SUM(x.value) omset, SUM(x.purch) purch
            FROM ins_ad x JOIN akun k ON k.act_id = CONCAT('act_', x.act_id)
            WHERE x.tanggal BETWEEN ? AND ?" . ($merek !== '' ? " AND k.merek = ?" : '') . "
            GROUP BY x.ad_id HAVING SUM(x.spend) > 0";
    $q = $db->prepare($sql); $q->execute($merek !== '' ? [$dari, $sampai, $merek] : [$dari, $sampai]);
    $o = [];
    foreach ($q->fetchAll() as $r) {
        $sp = (float)$r['spend']; $om = (float)$r['omset']; $pu = (int)$r['purch'];
        $r['spend'] = $sp; $r['omset'] = $om; $r['purch'] = $pu;
        $r['roas'] = $sp > 0 ? $om / $sp : 0.0;
        $r['cpa'] = $pu > 0 ? $sp / $pu : null;
        $r['kategori'] = kr_kategori($sp, $r['roas']);
        $r['kreator'] = kr_tentukan($r, $daftar, $manual);
        $r['konten'] = kr_konten((string)$r['ad_name'], (string)$r['campaign_name']);
        $r['konten_kunci'] = kr_konten_kunci($r['konten']);
        $o[] = $r;
    }
    usort($o, function ($a, $b) { return $b['spend'] <=> $a['spend']; });
    return $o;
}

/** Iklan digabung per konten (+ brand + kreator: konten yang kreatornya beda per iklan tetap dipisah, tidak ditebak). Kategori dari total konten. */
function kr_per_konten(array $iklan): array {
    $g = [];
    foreach ($iklan as $a) {
        $k = $a['merek'] . '|' . $a['konten_kunci'] . '|' . $a['kreator']['nama'];
        if (!isset($g[$k])) $g[$k] = ['ad_id' => $a['ad_id'], 'ad_name' => $a['konten'], 'konten' => $a['konten'], 'konten_kunci' => $a['konten_kunci'], 'campaign_id' => $a['campaign_id'],
            'campaign_name' => $a['campaign_name'], 'merek' => $a['merek'], 'akun' => $a['akun'], 'kreator' => $a['kreator'], 'spend' => 0.0, 'omset' => 0.0, 'purch' => 0, 'iklan' => [], 'akun_set' => []];
        $x = &$g[$k];
        $x['spend'] += $a['spend']; $x['omset'] += $a['omset']; $x['purch'] += $a['purch'];
        $x['iklan'][] = ['ad_id' => $a['ad_id'], 'ad_name' => $a['ad_name'], 'campaign_name' => $a['campaign_name'], 'akun' => $a['akun'], 'spend' => $a['spend']];
        $x['akun_set'][$a['akun']] = TRUE;
        unset($x);
    }
    foreach ($g as &$x) {
        $x['roas'] = $x['spend'] > 0 ? $x['omset'] / $x['spend'] : 0.0;
        $x['cpa'] = $x['purch'] > 0 ? $x['spend'] / $x['purch'] : null;
        $x['kategori'] = kr_kategori($x['spend'], $x['roas']);
        $x['akun'] = count($x['akun_set']) > 1 ? count($x['akun_set']) . ' akun' : $x['akun'];
    }
    unset($x);
    $o = array_values($g);
    usort($o, function ($a, $b) { return $b['spend'] <=> $a['spend']; });
    return $o;
}

/** Peringatan: iklan AKTIF BARU tanpa kode kreator (belanja kemarin/hari ini, pertama belanja <= 7 hari lalu, tanpa kode & tanpa set manual). */
function kr_tanpa_kode(PDO $db): array {
    $daftar = kr_daftar($db); $manual = kr_manual($db);
    $q = $db->query("SELECT x.ad_id, MAX(x.ad_name) ad_name, MAX(x.campaign_id) campaign_id, MAX(x.campaign_name) campaign_name, MAX(k.merek) merek, MAX(k.nama) akun,
                            MIN(x.tanggal) pertama, SUM(CASE WHEN x.tanggal >= CURDATE() - INTERVAL 1 DAY THEN x.spend ELSE 0 END) spend_baru
                     FROM ins_ad x JOIN akun k ON k.act_id = CONCAT('act_', x.act_id)
                     WHERE x.spend > 0 AND x.tanggal >= CURDATE() - INTERVAL 30 DAY
                     GROUP BY x.ad_id HAVING spend_baru > 0 AND pertama >= CURDATE() - INTERVAL 7 DAY ORDER BY pertama DESC, spend_baru DESC");
    $o = [];
    foreach ($q->fetchAll() as $r) if (kr_tentukan($r, $daftar, $manual)['sumber'] === 'belum') $o[] = $r;
    return $o;
}
