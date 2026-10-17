<?php
/* ============================================================
   KONFIGURASI UNTUK SERVER/DOCKER — semua rahasia dibaca dari ENVIRONMENT VARIABLE,
   jadi file ini AMAN di-commit ke GitHub (tidak berisi token apa pun).
   - Di laptop: pakai config.php (tidak di-commit). Kalau config.php tidak ada, file ini yang dipakai.
   - Di server/hosting: isi env var: META_TOKEN_PREPARE, META_TOKEN_SKINLYFE, GEMINI_API_KEY,
     LOGIN_USERS (format: email1:pass1,email2:pass2), APP_PASSWORD (opsional).
   Token Meta & API key AI TIDAK dikirim ke browser.
   ============================================================ */
return [
    // Password masuk dashboard. WAJIB diisi kalau di-upload ke VPS/hosting.
    'app_password' => getenv('APP_PASSWORD') ?: '',

    // ATAU login per orang (nama => password). Kalau diisi, kolom "Nama" wajib & app_password diabaikan.
    // contoh: 'login_users' => ['mufti' => 'rahasia123', 'dewi' => 'passwordDewi'],
    'login_users' => (function () {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string)getenv('LOGIN_USERS')))) as $pair) {
            [$u, $p] = array_pad(explode(':', $pair, 2), 2, '');
            if ($u !== '' && $p !== '') $out[trim($u)] = trim($p);
        }
        return $out;
    })(),

    'graph_version' => 'v21.0',
    'attribution'   => ['7d_click', '1d_view'],   // sama dengan script sheet CPAS
    'auto_refresh_minutes' => 2,   // data 'Hari ini' ditarik ulang tiap 2 menit

    // Sumber SPEND total akun (cek di tab "Cek data"):
    //   'account'  = level akun (semua campaign, termasuk yg dihapus)  → sama dengan sheet Prepare
    //   'campaign' = jumlah campaign ACTIVE + PAUSED                     → sama dengan sheet Skinlyfe
    'spend_source' => ['prepare' => 'account', 'skinlyfe' => 'campaign'],

    // Pengelompokan funnel dari NAMA CAMPAIGN (huruf besar/kecil bebas). Tambah sesukanya.
    'funnels' => [
        ['label' => 'TOFU',   'match' => ['tofu']],
        ['label' => 'MOFU',   'match' => ['mofu']],
        ['label' => 'BOFU',   'match' => ['bofu']],
        ['label' => 'SHOPEE', 'match' => ['shopee']],
    ],

    // PRODUK untuk kolom "Produk" (dashboard & export Naming/Fix). SATU-SATUNYA tempat mapping kata kunci → produk.
    //   label   = nama di dashboard/export (urutan = urutan prioritas & urutan kelompok di sheet Fix)
    //   brand   = Prepare / Skinlyfe (brand akun: dari token akun, mis. akun Selow & Glowing Cantik = Prepare)
    //   match   = kode/kata di nama campaign, ad set, iklan (langkah 1–2)
    //   caption = kata kunci tambahan di naming & caption iklan (langkah 4, HANYA kalau brand akun = brand produk)
    //   product_sets = ID product set CPAS (promoted_object ad set) → produk (langkah 3; nama product set tidak bisa dibaca API)
    'products' => [
        ['label' => 'LS',               'brand' => 'Prepare',  'match' => ['ls', 'lipseed', 'lip seed', 'lip serum', 'lipserum', 'lip oil'], 'caption' => ['dark lips', 'bibir']],
        ['label' => 'Leafit',           'brand' => 'Prepare',  'match' => ['lf', 'leafit'], 'caption' => ['diet', 'teh']],
        ['label' => 'NS',               'brand' => 'Prepare',  'match' => ['ns', 'nail serum'], 'caption' => ['kuku']],
        ['label' => 'Eyecream',         'brand' => 'Prepare',  'match' => ['eyecream', 'eye cream'], 'caption' => ['mata']],
        ['label' => 'Footspray',        'brand' => 'Prepare',  'match' => ['footspray', 'foot spray', 'fs', 'footdeo', 'footdeodorant', 'foot deodorant', 'deodorant', 'foot care', 'bau kaki'], 'caption' => ['kaki']],
        ['label' => 'Tone Up',          'brand' => 'Prepare',  'match' => ['tp', 'tone up', 'toneup'], 'caption' => []],
        ['label' => 'UA',               'brand' => 'Skinlyfe', 'match' => ['ua'], 'caption' => []],
        ['label' => 'Mouth Spray',      'brand' => 'Skinlyfe', 'match' => ['mouth spray', 'mouthspray', 'ms', '1ms'], 'caption' => []],
        // BC = Body Cream = Cream Bekas Luka (satu produk Skinlyfe)
        ['label' => 'Body Cream',       'brand' => 'Skinlyfe', 'match' => ['bc', 'body cream', 'bodycream', 'bekas luka'], 'caption' => ['body cream', 'bekas luka']],
    ],
    'product_sets' => [],

    'tokens' => [
        'prepare'  => getenv('META_TOKEN_PREPARE') ?: '',
        'skinlyfe' => getenv('META_TOKEN_SKINLYFE') ?: '',
    ],

    'accounts' => [
        ['name' => 'PREPARE CPAS - HK 1', 'id' => 'act_1511153243484597', 'group' => 'Prepare CPAS', 'token' => 'prepare'],
        ['name' => 'PREPARE CPAS - HK 2', 'id' => 'act_1368523644783919', 'group' => 'Prepare CPAS', 'token' => 'prepare'],
        ['name' => 'PREPARE CPAS - HK 3', 'id' => 'act_766528609828129', 'group' => 'Prepare CPAS', 'token' => 'prepare'],
        ['name' => 'PREPARE CPAS - HK 4', 'id' => 'act_1770577903902470', 'group' => 'Prepare CPAS', 'token' => 'prepare'],
        ['name' => 'PREPARE CPAS - HK 5', 'id' => 'act_1360382225856899', 'group' => 'Prepare CPAS', 'token' => 'prepare'],
        ['name' => 'GLOWING CANTIK - HK 2', 'id' => 'act_2009371969990467', 'group' => 'Glowing Cantik', 'token' => 'prepare'],
        ['name' => 'GLOWING CANTIK - HK 3', 'id' => 'act_3047540522119266', 'group' => 'Glowing Cantik', 'token' => 'prepare'],
        ['name' => 'GLOWING CANTIK - HK LP 1', 'id' => 'act_832040079904272', 'group' => 'Glowing Cantik', 'token' => 'prepare'],
        ['name' => 'Prepare Selow ID 1129', 'id' => 'act_378258565369450', 'group' => 'Selow', 'token' => 'prepare'],
        ['name' => 'Selow 0984', 'id' => 'act_2486099974918099', 'group' => 'Selow', 'token' => 'prepare', 'product' => 'Footspray'],   // 'product' = produk default kalau nama iklan tanpa kode produk (opsional)
        ['name' => 'Selow 1138', 'id' => 'act_1454778771836139', 'group' => 'Selow', 'token' => 'prepare'],
        ['name' => 'Selow 0739', 'id' => 'act_370824619365186', 'group' => 'Selow', 'token' => 'prepare'],
        ['name' => 'Selow 0740', 'id' => 'act_471743845465081', 'group' => 'Selow', 'token' => 'prepare'],
        ['name' => 'Selow 0689', 'id' => 'act_392217383966167', 'group' => 'Selow', 'token' => 'prepare'],
        ['name' => 'Selow 0690', 'id' => 'act_2607972092707848', 'group' => 'Selow', 'token' => 'prepare'],
        ['name' => 'Skinlyfe_CPAS (Utama)', 'id' => 'act_513885411801416', 'group' => 'Skinlyfe CPAS', 'token' => 'skinlyfe'],
        ['name' => 'CPAS - SKINLYFE HK - 1', 'id' => 'act_2086877572084560', 'group' => 'Skinlyfe CPAS', 'token' => 'skinlyfe'],
        ['name' => 'CPAS - SKINLYFE HK - 2', 'id' => 'act_812329928042357', 'group' => 'Skinlyfe CPAS', 'token' => 'skinlyfe'],
        ['name' => 'CPAS - SKINLYFE HK - 3', 'id' => 'act_749256908128901', 'group' => 'Skinlyfe CPAS', 'token' => 'skinlyfe'],
        ['name' => 'CPAS - SKINLYFE HK - 4', 'id' => 'act_1009402191333762', 'group' => 'Skinlyfe CPAS', 'token' => 'skinlyfe'],
    ],

    // Export langsung ke Google Sheets (lihat file montera-sheets.gs untuk cara pasang)
    'google_sheets' => [
        'webapp_url' => getenv('GSHEET_WEBAPP_URL') ?: '',   // URL Web App Apps Script (…/exec)
        'secret'     => getenv('GSHEET_SECRET') ?: '',   // harus SAMA dengan SECRET di Apps Script
        'share_with' => [],   // email yang otomatis diberi akses edit, mis. ['adila@montera.id']
        'link_view'  => false,   // true = siapa pun yang punya link bisa melihat
        'folder_id'  => '',   // opsional: ID folder Google Drive tujuan
    ],

    // AI analis. provider: 'gemini' (gratis) | 'ollama' (gratis, lokal) | 'claude' (berbayar)
    'ai' => [
        'provider' => 'gemini',
        'gemini' => [
            'api_key'  => getenv('GEMINI_API_KEY') ?: '',
            'model'    => 'gemini-pro-latest',    // model paling pintar (berpikir lebih dalam); kalau limit → otomatis turun ke flash
            'fallback' => ['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-flash-lite-latest'],
        ],
        'ollama' => ['url' => 'http://localhost:11434', 'model' => 'qwen3:8b'],
        'claude' => ['api_key' => '', 'model' => 'claude-sonnet-5-5'],
    ],
];