<?php
/* ============================================================
   SYNC DATA META → TABEL LOKAL (montera_ads.ins_*) untuk dashboard ads.prepareofficial.com
   Dashboard membaca tabel lokal (api.php?action=local), jadi tidak perlu menunggu Meta.

   Pakai:
     php /opt/montera-ads/sync-insights.php today                    hari ini            (cron tiap 10 menit)
     php /opt/montera-ads/sync-insights.php recent                   3 hari terakhir     (cron tiap 30 menit)
     php /opt/montera-ads/sync-insights.php pekan                    8 hari terakhir     (cron harian: purchase atribusi 7d_click masih berubah s.d. ~7 hari)
     php /opt/montera-ads/sync-insights.php range 2026-09-01 2026-10-16
     opsi: --acc=ID,ID  (hanya akun tertentu)

   Aturan tarik:
     - paralel maks 4 akun (proses anak), 1 akun berurutan
     - 3 level sama dengan app.js insightReqs: ad, campaign (ACTIVE+PAUSED), account; TANPA filter spend
       (konversi di hari tanpa belanja tetap ikut, sama dengan Ads Manager)
     - rentang > 7 hari: async insights job (POST → poll report_run_id → ambil hasil)
     - "Please reduce the amount of data" / code 1 / job gagal: TIDAK diulang ukuran sama → rentang dipecah 2 s.d. per hari
     - code 2 (temporary): retry backoff; 4/17/32/613/80000-80014 (rate limit): tunggu sesuai header x-business-use-case-usage
     - 190: token invalid; 10/200: token tidak punya akses → coba token lain dari config.php & /etc/montera-ads-meta.json
     - data 1 akun hanya diganti kalau KETIGA level berhasil (DELETE + INSERT dalam 1 transaksi)
   ============================================================ */
declare(strict_types=1);
// File ini ada di folder web (repo = DocumentRoot). Hanya boleh dijalankan dari CLI (cron / api.php refresh).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Jakarta');
set_time_limit(0);

const MAX_PARALEL = 4;
const PAGE_LIMIT = 100;
const ASYNC_LEBIH_DARI = 7;   // hari

$C = require '/var/www/montera-ads/config.php';
$V = $C['graph_version'] ?? 'v21.0';
$ATTR = json_encode($C['attribution'] ?? ['7d_click', '1d_view']);

function db(): PDO {
    static $p = null; if ($p) return $p;
    $e = [];
    foreach (@file('/etc/montera-ads-db.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l)
        if (strpos($l, '=') !== false) { [$k, $v] = explode('=', $l, 2); $e[trim($k)] = trim($v, " \t\"'"); }
    $p = new PDO('mysql:host=' . ($e['DB_HOST'] ?? 'localhost') . ';dbname=' . ($e['DB_NAME'] ?? 'montera_ads') . ';charset=utf8mb4',
        $e['DB_USER'] ?? '', $e['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec("SET time_zone = '+07:00'");
    return $p;
}
function say(string $s): void { echo '[' . date('Y-m-d H:i:s') . '] ' . $s . "\n"; }

/* ---------- token: config.php dulu (sesuai akun), lalu semua token di /etc ---------- */
function token_candidates(array $C, array $acc): array {
    $out = [];
    if (!empty($C['tokens'][$acc['token']])) $out['config:' . $acc['token']] = $C['tokens'][$acc['token']];
    $etc = json_decode((string) @file_get_contents('/etc/montera-ads-meta.json'), true) ?: [];
    foreach ($etc as $k => $t) if (is_string($t) && $t !== '' && !in_array($t, $out, true)) $out['etc:' . $k] = $t;
    return $out;
}

/* ---------- error Meta ---------- */
class MetaErr extends Exception {
    public string $jenis;   // too_big | token_invalid | no_access | rate | temp | lain
    public function __construct(string $jenis, string $msg, int $code = 0) { parent::__construct($msg, $code); $this->jenis = $jenis; }
}
function classify(array $err): MetaErr {
    $code = (int) ($err['code'] ?? 0); $msg = (string) ($err['message'] ?? 'error');
    $m = "code $code: " . mb_substr($msg, 0, 200);
    if ($code === 190) return new MetaErr('token_invalid', $m, $code);
    if (in_array($code, [10, 200, 294], true) || ($code >= 200 && $code < 300)) return new MetaErr('no_access', $m, $code);
    if ($code === 1 || stripos($msg, 'reduce the amount of data') !== false) return new MetaErr('too_big', $m, $code);
    if (in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80014)) return new MetaErr('rate', $m, $code);
    if ($code === 2 || !empty($err['is_transient'])) return new MetaErr('temp', $m, $code);
    return new MetaErr('lain', $m, $code);
}
/* tunggu (detik) dari header x-business-use-case-usage: {"<act>":[{"estimated_time_to_regain_access":menit,...}]} */
function wait_from_header(string $h): int {
    $j = json_decode($h, true); $menit = 0;
    if (is_array($j)) foreach ($j as $list) foreach ((array) $list as $u) $menit = max($menit, (int) ($u['estimated_time_to_regain_access'] ?? 0));
    return $menit * 60;
}

/* ---------- 1 request ke Graph + retry/backoff ---------- */
function graph(string $method, string $url, array $form = []): array {
    $temp = 0; $rate = 0; $net = 0;
    while (true) {
        $hdr = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING => '', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdr) { $p = explode(':', $l, 2); if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]); return strlen($l); }]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        $txt = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
        if ($txt === false || $cerr !== '') {
            if (++$net > 3) throw new MetaErr('temp', "koneksi: $cerr");
            sleep(2 ** $net); continue;
        }
        $j = json_decode((string) $txt, true);
        if (!is_array($j)) { if (++$net > 3) throw new MetaErr('temp', 'respon Meta tidak valid'); sleep(2 ** $net); continue; }
        if (!isset($j['error'])) return $j;
        $e = classify($j['error']);
        if ($e->jenis === 'temp' && ++$temp <= 3) { sleep(3 * 2 ** ($temp - 1)); continue; }               // 3, 6, 12 dtk
        if ($e->jenis === 'rate' && ++$rate <= 4) {
            $w = wait_from_header($hdr['x-business-use-case-usage'] ?? '') ?: 30 * $rate;
            say('  rate limit (' . $e->getMessage() . "), tunggu {$w} dtk");
            sleep(min($w, 900)); continue;
        }
        if ($e->jenis === 'temp') $e->jenis = 'too_big';   // code 2 terus-menerus pada request besar → pecah rentang
        throw $e;
    }
}
function get_all(string $url): array {
    $out = [];
    while ($url) { $j = graph('GET', $url); array_push($out, ...($j['data'] ?? [])); $url = $j['paging']['next'] ?? ''; }
    return $out;
}
function gurl(string $path, array $q, string $tok): string {
    global $V;
    foreach ($q as $k => $v) if (is_array($v)) $q[$k] = json_encode($v);
    $q['access_token'] = $tok;
    return "https://graph.facebook.com/$V/$path?" . http_build_query($q);
}

/* ---------- insights per level dengan pecah rentang ---------- */
function days_between(string $a, string $b): array { $o = []; for ($d = $a; $d <= $b; $d = date('Y-m-d', strtotime("$d +1 day"))) $o[] = $d; return $o; }
function level_params(string $level): array {
    global $ATTR;
    // [spend-terpisah 21 Okt DARURAT] "ad:spend" / "ad:konv": Meta menjawab spend 0 kalau spend diminta BERSAMA field turunan action
    // (actions, catalog_segment_*, inline_link_clicks) → spend hanya bersama metrik tayangan (impressions, frequency)
    [$level, $bag] = array_pad(explode(':', $level, 2), 2, '');
    $conv = 'actions,catalog_segment_actions,catalog_segment_value,video_thruplay_watched_actions';
    // TANPA filter spend > 0: Meta melaporkan konversi juga di hari ketika iklan tidak berbelanja
    // (atribusi 7d_click). Filter spend > 0 per hari membuat purchase/value per iklan/campaign
    // lebih rendah s/d 1,5% dari Ads Manager (audit 17 Okt 2026). Meta sendiri tidak mengirim baris kosong.
    $p = ['level' => $level, 'time_increment' => 1, 'action_attribution_windows' => $ATTR];
    if ($level === 'ad') {
        $p['fields'] = "ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name," . ($bag === 'spend' ? 'spend,impressions,frequency' : ($bag === 'konv' ? "inline_link_clicks,$conv" : "spend,impressions,inline_link_clicks,frequency,$conv"));
        $p['filtering'] = [['field' => 'ad.effective_status', 'operator' => 'IN', 'value' => ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'ARCHIVED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO', 'IN_PROCESS', 'WITH_ISSUES']]];
    } elseif ($level === 'campaign') {
        $p['fields'] = 'campaign_id,' . ($bag === 'spend' ? 'spend,impressions' : ($bag === 'konv' ? "inline_link_clicks,$conv" : "spend,impressions,inline_link_clicks,$conv"));
        $p['filtering'] = [['field' => 'campaign.effective_status', 'operator' => 'IN', 'value' => ['ACTIVE', 'PAUSED']]];
    } else {
        // [aturan-sheet 30 Okt] level akun juga action_values: purchase/omset pixel dipakai kalau katalog 0 (sama dengan sheet, lihat metrics_akun)
        $p['fields'] = $bag === 'spend' ? 'spend,impressions' : ($bag === 'konv' ? "inline_link_clicks,$conv,action_values" : "spend,impressions,inline_link_clicks,$conv,action_values");
    }
    return $p;
}
function fetch_async(string $act, string $level, string $since, string $until, string $tok): array {
    global $V;
    $p = level_params($level) + ['time_range' => ['since' => $since, 'until' => $until]];
    foreach ($p as $k => $v) if (is_array($v)) $p[$k] = json_encode($v);
    $p['access_token'] = $tok;
    $j = graph('POST', "https://graph.facebook.com/$V/$act/insights", $p);
    $rid = $j['report_run_id'] ?? null;
    if (!$rid) throw new MetaErr('too_big', 'async: report_run_id kosong');
    $t0 = time();
    while (true) {
        sleep(3);
        $s = graph('GET', gurl($rid, ['fields' => 'async_status,async_percent_completion'], $tok));
        $st = $s['async_status'] ?? '';
        if ($st === 'Job Completed') break;
        if (in_array($st, ['Job Failed', 'Job Skipped'], true)) throw new MetaErr('too_big', "async $st");
        if (time() - $t0 > 900) throw new MetaErr('too_big', 'async: lebih dari 15 menit');
    }
    return get_all(gurl("$rid/insights", ['limit' => 500], $tok));
}
function fetch_sync(string $act, string $level, string $since, string $until, string $tok, int $limit): array {
    $p = level_params($level) + ['time_range' => ['since' => $since, 'until' => $until], 'limit' => $limit];
    return get_all(gurl("$act/insights", $p, $tok));
}
function fetch_level(string $act, string $level, string $since, string $until, string $tok, array &$cara): array {
    $d = days_between($since, $until); $n = count($d);
    try {
        if ($n > ASYNC_LEBIH_DARI) { $r = fetch_async($act, $level, $since, $until, $tok); $cara[] = "$level:async {$n}h"; return $r; }
        $r = fetch_sync($act, $level, $since, $until, $tok, PAGE_LIMIT); $cara[] = "$level:get {$n}h"; return $r;
    } catch (MetaErr $e) {
        if ($e->jenis !== 'too_big') throw $e;
        if ($n > 1) {   // pecah 2: bulan → minggu → hari
            $mid = $d[intdiv($n, 2) - 1];
            say("  $act $level $since..$until terlalu besar ({$e->getMessage()}) → pecah");
            return array_merge(fetch_level($act, $level, $since, $mid, $tok, $cara), fetch_level($act, $level, date('Y-m-d', strtotime("$mid +1 day")), $until, $tok, $cara));
        }
        say("  $act $level $since tetap terlalu besar → limit 25");
        $r = fetch_sync($act, $level, $since, $until, $tok, 25); $cara[] = "$level:get 1h limit25"; return $r;
    }
}

/* ---------- konversi baris Meta → kolom tabel (sama dengan convOf/rowArr di app.js) ---------- */
function act_val($arr, array $types): float { foreach ($types as $t) foreach ((array) $arr as $a) if (($a['action_type'] ?? '') === $t) return (float) $a['value']; return 0.0; }
function metrics(array $r): array {
    return [
        'spend' => (float) ($r['spend'] ?? 0), 'impr' => (int) ($r['impressions'] ?? 0), 'clicks' => (int) ($r['inline_link_clicks'] ?? 0),
        'atc' => (int) act_val($r['catalog_segment_actions'] ?? [], ['omni_add_to_cart', 'add_to_cart']),
        'purch' => (int) act_val($r['catalog_segment_actions'] ?? [], ['omni_purchase', 'purchase']),
        'value' => act_val($r['catalog_segment_value'] ?? [], ['omni_purchase', 'purchase']),
        'vc' => (int) act_val($r['catalog_segment_actions'] ?? [], ['omni_view_content', 'view_content']),
        'v3s' => (int) act_val($r['actions'] ?? [], ['video_view']),
        'thru' => (int) act_val($r['video_thruplay_watched_actions'] ?? [], ['video_view']),
    ];
}
/** [aturan-sheet 30 Okt, keputusan Mufti] Total akun = aturan script sheet (lib.php ma_konversi): purchase & omset katalog CPAS,
 *  kalau katalog 0 → pixel (actions/action_values), kecuali akun "cpas_only" (Prepare). Skinlyfe: katalog ?: pixel.
 *  Hanya level akun; level iklan/campaign tetap katalog (dipakai analisis naming). */
function metrics_akun(array $r, array $aturan): array {
    $m = metrics($r); $sk = ($aturan['metode'] ?? '') === 'skinlyfe'; $cpas = !$sk && !empty($aturan['cpas_only']);
    if (!$cpas) {
        $jP = $sk ? ['purchase', 'omni_purchase', 'onsite_conversion.purchase'] : ['omni_purchase', 'onsite_conversion.purchase', 'purchase'];
        if (!$m['purch']) $m['purch'] = (int) act_val($r['actions'] ?? [], $jP);
        if (!$m['value']) $m['value'] = act_val($r['action_values'] ?? [], $jP);
        if (!$m['atc']) $m['atc'] = (int) act_val($r['actions'] ?? [], ['omni_add_to_cart', 'add_to_cart']);
        if (!$m['vc']) $m['vc'] = (int) act_val($r['actions'] ?? [], $sk ? ['view_content', 'omni_view_content'] : ['omni_view_content', 'view_content']);
    }
    return $m;
}
function insert_rows(PDO $db, string $table, array $cols, array $rows): void {
    foreach (array_chunk($rows, 400) as $chunk) {
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st = $db->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph)));
        $st->execute(array_merge(...array_map('array_values', $chunk)));
    }
}

/* ---------- 1 akun (proses anak) ---------- */
function sync_account(array $C, array $acc, string $since, string $until, string $mode): int {
    $db = db();
    $act = (string) $acc['id']; if (strpos($act, 'act_') !== 0) $act = 'act_' . $act;
    $id = substr($act, 4);
    if ((int) $db->query('SELECT GET_LOCK(' . $db->quote("ins_acc_$id") . ', 900)')->fetchColumn() !== 1) { say("$acc[name]: masih dikunci proses lain, lewati"); return 0; }
    $db->prepare('INSERT INTO ins_sync_log (act_id, mode, since, until, mulai) VALUES (?,?,?,?,NOW())')->execute([$id, $mode, $since, $until]);
    $logId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO ins_status (act_id, nama, terakhir_coba_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE nama=VALUES(nama), terakhir_coba_at=NOW()')->execute([$id, $acc['name']]);
    $t0 = microtime(true);
    $tokKey = null;
    try {
        // token yang bisa membuka akun ini (urut: config.php → /etc)
        $tok = null; $tokErr = [];
        foreach (token_candidates($C, $acc) as $k => $t) {
            try { graph('GET', gurl($act, ['fields' => 'name,account_status'], $t)); $tok = $t; $tokKey = $k; break; }
            catch (MetaErr $e) { $tokErr[$k] = $e; if (!in_array($e->jenis, ['token_invalid', 'no_access'], true)) throw $e; }
        }
        if (!$tok) {
            $allInvalid = $tokErr && !array_filter($tokErr, fn($e) => $e->jenis !== 'token_invalid');
            throw new MetaErr($allInvalid ? 'token_invalid' : 'no_access',
                ($allInvalid ? 'Token invalid (190)' : 'Tidak ada token yang punya akses ke akun ini — assign System User di Business Manager') . ' · ' . implode(' | ', array_map(fn($k, $e) => "$k: " . $e->getMessage(), array_keys($tokErr), $tokErr)));
        }

        // struktur (campaign/adset/iklan aktif) — dipakai app.js buildStructure; gagal di sini tidak menggagalkan angka
        if ($until >= date('Y-m-d', strtotime('-1 day'))) {
            try {
                $live = ['ACTIVE', 'IN_PROCESS', 'WITH_ISSUES'];
                $raw = [
                    'info' => graph('GET', gurl($act, ['fields' => 'name,currency'], $tok)),
                    'camps' => get_all(gurl("$act/campaigns", ['fields' => 'id,name,status,effective_status,daily_budget,lifetime_budget,start_time', 'effective_status' => $live, 'limit' => 500], $tok)),
                    'sets' => get_all(gurl("$act/adsets", ['fields' => 'id,name,campaign_id,status,daily_budget,lifetime_budget,start_time,promoted_object', 'effective_status' => $live, 'limit' => 500], $tok)),
                    'ads' => get_all(gurl("$act/ads", ['fields' => 'id,name,status,effective_status,adset_id,campaign_id,created_time,creative{body,title,thumbnail_url}', 'effective_status' => ['ACTIVE', 'PENDING_REVIEW', 'DISAPPROVED', 'WITH_ISSUES', 'IN_PROCESS', 'PREAPPROVED'], 'limit' => 50], $tok)),   // [24 Okt] 200 → 50: dengan creative{body} Meta sering menjawab "Please reduce the amount of data" (PREPARE CPAS HK 1, SKINLYFE HK-1)
                ];
                foreach ($raw['ads'] as &$o) { if (isset($o['creative']['body'])) $o['creative']['body'] = mb_substr($o['creative']['body'], 0, 400); } unset($o);
                unset($raw['info']['id']);
                $db->prepare('INSERT INTO ins_struct (act_id, raw, diambil_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE raw=VALUES(raw), diambil_at=NOW()')->execute([$id, json_encode($raw, JSON_UNESCAPED_UNICODE)]);
            } catch (MetaErr $e) { say("  $acc[name]: struktur gagal ({$e->getMessage()}), pakai struktur lama"); }
        }

        $cara = [];
        // [spend-terpisah] tiap level: spend & konversi ditarik terpisah lalu digabung per tanggal + id
        $dua = function (string $lv, string $id) use ($act, $since, $until, $tok, &$cara) {
            $k = fn($r) => $r['date_start'] . '|' . ($id ? ($r[$id] ?? '') : '');
            $sp = []; foreach (fetch_level($act, "$lv:spend", $since, $until, $tok, $cara) as $r) $sp[$k($r)] = $r;
            $kv = []; foreach (fetch_level($act, "$lv:konv", $since, $until, $tok, $cara) as $r) $kv[$k($r)] = $r;
            $o = [];
            foreach (array_keys($sp + $kv) as $x) {
                $r = $sp[$x] ?? ($kv[$x] + ['spend' => 0, 'impressions' => 0, 'frequency' => 0]);
                foreach (['inline_link_clicks', 'actions', 'action_values', 'catalog_segment_actions', 'catalog_segment_value', 'video_thruplay_watched_actions'] as $f) if (isset($kv[$x][$f])) $r[$f] = $kv[$x][$f]; else unset($r[$f]);
                $o[] = $r;
            }
            return $o;
        };
        $adI = $dua('ad', 'ad_id');
        $campI = $dua('campaign', 'campaign_id');
        $accI = $dua('account', '');
        // [spend-0 21 Okt] Meta kadang menjawab spend 0 (impresi juga 0) untuk SATU level padahal konversinya ada & level lain berbelanja
        // (Selow GCR 0114 level iklan 18–20 Okt tersimpan 0). Hari dengan baris iklan tapi total spend iklan 0 sementara akun > 0 (atau sebaliknya)
        // → level itu ditarik ulang maks 2x; tetap begitu = gagal, data lama tidak diganti.
        $perHari = function (array $rows) { $o = []; foreach ($rows as $r) $o[$r['date_start']] = ($o[$r['date_start']] ?? 0) + (float) ($r['spend'] ?? 0); return $o; };
        for ($coba = 1; ; $coba++) {
            $sA = $perHari($adI); $sK = $perHari($accI); $ulang = [];
            foreach ($sA as $t => $v) { if ($v == 0 && ($sK[$t] ?? 0) > 0) $ulang['ad'] = $t; if ($v > 0 && isset($sK[$t]) && $sK[$t] == 0) $ulang['account'] = $t; }
            if (!$ulang) break;
            if ($coba > 2) throw new MetaErr('temp', 'Meta menjawab spend 0 di level ' . implode('/', array_keys($ulang)) . ' (' . implode(', ', $ulang) . ') padahal level lain berbelanja — data lama dipertahankan');
            say("  $acc[name]: spend 0 di level " . implode('/', array_keys($ulang)) . ' (' . implode(', ', $ulang) . "), tarik ulang $coba");
            sleep(5);
            if (isset($ulang['ad'])) $adI = $dua('ad', 'ad_id');
            if (isset($ulang['account'])) $accI = $dua('account', '');
        }

        // tanpa filter spend, Meta juga mengirim baris harian yang SEMUA metriknya 0 (iklan sudah berhenti) → dilewati
        $kosong = fn(array $m) => !array_filter($m, fn($v) => (float) $v != 0);
        $adRows = []; foreach ($adI as $r) { if ($kosong(metrics($r))) continue; $adRows[] = ['act_id' => $id, 'tanggal' => $r['date_start'], 'ad_id' => $r['ad_id'],
            'ad_name' => mb_substr((string) ($r['ad_name'] ?? ''), 0, 500), 'adset_id' => (string) ($r['adset_id'] ?? ''), 'adset_name' => mb_substr((string) ($r['adset_name'] ?? ''), 0, 500),
            'campaign_id' => (string) ($r['campaign_id'] ?? ''), 'campaign_name' => mb_substr((string) ($r['campaign_name'] ?? ''), 0, 500)]
            + metrics($r) + ['freq' => (float) ($r['frequency'] ?? 0)]; }
        $campRows = []; foreach ($campI as $r) { if ($kosong(metrics($r))) continue; $campRows[] = ['act_id' => $id, 'tanggal' => $r['date_start'], 'campaign_id' => $r['campaign_id']] + metrics($r); }
        $q = $db->prepare("SELECT metode, cpas_only FROM akun WHERE act_id = ? LIMIT 1"); $q->execute(['act_' . $id]); $aturan = $q->fetch() ?: [];   // [aturan-sheet 30 Okt]
        $accRows = []; foreach ($accI as $r) $accRows[] = ['act_id' => $id, 'tanggal' => $r['date_start']] + metrics_akun($r, $aturan);

        $db->beginTransaction();
        foreach (['ins_ad' => $adRows, 'ins_campaign' => $campRows, 'ins_account' => $accRows] as $tb => $rows) {
            $db->prepare("DELETE FROM $tb WHERE act_id=? AND tanggal BETWEEN ? AND ?")->execute([$id, $since, $until]);
            if ($rows) insert_rows($db, $tb, array_keys($rows[0]), $rows);
        }
        $cov = $db->prepare('INSERT INTO ins_cover (act_id, tanggal, ditarik_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE ditarik_at=NOW()');
        foreach (days_between($since, $until) as $d) $cov->execute([$id, $d]);
        $db->commit();

        $db->prepare("UPDATE ins_sync_log SET selesai=NOW(), status='ok', baris_ad=?, baris_campaign=?, baris_account=?, token_key=?, cara=? WHERE id=?")
           ->execute([count($adRows), count($campRows), count($accRows), $tokKey, mb_substr(implode(' ', array_unique($cara)), 0, 120), $logId]);
        $db->prepare("UPDATE ins_status SET token_key=?, token_status='ok', terakhir_ok_at=NOW(), error_terakhir=NULL WHERE act_id=?")->execute([$tokKey, $id]);
        say(sprintf('%-26s ok  %s..%s  ad %d · campaign %d · account %d  (%s, %.1f dtk)', mb_substr($acc['name'], 0, 26), $since, $until, count($adRows), count($campRows), count($accRows), $tokKey, microtime(true) - $t0));
        return 0;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $jenis = $e instanceof MetaErr ? $e->jenis : 'error';
        $tstat = in_array($jenis, ['token_invalid', 'no_access'], true) ? ($jenis === 'token_invalid' ? 'invalid' : 'no_access') : 'error';
        $msg = mb_substr($e->getMessage(), 0, 900);
        $db->prepare("UPDATE ins_sync_log SET selesai=NOW(), status='gagal', error=?, token_key=? WHERE id=?")->execute([$msg, $tokKey, $logId]);
        $db->prepare('UPDATE ins_status SET token_status=?, error_terakhir=? WHERE act_id=?')->execute([$tstat, mb_substr($msg, 0, 500), $id]);
        say(sprintf('%-26s GAGAL %s..%s  %s', mb_substr($acc['name'], 0, 26), $since, $until, $msg));
        return 1;
    } finally {
        $db->query('SELECT RELEASE_LOCK(' . $db->quote("ins_acc_$id") . ')');
    }
}

/* ---------- main ---------- */
$args = array_slice($argv, 1);
$opt = []; $pos = [];
foreach ($args as $a) { if (preg_match('/^--(\w+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true; else $pos[] = $a; }
$ACCS = $C['accounts'];
$idOf = fn($a) => preg_replace('/^act_/', '', $a['id']);

if (!empty($opt['child'])) {   // proses anak: 1 akun
    [$aid, $since, $until, $mode] = $pos;
    foreach ($ACCS as $a) if ($idOf($a) === $aid) exit(sync_account($C, $a, $since, $until, $mode));
    say("akun $aid tidak ada di config.php"); exit(1);
}

$mode = $pos[0] ?? 'today'; $today = date('Y-m-d');
switch ($mode) {
    case 'today':  $since = $until = $today; break;
    case 'recent': $since = date('Y-m-d', strtotime('-3 days')); $until = $today; break;
    case 'pekan':  $since = date('Y-m-d', strtotime('-8 days')); $until = $today; break;
    case 'range':
        [$since, $until] = [$pos[1] ?? '', $pos[2] ?? ''];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $since > $until) { say('format: range YYYY-MM-DD YYYY-MM-DD'); exit(1); }
        if ($until > $today) $until = $today;
        break;
    default: say("mode tidak dikenal: $mode"); exit(1);
}
if (!empty($opt['acc'])) { $want = explode(',', (string) $opt['acc']); $ACCS = array_values(array_filter($ACCS, fn($a) => in_array($idOf($a), $want, true))); }

// satu run per mode sekaligus (cron tidak menumpuk); antar-mode aman karena kunci per akun
$lock = 'ins_run_' . $mode . ($mode === 'range' ? "_{$since}_{$until}" : '');
if ((int) db()->query('SELECT GET_LOCK(' . db()->quote($lock) . ', 0)')->fetchColumn() !== 1) { say("$mode masih berjalan, lewati"); exit(0); }

say("mulai $mode $since..$until · " . count($ACCS) . ' akun · paralel ' . MAX_PARALEL);
$queue = $ACCS; $run = []; $gagal = 0;
while ($queue || $run) {
    while ($queue && count($run) < MAX_PARALEL) {
        $a = array_shift($queue);
        $p = proc_open([PHP_BINARY, __FILE__, '--child', $idOf($a), $since, $until, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $run[] = [$p, $pipes];
    }
    foreach ($run as $i => [$p, $pipes]) {
        echo stream_get_contents($pipes[1]), stream_get_contents($pipes[2]);
        $s = proc_get_status($p);
        if (!$s['running']) {
            echo stream_get_contents($pipes[1]), stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            if ($s['exitcode'] !== 0) $gagal++;
            unset($run[$i]);
        }
    }
    usleep(200000);
}
say("selesai $mode $since..$until · gagal $gagal akun");
exit($gagal ? 2 : 0);
