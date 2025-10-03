<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/util.php';

class BackupController {
    
    // バックアップ一覧取得
    static function list() {
        require_role(['ADMIN']);
        
        $backup_dir = __DIR__.'/../../backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $backups = [];
        $files = glob($backup_dir.'/lotus_*.sql');
        
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
        usort($backups, function($a, $b) {
            return strcmp($b['datetime'], $a['datetime']);
        });
        
        return json_ok($backups);
    }
    
    // バックアップ作成
    static function create() {
        require_role(['ADMIN']);
        
        $type = $_POST['type'] ?? 'full';
        $backup_dir = __DIR__.'/../../backups';
        
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $cfg = require __DIR__.'/../config.php';
        $date = date('Ymd_His');
        $filename = "lotus_{$type}_{$date}.sql";
        $filepath = $backup_dir.'/'.$filename;
        
        // mysqldumpコマンドの存在確認
        exec('which mysqldump', $which_output, $which_code);
        $mysqldump_path = '';
        if ($which_code === 0 && !empty($which_output)) {
            $mysqldump_path = trim($which_output[0]);
        } else {
            // Docker環境での代替パスを試す
            $possible_paths = [
                '/usr/bin/mysqldump',
                '/usr/local/bin/mysqldump',
                '/opt/mysql/bin/mysqldump'
            ];
            
            foreach ($possible_paths as $path) {
                if (file_exists($path) && is_executable($path)) {
                    $mysqldump_path = $path;
                    break;
                }
            }
            
            if (empty($mysqldump_path)) {
                // mysqldumpが見つからない場合は、PHPで直接ダンプを試す
                return self::createBackupWithPHP($cfg, $filepath, $type);
            }
        }
        
        // バックアップディレクトリの書き込み権限確認
        if (!is_writable($backup_dir)) {
            return json_ng('バックアップディレクトリに書き込み権限がありません: ' . $backup_dir);
        }
        
        try {
            // データベース接続テスト
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name']),
                $cfg['db']['user'], $cfg['db']['pass']
            );
            
            // 購入希望リスト関連のテーブルのみをバックアップ
            $tables = [
                'requests',           // 購入希望リスト（メイン）
                'request_items',      // 購入項目詳細
                'receipts',           // レシート情報
                'departments',        // 部署マスタ（参照用）
                'members',            // 役員マスタ（参照用）
                'events'              // 行事マスタ（参照用）
            ];
            
            $tables_str = implode(' ', array_map('escapeshellarg', $tables));
            
            $command = sprintf(
                '%s -h %s -P %s -u %s -p%s --single-transaction --routines --triggers %s %s > %s 2>&1',
                escapeshellarg($mysqldump_path),
                escapeshellarg($cfg['db']['host']),
                escapeshellarg($cfg['db']['port']),
                escapeshellarg($cfg['db']['user']),
                escapeshellarg($cfg['db']['pass']),
                escapeshellarg($cfg['db']['name']),
                $tables_str,
                escapeshellarg($filepath)
            );
            
            if ($type === 'data') {
                $command = str_replace('--routines --triggers', '--no-create-info', $command);
            } elseif ($type === 'schema') {
                $command = str_replace('--routines --triggers', '--no-data --routines --triggers', $command);
            }
            
            // コマンドをログに記録
            error_log("Backup command: " . str_replace($cfg['db']['pass'], '***', $command));
            
            exec($command, $output, $return_code);
            
            // エラー出力をログに記録
            if (!empty($output)) {
                error_log("Backup output: " . implode("\n", $output));
            }
            error_log("Backup return code: " . $return_code);
            
            if ($return_code === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                return json_ok([
                    'filename' => $filename,
                    'size' => filesize($filepath),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $error_msg = 'バックアップの作成に失敗しました';
                if (!empty($output)) {
                    $error_msg .= ': ' . implode('; ', $output);
                }
                if ($return_code !== 0) {
                    $error_msg .= " (終了コード: $return_code)";
                }
                return json_ng($error_msg);
            }
            
        } catch (Exception $e) {
            error_log("Backup exception: " . $e->getMessage());
            return json_ng('バックアップエラー: ' . $e->getMessage());
        }
    }
    
    // バックアップダウンロード
    static function download() {
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
        
        $backup_dir = __DIR__.'/../../backups';
        $filepath = $backup_dir.'/'.$filename;
        
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
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
    
    // バックアップ削除
    static function delete() {
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
        
        $backup_dir = __DIR__.'/../../backups';
        $filepath = $backup_dir.'/'.$filename;
        
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
    static function list_by_period() {
        require_role(['ADMIN']);
        
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        
        if (empty($start_date) || empty($end_date)) {
            return json_ng('開始日と終了日を指定してください');
        }
        
        $backup_dir = __DIR__.'/../../backups';
        if (!is_dir($backup_dir)) {
            return json_ok([]);
        }
        
        $backups = [];
        $files = glob($backup_dir.'/lotus_*.sql');
        
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
                        'datetime' => $date.'_'.$time,
                        'size' => $stat['size'],
                        'created_at' => date('Y-m-d H:i:s', $stat['mtime'])
                    ];
                }
            }
        }
        
        // 日付で降順ソート
        usort($backups, function($a, $b) {
            return strcmp($b['datetime'], $a['datetime']);
        });
        
        return json_ok($backups);
    }
    
    // PHPで直接バックアップを作成（mysqldumpが利用できない場合の代替手段）
    private static function createBackupWithPHP($cfg, $filepath, $type) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name']),
                $cfg['db']['user'], $cfg['db']['pass']
            );
            
            $backup_content = "-- Lotus Purchase Request Backup\n";
            $backup_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $backup_content .= "-- Database: " . $cfg['db']['name'] . "\n";
            $backup_content .= "-- Tables: requests, request_items, receipts, departments, members, events\n\n";
            $backup_content .= "SET NAMES utf8mb4;\n";
            $backup_content .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            
            // 購入希望リスト関連のテーブルのみをバックアップ
            $tables = [
                'requests',           // 購入希望リスト（メイン）
                'request_items',      // 購入項目詳細
                'receipts',           // レシート情報
                'departments',        // 部署マスタ（参照用）
                'members',            // 役員マスタ（参照用）
                'events'              // 行事マスタ（参照用）
            ];
            
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
                return json_ng('バックアップファイルの書き込みに失敗しました');
            }
            
            return json_ok([
                'filename' => basename($filepath),
                'size' => filesize($filepath),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            return json_ng('PHPバックアップエラー: ' . $e->getMessage());
        }
    }
}
?>
