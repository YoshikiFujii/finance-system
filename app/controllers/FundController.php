<?php

require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/db.php';

class FundController {
    
    // 資金データを取得
    public static function get() {
        try {
            $pdo = get_db();
            
            // テーブルの存在確認
            $tableCheck = $pdo->prepare("SHOW TABLES LIKE 'funds'");
            $tableCheck->execute();
            if (!$tableCheck->fetch()) {
                // テーブルが存在しない場合は初期値を返す
                return json_ok([
                    'bankBalance' => 0,
                    'cashOnHand' => 0,
                    'processedAmount' => 0
                ]);
            }
            
            // 資金データを取得（存在しない場合は初期値を作成）
            $stmt = $pdo->prepare("SELECT * FROM funds LIMIT 1");
            $stmt->execute();
            $fund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fund) {
                // 初期データを作成
                $stmt = $pdo->prepare("INSERT INTO funds (bank_balance, cash_on_hand, processed_amount) VALUES (0, 0, 0)");
                $stmt->execute();
                
                $fund = [
                    'bank_balance' => 0,
                    'cash_on_hand' => 0,
                    'processed_amount' => 0
                ];
            }
            
            // 処理済み額を再計算
            $processedAmount = self::calculateProcessedAmount($pdo);
            
            return json_ok([
                'bankBalance' => (float)$fund['bank_balance'],
                'cashOnHand' => (float)$fund['cash_on_hand'],
                'processedAmount' => $processedAmount
            ]);
            
        } catch (Exception $e) {
            // エラーが発生した場合は初期値を返す
            return json_ok([
                'bankBalance' => 0,
                'cashOnHand' => 0,
                'processedAmount' => 0
            ]);
        }
    }
    
    // 銀行残高を更新
    public static function updateBankBalance() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $amount = (float)$data['amount'];
            
            if ($amount < 0) {
                return json_ng('金額は0以上である必要があります');
            }
            
            $pdo = get_db();
            
            // テーブルの存在確認
            $tableCheck = $pdo->prepare("SHOW TABLES LIKE 'funds'");
            $tableCheck->execute();
            if (!$tableCheck->fetch()) {
                return json_ng('資金管理テーブルが存在しません。管理者にお問い合わせください。');
            }
            
            // 資金データを更新
            $stmt = $pdo->prepare("UPDATE funds SET bank_balance = ? WHERE id = 1");
            $stmt->execute([$amount]);
            
            // ログを記録
            self::addFundLog($pdo, '銀行残高', '直接更新', $amount);
            
            return json_ok(['message' => '銀行残高を更新しました']);
            
        } catch (Exception $e) {
            return json_ng('銀行残高の更新に失敗しました: ' . $e->getMessage());
        }
    }
    
    // 所持金額を更新
    public static function updateCashOnHand() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $amount = (float)$data['amount'];
            
            if ($amount < 0) {
                return json_ng('金額は0以上である必要があります');
            }
            
            $pdo = get_db();
            
            // 資金データを更新
            $stmt = $pdo->prepare("UPDATE funds SET cash_on_hand = ? WHERE id = 1");
            $stmt->execute([$amount]);
            
            // ログを記録
            self::addFundLog($pdo, '所持金額', '直接更新', $amount);
            
            return json_ok(['message' => '所持金額を更新しました']);
            
        } catch (Exception $e) {
            return json_ng('所持金額の更新に失敗しました: ' . $e->getMessage());
        }
    }
    
    // 銀行から引き出し
    public static function withdrawFromBank() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $amount = (float)$data['amount'];
            
            if ($amount <= 0) {
                return json_ng('引き出し額は0より大きい必要があります');
            }
            
            $pdo = get_db();
            
            // 現在の残高を確認
            $stmt = $pdo->prepare("SELECT bank_balance, cash_on_hand FROM funds WHERE id = 1");
            $stmt->execute();
            $fund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($amount > $fund['bank_balance']) {
                return json_ng('銀行残高が不足しています');
            }
            
            // 資金データを更新
            $stmt = $pdo->prepare("UPDATE funds SET bank_balance = bank_balance - ?, cash_on_hand = cash_on_hand + ? WHERE id = 1");
            $stmt->execute([$amount, $amount]);
            
            // ログを記録
            self::addFundLog($pdo, '銀行残高', '引き出し', -$amount);
            self::addFundLog($pdo, '所持金額', '預入', $amount);
            
            return json_ok(['message' => '銀行から引き出しました']);
            
        } catch (Exception $e) {
            return json_ng('銀行からの引き出しに失敗しました: ' . $e->getMessage());
        }
    }
    
    // 銀行に預入
    public static function depositToBank() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $amount = (float)$data['amount'];
            
            if ($amount <= 0) {
                return json_ng('預入額は0より大きい必要があります');
            }
            
            $pdo = get_db();
            
            // 現在の残高を確認
            $stmt = $pdo->prepare("SELECT bank_balance, cash_on_hand FROM funds WHERE id = 1");
            $stmt->execute();
            $fund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($amount > $fund['cash_on_hand']) {
                return json_ng('所持金額が不足しています');
            }
            
            // 資金データを更新
            $stmt = $pdo->prepare("UPDATE funds SET bank_balance = bank_balance + ?, cash_on_hand = cash_on_hand - ? WHERE id = 1");
            $stmt->execute([$amount, $amount]);
            
            // ログを記録
            self::addFundLog($pdo, '銀行残高', '預入', $amount);
            self::addFundLog($pdo, '所持金額', '引き出し', -$amount);
            
            return json_ok(['message' => '銀行に預入しました']);
            
        } catch (Exception $e) {
            return json_ng('銀行への預入に失敗しました: ' . $e->getMessage());
        }
    }
    
    // 所持金額から引き出し
    public static function withdrawFromCash() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $amount = (float)$data['amount'];
            
            if ($amount <= 0) {
                return json_ng('引き出し額は0より大きい必要があります');
            }
            
            $pdo = get_db();
            
            // 現在の残高を確認
            $stmt = $pdo->prepare("SELECT cash_on_hand FROM funds WHERE id = 1");
            $stmt->execute();
            $fund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($amount > $fund['cash_on_hand']) {
                return json_ng('所持金額が不足しています');
            }
            
            // 資金データを更新
            $stmt = $pdo->prepare("UPDATE funds SET cash_on_hand = cash_on_hand - ? WHERE id = 1");
            $stmt->execute([$amount]);
            
            // ログを記録
            self::addFundLog($pdo, '所持金額', '引き出し', -$amount);
            
            return json_ok(['message' => '所持金額から引き出しました']);
            
        } catch (Exception $e) {
            return json_ng('所持金額からの引き出しに失敗しました: ' . $e->getMessage());
        }
    }
    
    // 所持金額に預入
    public static function depositToCash() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $amount = (float)$data['amount'];
            
            if ($amount <= 0) {
                return json_ng('預入額は0より大きい必要があります');
            }
            
            $pdo = get_db();
            
            // 資金データを更新
            $stmt = $pdo->prepare("UPDATE funds SET cash_on_hand = cash_on_hand + ? WHERE id = 1");
            $stmt->execute([$amount]);
            
            // ログを記録
            self::addFundLog($pdo, '所持金額', '預入', $amount);
            
            return json_ok(['message' => '所持金額に預入しました']);
            
        } catch (Exception $e) {
            return json_ng('所持金額への預入に失敗しました: ' . $e->getMessage());
        }
    }
    
    // 処理済み額を再算出
    public static function recalculateProcessedAmount() {
        try {
            $pdo = get_db();
            
            $processedAmount = self::calculateProcessedAmount($pdo);
            
            // 資金データを更新
            $stmt = $pdo->prepare("UPDATE funds SET processed_amount = ? WHERE id = 1");
            $stmt->execute([$processedAmount]);
            
            // ログを記録
            self::addFundLog($pdo, '処理済み額', '再算出', $processedAmount);
            
            return json_ok([
                'message' => '処理済み額を再算出しました',
                'processedAmount' => $processedAmount
            ]);
            
        } catch (Exception $e) {
            return json_ng('処理済み額の再算出に失敗しました: ' . $e->getMessage());
        }
    }
    
    // 処理済み額を計算
    private static function calculateProcessedAmount($pdo) {
        $stmt = $pdo->prepare("SELECT SUM(total) as total FROM receipts WHERE deleted_at IS NULL");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (float)($result['total'] ?? 0);
    }
    
    // 資金ログを追加
    private static function addFundLog($pdo, $type, $action, $amount) {
        try {
            $stmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$type, $action, $amount]);
        } catch (Exception $e) {
            // ログの記録に失敗しても処理は続行
            error_log("Fund log error: " . $e->getMessage());
        }
    }
    
    // 資金ログを取得
    public static function getFundLogs() {
        try {
            $pdo = get_db();
            
            $stmt = $pdo->prepare("SELECT * FROM fund_logs ORDER BY created_at DESC LIMIT 100");
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return json_ok($logs);
            
        } catch (Exception $e) {
            return json_ng('資金ログの取得に失敗しました: ' . $e->getMessage());
        }
    }
}
