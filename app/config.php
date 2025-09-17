<?php
return [
'db' => [
'host' => getenv('DB_HOST') ?: '127.0.0.1',
'port' => getenv('DB_PORT') ?: '3306',
'name' => getenv('DB_NAME') ?: 'finance_db',
'user' => getenv('DB_USER') ?: 'root',
'pass' => getenv('DB_PASS') ?: ''
],
'upload_dir' => __DIR__ . '/../..//storage/uploads',
'session_name' => 'finance_sid',
'session_lifetime' => 1200, // 20分
];