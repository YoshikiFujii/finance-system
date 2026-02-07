<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/util.php';

class FinanceController
{

    // 財務テーブルの初期化
    static function init_finance_tables()
    {
        try {
            error_log("Starting finance tables initialization...");

            $pdo = db();
            error_log("Database connection successful");

            // 既存のテーブル構造を確認
            error_log("Checking existing table structure...");
            $describeStmt = $pdo->query("DESCRIBE finance_logs");
            $columns = $describeStmt->fetchAll();
            error_log("Existing columns: " . json_encode($columns));

            // 既存のテーブルを削除して新しく作成
            error_log("Dropping existing finance_logs table if exists...");
            $pdo->exec("DROP TABLE IF EXISTS finance_logs");

            // finance_logsテーブルの作成
            error_log("Creating finance_logs table...");
            $createResult = $pdo->exec("CREATE TABLE finance_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('bank_balance', 'cash_amount') NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                action VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            error_log("Finance table creation result: " . $createResult);

            // テーブル構造を再確認
            $describeStmt2 = $pdo->query("DESCRIBE finance_logs");
            $columns2 = $describeStmt2->fetchAll();
            error_log("Columns after creation: " . json_encode($columns2));

            // 初期データの挿入
            error_log("Inserting initial data...");
            $insertResult = $pdo->exec("INSERT INTO finance_logs (type, amount, action) VALUES 
                ('bank_balance', 0, 'initial'),
                ('cash_amount', 0, 'initial')
            ");
            error_log("Initial data insert result: " . $insertResult);

            // 挿入されたデータを確認
            $checkStmt = $pdo->query("SELECT COUNT(*) as count FROM finance_logs");
            $count = $checkStmt->fetch()['count'];
            error_log("Final finance_logs count: " . $count);

            error_log("Finance tables initialization completed successfully");
            return json_ok(['message' => '財務テーブルの初期化が完了しました', 'created' => $createResult, 'count' => $count]);
        } catch (Exception $e) {
            error_log("Finance tables init error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('財務テーブルの初期化に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 財務テーブルの初期化（初期値付き）
    static function init_finance_tables_with_initial_values()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $bankBalance = $input['bank_balance'] ?? 0;
            $cashAmount = $input['cash_amount'] ?? 0;

            if ($bankBalance < 0 || $cashAmount < 0) {
                return json_ng('有効な金額を入力してください', 400);
            }

            error_log("Starting finance tables initialization with initial values...");
            error_log("Bank balance: " . $bankBalance . ", Cash amount: " . $cashAmount);

            $pdo = db();
            error_log("Database connection successful");

            // 既存のテーブル構造を確認
            error_log("Checking existing table structure...");
            $describeStmt = $pdo->query("DESCRIBE finance_logs");
            $columns = $describeStmt->fetchAll();
            error_log("Existing columns: " . json_encode($columns));

            // 既存のテーブルを削除して新しく作成
            error_log("Dropping existing finance_logs table if exists...");
            $pdo->exec("DROP TABLE IF EXISTS finance_logs");

            // finance_logsテーブルの作成
            error_log("Creating finance_logs table...");
            $createResult = $pdo->exec("CREATE TABLE finance_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('bank_balance', 'cash_amount') NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                action VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            error_log("Finance table creation result: " . $createResult);

            // テーブル構造を再確認
            $describeStmt2 = $pdo->query("DESCRIBE finance_logs");
            $columns2 = $describeStmt2->fetchAll();
            error_log("Columns after creation: " . json_encode($columns2));

            // 初期データの挿入（指定された初期値で）
            error_log("Inserting initial data with specified values...");
            $insertResult = $pdo->exec("INSERT INTO finance_logs (type, amount, action) VALUES 
                ('bank_balance', {$bankBalance}, 'initial'),
                ('cash_amount', {$cashAmount}, 'initial')
            ");
            error_log("Initial data insert result: " . $insertResult);

            // fundsテーブルも初期化
            error_log("Initializing funds table with specified values...");
            $fundsUpdateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
            $fundsUpdateResult = $fundsUpdateStmt->execute([$bankBalance, $cashAmount]);

            // 更新された行数が0の場合、新規作成
            if ($fundsUpdateStmt->rowCount() == 0) {
                $fundsInsertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                $fundsInsertStmt->execute([$bankBalance, $cashAmount]);
                error_log("Funds table created with initial values");
            } else {
                error_log("Funds table updated with initial values");
            }

            // ログに記録（fund_logsテーブルに）
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['bank_balance', 'init', $bankBalance]);
            $logStmt->execute(['cash_amount', 'init', $cashAmount]);

            // 挿入されたデータを確認
            $checkStmt = $pdo->query("SELECT COUNT(*) as count FROM finance_logs");
            $count = $checkStmt->fetch()['count'];
            error_log("Final finance_logs count: " . $count);

            error_log("Finance tables initialization with initial values completed successfully");
            return json_ok([
                'message' => '財務テーブルの初期化が完了しました（初期値設定済み）',
                'created' => $createResult,
                'count' => $count,
                'bank_balance' => $bankBalance,
                'cash_amount' => $cashAmount
            ]);
        } catch (Exception $e) {
            error_log("Finance tables init with values error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('財務テーブルの初期化に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 銀行残高の初期値を設定
    static function init_bank_balance()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $amount = $input['amount'] ?? 0;

            if ($amount < 0) {
                return json_ng('有効な金額を入力してください', 400);
            }

            error_log("Init bank balance: " . $amount);

            $pdo = db();

            // 現在の持ち出し額を取得して保持
            $currentStmt = $pdo->query("SELECT cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentCashOnHand = $currentData ? (float) $currentData['cash_on_hand'] : 0;

            // fundsテーブルを更新（持ち出し額を保持、銀行残高を初期値に設定）
            $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$amount]);

            // 更新された行数が0の場合、新規作成（持ち出し額も保持）
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                $insertStmt->execute([$amount, $currentCashOnHand]);
            }

            // ログに記録（fund_logsテーブルに）
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['bank_balance', 'init', $amount]);

            error_log("Bank balance initialized successfully: " . $amount);

            return json_ok(['message' => '銀行残高の初期値を設定しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Init bank balance error: " . $e->getMessage());
            return json_ng('銀行残高の初期値設定に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 持ち出し額の初期値を設定
    static function init_cash_on_hand()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $amount = $input['amount'] ?? 0;

            if ($amount < 0) {
                return json_ng('有効な金額を入力してください', 400);
            }

            error_log("Init cash on hand: " . $amount);

            $pdo = db();

            // 現在の銀行残高を取得して保持
            $currentStmt = $pdo->query("SELECT bank_balance FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentBankBalance = $currentData ? (float) $currentData['bank_balance'] : 0;

            // fundsテーブルを更新（銀行残高を保持、持ち出し額を初期値に設定）
            $updateStmt = $pdo->prepare("UPDATE funds SET cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$amount]);

            // 更新された行数が0の場合、新規作成（銀行残高も保持）
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                $insertStmt->execute([$currentBankBalance, $amount]);
            }

            // ログに記録（fund_logsテーブルに）
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['cash_on_hand', 'init', $amount]);

            error_log("Cash on hand initialized successfully: " . $amount);

            return json_ok(['message' => '持ち出し額の初期値を設定しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Init cash on hand error: " . $e->getMessage());
            return json_ng('持ち出し額の初期値設定に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 財務データの取得
    static function get_finance_data()
    {
        try {
            $pdo = db();

            // finance_logsテーブルの存在確認
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'finance_logs'");
            if ($tableCheck->rowCount() == 0) {
                error_log("finance_logs table does not exist, returning default values");
                return json_ok([
                    'bank_balance' => 0.0,
                    'cash_amount' => 0.0,
                    'processed_amount' => 0.0
                ]);
            }

            // デバッグ: finance_logsテーブルの構造を確認
            $structureStmt = $pdo->query("DESCRIBE finance_logs");
            $structure = $structureStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("finance_logs table structure: " . json_encode($structure));

            // デバッグ: finance_logsテーブルの全データを確認
            $allDataStmt = $pdo->query("SELECT * FROM finance_logs ORDER BY created_at DESC LIMIT 10");
            $allData = $allDataStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("finance_logs recent data: " . json_encode($allData));

            // 銀行残高を取得（fundsテーブルから）
            $bankBalance = 0;
            try {
                $bankStmt = $pdo->query("SELECT bank_balance FROM funds ORDER BY updated_at DESC LIMIT 1");
                $bankData = $bankStmt->fetch(PDO::FETCH_ASSOC);
                $bankBalance = $bankData ? (float) $bankData['bank_balance'] : 0;
                error_log("Bank balance from funds table: " . $bankBalance);
            } catch (Exception $e) {
                error_log("Bank balance query error: " . $e->getMessage());
            }

            // 持ち出し額を取得（fundsテーブルから）
            $cashAmount = 0;
            try {
                $cashStmt = $pdo->query("SELECT cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
                $cashData = $cashStmt->fetch(PDO::FETCH_ASSOC);
                $cashAmount = $cashData ? (float) $cashData['cash_on_hand'] : 0;
                error_log("Cash amount (持ち出し額) from funds table: " . $cashAmount);
            } catch (Exception $e) {
                error_log("Cash amount query error: " . $e->getMessage());
            }

            // 処理済み額を取得（レシートの総額）
            $processedAmount = 0;
            try {
                // receiptsテーブルの存在確認
                $receiptsTableCheck = $pdo->query("SHOW TABLES LIKE 'receipts'");
                if ($receiptsTableCheck->rowCount() > 0) {
                    // deleted_atカラムの存在確認
                    $receiptsColumnCheck = $pdo->query("SHOW COLUMNS FROM receipts LIKE 'deleted_at'");
                    $hasReceiptsDeletedAt = $receiptsColumnCheck->rowCount() > 0;

                    if ($hasReceiptsDeletedAt) {
                        $processedStmt = $pdo->query("
                            SELECT COALESCE(SUM(total), 0) as total 
                            FROM receipts 
                            WHERE deleted_at IS NULL
                        ");
                    } else {
                        $processedStmt = $pdo->query("
                            SELECT COALESCE(SUM(total), 0) as total 
                            FROM receipts
                        ");
                    }
                    $processedAmount = $processedStmt->fetch()['total'] ?? 0;
                }
            } catch (Exception $e) {
                error_log("Receipts table query error: " . $e->getMessage());
                // receiptsテーブルが存在しない場合は0を返す
            }

            // 実際の所持金額を取得（fundsテーブルから）
            $actualCash = 0;
            try {
                // fundsテーブルから実際の所持金額を取得
                $fundsStmt = $pdo->query("SELECT actual_cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
                $fundsData = $fundsStmt->fetch(PDO::FETCH_ASSOC);
                $actualCash = $fundsData ? (float) $fundsData['actual_cash_on_hand'] : 0;
                error_log("Actual cash from funds table: " . $actualCash);
            } catch (Exception $e) {
                error_log("Actual cash query error: " . $e->getMessage());
            }

            return json_ok([
                'bank_balance' => (float) $bankBalance,
                'cash_amount' => (float) $cashAmount,
                'processed_amount' => (float) $processedAmount,
                'actual_cash' => (float) $actualCash
            ]);
        } catch (Exception $e) {
            error_log("Finance data error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('財務データの取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 銀行残高の更新
    static function update_bank_balance()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount = (float) ($in['amount'] ?? 0);

        if ($amount < 0) {
            return json_ng('金額は0以上である必要があります', 400);
        }

        try {
            $pdo = db();

            // 現在の持ち出し額を取得して保持
            $currentStmt = $pdo->query("SELECT cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentCashOnHand = $currentData ? (float) $currentData['cash_on_hand'] : 0;

            // fundsテーブルを更新（持ち出し額を保持）
            $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$amount]);

            // 更新された行数が0の場合、新規作成（持ち出し額も保持）
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                $insertStmt->execute([$amount, $currentCashOnHand]);
            }

            // ログに記録（fund_logsテーブルに）
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['bank_balance', 'update', $amount]);

            error_log("Bank balance updated successfully: " . $amount);

            return json_ok(['message' => '銀行残高を更新しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Bank balance update error: " . $e->getMessage());
            return json_ng('銀行残高の更新に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 所持金額の更新
    static function update_cash_amount()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount = (float) ($in['amount'] ?? 0);

        if ($amount < 0) {
            return json_ng('金額は0以上である必要があります', 400);
        }

        try {
            $pdo = db();

            // 現在の銀行残高を取得して保持
            $currentStmt = $pdo->query("SELECT bank_balance FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentBankBalance = $currentData ? (float) $currentData['bank_balance'] : 0;

            // fundsテーブルを更新（銀行残高を保持）
            $updateStmt = $pdo->prepare("UPDATE funds SET cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$amount]);

            // 更新された行数が0の場合、新規作成（銀行残高も保持）
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                $insertStmt->execute([$currentBankBalance, $amount]);
            }

            // ログに記録（fund_logsテーブルに）
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['cash_amount', 'update', $amount]);

            error_log("Cash amount updated successfully: " . $amount);

            return json_ok(['message' => '所持金額を更新しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Cash amount update error: " . $e->getMessage());
            return json_ng('所持金額の更新に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 銀行から引き出し
    static function bank_withdraw()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount = (float) ($in['amount'] ?? 0);

        if ($amount <= 0) {
            return json_ng('引き出し額は0より大きい必要があります', 400);
        }

        try {
            $pdo = db();

            // 現在の銀行残高と持ち出し額を取得
            $currentStmt = $pdo->query("SELECT bank_balance, cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentBankBalance = $currentData ? (float) $currentData['bank_balance'] : 0;
            $currentCashOnHand = $currentData ? (float) $currentData['cash_on_hand'] : 0;

            $newBankBalance = $currentBankBalance - $amount;
            $newCashOnHand = $currentCashOnHand + $amount;

            // fundsテーブルを更新
            $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$newBankBalance, $newCashOnHand]);

            // 更新された行数が0の場合、新規作成
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                $insertStmt->execute([$newBankBalance, $newCashOnHand]);
            }

            // 履歴をfund_logsテーブルに記録
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['bank_withdraw', 'withdraw', $amount]);

            return json_ok(['message' => '銀行から引き出しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Bank withdraw error: " . $e->getMessage());
            return json_ng('引き出しに失敗しました', 500);
        }
    }

    // 銀行に預入
    static function bank_deposit()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount = (float) ($in['amount'] ?? 0);

        if ($amount <= 0) {
            return json_ng('預入額は0より大きい必要があります', 400);
        }

        try {
            $pdo = db();

            // 現在の銀行残高と持ち出し額を取得
            $currentStmt = $pdo->query("SELECT bank_balance, cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentBankBalance = $currentData ? (float) $currentData['bank_balance'] : 0;
            $currentCashOnHand = $currentData ? (float) $currentData['cash_on_hand'] : 0;

            $newBankBalance = $currentBankBalance + $amount;
            $newCashOnHand = $currentCashOnHand - $amount;

            // fundsテーブルを更新
            $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$newBankBalance, $newCashOnHand]);

            // 更新された行数が0の場合、新規作成
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                $insertStmt->execute([$newBankBalance, $newCashOnHand]);
            }

            // 履歴をfund_logsテーブルに記録
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['bank_deposit', 'deposit', $amount]);

            return json_ok(['message' => '銀行に預入しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Bank deposit error: " . $e->getMessage());
            return json_ng('預入に失敗しました', 500);
        }
    }

    // 現金から引き出し
    static function cash_withdraw()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount = (float) ($in['amount'] ?? 0);

        if ($amount <= 0) {
            return json_ng('引き出し額は0より大きい必要があります', 400);
        }

        try {
            $stmt = db()->prepare("INSERT INTO finance_logs (type, amount, action, created_at) VALUES ('cash_amount', ?, 'withdraw', NOW())");
            $stmt->execute([-$amount]);

            return json_ok(['message' => '現金を引き出しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Cash withdraw error: " . $e->getMessage());
            return json_ng('引き出しに失敗しました', 500);
        }
    }

    // 現金に預入
    static function cash_deposit()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount = (float) ($in['amount'] ?? 0);

        if ($amount <= 0) {
            return json_ng('預入額は0より大きい必要があります', 400);
        }

        try {
            $stmt = db()->prepare("INSERT INTO finance_logs (type, amount, action, created_at) VALUES ('cash_amount', ?, 'deposit', NOW())");
            $stmt->execute([$amount]);

            return json_ok(['message' => '現金を預入しました', 'amount' => $amount]);
        } catch (Exception $e) {
            error_log("Cash deposit error: " . $e->getMessage());
            return json_ng('預入に失敗しました', 500);
        }
    }

    // 処理済み額の再算出
    static function recalculate_processed()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        try {
            $pdo = db();

            // receiptsテーブルの存在確認
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'receipts'");
            if ($tableCheck->rowCount() == 0) {
                error_log("receipts table does not exist, returning 0");
                return json_ok([
                    'message' => '処理済み額を再算出しました（レシートテーブルが存在しないため0）',
                    'processed_amount' => 0
                ]);
            }

            // deleted_atカラムの存在確認
            $columnCheck = $pdo->query("SHOW COLUMNS FROM receipts LIKE 'deleted_at'");
            $hasDeletedAt = $columnCheck->rowCount() > 0;

            error_log("receipts table has deleted_at column: " . ($hasDeletedAt ? 'yes' : 'no'));

            // deleted_atカラムがある場合は条件を追加、ない場合は全件取得
            if ($hasDeletedAt) {
                $stmt = $pdo->query("SELECT COALESCE(SUM(total), 0) as total FROM receipts WHERE deleted_at IS NULL");
            } else {
                $stmt = $pdo->query("SELECT COALESCE(SUM(total), 0) as total FROM receipts");
            }

            $result = $stmt->fetch();
            $processedAmount = (float) $result['total'];

            error_log("Recalculated processed amount: " . $processedAmount);

            return json_ok([
                'message' => '処理済み額を再算出しました',
                'processed_amount' => $processedAmount
            ]);
        } catch (Exception $e) {
            error_log("Recalculate processed amount error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('処理済み額の再算出に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 財務ログの取得
    static function get_finance_logs()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        try {
            $pdo = db();

            // finance_logsとfund_logsの両方からログを取得（安定した順序で）
            $stmt = $pdo->query("
                (SELECT id, type, amount, action, created_at, 'finance_logs' as source, 1 as priority, NULL as description
                 FROM finance_logs)
                UNION ALL
                (SELECT id, type, amount, action, created_at, 'fund_logs' as source, 2 as priority, description
                 FROM fund_logs)
                ORDER BY created_at DESC, priority ASC, id DESC
                LIMIT 100
            ");
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ログの順序をさらに安定化（PHP側でソート）
            usort($logs, function ($a, $b) {
                // まず日時で比較
                $dateCompare = strtotime($b['created_at']) - strtotime($a['created_at']);
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                // 日時が同じ場合は優先度で比較
                $priorityCompare = $a['priority'] - $b['priority'];
                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }

                // 優先度も同じ場合はIDで比較
                return $b['id'] - $a['id'];
            });

            error_log("Retrieved " . count($logs) . " logs from both tables");
            error_log("Log details: " . json_encode(array_slice($logs, 0, 5))); // 最初の5件をログ出力

            return json_ok($logs);
        } catch (Exception $e) {
            error_log("Finance logs error: " . $e->getMessage());
            return json_ng('財務ログの取得に失敗しました', 500);
        }
    }

    // 個別ログの削除（財務値に影響しない）
    static function delete_finance_log()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        try {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $logId = $in['logId'] ?? null;
            $source = $in['source'] ?? 'finance_logs'; // デフォルトはfinance_logs

            if (!$logId) {
                return json_ng('ログIDが指定されていません', 400);
            }

            $pdo = db();

            // ログの存在確認（指定されたテーブルから）
            $tableName = ($source === 'fund_logs') ? 'fund_logs' : 'finance_logs';
            $stmt = $pdo->prepare("SELECT id FROM {$tableName} WHERE id = ?");
            $stmt->execute([$logId]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$log) {
                return json_ng('ログが見つかりません', 404);
            }

            // ログを削除（財務値には影響しない）
            $stmt = $pdo->prepare("DELETE FROM {$tableName} WHERE id = ?");
            $stmt->execute([$logId]);

            return json_ok(['message' => 'ログを削除しました（財務値は変わりません）']);
        } catch (Exception $e) {
            error_log("Delete finance log error: " . $e->getMessage());
            return json_ng('ログの削除に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 全ログのクリア（財務値に影響しない）
    static function clear_finance_logs()
    {
        // require_role(['ADMIN']); // 一時的に認証チェックを無効化

        try {
            $pdo = db();

            // finance_logsテーブルをクリア
            $stmt1 = $pdo->query("DELETE FROM finance_logs");
            $deletedCount1 = $stmt1->rowCount();

            // fund_logsテーブルをクリア
            $stmt2 = $pdo->query("DELETE FROM fund_logs");
            $deletedCount2 = $stmt2->rowCount();

            $totalDeleted = $deletedCount1 + $deletedCount2;

            return json_ok(['message' => "すべてのログを削除しました（{$totalDeleted}件）財務値は変わりません"]);
        } catch (Exception $e) {
            error_log("Clear finance logs error: " . $e->getMessage());
            return json_ng('ログのクリアに失敗しました: ' . $e->getMessage(), 500);
        }
    }

    public static function update_actual_cash()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $amount = $input['amount'] ?? 0;

            error_log("Update actual cash: " . $amount);

            $pdo = db();

            // 現在の銀行残高と持ち出し額を取得して保持
            $currentStmt = $pdo->query("SELECT bank_balance, cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentBankBalance = $currentData ? (float) $currentData['bank_balance'] : 0;
            $currentCashOnHand = $currentData ? (float) $currentData['cash_on_hand'] : 0;

            // fundsテーブルを更新（銀行残高と持ち出し額を保持、実際の所持金額を更新）
            $updateStmt = $pdo->prepare("UPDATE funds SET actual_cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$amount]);

            // 更新された行数が0の場合、新規作成（銀行残高と持ち出し額も保持）
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, actual_cash_on_hand, updated_at) VALUES (1, ?, ?, ?, NOW())");
                $insertStmt->execute([$currentBankBalance, $currentCashOnHand, $amount]);
            }

            // ログに記録（fund_logsテーブルに）
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['actual_cash', 'update', $amount]);

            error_log("Actual cash updated successfully: " . $amount);

            return json_ok(['message' => '実際の所持金額を更新しました']);
        } catch (Exception $e) {
            error_log("Update actual cash error: " . $e->getMessage());
            return json_ng('実際の所持金額の更新に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    public static function init_actual_cash()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $amount = $input['amount'] ?? 0;

            if ($amount < 0) {
                return json_ng('有効な金額を入力してください', 400);
            }

            error_log("Init actual cash: " . $amount);

            $pdo = db();

            // 現在の銀行残高と持ち出し額を取得して保持
            $currentStmt = $pdo->query("SELECT bank_balance, cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
            $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $currentBankBalance = $currentData ? (float) $currentData['bank_balance'] : 0;
            $currentCashOnHand = $currentData ? (float) $currentData['cash_on_hand'] : 0;

            // fundsテーブルを更新（銀行残高と持ち出し額を保持、実際の所持金額を更新）
            $updateStmt = $pdo->prepare("UPDATE funds SET actual_cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
            $updateResult = $updateStmt->execute([$amount]);

            // 更新された行数が0の場合、新規作成（銀行残高と持ち出し額も保持）
            if ($updateStmt->rowCount() == 0) {
                $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, actual_cash_on_hand, updated_at) VALUES (1, ?, ?, ?, NOW())");
                $insertStmt->execute([$currentBankBalance, $currentCashOnHand, $amount]);
            }

            // ログに記録（fund_logsテーブルに）
            $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
            $logStmt->execute(['actual_cash', 'init', $amount]);

            error_log("Actual cash initialized successfully: " . $amount);

            return json_ok(['message' => '実際の所持金額を初期値として設定しました']);
        } catch (Exception $e) {
            error_log("Init actual cash error: " . $e->getMessage());
            return json_ng('初期値の設定に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    public static function add_income()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $amount = $input['amount'] ?? 0;
            $description = $input['description'] ?? '';

            if ($amount <= 0) {
                return json_ng('有効な金額を入力してください', 400);
            }

            if (empty($description)) {
                return json_ng('収入内容を入力してください', 400);
            }

            error_log("Add income: " . $amount . " - " . $description);

            $pdo = db();

            // income_recordsテーブルの存在確認と作成
            try {
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'income_records'");
                if ($tableCheck->rowCount() == 0) {
                    error_log("Creating income_records table");
                    $pdo->exec("
                        CREATE TABLE income_records (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            amount DECIMAL(10,2) NOT NULL,
                            description TEXT NOT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        )
                    ");
                    error_log("income_records table created successfully");
                } else {
                    error_log("income_records table already exists");
                }
            } catch (Exception $e) {
                error_log("Table creation error: " . $e->getMessage());
                return json_ng('テーブル作成に失敗しました: ' . $e->getMessage(), 500);
            }

            // トランザクション開始（既存のトランザクションをチェック）
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                error_log("Transaction started");
            } else {
                error_log("Transaction already active");
            }

            try {
                // 現在の収入記録数を確認
                $countStmt = $pdo->query("SELECT COUNT(*) as count FROM income_records");
                $currentCount = $countStmt->fetch()['count'];
                error_log("Current income records count before insert: " . $currentCount);

                // 収入データをincome_recordsテーブルに保存
                error_log("Inserting income record: amount=" . $amount . ", description=" . $description);
                $stmt = $pdo->prepare("INSERT INTO income_records (amount, description, created_at) VALUES (?, ?, NOW())");
                $result = $stmt->execute([$amount, $description]);
                error_log("Income record insert result: " . ($result ? 'success' : 'failed'));

                // 挿入後の収入記録数を確認
                $countStmt2 = $pdo->query("SELECT COUNT(*) as count FROM income_records");
                $newCount = $countStmt2->fetch()['count'];
                error_log("Income records count after insert: " . $newCount);

                // 銀行残高を更新（fundsテーブルに加算）
                error_log("Updating bank balance in funds table: adding amount=" . $amount);

                // 現在の銀行残高を取得
                $currentStmt = $pdo->query("SELECT bank_balance FROM funds ORDER BY updated_at DESC LIMIT 1");
                $currentBalance = $currentStmt->fetch(PDO::FETCH_ASSOC);
                $currentBankBalance = $currentBalance ? (float) $currentBalance['bank_balance'] : 0;
                $newBankBalance = $currentBankBalance + $amount;

                error_log("Current bank balance: " . $currentBankBalance . ", adding: " . $amount . ", new balance: " . $newBankBalance);

                // fundsテーブルを更新（既存レコードを更新、なければ新規作成）
                $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, updated_at = NOW() WHERE id = 1");
                $updateResult = $updateStmt->execute([$newBankBalance]);

                // 更新された行数が0の場合、新規作成
                if ($updateStmt->rowCount() == 0) {
                    $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, updated_at) VALUES (1, ?, NOW())");
                    $result = $insertStmt->execute([$newBankBalance]);
                    error_log("Funds table insert result: " . ($result ? 'success' : 'failed'));
                } else {
                    error_log("Funds table update result: success");
                }

                // 履歴をfund_logsテーブルに記録（descriptionカラムがあるかチェック）
                try {
                    // descriptionカラムの存在確認
                    $columnCheck = $pdo->query("SHOW COLUMNS FROM fund_logs LIKE 'description'");
                    $hasDescription = $columnCheck->rowCount() > 0;

                    if ($hasDescription) {
                        $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $logResult = $logStmt->execute(['income', 'add', $amount, $description]);
                    } else {
                        // descriptionカラムがない場合は追加
                        $pdo->exec("ALTER TABLE fund_logs ADD COLUMN description TEXT");
                        $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $logResult = $logStmt->execute(['income', 'add', $amount, $description]);
                    }
                    error_log("Fund log insert result: " . ($logResult ? 'success' : 'failed'));
                } catch (Exception $e) {
                    error_log("Fund log insert error: " . $e->getMessage());
                    // エラーが発生した場合はdescriptionなしで記録
                    $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
                    $logResult = $logStmt->execute(['income', 'add', $amount]);
                }

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                    error_log("Transaction committed");
                }

                error_log("Income added successfully: " . $amount . " - " . $description);

                return json_ok(['message' => '収入を記録しました']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                    error_log("Transaction rolled back due to error: " . $e->getMessage());
                }
                throw $e;
            }
        } catch (Exception $e) {
            error_log("Add income error: " . $e->getMessage());
            return json_ng('収入の記録に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    public static function get_income_list()
    {
        try {
            $pdo = db();

            // income_recordsテーブルの存在確認
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'income_records'");
            if ($tableCheck->rowCount() == 0) {
                return json_ok(['incomes' => []]);
            }

            // 収入一覧を取得
            $stmt = $pdo->query("
                SELECT 
                    id,
                    amount,
                    description,
                    created_at
                FROM income_records 
                ORDER BY created_at DESC
            ");
            $incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            error_log("Retrieved " . count($incomes) . " income records");
            error_log("Income records details: " . json_encode($incomes));

            return json_ok(['incomes' => $incomes]);
        } catch (Exception $e) {
            error_log("Get income list error: " . $e->getMessage());
            return json_ng('収入一覧の取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    public static function delete_income()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $incomeId = $input['id'] ?? 0;

            if ($incomeId <= 0) {
                return json_ng('有効なIDを指定してください', 400);
            }

            error_log("Delete income: " . $incomeId);

            $pdo = db();

            // トランザクション開始（既存のトランザクションをチェック）
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                error_log("Delete transaction started");
            } else {
                error_log("Delete transaction already active");
            }

            try {
                // 削除対象の収入記録を取得
                $stmt = $pdo->prepare("SELECT amount, description FROM income_records WHERE id = ?");
                $stmt->execute([$incomeId]);
                $income = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$income) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return json_ng('収入記録が見つかりません', 404);
                }

                $amount = $income['amount'];
                $description = $income['description'];

                // 収入記録を削除
                $stmt = $pdo->prepare("DELETE FROM income_records WHERE id = ?");
                $stmt->execute([$incomeId]);

                // 銀行残高から差し引く（fundsテーブルを直接更新）
                error_log("Updating bank balance in funds table: subtracting amount=" . $amount);

                // 現在の銀行残高を取得
                $currentStmt = $pdo->query("SELECT bank_balance FROM funds ORDER BY updated_at DESC LIMIT 1");
                $currentBalance = $currentStmt->fetch(PDO::FETCH_ASSOC);
                $currentBankBalance = $currentBalance ? (float) $currentBalance['bank_balance'] : 0;
                $newBankBalance = $currentBankBalance - $amount;

                error_log("Current bank balance: " . $currentBankBalance . ", subtracting: " . $amount . ", new balance: " . $newBankBalance);

                // fundsテーブルを更新（既存レコードを更新、なければ新規作成）
                $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, updated_at = NOW() WHERE id = 1");
                $updateResult = $updateStmt->execute([$newBankBalance]);

                // 更新された行数が0の場合、新規作成
                if ($updateStmt->rowCount() == 0) {
                    $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, updated_at) VALUES (1, ?, NOW())");
                    $insertStmt->execute([$newBankBalance]);
                    error_log("Funds table insert result for delete");
                } else {
                    error_log("Funds table update result for delete: success");
                }

                // 履歴をfund_logsテーブルに記録（descriptionカラムがあるかチェック）
                try {
                    // descriptionカラムの存在確認
                    $columnCheck = $pdo->query("SHOW COLUMNS FROM fund_logs LIKE 'description'");
                    $hasDescription = $columnCheck->rowCount() > 0;

                    if ($hasDescription) {
                        $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $logStmt->execute(['income', 'delete', -$amount, $description]);
                    } else {
                        // descriptionカラムがない場合は追加
                        $pdo->exec("ALTER TABLE fund_logs ADD COLUMN description TEXT");
                        $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, description, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $logStmt->execute(['income', 'delete', -$amount, $description]);
                    }
                } catch (Exception $e) {
                    error_log("Fund log insert error for delete: " . $e->getMessage());
                    // エラーが発生した場合はdescriptionなしで記録
                    $logStmt = $pdo->prepare("INSERT INTO fund_logs (type, action, amount, created_at) VALUES (?, ?, ?, NOW())");
                    $logStmt->execute(['income', 'delete', -$amount]);
                }

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                    error_log("Delete transaction committed");
                }

                error_log("Income deleted successfully: " . $incomeId);

                return json_ok(['message' => '収入記録を削除しました']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                    error_log("Delete transaction rolled back due to error: " . $e->getMessage());
                }
                throw $e;
            }
        } catch (Exception $e) {
            error_log("Delete income error: " . $e->getMessage());
            return json_ng('収入の削除に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 銀行残高の初期値を取得
    public static function get_initial_bank_balance()
    {
        try {
            $pdo = db();

            // fund_logsテーブルから初期設定のログを取得
            $stmt = $pdo->query("
                SELECT amount 
                FROM fund_logs 
                WHERE type = 'bank_balance' AND action = 'init' 
                ORDER BY created_at ASC 
                LIMIT 1
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $initialBalance = $result ? (float) $result['amount'] : 0;

            error_log("Initial bank balance retrieved: " . $initialBalance);

            return json_ok(['initial_balance' => $initialBalance]);
        } catch (Exception $e) {
            error_log("Get initial bank balance error: " . $e->getMessage());
            return json_ng('銀行残高の初期値取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // 未処理の購入希望額を取得
    public static function get_pending_requests_amount()
    {
        try {
            $pdo = db();

            // requestsテーブルの存在確認
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'requests'");
            if ($tableCheck->rowCount() == 0) {
                return json_ok(['pending_amount' => 0]);
            }

            // 渡し済み、振込済み、回収済み、または処理完了（レシート提出済み）であり、
            // まだ最終化（記入完了）されていない購入希望について、
            // 渡し額（予算）と処理済み額（実績）の差額（未処理額）の合計を取得
            // レシートが削除された場合、processed_amountが減るため、その分が再び「未処理」として計上される
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(cash_given - COALESCE(processed_amount, 0)), 0) as total 
                FROM requests 
                WHERE state IN ('CASH_GIVEN', 'TRANSFERRED', 'COLLECTED', 'RECEIPT_DONE') 
                AND state NOT IN ('FINALIZED', 'REJECTED')
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $pendingAmount = $result ? (float) $result['total'] : 0;

            error_log("Pending requests amount retrieved: " . $pendingAmount);

            return json_ok(['pending_amount' => $pendingAmount]);
        } catch (Exception $e) {
            error_log("Get pending requests amount error: " . $e->getMessage());
            return json_ng('未処理の購入希望額取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }

    // デバッグ用: データベースの状態を確認
    static function debug_database()
    {
        try {
            $pdo = db();

            $result = [];

            // テーブル一覧
            $tablesStmt = $pdo->query("SHOW TABLES");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
            $result['tables'] = $tables;

            // データベースの文字セット情報
            $dbCharset = $pdo->query("
                SELECT default_character_set_name, default_collation_name 
                FROM information_schema.SCHEMATA 
                WHERE schema_name = DATABASE()
            ")->fetch(PDO::FETCH_ASSOC);
            $result['database_charset'] = $dbCharset;

            // 各テーブルの文字セット情報
            $tableCharsets = $pdo->query("
                SELECT table_name, table_collation 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ")->fetchAll(PDO::FETCH_ASSOC);
            $result['table_charsets'] = $tableCharsets;

            // finance_logsテーブルの構造
            if (in_array('finance_logs', $tables)) {
                $structureStmt = $pdo->query("DESCRIBE finance_logs");
                $result['finance_logs_structure'] = $structureStmt->fetchAll(PDO::FETCH_ASSOC);

                // finance_logsの全データ
                $dataStmt = $pdo->query("SELECT * FROM finance_logs ORDER BY created_at DESC");
                $result['finance_logs_data'] = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

                // type別の集計
                $typeStmt = $pdo->query("SELECT type, COUNT(*) as count, SUM(amount) as total FROM finance_logs GROUP BY type");
                $result['finance_logs_by_type'] = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // finance_balancesテーブルの構造とデータ
            if (in_array('finance_balances', $tables)) {
                $structureStmt = $pdo->query("DESCRIBE finance_balances");
                $result['finance_balances_structure'] = $structureStmt->fetchAll(PDO::FETCH_ASSOC);

                $dataStmt = $pdo->query("SELECT * FROM finance_balances");
                $result['finance_balances_data'] = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // fund_logsテーブルの構造とデータ
            if (in_array('fund_logs', $tables)) {
                $structureStmt = $pdo->query("DESCRIBE fund_logs");
                $result['fund_logs_structure'] = $structureStmt->fetchAll(PDO::FETCH_ASSOC);

                $dataStmt = $pdo->query("SELECT * FROM fund_logs ORDER BY created_at DESC LIMIT 10");
                $result['fund_logs_data'] = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // fundsテーブルの構造とデータ
            if (in_array('funds', $tables)) {
                $structureStmt = $pdo->query("DESCRIBE funds");
                $result['funds_structure'] = $structureStmt->fetchAll(PDO::FETCH_ASSOC);

                $dataStmt = $pdo->query("SELECT * FROM funds");
                $result['funds_data'] = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            return json_ok($result);
        } catch (Exception $e) {
            error_log("Debug database error: " . $e->getMessage());
            return json_ng('データベースの確認に失敗しました: ' . $e->getMessage(), 500);
        }
    }
}
?>