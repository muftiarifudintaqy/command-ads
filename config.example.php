<?php
/* ============================================================
   CONTOH KONFIGURASI — copy jadi config.php lalu isi token & API key.
   config.php TIDAK boleh di-commit ke GitHub (sudah ada di .gitignore).
   Token Meta & API key AI TIDAK dikirim ke browser.
   ============================================================ */
return [
    // Password masuk dashboard. WAJIB diisi kalau di-upload ke VPS/hosting.
    'app_password' => '',

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

    'tokens' => [
        'prepare' => 'ISI_TOKEN_META_PREPARE',
        'skinlyfe' => 'ISI_TOKEN_META_SKINLYFE',
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
        ['name' => 'Selow 0984', 'id' => 'act_2486099974918099', 'group' => 'Selow', 'token' => 'prepare'],
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

    // AI analis. provider: 'gemini' (gratis) | 'ollama' (gratis, lokal) | 'claude' (berbayar)
    'ai' => [
        'provider' => 'gemini',
        'gemini' => [
            'api_key'  => '',
            'model'    => 'gemini-pro-latest',    // model paling pintar (berpikir lebih dalam); kalau limit → otomatis turun ke flash
            'fallback' => ['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-flash-lite-latest'],
        ],
        'ollama' => ['url' => 'http://localhost:11434', 'model' => 'qwen3:8b'],
        'claude' => ['api_key' => '', 'model' => 'claude-sonnet-5-5'],
    ],
];
