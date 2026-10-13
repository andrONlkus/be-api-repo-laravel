<?php

return [

    // Disk penyimpanan file arsip (config/filesystems.php)
    'disk' => 'arsip',

    // Ekstensi file yang boleh diupload lewat API arsip umum
    'allowed_types' => array_values(array_filter(array_map('trim', explode(',', env(
        'ARSIP_ALLOWED_TYPES', 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx,ppt,pptx'
    ))))),

    /*
    | API key untuk komunikasi antar server, format: "key1:nama-app,key2:nama-app".
    | Buat key: php -r 'echo bin2hex(random_bytes(20));'
    | Alternatif: token Sanctum per aplikasi (php artisan arsip:client-create).
    */
    'api_keys' => collect(explode(',', (string) env('ARSIP_API_KEYS', '')))
        ->map(fn ($pair) => array_map('trim', explode(':', $pair, 2)))
        ->filter(fn ($pair) => count($pair) === 2 && $pair[0] !== '' && $pair[1] !== '')
        ->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]])
        ->all(),

    // User DMS yang dicatat sebagai pembuat/pengubah bila tidak dikirim (repository@prasetiyamulya.ac.id)
    'default_user' => (int) env('ARSIP_DEFAULT_USER', 819),

    /*
    | Endpoint lama (Ijazah & Transkrip): nilai tetap dari CodeIgniter.
    | base_url: awalan URL download di ListOfFiles. Kosong = APP_URL request ini.
    */
    'legacy' => [
        'base_url' => env('ARSIP_LEGACY_BASE_URL'),
        'categories' => ['SBE' => 1075, 'STEM' => 1076],
        'departement' => 12,
        'tipefile' => 8,
    ],
];
