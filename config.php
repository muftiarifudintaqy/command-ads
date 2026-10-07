<?php
/* KONFIGURASI ADS COMMAND — versi Render/Docker.
   Secret (token Meta, API key, password login) dibaca dari Environment variables.
   File ini aman di-commit karena tidak berisi secret. */
$j = fn(string $k) => json_decode(getenv($k) ?: '', true) ?: [];

return [
    'app_password' => getenv('APP_PASSWORD') ?: '',
    'login_users'  => $j('LOGIN_USERS'),   // {"adila@montera.id":"...","admin@montera.id":"...","natasya@montera.id":"..."}

    'graph_version' => 'v21.0',
    'attribution'   => ['7d_click', '1d_view'],
    'auto_refresh_minutes' => 2,

    'spend_source' => ['prepare' => 'account', 'skinlyfe' => 'campaign'],

    'funnels' => [
        ['label' => 'TOFU',   'match' => ['tofu']],
        ['label' => 'MOFU',   'match' => ['mofu']],
        ['label' => 'BOFU',   'match' => ['bofu']],
        ['label' => 'SHOPEE', 'match' => ['shopee']],
    ],

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

    'ai' => [
        'provider' => 'gemini',
        'gemini' => [
            'api_key'  => getenv('GEMINI_API_KEY') ?: '',
            'model'    => 'gemini-pro-latest',
            'fallback' => ['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-flash-lite-latest'],
        ],
        'ollama' => ['url' => 'http://localhost:11434', 'model' => 'qwen3:8b'],   // tidak jalan di Render
        'claude' => ['api_key' => getenv('ANTHROPIC_API_KEY') ?: '', 'model' => 'claude-sonnet-5-5'],
    ],
];