<?php
return [
    'db' => [
        'host' => env('DB_HOST', 'sql210.infinityfree.com'),
        'port' => env('DB_PORT', '3306'),
        'name' => env('DB_DATABASE', 'if0_40099794_lotus'),
        'user' => env('DB_USERNAME', 'if0_40099794'),
        'pass' => env('DB_PASSWORD', '9SOmvbgTT6u')
    ],
    'upload_dir' => env('UPLOAD_DIR', '/home/vol7_8/infinityfree.com/if0_40099794/htdocs/storage/uploads'),
    'session_name' => env('SESSION_NAME', 'finance_sid'),
    'session_lifetime' => env('SESSION_LIFETIME', 1200), // 20分

    // レンタルサーバー用の追加設定
    'max_upload_size' => env('MAX_UPLOAD_SIZE', 5 * 1024 * 1024), // 5MB (レンタルサーバーの制限に合わせる)
    'allowed_file_types' => env('ALLOWED_FILE_TYPES') ? explode(',', env('ALLOWED_FILE_TYPES')) : ['jpg', 'jpeg', 'png', 'xlsx', 'xls'],
];