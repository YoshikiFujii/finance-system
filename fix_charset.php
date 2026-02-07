<?php
// fix_charset.php
// 既存のテーブルの文字コードを utf8mb4_unicode_ci に変換するスクリプト

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/app/lib/env.php';
load_env(__DIR__ . '/.env');
require_once __DIR__ . '/app/lib/db.php';

try {
    $pdo = db();
    echo "Connected to database.\n";

    // データベース名を取得
    $stmt = $pdo->query('SELECT DATABASE()');
    $dbName = $stmt->fetchColumn();
    echo "Database: $dbName\n\n";

    // テーブル一覧を取得
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "No tables found.\n";
        exit;
    }

    foreach ($tables as $table) {
        echo "Converting table `$table`... ";

        // テーブルのデフォルト文字セットと照合順序を変更
        $sql = "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

        try {
            $pdo->exec($sql);
            echo "DONE\n";
        } catch (PDOException $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    }

    echo "\nAll conversions completed.\n";
    echo "Please delete this file after use for security reasons.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
