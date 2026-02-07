<?php
// force_charset_fix.php
// データベースとテーブルの文字コードを強制的に utf8mb4_unicode_ci に変換するスクリプト

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/app/lib/env.php';
load_env(__DIR__ . '/.env');
require_once __DIR__ . '/app/lib/db.php';

function log_msg($msg, $is_error = false)
{
    $class = $is_error ? 'error' : 'success';
    echo "<div class='log $class'>" . htmlspecialchars($msg) . "</div>";
    flush();
}

?>
<!DOCTYPE html>
<html>

<head>
    <title>Force Charset Fix</title>
    <style>
        body {
            font-family: monospace;
            background: #f0f0f0;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .log {
            padding: 5px 10px;
            border-bottom: 1px solid #eee;
        }

        .error {
            color: red;
            background: #ffeeee;
        }

        .success {
            color: green;
        }

        h1 {
            margin-top: 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Database Charset Fixer</h1>
        <?php

        try {
            $pdo = db();
            log_msg("Connected to database.");

            // データベース名を取得
            $stmt = $pdo->query('SELECT DATABASE()');
            $dbName = $stmt->fetchColumn();
            log_msg("Target Database: $dbName");

            // データベース自体のデフォルト文字コードを変更
            log_msg("Converting database default charset...");
            $sql = "ALTER DATABASE `$dbName` CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci";
            try {
                $pdo->exec($sql);
                log_msg("Database default charset updated to utf8mb4_unicode_ci.");
            } catch (PDOException $e) {
                log_msg("Failed to update database default charset: " . $e->getMessage(), true);
            }

            // テーブル一覧を取得
            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($tables)) {
                log_msg("No tables found.");
            } else {
                foreach ($tables as $table) {
                    log_msg("Converting table `$table`...");

                    // テーブルのデフォルト文字セットと照合順序を変更
                    // CONVERT TO はカラムの型も変換する
                    $sql = "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

                    try {
                        $pdo->exec($sql);
                        log_msg("Table `$table` converted successfully.");
                    } catch (PDOException $e) {
                        log_msg("Failed to convert table `$table`: " . $e->getMessage(), true);
                    }
                }
            }

            log_msg("All operations completed.");
            log_msg("Please delete this file immediately after verification.");

        } catch (Exception $e) {
            log_msg("Critical Error: " . $e->getMessage(), true);
        }
        ?>
    </div>
</body>

</html>