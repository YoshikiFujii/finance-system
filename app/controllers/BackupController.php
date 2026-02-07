<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/util.php';

class BackupController
{

    // バックアップ一覧取得
    static function list()
    {
        require_role(['ADMIN']);

        $backup_dir = __DIR__ . '/../../backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }

        $backups = [];
        $files = glob($backup_dir . '/lotus_*.sql');

        foreach ($files as $file) {
            $filename = basename($file);
            $stat = stat($file);

            // ファイル名から日付を抽出
            if (preg_match('/lotus_(\w+)_(\d{8}_\d{6})\.sql/', $filename, $matches)) {
                $type = $matches[1];
                $datetime = $matches[2];

                $backups[] = [
                    'filename' => $filename,
                    'type' => $type,
                    'date' => substr($datetime, 0, 8),
                    'time' => substr($datetime, 9, 6),
                    'datetime' => $datetime,
                    'size' => $stat['size'],
                    'created_at' => date('Y-m-d H:i:s', $stat['mtime'])
                ];
            }
        }

        // 日付で降順ソート
        usort($backups, function ($a, $b) {
            return strcmp($b['datetime'], $a['datetime']);
        });

        return json_ok($backups);
    }

    // バックアップ作成
    static function create()
    {
        $debug_info = [];
        try {
            $debug_info[] = "BackupController::create() called";
            $debug_info[] = "Request method: " . $_SERVER['REQUEST_METHOD'];
            $debug_info[] = "Request URI: " . $_SERVER['REQUEST_URI'];

            require_role(['ADMIN']);
            $debug_info[] = "Role check passed";

            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $debug_info[] = "Input data: " . json_encode($input);
            $type = $input['type'] ?? 'full';
            $backup_dir = __DIR__ . '/../../backups';

            $debug_info[] = "Backup create started - type: $type, backup_dir: $backup_dir";

            if (!is_dir($backup_dir)) {
                if (!mkdir($backup_dir, 0755, true)) {
                    $debug_info[] = "Failed to create backup directory: $backup_dir";
                    return json_ng('バックアップディレクトリの作成に失敗しました: ' . $backup_dir, 500, $debug_info);
                }
                $debug_info[] = "Created backup directory: $backup_dir";
            }

            $debug_info[] = "Loading config file...";
            $cfg = require __DIR__ . '/../config.php';
            $debug_info[] = "Config loaded successfully";

            $date = date('Ymd_His');
            $filename = "lotus_{$type}_{$date}.sql";
            $filepath = $backup_dir . '/' . $filename;

            $debug_info[] = "Backup file path: $filepath";

            // バックアップディレクトリの書き込み権限確認
            if (!is_writable($backup_dir)) {
                $debug_info[] = "Backup directory not writable: $backup_dir";
                return json_ng('バックアップディレクトリに書き込み権限がありません: ' . $backup_dir, 500, $debug_info);
            }

            try {
                // データベース接続テスト
                $debug_info[] = "Attempting database connection...";
                $pdo = new PDO(
                    sprintf(
                        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                        $cfg['db']['host'],
                        $cfg['db']['port'],
                        $cfg['db']['name']
                    ),
                    $cfg['db']['user'],
                    $cfg['db']['pass']
                );
                $debug_info[] = "Database connection successful";

                // mysqldumpコマンドの存在確認とパス取得
                $debug_info[] = "Looking for mysqldump...";
                $mysqldump_path = self::findMysqldumpPath();
                $debug_info[] = "mysqldump path: " . ($mysqldump_path ?: 'not found');

                if (empty($mysqldump_path)) {
                    // mysqldumpが見つからない場合は、PHPで直接ダンプを試す
                    $debug_info[] = "mysqldump not found, using PHP backup method";
                    $result = self::createBackupWithPHP($cfg, $filepath, $type);
                    if ($result) {
                        $debug_info[] = "PHP backup completed successfully";
                        return json_ok([
                            'filename' => $filename,
                            'size' => filesize($filepath),
                            'created_at' => date('Y-m-d H:i:s'),
                            'debug_info' => $debug_info
                        ]);
                    } else {
                        $debug_info[] = "PHP backup failed";
                        return json_ng('PHPバックアップの作成に失敗しました', 500, $debug_info);
                    }
                }

                // データベース全体をバックアップ

                // レンタルサーバー用のmysqldumpコマンド（SSL無効化）
                $debug_info[] = "Building mysqldump command...";
                // テーブル指定を削除して全テーブルを対象にする
                $command = sprintf(
                    '%s -h %s -P %s -u %s -p%s --single-transaction --routines --triggers --skip-ssl --no-tablespaces %s > %s 2>&1',
                    escapeshellarg($mysqldump_path),
                    escapeshellarg($cfg['db']['host']),
                    escapeshellarg($cfg['db']['port']),
                    escapeshellarg($cfg['db']['user']),
                    escapeshellarg($cfg['db']['pass']),
                    escapeshellarg($cfg['db']['name']),
                    escapeshellarg($filepath)
                );
                $debug_info[] = "mysqldump command built (password hidden)";

                if ($type === 'data') {
                    $command = str_replace('--routines --triggers', '--no-create-info', $command);
                } elseif ($type === 'schema') {
                    $command = str_replace('--routines --triggers', '--no-data --routines --triggers', $command);
                }

                // コマンドをログに記録
                $debug_info[] = "Executing mysqldump command...";
                $debug_info[] = "Command: " . str_replace($cfg['db']['pass'], '***', $command);

                // レンタルサーバーではexec()が無効化されている可能性があるため、PHPバックアップにフォールバック
                if (!function_exists('exec')) {
                    $debug_info[] = "exec() function not available, using PHP backup method";
                    $result = self::createBackupWithPHP($cfg, $filepath, $type);
                    if ($result) {
                        $debug_info[] = "PHP backup completed successfully";
                        return json_ok([
                            'filename' => $filename,
                            'size' => filesize($filepath),
                            'created_at' => date('Y-m-d H:i:s'),
                            'debug_info' => $debug_info
                        ]);
                    } else {
                        $debug_info[] = "PHP backup failed";
                        return json_ng('PHPバックアップの作成に失敗しました', 500, $debug_info);
                    }
                }

                exec($command, $output, $return_code);

                // エラー出力をログに記録
                if (!empty($output)) {
                    $debug_info[] = "Backup output: " . implode("\n", $output);
                }
                $debug_info[] = "Backup return code: " . $return_code;

                if ($return_code === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                    $debug_info[] = "mysqldump completed successfully";
                    return json_ok([
                        'filename' => $filename,
                        'size' => filesize($filepath),
                        'created_at' => date('Y-m-d H:i:s'),
                        'debug_info' => $debug_info
                    ]);
                } else {
                    $debug_info[] = "mysqldump failed, trying PHP backup method";
                    $result = self::createBackupWithPHP($cfg, $filepath, $type);
                    if ($result) {
                        $debug_info[] = "PHP backup completed successfully";
                        return json_ok([
                            'filename' => $filename,
                            'size' => filesize($filepath),
                            'created_at' => date('Y-m-d H:i:s'),
                            'debug_info' => $debug_info
                        ]);
                    } else {
                        $debug_info[] = "PHP backup also failed";
                        return json_ng('バックアップの作成に失敗しました', 500, $debug_info);
                    }
                }

            } catch (Exception $e) {
                $debug_info[] = "Backup exception: " . $e->getMessage();
                $debug_info[] = "Backup exception trace: " . $e->getTraceAsString();
                return json_ng('バックアップエラー: ' . $e->getMessage(), 500, $debug_info);
            }
        } catch (Exception $e) {
            $debug_info[] = "Backup create exception: " . $e->getMessage();
            $debug_info[] = "Backup create exception trace: " . $e->getTraceAsString();
            return json_ng('バックアップ作成エラー: ' . $e->getMessage(), 500, $debug_info);
        }
    }

    // mysqldumpのパスを検索（レンタルサーバー対応）
    private static function findMysqldumpPath()
    {
        // レンタルサーバーでは通常mysqldumpは利用できないため、直接nullを返す
        // open_basedir制限により、システムパスへのアクセスが制限されている
        return null;
    }

    // ログ出力関数
    private static function writeLog($message)
    {
        error_log("[BackupController] " . $message);
    }

    // バックアップダウンロード
    static function download()
    {
        require_role(['ADMIN']);

        $filename = $_GET['file'] ?? '';
        if (empty($filename)) {
            return json_ng('ファイル名が指定されていません');
        }

        // セキュリティチェック: ファイル名に危険な文字が含まれていないか確認
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return json_ng('無効なファイル名です');
        }

        // バックアップファイルの命名規則に従っているか確認
        if (!preg_match('/^lotus_\w+_\d{8}_\d{6}\.sql$/', $filename)) {
            error_log("Invalid download filename pattern: $filename");
            return json_ng('無効なファイル名です: ' . $filename);
        }

        $backup_dir = __DIR__ . '/../../backups';
        $filepath = $backup_dir . '/' . $filename;

        // パストラバーサル攻撃を防ぐため、バックアップディレクトリ内のファイルのみ許可
        $real_backup_dir = realpath($backup_dir);
        $real_filepath = realpath($filepath);

        if ($real_filepath === false || strpos($real_filepath, $real_backup_dir) !== 0) {
            return json_ng('ファイルが見つからないか、無効なパスです');
        }

        if (!file_exists($filepath)) {
            return json_ng('ファイルが見つかりません: ' . $filename);
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }

    // バックアップ削除
    static function delete()
    {
        require_role(['ADMIN']);

        // デバッグ情報をログに記録
        error_log("Delete request - Method: " . $_SERVER['REQUEST_METHOD']);
        error_log("Delete request - GET: " . json_encode($_GET));
        error_log("Delete request - POST: " . json_encode($_POST));

        // DELETEリクエストでは、クエリパラメータまたはリクエストボディから取得
        $filename = $_GET['filename'] ?? $_POST['filename'] ?? '';

        // リクエストボディからJSONデータを読み取る場合
        if (empty($filename)) {
            $input = json_decode(file_get_contents('php://input'), true);
            $filename = $input['filename'] ?? '';
            error_log("Delete request - JSON input: " . json_encode($input));
        }

        error_log("Delete request - Filename: " . $filename);

        if (empty($filename)) {
            return json_ng('ファイル名が指定されていません');
        }

        // セキュリティチェック: ファイル名に危険な文字が含まれていないか確認
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return json_ng('無効なファイル名です');
        }

        // バックアップファイルの命名規則に従っているか確認（より柔軟なパターン）
        if (!preg_match('/^lotus_\w+_\d{8}_\d{6}\.sql$/', $filename)) {
            // ログに記録して詳細を確認
            error_log("Invalid filename pattern: $filename");
            return json_ng('無効なファイル名です: ' . $filename);
        }

        $backup_dir = __DIR__ . '/../../backups';
        $filepath = $backup_dir . '/' . $filename;

        // パストラバーサル攻撃を防ぐため、バックアップディレクトリ内のファイルのみ許可
        $real_backup_dir = realpath($backup_dir);
        $real_filepath = realpath($filepath);

        if ($real_filepath === false || strpos($real_filepath, $real_backup_dir) !== 0) {
            return json_ng('ファイルが見つからないか、無効なパスです');
        }

        if (!file_exists($filepath)) {
            return json_ng('ファイルが見つかりません: ' . $filename);
        }

        if (unlink($filepath)) {
            error_log("Backup file deleted: $filename");
            return json_ok();
        } else {
            error_log("Failed to delete backup file: $filename");
            return json_ng('ファイルの削除に失敗しました');
        }
    }

    // 期間指定でバックアップ一覧取得
    static function list_by_period()
    {
        require_role(['ADMIN']);

        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';

        if (empty($start_date) || empty($end_date)) {
            return json_ng('開始日と終了日を指定してください');
        }

        $backup_dir = __DIR__ . '/../../backups';
        if (!is_dir($backup_dir)) {
            return json_ok([]);
        }

        $backups = [];
        $files = glob($backup_dir . '/lotus_*.sql');

        foreach ($files as $file) {
            $filename = basename($file);
            $stat = stat($file);

            if (preg_match('/lotus_(\w+)_(\d{8})_(\d{6})\.sql/', $filename, $matches)) {
                $type = $matches[1];
                $date = $matches[2];
                $time = $matches[3];

                // 日付範囲チェック
                if ($date >= $start_date && $date <= $end_date) {
                    $backups[] = [
                        'filename' => $filename,
                        'type' => $type,
                        'date' => $date,
                        'time' => $time,
                        'datetime' => $date . '_' . $time,
                        'size' => $stat['size'],
                        'created_at' => date('Y-m-d H:i:s', $stat['mtime'])
                    ];
                }
            }
        }

        // 日付で降順ソート
        usort($backups, function ($a, $b) {
            return strcmp($b['datetime'], $a['datetime']);
        });

        return json_ok($backups);
    }

    // バックアップ復元
    static function restore()
    {
        require_role(['ADMIN']);

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $filename = $input['filename'] ?? '';

        if (empty($filename)) {
            return json_ng('ファイル名が指定されていません');
        }

        // セキュリティチェック: ファイル名に危険な文字が含まれていないか確認
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return json_ng('無効なファイル名です');
        }

        // バックアップファイルの命名規則に従っているか確認
        if (!preg_match('/^lotus_\w+_\d{8}_\d{6}\.sql$/', $filename)) {
            return json_ng('無効なファイル名です: ' . $filename);
        }

        $backup_dir = __DIR__ . '/../../backups';
        $filepath = $backup_dir . '/' . $filename;

        // パストラバーサル攻撃を防ぐため、バックアップディレクトリ内のファイルのみ許可
        $real_backup_dir = realpath($backup_dir);
        $real_filepath = realpath($filepath);

        if ($real_filepath === false || strpos($real_filepath, $real_backup_dir) !== 0) {
            return json_ng('ファイルが見つからないか、無効なパスです');
        }

        if (!file_exists($filepath)) {
            return json_ng('ファイルが見つかりません: ' . $filename);
        }

        try {
            $cfg = require __DIR__ . '/../config.php';

            // データベース接続
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $cfg['db']['host'],
                    $cfg['db']['port'],
                    $cfg['db']['name']
                ),
                $cfg['db']['user'],
                $cfg['db']['pass']
            );

            // 復元前に現在のデータをバックアップ（安全のため）
            $restore_backup_filename = "restore_backup_" . date('Ymd_His') . ".sql";
            $restore_backup_path = $backup_dir . '/' . $restore_backup_filename;

            // 現在のデータをバックアップ
            $backup_result = self::createBackupWithPHP($cfg, $restore_backup_path, 'full');
            if (!$backup_result) {
                return json_ng('復元前のバックアップ作成に失敗しました');
            }

            // SQLファイルの内容を読み込み
            $sql_content = file_get_contents($filepath);
            if ($sql_content === false) {
                return json_ng('バックアップファイルの読み込みに失敗しました');
            }

            // トランザクション開始（既存のトランザクションをチェック）
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            try {
                // 外部キー制約を一時的に無効化
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                // 全テーブルを削除（存在する場合）
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `$table`");
                }

                // SQLファイルを実行
                $statements = explode(';', $sql_content);
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement) && !preg_match('/^--/', $statement)) {
                        $pdo->exec($statement);
                    }
                }

                // 外部キー制約を再有効化
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                // トランザクションコミット（トランザクションが開始されている場合のみ）
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                // 復元前のバックアップファイルを削除（成功した場合）
                if (file_exists($restore_backup_path)) {
                    unlink($restore_backup_path);
                }

                return json_ok([
                    'message' => 'バックアップの復元が完了しました',
                    'restored_file' => $filename,
                    'restore_backup' => $restore_backup_filename
                ]);

            } catch (Exception $e) {
                // エラーが発生した場合はロールバック（トランザクションが開始されている場合のみ）
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // 復元前のバックアップから復元を試行
                if (file_exists($restore_backup_path)) {
                    $restore_sql = file_get_contents($restore_backup_path);
                    if ($restore_sql !== false) {
                        $statements = explode(';', $restore_sql);
                        foreach ($statements as $statement) {
                            $statement = trim($statement);
                            if (!empty($statement) && !preg_match('/^--/', $statement)) {
                                $pdo->exec($statement);
                            }
                        }
                    }
                }

                return json_ng('復元エラー: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            return json_ng('復元エラー: ' . $e->getMessage());
        }
    }

    // アップロード復元機能
    static function uploadRestore()
    {
        require_role(['ADMIN']);

        // アップロードされたファイルをチェック
        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            return json_ng('ファイルのアップロードに失敗しました');
        }

        $uploaded_file = $_FILES['backup_file'];
        $filename = $uploaded_file['name'];
        $tmp_path = $uploaded_file['tmp_name'];

        // ファイル名のセキュリティチェック
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return json_ng('無効なファイル名です');
        }

        // SQLファイルかチェック
        if (!preg_match('/\.sql$/', $filename)) {
            return json_ng('SQLファイルを選択してください');
        }

        // ファイルサイズチェック（10MB制限）
        if ($uploaded_file['size'] > 10 * 1024 * 1024) {
            return json_ng('ファイルサイズが大きすぎます（10MB以下）');
        }

        try {
            $cfg = require __DIR__ . '/../config.php';

            // データベース接続
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $cfg['db']['host'],
                    $cfg['db']['port'],
                    $cfg['db']['name']
                ),
                $cfg['db']['user'],
                $cfg['db']['pass']
            );

            // 復元前に現在のデータをバックアップ（安全のため）
            $backup_dir = __DIR__ . '/../../backups';
            $restore_backup_filename = "restore_backup_" . date('Ymd_His') . ".sql";
            $restore_backup_path = $backup_dir . '/' . $restore_backup_filename;

            // 現在のデータをバックアップ
            $backup_result = self::createBackupWithPHP($cfg, $restore_backup_path, 'full');
            if (!$backup_result) {
                return json_ng('復元前のバックアップ作成に失敗しました');
            }

            // アップロードされたファイルの内容を読み込み
            $sql_content = file_get_contents($tmp_path);
            if ($sql_content === false) {
                return json_ng('アップロードファイルの読み込みに失敗しました');
            }

            // トランザクション開始（既存のトランザクションをチェック）
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            try {
                // 外部キー制約を一時的に無効化
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                // 全テーブルを削除（存在する場合）
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `$table`");
                }

                // SQLファイルを実行
                $statements = explode(';', $sql_content);
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement) && !preg_match('/^--/', $statement)) {
                        $pdo->exec($statement);
                    }
                }

                // 外部キー制約を再有効化
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                // トランザクションコミット（トランザクションが開始されている場合のみ）
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                // 復元前のバックアップファイルを削除（成功した場合）
                if (file_exists($restore_backup_path)) {
                    unlink($restore_backup_path);
                }

                return json_ok([
                    'message' => 'アップロード復元が完了しました',
                    'uploaded_file' => $filename,
                    'restore_backup' => $restore_backup_filename
                ]);

            } catch (Exception $e) {
                // エラーが発生した場合はロールバック（トランザクションが開始されている場合のみ）
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // 復元前のバックアップから復元を試行
                if (file_exists($restore_backup_path)) {
                    $restore_sql = file_get_contents($restore_backup_path);
                    if ($restore_sql !== false) {
                        $statements = explode(';', $restore_sql);
                        foreach ($statements as $statement) {
                            $statement = trim($statement);
                            if (!empty($statement) && !preg_match('/^--/', $statement)) {
                                $pdo->exec($statement);
                            }
                        }
                    }
                }

                return json_ng('アップロード復元エラー: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            return json_ng('アップロード復元エラー: ' . $e->getMessage());
        }
    }

    // PHPで直接バックアップを作成（mysqldumpが利用できない場合の代替手段）
    private static function createBackupWithPHP($cfg, $filepath, $type)
    {
        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $cfg['db']['host'],
                    $cfg['db']['port'],
                    $cfg['db']['name']
                ),
                $cfg['db']['user'],
                $cfg['db']['pass']
            );

            $backup_content = "-- Lotus Purchase Request Backup\n";
            $backup_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $backup_content .= "-- Database: " . $cfg['db']['name'] . "\n";
            $backup_content .= "-- Tables: All\n\n";
            $backup_content .= "SET NAMES utf8mb4;\n";
            $backup_content .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

            // データベース内の全テーブルを取得
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // テーブルが存在するかチェック
                $table_exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
                if (!$table_exists) {
                    $backup_content .= "-- Table $table does not exist, skipping\n\n";
                    continue;
                }

                if ($type !== 'data') {
                    // テーブル構造を取得
                    $create_table = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
                    $backup_content .= "DROP TABLE IF EXISTS `$table`;\n";
                    $backup_content .= $create_table['Create Table'] . ";\n\n";
                }

                if ($type !== 'schema') {
                    // テーブルデータを取得
                    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($rows)) {
                        $columns = array_keys($rows[0]);
                        $backup_content .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";

                        $values = [];
                        foreach ($rows as $row) {
                            $escaped_values = [];
                            foreach ($row as $value) {
                                if ($value === null) {
                                    $escaped_values[] = 'NULL';
                                } else {
                                    $escaped_values[] = $pdo->quote($value);
                                }
                            }
                            $values[] = '(' . implode(', ', $escaped_values) . ')';
                        }

                        $backup_content .= implode(",\n", $values) . ";\n\n";
                    } else {
                        $backup_content .= "-- Table $table is empty\n\n";
                    }
                }
            }

            $backup_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";

            if (file_put_contents($filepath, $backup_content) === false) {
                return false; // エラーの場合はfalseを返す
            }

            return true; // 成功の場合はtrueを返す

        } catch (Exception $e) {
            error_log("PHP backup error: " . $e->getMessage());
            return false; // エラーの場合はfalseを返す
        }
    }
}
?>