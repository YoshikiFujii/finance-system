<?php
header('Content-Type: text/plain; charset=utf-8');
require_once '../app/lib/db.php';

echo "データベース初期化を開始します...\n";

try {
    // eventsテーブルが存在するかチェック
    $result = db()->query('SHOW TABLES LIKE "events"')->fetchAll();
    if (empty($result)) {
        echo "eventsテーブルが存在しません。作成します...\n";
        
        // eventsテーブルを作成
        db()->exec("CREATE TABLE IF NOT EXISTS events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        
        echo "eventsテーブルを作成しました。\n";
        
        // 初期データを挿入
        $events = ['定期総会', '文化祭', '体育祭', '卒業式', '入学式'];
        $stmt = db()->prepare('INSERT INTO events (name) VALUES (?)');
        foreach ($events as $event) {
            $stmt->execute([$event]);
        }
        echo "初期行事データを挿入しました。\n";
    } else {
        echo "eventsテーブルは既に存在します。\n";
    }
    
    // receiptsテーブルに新しいカラムが存在するかチェック
    $columns = db()->query('SHOW COLUMNS FROM receipts')->fetchAll();
    $column_names = array_column($columns, 'Field');
    
    if (!in_array('subject', $column_names)) {
        echo "receiptsテーブルにsubjectカラムを追加します...\n";
        db()->exec('ALTER TABLE receipts ADD COLUMN subject VARCHAR(255) DEFAULT NULL');
        echo "subjectカラムを追加しました。\n";
    }
    
    if (!in_array('event_id', $column_names)) {
        echo "receiptsテーブルにevent_idカラムを追加します...\n";
        db()->exec('ALTER TABLE receipts ADD COLUMN event_id BIGINT DEFAULT NULL');
        echo "event_idカラムを追加しました。\n";
    }
    
    if (!in_array('purpose', $column_names)) {
        echo "receiptsテーブルにpurposeカラムを追加します...\n";
        db()->exec("ALTER TABLE receipts ADD COLUMN purpose ENUM('備品購入','運営用品購入','景品購入','広報物作成','朝・昼・夜食購入','その他') DEFAULT NULL");
        echo "purposeカラムを追加しました。\n";
    }
    
    if (!in_array('payer', $column_names)) {
        echo "receiptsテーブルにpayerカラムを追加します...\n";
        db()->exec('ALTER TABLE receipts ADD COLUMN payer VARCHAR(100) DEFAULT NULL');
        echo "payerカラムを追加しました。\n";
    }
    
    if (!in_array('receipt_date', $column_names)) {
        echo "receiptsテーブルにreceipt_dateカラムを追加します...\n";
        db()->exec('ALTER TABLE receipts ADD COLUMN receipt_date DATE DEFAULT NULL');
        echo "receipt_dateカラムを追加しました。\n";
    }
    
    // 外部キー制約を追加（存在しない場合）
    try {
        db()->exec('ALTER TABLE receipts ADD FOREIGN KEY (event_id) REFERENCES events(id)');
        echo "外部キー制約を追加しました。\n";
    } catch (Exception $e) {
        echo "外部キー制約は既に存在するか、追加に失敗しました: " . $e->getMessage() . "\n";
    }
    
    echo "データベース初期化が完了しました。\n";
    
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage() . "\n";
    exit(1);
}
?>
