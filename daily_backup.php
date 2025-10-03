<?php
/**
 * 日次バックアップ自動実行スクリプト
 * 使用方法: php daily_backup.php
 * または cron で実行: 0 2 * * * /usr/bin/php /path/to/daily_backup.php
 */

require_once __DIR__.'/app/lib/db.php';
require_once __DIR__.'/app/lib/util.php';

// ログファイル
$log_file = __DIR__.'/logs/backup.log';
$log_dir = dirname($log_file);

// ログディレクトリを作成
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    echo $log_message;
}

try {
    writeLog("=== 日次バックアップ開始 ===");
    
    // 設定読み込み
    $cfg = require __DIR__.'/app/config.php';
    $backup_dir = __DIR__.'/backups';
    
    // バックアップディレクトリを作成
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
        writeLog("バックアップディレクトリを作成: $backup_dir");
    }
    
    // 日付を生成
    $date = date('Ymd_His');
    $filename = "lotus_full_{$date}.sql";
    $filepath = $backup_dir.'/'.$filename;
    
    writeLog("バックアップファイル: $filename");
    
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
    
    // mysqldumpコマンドを実行
    $command = sprintf(
        'mysqldump -h %s -P %s -u %s -p%s --single-transaction --routines --triggers %s %s > %s',
        escapeshellarg($cfg['db']['host']),
        escapeshellarg($cfg['db']['port']),
        escapeshellarg($cfg['db']['user']),
        escapeshellarg($cfg['db']['pass']),
        escapeshellarg($cfg['db']['name']),
        $tables_str,
        escapeshellarg($filepath)
    );
    
    exec($command, $output, $return_code);
    
    if ($return_code === 0 && file_exists($filepath)) {
        $file_size = filesize($filepath);
        $file_size_mb = round($file_size / 1024 / 1024, 2);
        writeLog("✅ バックアップが正常に完了しました");
        writeLog("ファイルサイズ: {$file_size_mb} MB");
        
        // 古いバックアップを削除（30日以上古いもの）
        $old_backups = glob($backup_dir.'/lotus_*.sql');
        $deleted_count = 0;
        
        foreach ($old_backups as $old_file) {
            if (filemtime($old_file) < strtotime('-30 days')) {
                if (unlink($old_file)) {
                    $deleted_count++;
                    writeLog("古いバックアップを削除: " . basename($old_file));
                }
            }
        }
        
        if ($deleted_count > 0) {
            writeLog("古いバックアップ {$deleted_count} 件を削除しました");
        }
        
    } else {
        writeLog("❌ バックアップの作成に失敗しました (終了コード: $return_code)");
        if (!empty($output)) {
            writeLog("エラー出力: " . implode("\n", $output));
        }
        exit(1);
    }
    
    writeLog("=== 日次バックアップ完了 ===");
    
} catch (Exception $e) {
    writeLog("❌ バックアップエラー: " . $e->getMessage());
    writeLog("スタックトレース: " . $e->getTraceAsString());
    exit(1);
}
?>
