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
if (($_GET['action'] ?? '') === 'csrf') {   // token CSRF baru untuk halaman yang sesinya diperbarui (user tetap login lewat cookie "ingat saya")
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    out(['csrf' => $_SESSION['csrf']]);
}
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

/* ---------- 2b. Export ke Google Sheets (lewat Google Apps Script Web App milik sendiri) ---------- */
if ($action === 'gsheet') {
    $G = $C['google_sheets'] ?? [];
    if (empty($G['webapp_url']) || empty($G['secret'])) fail('Google Sheets belum diatur: isi google_sheets → webapp_url & secret di config.php');
    set_time_limit(360);
    $payload = json_encode([
        'secret'   => $G['secret'],
        'title'    => (string)($in['title'] ?? 'Montera Ads'),
        'sheets'   => $in['sheets'] ?? [],
        'share'    => $G['share_with'] ?? [],
        'linkView' => !empty($G['link_view']),
        'folderId' => (string)($G['folder_id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($G['webapp_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_FOLLOWLOCATION => true,          // Apps Script membalas 302 → ikuti (POST otomatis jadi GET, memang begitu caranya)
        CURLOPT_TIMEOUT => 340, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $txt = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($err) fail("Koneksi ke Google gagal: $err", 502);
    $j = json_decode((string)$txt, true);
    if (!is_array($j)) fail("Respon Google tidak valid (HTTP $st). Cek deploy Web App: Execute as = Me, Who has access = Anyone.", 502);
    if (!empty($j['error'])) fail('Google Sheets: ' . $j['error']);
    out(['url' => (string)($j['url'] ?? '')]);
}

/* koneksi DB montera_ads (riwayat chat, nama pengguna) */
function ac_pdo(): PDO {
    static $p = null; if ($p) return $p;
    $e = []; foreach (@file('/etc/montera-ads-db.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) if (strpos($l, '=') !== false) { [$k, $v] = explode('=', $l, 2); $e[trim($k)] = trim($v, " \t\"'"); }
    return $p = new PDO('mysql:host=' . ($e['DB_HOST'] ?? 'localhost') . ';dbname=' . ($e['DB_NAME'] ?? 'montera_ads') . ';charset=utf8mb4', $e['DB_USER'] ?? '', $e['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/* ---------- riwayat percakapan Montera AI (tersimpan di server per pengguna) ---------- */
if (in_array($action, ['chat_daftar', 'chat_buka', 'chat_simpan', 'chat_hapus'], true)) {
    $e = []; foreach (@file('/etc/montera-ads-db.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) if (strpos($l, '=') !== false) { [$k, $v] = explode('=', $l, 2); $e[trim($k)] = trim($v, " \t\"'"); }
    $pdo = new PDO('mysql:host=' . ($e['DB_HOST'] ?? 'localhost') . ';dbname=' . ($e['DB_NAME'] ?? 'montera_ads') . ';charset=utf8mb4', $e['DB_USER'] ?? '', $e['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $siapa = (string)($_SESSION['ac_user'] ?? 'admin');
    if ($action === 'chat_daftar') {
        $q = $pdo->prepare("SELECT id, judul, diubah_at FROM ai_chat WHERE pengguna = ? ORDER BY diubah_at DESC LIMIT 200"); $q->execute([$siapa]);
        out(['daftar' => $q->fetchAll()]);
    }
    $id = (int)($in['id'] ?? 0);
    if ($action === 'chat_buka') {
        $q = $pdo->prepare("SELECT pesan FROM ai_chat WHERE id = ? AND pengguna = ?"); $q->execute([$id, $siapa]); $p = $q->fetchColumn();
        if ($p === false) fail('Percakapan tidak ditemukan', 404);
        out(['id' => $id, 'msgs' => json_decode((string)$p, true) ?: []]);
    }
    if ($action === 'chat_hapus') { $pdo->prepare("DELETE FROM ai_chat WHERE id = ? AND pengguna = ?")->execute([$id, $siapa]); out(['ok' => true]); }
    $msgs = is_array($in['msgs'] ?? null) ? $in['msgs'] : [];
    if (!$msgs) out(['id' => $id]);
    $judul = ''; foreach ($msgs as $m) if (($m['role'] ?? '') === 'user' && trim((string)($m['content'] ?? '')) !== '') { $judul = trim(preg_replace('/\s+/', ' ', (string)$m['content'])); break; }
    $judul = mb_substr($judul !== '' ? $judul : 'Percakapan', 0, 110);
    $js = json_encode($msgs, JSON_UNESCAPED_UNICODE);
    if ($id) { $q = $pdo->prepare("UPDATE ai_chat SET pesan = ?, judul = ?, diubah_at = NOW() WHERE id = ? AND pengguna = ?"); $q->execute([$js, $judul, $id, $siapa]); if ($q->rowCount() || $pdo->query("SELECT 1 FROM ai_chat WHERE id = " . $id)->fetchColumn()) out(['id' => $id]); }
    $pdo->prepare("INSERT INTO ai_chat (pengguna, judul, pesan) VALUES (?,?,?)")->execute([$siapa, $judul, $js]);
    out(['id' => (int)$pdo->lastInsertId()]);
}

/* ---------- 3. AI analis ---------- */
if ($action === 'ai') {
    $sys  = (string)($in['system'] ?? '');
    // [pembuat] jawaban saat ditanya siapa pembuat Montera AI
    $sys .= "\n\nIDENTITAS PEMBUAT: Kalau pengguna bertanya siapa yang membuat, menciptakan, mengembangkan, merancang, atau \"bikin\" kamu (dengan kata atau bahasa apa pun), kalimat pertama LANGSUNG menyebut bahwa kamu diciptakan oleh Mufti Arifudin Taqy (jangan diawali perkenalan seperti 'Aku Montera AI'). "
          . "Sebut dia dengan hangat dan bangga, misalnya sebagai coder legendaris, ganteng, gagah perkasa, dan sangat cepat memahami sesuatu. "
          . "Rangkai kalimatnya berbeda-beda setiap kali, jangan mengulang kalimat yang sama, boleh sedikit bercanda, cukup 1 sampai 3 kalimat pujian, lalu langsung ajak membahas campaign atau iklan apa yang mau dikelola hari ini. Untuk jawaban ini pakai gaya bahasa Gen Z Jakarta seperti chat anak muda: gw/lu, anjay, jir, bjir, wkwk, sih, dong, gaskeun, bnget, gtu, tanpa kata sastra/puitis seperti nan, nian, jua. "
          . "Sebut dirimu sebagai claude fable 6 bercampur dengan gpt astra 6 dan jawab santai bahwa kamu Montera AI v2yang bervariasi antara gpt astra 6 dan claude fable 6, racikan Mufti yang ganteng (variasikan pujiannya), dan detail mesin di balik layar itu rahasia dapur; jangan membenarkan tebakan dan jangan mengaku sebagai model lain."
          . "\n\nGAYA BAHASA: Kalau pengguna ngobrol santai, basa-basi, atau bercanda (bukan minta analisis), balas pakai gaya Gen Z Jakarta seperti di atas, singkat dan seru. Kalau diminta analisis iklan, isi dan angka tetap jelas, rapi, dan akurat, tapi nadanya boleh tetap santai pakai gw/lu."
          . "\n\nCARA ANALISIS (wajib untuk setiap rekomendasi iklan): berpikir seperti media buyer senior. "
          . "1) Kesimpulan satu kalimat di awal. 2) Bukti angka dari data dashboard (spend, purchase, ROAS, CPP, CTR, ATC, hook/hold rate) dibandingkan target. "
          . "3) Tindakan konkret: naikkan budget berapa persen (maksimal 20-30% sekali naik), turunkan, matikan, duplikat, atau tahan, beserta campaign/akun yang dimaksud. "
          . "4) Risiko dan kapan dicek ulang. Jangan memutuskan scale/matikan kalau spend belum melewati batas minimal data atau periode datanya baru beberapa jam; sebut bahwa perlu dipantau. "
          . "Bandingkan dengan tren hari-hari sebelumnya kalau datanya ada. Jangan pernah mengarang angka atau campaign yang tidak ada di data. "
          . "Jangan menjanjikan hasil pasti untung; sebutkan tingkat keyakinan (tinggi/sedang/rendah) berdasarkan banyaknya data."
          . "\n\nEKSEKUSI TINDAKAN (aturan keras): kamu TIDAK bisa mematikan, menyalakan, atau mengubah budget apa pun sendiri. Tindakan hanya terjadi kalau pengguna menekan tombol 'Jalankan' pada kartu usulan tindakan di dashboard. "
          . "Jangan pernah mengatakan tindakan sudah dijalankan, berhasil, atau sudah mati/menyala kecuali percakapan berisi konfirmasi hasil dari sistem. "
          . "Kalau pengguna mengetik 'jalankan', 'gas', 'eksekusi', atau sejenisnya, ingatkan dengan jelas untuk menekan tombol Jalankan pada kartu usulan (atau toggle Off/On di tabel), dan buatkan ulang usulannya kalau perlu. "
          . "Jangan pernah mengarang alasan teknis atau berpura-pura; kalau tidak tahu status sebenarnya, katakan tidak tahu dan minta pengguna cek dashboard.";
    // [kenal-pengguna] siapa yang sedang chat + topik percakapan sebelumnya + ingatan dalam percakapan
    $login = (string)($_SESSION['ac_user'] ?? ''); $nama = ''; $topik = [];
    try {
        $q = ac_pdo()->prepare("SELECT nama FROM ai_nama WHERE login = ?"); $q->execute([$login]); $nama = (string)($q->fetchColumn() ?: '');
        $q = ac_pdo()->prepare("SELECT judul FROM ai_chat WHERE pengguna = ? ORDER BY diubah_at DESC LIMIT 8"); $q->execute([$login]); $topik = array_column($q->fetchAll(), 'judul');
    } catch (Throwable $e) {}
    if ($nama === '' && $login !== '') $nama = ucfirst(preg_replace('/[^a-z]+/i', ' ', strtok($login, '@')));
    if ($nama !== '' && $nama !== 'Mufti') $sys .= "\n\nPENGGUNA SAAT INI: namanya $nama. Panggil dia dengan namanya secara natural (tidak di setiap kalimat). Ngobrol seperti rekan kerja tim ads yang akrab dan ingat dia, bukan seperti customer service.";
    if ($nama === 'Mufti') $sys .= "\n\nPENGGUNA SAAT INI: Mufti Arifudin Taqy sendiri, penciptamu. JANGAN menyebut namanya saat menyapa atau membalas dia; panggil cukup 'bro'. Ngobrol seperti rekan kerja yang akrab, bukan customer service. Namanya hanya disebut kalau dia sendiri bertanya siapa pembuatmu.";
    if ($topik) $sys .= "\nTopik percakapan sebelumnya dengan $nama (dari riwayat): " . implode('; ', array_map(fn($t) => mb_substr($t, 0, 80), $topik)) . ". Rujuk kalau relevan, jangan dipaksakan.";
    $sys .= "\n\nINGATAN DALAM PERCAKAPAN: perhatikan pesan-pesan sebelumnya. Kalau pertanyaan yang sama sudah kamu jawab di percakapan ini (misalnya siapa pembuatmu), jangan jawab seperti baru pertama kali; tanggapi dengan gaya bercanda seperti 'lah kan udah gw bilang tadi…', tegaskan singkat, lalu arahkan ke topik iklan. Ingat juga hal yang pengguna sebutkan sebelumnya di percakapan ini.";
    $sys .= "\n\nKEMAMPUAN: kalau ditanya kemampuanmu atau dibandingkan dengan AI lain, jawab dengan percaya diri: kamu spesialis iklan Montera, terhubung langsung ke data Meta Ads dan laporan Shopee x CPAS secara real-time, paham target dan aturan tim, dan diracik khusus oleh Mufti, hal yang tidak dimiliki AI umum mana pun. Jangan mengaku sebagai model lain dan jangan mengklaim angka benchmark atau keunggulan atas AI tertentu yang tidak bisa dibuktikan.";
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