<?php
/* ============================================================
   API ADS COMMAND — jembatan browser → Meta Graph API & AI.
   Token & API key hanya dibaca di sini (config.php).
   ============================================================ */
declare(strict_types=1);
// Jangan pernah cetak warning/deprecated ke output — itu merusak JSON (penyebab "Server PHP error (HTTP 200)")
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ob_start();
require __DIR__ . '/auth.php';
ma_session_start();
$C = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');   // laptop: config.php · server: env var
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $x, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();   // buang output nyasar apa pun
    http_response_code($code);
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): void { out(['error' => ['message' => $msg]], $code); }

// --- keamanan: login + CSRF ---
if (empty($_SESSION['ac_ok']) && ($ru = ma_remember_check($C)) !== null) { $_SESSION['ac_ok'] = true; $_SESSION['ac_user'] = $ru; }   // login tahan lama
if ((($C['app_password'] ?? '') !== '' || !empty($C['login_users'])) && empty($_SESSION['ac_ok'])) fail('Belum login', 401);
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Sesi tidak valid', 401);
session_write_close();   // lepas kunci session supaya request paralel tidak antre
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method tidak diizinkan', 405);
set_time_limit(180);

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? '';

function http_req(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 60): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',      // terima gzip → lebih cepat
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,   // server VPS: IPv6 sering lambat/timeout → paksa IPv4
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $txt = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    return [$st, $txt === false ? '' : $txt, $err];
}
function acc_token(array $C, string $accId): ?string {
    $accId = preg_replace('/^act_/', '', $accId);
    foreach ($C['accounts'] as $a) {
        if (preg_replace('/^act_/', '', $a['id']) === $accId) return $C['tokens'][$a['token']] ?? null;
    }
    return null;
}

/* ---------- 1. GET ke Meta Graph API ---------- */
if ($action === 'graph') {
    $tok  = acc_token($C, (string)($in['acc'] ?? ''));
    $path = (string)($in['path'] ?? '');
    if (!$tok) fail('Token Meta kosong untuk akun ini — isi di config.php atau env var META_TOKEN_PREPARE / META_TOKEN_SKINLYFE');
    if (!preg_match('#^[A-Za-z0-9_/]+$#', $path)) fail('Path tidak valid');
    $q = [];
    foreach (($in['params'] ?? []) as $k => $v) $q[$k] = is_array($v) ? json_encode($v) : (string)$v;
    $q['access_token'] = $tok;
    [$st, $txt, $err] = http_req('GET', "https://graph.facebook.com/{$C['graph_version']}/$path?" . http_build_query($q));
    if ($err) fail("Koneksi ke Meta gagal: $err", 502);
    $j = json_decode($txt, true);
    if (!is_array($j)) fail("Respon Meta tidak valid (HTTP $st)", 502);
    if (isset($j['paging'])) {   // jangan kirim URL "next" (berisi token) ke browser — cukup cursor-nya
        $j['next_after'] = isset($j['paging']['next']) ? ($j['paging']['cursors']['after'] ?? null) : null;
        unset($j['paging']);
    }
    out($j);
}

/* ---------- 1b. BANYAK request sekaligus (paralel di server) → sync 20 akun jauh lebih cepat ---------- */
if ($action === 'multi') {
    $reqs = $in['requests'] ?? [];
    if (!is_array($reqs) || count($reqs) > 300) fail('Request tidak valid');
    $results = array_fill(0, count($reqs), null);
    $data = []; $queue = [];
    foreach (array_values($reqs) as $i => $r) {
        $tok = acc_token($C, (string)($r['acc'] ?? ''));
        $path = (string)($r['path'] ?? '');
        if (!$tok) { $results[$i] = ['error' => ['message' => 'Token Meta kosong untuk akun ini — isi di config.php atau env var META_TOKEN_PREPARE / META_TOKEN_SKINLYFE']]; continue; }
        if (!preg_match('#^[A-Za-z0-9_/]+$#', $path)) { $results[$i] = ['error' => ['message' => 'Path tidak valid']]; continue; }
        $q = [];
        foreach (($r['params'] ?? []) as $k => $v) $q[$k] = is_array($v) ? json_encode($v) : (string)$v;
        $q['access_token'] = $tok;
        $data[$i] = [];
        $queue[] = [$i, "https://graph.facebook.com/{$C['graph_version']}/$path?" . http_build_query($q)];
    }
    $mh = curl_multi_init();
    $active = [];
    $MAX = 20;   // jumlah koneksi paralel ke Meta
    while ($queue || $active) {
        while ($queue && count($active) < $MAX) {
            [$i, $url] = array_shift($queue);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_ENCODING => '', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = $i;
        }
        do { $st = curl_multi_exec($mh, $running); } while ($st === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $i = $active[spl_object_id($ch)];
            unset($active[spl_object_id($ch)]);
            $txt = curl_multi_getcontent($ch);
            $err = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
                    $j = $err ? null : json_decode((string)$txt, true);
            if (!is_array($j)) { $results[$i] = ['error' => ['message' => $err ?: 'Respon Meta tidak valid']]; continue; }
            if (isset($j['error'])) { $results[$i] = ['error' => $j['error']]; continue; }
            if (isset($j['data']) && is_array($j['data'])) {
                foreach ($j['data'] as $row) $data[$i][] = $row;
                if (!empty($j['paging']['next']) && count($data[$i]) < 60000) { $queue[] = [$i, $j['paging']['next']]; continue; }   // halaman berikutnya
                $results[$i] = ['data' => $data[$i]];
            } else {
                $results[$i] = ['obj' => $j];
            }
        }
        if ($active) curl_multi_select($mh, 0.5);
    }
    curl_multi_close($mh);
    out(['results' => $results]);
}

/* ---------- 2. Ubah status / budget di Meta ---------- */
if ($action === 'post') {
    $tok = acc_token($C, (string)($in['acc'] ?? ''));
    $id  = (string)($in['id'] ?? '');
    if (!$tok || !preg_match('/^\d+$/', $id)) fail('Akun / ID tidak valid');
    $f = [];
    if (isset($in['status']) && in_array($in['status'], ['ACTIVE', 'PAUSED'], true)) $f['status'] = $in['status'];
    if (isset($in['daily_budget']) && ctype_digit((string)$in['daily_budget'])) $f['daily_budget'] = (string)$in['daily_budget'];
    if (!$f) fail('Tidak ada perubahan yang diizinkan');
    $f['access_token'] = $tok;
    [$st, $txt, $err] = http_req('POST', "https://graph.facebook.com/{$C['graph_version']}/$id", http_build_query($f), ['Content-Type: application/x-www-form-urlencoded']);
    if ($err) fail("Koneksi ke Meta gagal: $err", 502);
    unset($f['access_token']);
    @mkdir(__DIR__ . '/logs', 0750, true);
    @file_put_contents(__DIR__ . '/logs/actions.log', json_encode(['t' => date('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'acc' => $in['acc'], 'id' => $id, 'set' => $f, 'meta' => json_decode($txt, true)]) . "\n", FILE_APPEND | LOCK_EX);
    out(json_decode($txt, true) ?: ['error' => ['message' => "Respon Meta tidak valid (HTTP $st)"]]);
}

/* ---------- 3. AI analis ---------- */
if ($action === 'ai') {
    $sys  = (string)($in['system'] ?? '');
    $msgs = array_values(array_filter($in['messages'] ?? [], fn($m) => is_array($m) && isset($m['role'], $m['content'])));
    if (!$msgs) fail('Pesan kosong');
    $A = $C['ai']; $P = $A['provider'] ?? 'gemini';

    if ($P === 'gemini') {
        $g = $A['gemini'];
        if (($g['api_key'] ?? '') === '') fail('Montera AI belum aktif: API key belum diisi di config.php (ai → gemini → api_key).');
        $models = array_values(array_unique(array_merge([$g['model'] ?? 'gemini-flash-latest'], $g['fallback'] ?? [])));
        if (($in['mode'] ?? '') === 'fast') {   // mode Cepat: langsung model Flash (±5–10 detik)
            $models = array_values(array_filter($models, fn($m) => stripos($m, 'pro') === false)) ?: ['gemini-flash-latest'];
        }
        $body = json_encode([
            'systemInstruction' => ['parts' => [['text' => $sys]]],
            'contents' => array_map(fn($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string)$m['content']]]], $msgs),
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2, 'maxOutputTokens' => 16384],
        ]);
        $last = '';
        foreach ($models as $model) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                [$st, $txt, $err] = http_req('POST', "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . urlencode($g['api_key']), $body, ['Content-Type: application/json', 'x-goog-api-key: ' . $g['api_key']], 120);
                if ($err) { $last = $err; sleep(1); continue; }
                $j = json_decode($txt, true) ?: [];
                if (empty($j['error'])) {
                    $text = implode('', array_map(fn($p) => $p['text'] ?? '', $j['candidates'][0]['content']['parts'] ?? []));
                    if ($text !== '') out(['text' => $text, 'model' => $model]);
                    $last = 'Jawaban kosong'; continue;
                }
                $last = $j['error']['message'] ?? "HTTP $st";
                if ($st === 400 || $st === 403) fail($last);   // key salah → jangan diulang
                if ($st === 404) break;                         // model tidak ada → model berikutnya
                sleep($st === 429 ? 4 : 2 * ($attempt + 1));    // 503 high demand / 429 limit → tunggu
            }
        }
        fail("Montera AI sedang sibuk ($last). Coba 1–2 menit lagi.", 503);
    }

    if ($P === 'ollama') {
        $o = $A['ollama'];
        $body = json_encode(['model' => $o['model'], 'stream' => false, 'format' => 'json', 'options' => ['temperature' => 0.2, 'num_ctx' => 32768],
            'messages' => array_merge([['role' => 'system', 'content' => $sys]], $msgs)]);
        [$st, $txt, $err] = http_req('POST', rtrim($o['url'], '/') . '/api/chat', $body, ['Content-Type: application/json'], 170);
        if ($err) fail("Ollama tidak bisa dihubungi ($err). Jalankan: ollama serve", 502);
        $j = json_decode($txt, true) ?: [];
        if (!empty($j['error'])) fail((string)$j['error']);
        out(['text' => $j['message']['content'] ?? '', 'model' => $o['model']]);
    }

    if ($P === 'claude') {
        $c = $A['claude'];
        if (($c['api_key'] ?? '') === '') fail('API key Claude belum diisi di config.php.');
        $body = json_encode(['model' => $c['model'], 'max_tokens' => 4000, 'system' => $sys, 'messages' => $msgs]);
        [$st, $txt, $err] = http_req('POST', 'https://api.anthropic.com/v1/messages', $body, ['Content-Type: application/json', 'x-api-key: ' . $c['api_key'], 'anthropic-version: 2023-06-01'], 120);
        if ($err) fail("Koneksi ke Claude gagal: $err", 502);
        $j = json_decode($txt, true) ?: [];
        if (!empty($j['error'])) fail($j['error']['message'] ?? 'Claude error');
        $text = implode('', array_map(fn($b) => $b['text'] ?? '', array_filter($j['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text')));
        out(['text' => $text, 'model' => $c['model']]);
    }
    fail('Provider AI tidak dikenal');
}

fail('Action tidak dikenal', 404);