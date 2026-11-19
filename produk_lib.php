<?php
/* [naming-produk 19 Nov, Mufti] PRODUK PER IKLAN + kunci gabung NAMING + PRODUK (sama dengan app.js productOfAd / namingGroups).
   Produk 1 iklan = kode/kata produk (config.php 'products' → match) di nama CAMPAIGN, lalu nama AD SET, lalu nama IKLAN.
   Tidak ketemu → "Belum diketahui". TIDAK pernah memakai produk mayoritas / spend terbesar / default akun.
   pl_cek(): pengaman setelah sync (sync-insights.php) → /var/lib/montera-status/naming-cek.json → banner dashboard:
     kuning = naming dipakai 2+ produk / iklan produk "Belum diketahui" / nomor naming iklan ≠ nomor di nama campaign
     merah  = total level iklan per akun (dasar export) ≠ level akun (Ads Manager) */
declare(strict_types=1);
require_once __DIR__ . '/kreator_lib.php';   // kr_db, kr_konten, kr_konten_kunci

const PL_BELUM = 'Belum diketahui';
const PL_KATALOG = 'Katalog (multi produk)';
const PL_UMUM = 'Umum (multi produk)';   // [19 Nov, Mufti] campaign JENIS (bukan produk) tanpa kode produk di ad set/iklan
// jenis campaign (= app.js JENIS_CAMPAIGN): OFC / OFFICIAL / IG OFFICIAL, ALL / ALL APP, BOFU LVA / LIVE ADS, BOFU APPWEB, WINNING ADS, BOFU IE INT JAWA/SMT
const PL_JENIS = '/(^|[^a-z0-9])(ofc|official|all|all app|lva|live ads|appweb|winning ads|int jawa|smt)([^a-z0-9]|$)/i';
// akun-hari yang selisihnya dari Meta sendiri (dicek langsung ke API: level akun ≠ jumlah level iklan, termasuk DELETED/ARCHIVED) → abu, selesai
const PL_SELISIH_META = [['Prepare Selow ID 1129', '2026-10-17']];   // [19 Nov, Mufti] tanpa kode produk tapi adset promosi product set katalog: tidak dipaksa 1 produk
const PL_CEK = '/var/lib/montera-status/naming-cek.json';

/** daftar produk dari config.php (urutan = prioritas), kunci dinormalkan seperti app.js PRODUCTS */
function pl_daftar(?array $C = null): array {
    static $c = null; if ($c !== null && $C === null) return $c;
    $C = $C ?? (require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php'));
    $n = function ($k) { return ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$k))) . ' '; };
    $o = [];
    foreach ($C['products'] ?? [] as $p) $o[] = ['label' => (string)$p['label'], 'brand' => (string)($p['brand'] ?? ''), 'keys' => array_values(array_filter(array_map($n, $p['match'] ?? []), 'trim'))];
    return $c = $o;
}

/** = app.js productOfText */
function pl_teks(?string $t, array $P): string {
    if ($t === null || $t === '') return '';
    $x = ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($t))) . ' ';
    foreach ($P as $p) foreach ($p['keys'] as $k) if (strpos($x, $k) !== false) return $p['label'];
    return '';
}

/** = app.js productOf: campaign → ad set → nama iklan → "Katalog (multi produk)" (adset promosi product set) → "Belum diketahui" */
function pl_produk(string $camp, string $adset, string $ad, ?array $P = null, bool $katalog = false): string {
    $P = $P ?? pl_daftar();
    foreach ([$camp, $adset, $ad] as $t) if (($p = pl_teks($t, $P)) !== '') return $p;
    if (preg_match(PL_JENIS, $camp)) return PL_UMUM;
    return $katalog ? PL_KATALOG : PL_BELUM;
}

/** [adset_id => TRUE] adset yang mempromosikan product set katalog (promoted_object.product_set_id, dari ins_struct) */
function pl_set_katalog(PDO $db): array {
    static $c = null; if ($c !== null) return $c;
    $c = [];
    foreach ($db->query("SELECT raw FROM ins_struct", PDO::FETCH_NUM) as [$raw]) {
        $j = json_decode((string)$raw, true);
        foreach ($j['sets'] ?? [] as $s) if (!empty($s['promoted_object']['product_set_id'])) $c[(string)$s['id']] = TRUE;
    }
    return $c;
}

/** nomor di naming iklan ≠ nomor di naming campaign (mis. iklan "nsyhjdn 01" di campaign "... - nsyhjdn 02"): hanya kalau kata dasarnya sama */
function pl_nomor_beda(string $ad, string $camp): ?string {
    $a = kr_naming($ad); $segs = array_values(array_filter(array_map('trim', preg_split('/\s+-\s+/', kr_naming($camp))), 'strlen')); $c = (string)end($segs);
    $pola = '/^(.*?[a-z])[\s_-]*([a-z]{0,3}\d+[a-z]?)$/i';
    if (!preg_match($pola, $a, $ma) || !preg_match($pola, $c, $mc)) return null;
    if (strcasecmp(trim($ma[1]), trim($mc[1])) !== 0) return null;
    // yang dibandingkan ANGKA-nya saja: "31A" = "31" (varian), "01X" ≠ "03X", "LF07R" ≠ "LF10R"
    preg_match('/\d+/', $ma[2], $na); preg_match('/\d+/', $mc[2], $nc);
    return (int)$na[0] !== (int)$nc[0] ? $c : null;
}

/**
 * Cek naming + produk + total akun. $dari/$sampai NULL = semua data di DB.
 * @return ['dibuat','dari','sampai','campur' => [naming 2+ produk], 'belum' => [iklan produk Belum diketahui], 'nomor' => [iklan nomor beda], 'akun' => [akun level iklan ≠ level akun]]
 */
function pl_cek(PDO $db, ?string $dari = null, ?string $sampai = null): array {
    $P = pl_daftar();
    $w = $dari ? "WHERE x.tanggal BETWEEN ? AND ?" : ''; $b = $dari ? [$dari, $sampai] : [];
    $kat = pl_set_katalog($db);
    $q = $db->prepare("SELECT x.ad_id, MAX(x.ad_name) ad_name, MAX(x.campaign_name) campaign_name, MAX(x.adset_name) adset_name, MAX(x.adset_id) adset_id, MAX(k.merek) merek, MAX(k.nama) akun,
            MAX(x.act_id) act_id, SUM(x.spend) sp, SUM(x.purch) pu, SUM(x.value) om, MIN(x.tanggal) t0, MAX(x.tanggal) t1
        FROM ins_ad x JOIN akun k ON k.act_id = CONCAT('act_', x.act_id) $w GROUP BY x.ad_id HAVING SUM(x.spend) > 0 OR SUM(x.purch) > 0 OR SUM(x.value) > 0");
    $q->execute($b);
    $g = []; $belum = []; $nomor = []; $nKat = 0;
    foreach ($q->fetchAll() as $r) {
        $prod = pl_produk((string)$r['campaign_name'], (string)$r['adset_name'], (string)$r['ad_name'], $P, isset($kat[(string)$r['adset_id']]));
        if ($prod === PL_KATALOG) $nKat++;
        $konten = kr_konten((string)$r['ad_name'], (string)$r['campaign_name']);
        $k = $r['merek'] . '|' . kr_konten_kunci($konten);
        $g[$k]['naming'] = $konten; $g[$k]['merek'] = $r['merek'];
        $x = &$g[$k]['produk'][$prod];
        $x['sp'] = ($x['sp'] ?? 0) + (float)$r['sp']; $x['pu'] = ($x['pu'] ?? 0) + (int)$r['pu']; $x['om'] = ($x['om'] ?? 0) + (float)$r['om'];
        $x['iklan'][] = ['ad_id' => $r['ad_id'], 'akun' => $r['akun'], 'campaign' => $r['campaign_name'], 'iklan' => $r['ad_name'], 'sp' => (float)$r['sp'], 'pu' => (int)$r['pu'], 'om' => (float)$r['om']];
        unset($x);
        if ($prod === PL_BELUM) $belum[] = ['merek' => $r['merek'], 'akun' => $r['akun'], 'iklan' => $r['ad_name'], 'campaign' => $r['campaign_name'], 'adset' => $r['adset_name'], 'sp' => (float)$r['sp'], 'pu' => (int)$r['pu'], 'om' => (float)$r['om'], 'ad_id' => $r['ad_id']];
        if (($c = pl_nomor_beda((string)$r['ad_name'], (string)$r['campaign_name'])) !== null)
            $nomor[] = ['merek' => $r['merek'], 'akun' => $r['akun'], 'iklan' => $r['ad_name'], 'campaign' => $r['campaign_name'], 'naming_campaign' => $c, 'sp' => (float)$r['sp'], 'pu' => (int)$r['pu'], 'ad_id' => $r['ad_id']];
    }
    $campur = array_values(array_filter($g, function ($x) { return count(array_diff(array_keys($x['produk']), [PL_BELUM, PL_KATALOG, PL_UMUM])) >= 2; }));
    usort($campur, function ($a, $b) { return array_sum(array_column($b['produk'], 'sp')) <=> array_sum(array_column($a['produk'], 'sp')); });
    usort($belum, function ($a, $b) { return $b['sp'] <=> $a['sp']; });
    usort($nomor, function ($a, $b) { return $b['sp'] <=> $a['sp']; });
    return ['dibuat' => date('c'), 'dari' => $dari, 'sampai' => $sampai, 'campur' => $campur, 'belum' => $belum, 'nomor' => $nomor, 'n_katalog' => $nKat];
}

/**
 * total per akun: level iklan (dasar export, naming+produk hanya memindah baris) vs level akun (Ads Manager) per hari; hanya yang beda.
 * [19 Nov, Mufti] kategori: 'cek'      = spend beda (hari yang sudah lewat) ATAU total purchase/value brand hari itu beda → perlu dicek
 *                           'atribusi' = spend sama & total brand sama, purchase hanya pindah antarakun (atribusi/dedup Meta, dicek ke API) → info abu
 *                           'berjalan' = selisih spend di kemarin/hari ini (Meta masih memperbarui) → info abu
 */
function pl_cek_akun(PDO $db, string $dari, string $sampai): array {
    $v = kr_validasi($db, $dari, $sampai);
    $brand = [];   // merek|tanggal → selisih total brand
    foreach ($v['beda'] as $t) { $k = $t['merek'] . '|' . $t['tanggal']; $brand[$k]['pu'] = ($brand[$k]['pu'] ?? 0) + $t['pu_i'] - $t['pu_a']; $brand[$k]['om'] = ($brand[$k]['om'] ?? 0) + $t['om_i'] - $t['om_a']; }
    $kemarin = date('Y-m-d', strtotime('-1 day'));
    $o = [];
    foreach ($v['beda'] as $t) {
        $sp = round($t['sp_i'] - $t['sp_a']); $b = $brand[$t['merek'] . '|' . $t['tanggal']];
        if (in_array([$t['nama'], $t['tanggal']], PL_SELISIH_META, true)) $kat = 'meta';
        elseif ($sp != 0) $kat = $t['tanggal'] >= $kemarin ? 'berjalan' : 'cek';
        else $kat = ($b['pu'] == 0 && abs($b['om']) <= 1) ? 'atribusi' : 'cek';   // toleransi Rp1 pembulatan
        $o[] = ['akun' => $t['nama'], 'merek' => $t['merek'], 'tanggal' => $t['tanggal'], 'kategori' => $kat,
            'sp' => $sp, 'pu' => $t['pu_i'] - $t['pu_a'], 'om' => round($t['om_i'] - $t['om_a']),
            'sp_i' => $t['sp_i'], 'sp_a' => $t['sp_a'], 'pu_i' => $t['pu_i'], 'pu_a' => $t['pu_a'], 'om_i' => $t['om_i'], 'om_a' => $t['om_a']];
    }
    return $o;
}

/** dijalankan di akhir sync-insights.php: cek 60 hari terakhir (naming/produk) + bulan ini & bulan lalu s/d kemarin (total akun) → berkas status */
function pl_simpan_cek(PDO $db): array {
    $kemarin = date('Y-m-d', strtotime('-1 day'));
    $h = pl_cek($db, date('Y-m-d', strtotime('-60 day')), date('Y-m-d'));
    $h['akun'] = pl_cek_akun($db, date('Y-m-01', strtotime('first day of last month')), $kemarin);
    $h['akun_periode'] = [date('Y-m-01', strtotime('first day of last month')), $kemarin];
    $ring = ['dibuat' => $h['dibuat'], 'campur' => array_slice($h['campur'], 0, 50), 'n_campur' => count($h['campur']),
             'belum' => array_slice($h['belum'], 0, 50), 'n_belum' => count($h['belum']), 'nomor' => array_slice($h['nomor'], 0, 50), 'n_nomor' => count($h['nomor']), 'n_katalog' => $h['n_katalog'],
             'akun' => $h['akun'], 'akun_periode' => $h['akun_periode']];
    $tmp = PL_CEK . '.' . getmypid(); @file_put_contents($tmp, json_encode($ring, JSON_UNESCAPED_UNICODE)); @chmod($tmp, 0664); @rename($tmp, PL_CEK);
    return $ring;
}
