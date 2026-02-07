<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/util.php';

// 5桁以内の簡略化された受付番号を生成
function generateShortRequestNo()
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $maxAttempts = 100;

    for ($i = 0; $i < $maxAttempts; $i++) {
        // 4桁のランダム文字列を生成（ローマ字+数字）
        $no = '';
        for ($j = 0; $j < 4; $j++) {
            $no .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // 重複チェック
        $check = db()->prepare('SELECT id FROM requests WHERE request_no = ?');
        $check->execute([$no]);
        if (!$check->fetch()) {
            return $no;
        }
    }

    // 重複が解決しない場合はタイムスタンプベースにフォールバック
    return 'R' . substr(str_replace(['-', ':', ' '], '', date('Y-m-d H:i:s')), -4);
}


class RequestController
{
    static function create()
    { // 提出（OFFICER用）
        require_role(['OFFICER', 'FINANCE', 'ADMIN']);
        $cfg = require __DIR__ . '/../config.php';

        // デバッグ情報をログに記録
        error_log("=== Request Create Debug ===");
        error_log("POST data: " . json_encode($_POST));
        error_log("FILES data: " . json_encode($_FILES));

        // multipart
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $department_id = (int) ($_POST['department_id'] ?? 0);
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $expects = (string) ($_POST['expects_network'] ?? 'NONE');

        // 振込口座情報
        $bank_name = trim((string) ($_POST['bank_name'] ?? ''));
        $branch_name = trim((string) ($_POST['branch_name'] ?? ''));
        $account_type = trim((string) ($_POST['account_type'] ?? ''));
        $account_number = trim((string) ($_POST['account_number'] ?? ''));
        $account_holder = trim((string) ($_POST['account_holder'] ?? ''));

        error_log("Parsed data: member_id=$member_id, department_id=$department_id, summary='$summary', expects='$expects'");

        if (!$member_id || !$department_id || $summary === '') {
            error_log("Validation failed: missing required fields");
            return json_ng('必須項目が不足しています（部署、氏名、概要）');
        }

        // 振込選択時は振込口座情報が必須
        if ($expects === 'BANK_TRANSFER') {
            error_log("Bank transfer validation - bank_name: '$bank_name', branch_name: '$branch_name', account_type: '$account_type', account_number: '$account_number', account_holder: '$account_holder'");
            if (!$bank_name || !$branch_name || !$account_type || !$account_number || !$account_holder) {
                error_log("Bank transfer validation failed - missing required fields");
                return json_ng('振込を選択した場合は、振込口座情報をすべて入力してください');
            }
        }

        // エクセルファイルは任意のため、ファイルがアップロードされている場合のみ処理
        $excel_file = null;
        if (isset($_FILES['excel']) && $_FILES['excel']['error'] === UPLOAD_ERR_OK) {
            $excel_file = $_FILES['excel'];
            error_log("Excel file uploaded: " . $excel_file['name'] . " (" . $excel_file['size'] . " bytes)");
        } else {
            error_log("No excel file uploaded or upload error: " . ($_FILES['excel']['error'] ?? 'not set'));
        }

        // 保存先（受付番号を5桁以内に簡略化）
        $no = generateShortRequestNo();
        $basedir = $cfg['upload_dir'] . '/' . date('Y') . '/' . date('m') . '/' . $no;

        try {
            error_log("Attempting to create directory: " . $basedir);
            ensure_dir($basedir);
            error_log("Directory created successfully: " . $basedir);
        } catch (Exception $e) {
            error_log("Upload directory creation failed: " . $e->getMessage());
            error_log("Directory path: " . $basedir);
            error_log("Parent directory exists: " . (is_dir(dirname($basedir)) ? 'yes' : 'no'));
            error_log("Parent directory writable: " . (is_writable(dirname($basedir)) ? 'yes' : 'no'));
            return json_ng('アップロードディレクトリの作成に失敗しました。管理者にお問い合わせください。');
        }

        // エクセルファイルの処理（ファイルがアップロードされている場合のみ）
        $path = null;
        if ($excel_file) {
            // ファイルサイズチェック（簡易）
            $f = $excel_file;
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if ($f['size'] > 10 * 1024 * 1024) {
                error_log("File too large: " . $f['size'] . " bytes");
                return json_ng('ファイルサイズが大きすぎます（10MB以下にしてください）');
            }

            // ファイル名を元の名前のまま保持（重複回避のためタイムスタンプとランダム数を追加）
            $original_name = pathinfo($f['name'], PATHINFO_FILENAME);
            $timestamp = date('His');
            $random = sprintf('%03d', random_int(100, 999));
            $fname = $original_name . '_' . $timestamp . '_' . $random . '.' . $ext;
            $path = $basedir . '/' . $fname;

            if (!move_uploaded_file($f['tmp_name'], $path)) {
                error_log("File upload failed: " . $f['tmp_name'] . " -> " . $path);
                return json_ng('ファイルのアップロードに失敗しました。管理者にお問い合わせください。');
            }
            error_log("Excel file saved to: " . $path);
        }

        error_log("Inserting request with bank info - expects: '$expects', bank_name: '$bank_name', branch_name: '$branch_name', account_type: '$account_type', account_number: '$account_number', account_holder: '$account_holder'");

        // Active Year取得
        $year_stmt = db()->query("SELECT id FROM years WHERE is_active=1 LIMIT 1");
        $year_row = $year_stmt->fetch();
        $year_id = $year_row ? $year_row['id'] : null;

        try {
            $st = db()->prepare('INSERT INTO requests(request_no,member_id,department_id,submitted_at,summary,expects_network,excel_path,bank_name,branch_name,account_type,account_number,account_holder,year_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $result = $st->execute([$no, $member_id, $department_id, date('Y-m-d H:i:s'), $summary, $expects, $path, $bank_name ?: null, $branch_name ?: null, $account_type ?: null, $account_number ?: null, $account_holder ?: null, $year_id]);

            if (!$result) {
                error_log("Database insert failed - SQL error info: " . json_encode($st->errorInfo()));
                return json_ng('データベース挿入に失敗しました: ' . implode(', ', $st->errorInfo()));
            }

            $inserted_id = db()->lastInsertId();
            error_log("Request inserted successfully with ID: " . $inserted_id . " Year ID: " . $year_id);

        } catch (Exception $e) {
            error_log("Database insert error: " . $e->getMessage());
            error_log("SQL error info: " . json_encode($st->errorInfo()));
            return json_ng('データベースエラー: ' . $e->getMessage());
        }

        try {
            db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')
                ->execute([$inserted_id, 'OFFICER', 'CREATE', 'created request']);
            error_log("Audit log created successfully");
        } catch (Exception $e) {
            error_log("Audit log creation failed: " . $e->getMessage());
            // 監査ログの失敗は致命的ではないので続行
        }

        error_log("Request creation completed successfully - Request No: $no");

        // デバッグ用：返却データをログに記録
        $response_data = ['request_no' => $no];
        error_log("Returning response data: " . json_encode($response_data));

        // 直接json_encodeを試してみる
        $direct_response = json_encode(['ok' => true, 'data' => $response_data], JSON_UNESCAPED_UNICODE);
        error_log("Direct JSON response: " . $direct_response);

        return json_ok($response_data);
    }


    static function list()
    { // 財務/管理者/役員向けリスト
        $cur = current_role();
        if (!$cur || !in_array($cur, ['FINANCE', 'ADMIN', 'OFFICER'], true)) {
            return json_ng('unauthorized', 401);
        }

        try {
            $q = $_GET['q'] ?? '';
            $state = $_GET['state'] ?? '';
            $dep = $_GET['dept'] ?? '';
            $member_id = $_GET['member_id'] ?? '';
            $department_id = $_GET['department_id'] ?? '';

            error_log("List request - q: '$q', state: '$state', dep: '$dep', member_id: '$member_id', department_id: '$department_id'");

            $where = [];
            $args = [];

            if ($q != '') {
                $where[] = '(r.summary LIKE ? OR m.name LIKE ?)';
                $args[] = "%$q%";
                $args[] = "%$q%";
            }

            if ($state != '') {
                // カンマ区切りで複数の状態を指定可能
                if (strpos($state, ',') !== false) {
                    $states = explode(',', $state);
                    $placeholders = str_repeat('?,', count($states) - 1) . '?';
                    $where[] = "r.state IN ($placeholders)";
                    foreach ($states as $s) {
                        $args[] = trim($s);
                    }
                } else {
                    $where[] = 'r.state=?';
                    $args[] = $state;
                }
            }

            if ($dep != '') {
                $where[] = 'r.department_id=?';
                $args[] = (int) $dep;
            }
            if ($member_id != '') {
                $where[] = 'r.member_id=?';
                $args[] = (int) $member_id;
            }
            if ($department_id != '') {
                $where[] = 'r.department_id=?';
                $args[] = (int) $department_id;
            }

            // 年度フィルタ（指定がない場合はアクティブ年度）
            $year_id = $_GET['year_id'] ?? null;
            if ($year_id === null || $year_id === '') {
                // default to active year
                $yst = db()->query("SELECT id FROM years WHERE is_active=1 LIMIT 1");
                $yrow = $yst->fetch();
                if ($yrow) {
                    $where[] = 'r.year_id=?';
                    $args[] = $yrow['id'];
                }
            } else {
                $where[] = 'r.year_id=?';
                $args[] = (int) $year_id;
            }

            // テーブルが存在するかチェック
            $table_check = db()->query("SHOW TABLES LIKE 'requests'")->fetch();
            if (!$table_check) {
                error_log("Requests table does not exist");
                return json_ng('Requests table not found', 500);
            }

            // テーブル構造を確認
            $columns = db()->query("DESCRIBE requests")->fetchAll();
            error_log("Requests table columns: " . json_encode($columns));

            $sql = 'SELECT r.id,r.request_no,r.submitted_at,r.summary,r.expects_network,r.state,r.cash_given,r.expected_total,r.processed_amount,r.rejected_reason,r.bank_name,r.branch_name,r.account_type,r.account_number,r.account_holder,r.excel_path, m.name AS member_name, d.name AS dept_name
        FROM requests r 
        LEFT JOIN members m ON m.id=r.member_id 
        LEFT JOIN departments d ON d.id=r.department_id';

            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY r.submitted_at DESC LIMIT 200';

            error_log("SQL query: $sql");
            error_log("SQL args: " . json_encode($args));

            $st = db()->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll();

            error_log("Query executed successfully, found " . count($rows) . " rows");


            return json_ok($rows);

        } catch (Exception $e) {
            error_log("List method error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('Database error: ' . $e->getMessage());
        }
    }


    static function accept($id)
    {
        require_role(['FINANCE', 'ADMIN']);

        try {
            // リクエストの存在確認
            $check = db()->prepare('SELECT id, state FROM requests WHERE id = ?');
            $check->execute([$id]);
            $request = $check->fetch();

            if (!$request) {
                error_log("Accept failed: Request not found - ID: $id");
                return json_ng('リクエストが見つかりません', 404);
            }

            if ($request['state'] !== 'NEW') {
                error_log("Accept failed: Invalid state transition - ID: $id, Current state: " . $request['state']);
                return json_ng('このリクエストは受理できません（状態: ' . $request['state'] . '）');
            }

            // 状態を受理に変更
            $st = db()->prepare('UPDATE requests SET state="ACCEPTED" WHERE id=? AND state IN ("NEW")');
            $result = $st->execute([$id]);

            if (!$result) {
                error_log("Accept failed: Database update failed - ID: $id, Error: " . json_encode($st->errorInfo()));
                return json_ng('データベース更新に失敗しました');
            }

            if ($st->rowCount() === 0) {
                error_log("Accept failed: No rows updated - ID: $id");
                return json_ng('リクエストの状態更新に失敗しました');
            }

            // 監査ログに記録
            try {
                db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')
                    ->execute([$id, 'FINANCE', 'STATE', 'ACCEPTED']);
            } catch (Exception $e) {
                error_log("Accept audit log failed: " . $e->getMessage());
                // 監査ログの失敗は致命的ではないので続行
            }

            error_log("Accept successful - ID: $id");
            return json_ok();

        } catch (Exception $e) {
            error_log("Accept error: " . $e->getMessage());
            return json_ng('受理処理中にエラーが発生しました: ' . $e->getMessage());
        }
    }

    static function reject($id)
    {
        require_role(['FINANCE', 'ADMIN']);

        try {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $reason = trim((string) ($in['reason'] ?? ''));

            if ($reason === '') {
                error_log("Reject failed: No reason provided - ID: $id");
                return json_ng('却下理由を入力してください');
            }

            // リクエストの存在確認
            $check = db()->prepare('SELECT id, state FROM requests WHERE id = ?');
            $check->execute([$id]);
            $request = $check->fetch();

            if (!$request) {
                error_log("Reject failed: Request not found - ID: $id");
                return json_ng('リクエストが見つかりません', 404);
            }

            if (!in_array($request['state'], ['NEW', 'ACCEPTED'])) {
                error_log("Reject failed: Invalid state for rejection - ID: $id, Current state: " . $request['state']);
                return json_ng('このリクエストは却下できません（状態: ' . $request['state'] . '）');
            }

            // 状態を却下に変更
            $st = db()->prepare('UPDATE requests SET state="REJECTED", rejected_reason=? WHERE id=? AND state IN ("NEW","ACCEPTED")');
            $result = $st->execute([$reason, $id]);

            if (!$result) {
                error_log("Reject failed: Database update failed - ID: $id, Error: " . json_encode($st->errorInfo()));
                return json_ng('データベース更新に失敗しました');
            }

            if ($st->rowCount() === 0) {
                error_log("Reject failed: No rows updated - ID: $id");
                return json_ng('リクエストの状態更新に失敗しました');
            }

            // 監査ログに記録
            try {
                db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')
                    ->execute([$id, 'FINANCE', 'STATE', 'REJECTED']);
            } catch (Exception $e) {
                error_log("Reject audit log failed: " . $e->getMessage());
                // 監査ログの失敗は致命的ではないので続行
            }

            error_log("Reject successful - ID: $id, Reason: $reason");
            return json_ok();

        } catch (Exception $e) {
            error_log("Reject error: " . $e->getMessage());
            return json_ng('却下処理中にエラーが発生しました: ' . $e->getMessage());
        }
    }


    static function set_cash($id)
    {
        require_role(['FINANCE', 'ADMIN']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $cash = (float) ($in['cash_given'] ?? 0);
        db()->prepare('UPDATE requests SET cash_given=? WHERE id=?')->execute([$cash, $id]);
        return json_ok();
    }

    // 状態更新メソッド
// 状態更新メソッド
    static function state($id)
    {
        require_role(['FINANCE', 'ADMIN']);

        error_log("=== State Update Start ===");
        error_log("ID: $id");

        $pdo = db();

        try {
            // 入力データの取得
            $input = file_get_contents('php://input');
            error_log("Raw input: " . $input);

            $in = json_decode($input, true);
            if (!$in) {
                error_log("JSON decode failed");
                return json_ng('Invalid JSON input', 400);
            }

            $next = (string) ($in['next_state'] ?? '');
            error_log("Next state: '$next'");

            if ($next === '') {
                return json_ng('次の状態を指定してください', 400);
            }

            $valid_states = ['NEW', 'ACCEPTED', 'REJECTED', 'CASH_GIVEN', 'COLLECTED', 'RECEIPT_DONE', 'TRANSFERRED', 'FINALIZED'];
            if (!in_array($next, $valid_states, true)) {
                error_log("Invalid state: $next");
                return json_ng('無効な状態です: ' . $next, 400);
            }

            // トランザクション開始
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            // リクエストの存在確認
            $check = $pdo->prepare('SELECT id, state, expects_network, cash_given FROM requests WHERE id = ? FOR UPDATE');
            $check->execute([$id]);
            $request = $check->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                error_log("Request not found: $id");
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                return json_ng('リクエストが見つかりません', 404);
            }

            error_log("Current state: " . $request['state'] . ", Next state: $next");

            // 状態遷移の検証
            if (!self::can_transition($id, $next)) {
                error_log("Invalid state transition from " . $request['state'] . " to $next");
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                return json_ng('無効な状態遷移です: ' . $request['state'] . ' → ' . $next, 400);
            }

            // 状態を更新
            $st = $pdo->prepare('UPDATE requests SET state = ? WHERE id = ?');
            error_log("Executing state update - ID: $id, New state: '$next'");
            $result = $st->execute([$next, $id]);

            if (!$result) {
                error_log("Database update failed: " . json_encode($st->errorInfo()));
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                return json_ng('データベース更新に失敗しました', 500);
            }

            // 渡し済み（CASH_GIVEN）または振込済み（TRANSFERRED）に変更する場合、持ち出し額または銀行残高から減算
            // ただし、ACCEPTEDから変更する場合のみ（重複減算を防ぐ）
            if (($next === 'CASH_GIVEN' || $next === 'TRANSFERRED') && $request['state'] === 'ACCEPTED') {
                try {
                    if ($request['cash_given'] <= 0) {
                        throw new Exception("金額が設定されていません。先に金額を入力してください。");
                    }

                    $amount = (float) $request['cash_given'];

                    // 銀行振込の場合は銀行残高から減算
                    if ($request['expects_network'] === 'BANK_TRANSFER') {
                        // 現在のfundsテーブルの状態を取得
                        $fundsStmt = $pdo->query("SELECT bank_balance, cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
                        $fundsData = $fundsStmt->fetch(PDO::FETCH_ASSOC);
                        $currentBankBalance = $fundsData ? (float) $fundsData['bank_balance'] : 0;
                        $currentCashOnHand = $fundsData ? (float) $fundsData['cash_on_hand'] : 0;

                        // 銀行残高から減算し、持ち出し額は変更しない（正しい振込ロジック）
                        // 以前のロジックでは "cash_on_hand + amount" していましたが、振込は現金を経由しないため削除しました
                        $newBankBalance = $currentBankBalance - $amount;
                        $newCashOnHand = $currentCashOnHand;

                        $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
                        $updateResult = $updateStmt->execute([$newBankBalance, $newCashOnHand]);

                        if ($updateStmt->rowCount() == 0) {
                            // レコードが存在しない場合は新規作成
                            $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                            $insertStmt->execute([$newBankBalance, $newCashOnHand]);
                        }

                        error_log("Bank balance decreased by {$amount} for request {$id}. New balance: {$newBankBalance}. Cash on hand unchanged: {$newCashOnHand}");

                        // ログ記録
                        $pdo->prepare("INSERT INTO finance_logs (type, amount, action) VALUES (?, ?, ?)")
                            ->execute(['bank_balance', -$amount, "transfer_req_{$id}"]);
                    }
                    // 現金渡しの場合は、持ち出し額から減算しない（理論所持金額の計算ロジックに従うため）
                } catch (Exception $e) {
                    error_log("Funds update error: " . $e->getMessage());
                    if ($pdo->inTransaction())
                        $pdo->rollBack();
                    return json_ng('資金データの更新に失敗しました: ' . $e->getMessage(), 500);
                }
            }

            // 振込済み（TRANSFERRED）から他の状態（ACCEPTEDなど）に戻された場合、銀行残高に戻す
            if ($request['state'] === 'TRANSFERRED' && $next !== 'TRANSFERRED' && $next !== 'COLLECTED' && $next !== 'RECEIPT_DONE' && $next !== 'FINALIZED') {
                try {
                    if ($request['cash_given'] > 0 && $request['expects_network'] === 'BANK_TRANSFER') {
                        $amount = (float) $request['cash_given'];

                        // 現在のfundsテーブルの状態を取得
                        $fundsStmt = $pdo->query("SELECT bank_balance, cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
                        $fundsData = $fundsStmt->fetch(PDO::FETCH_ASSOC);
                        $currentBankBalance = $fundsData ? (float) $fundsData['bank_balance'] : 0;
                        $currentCashOnHand = $fundsData ? (float) $fundsData['cash_on_hand'] : 0;

                        // 銀行残高に戻す
                        $newBankBalance = $currentBankBalance + $amount;
                        $newCashOnHand = $currentCashOnHand; // 現金は変更なし

                        $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
                        $updateResult = $updateStmt->execute([$newBankBalance, $newCashOnHand]);

                        if ($updateStmt->rowCount() == 0) {
                            // レコードが存在しない場合は新規作成
                            $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                            $insertStmt->execute([$newBankBalance, $newCashOnHand]);
                        }

                        error_log("Bank balance increased by {$amount} for request {$id} (state reverted from TRANSFERRED). New balance: {$newBankBalance}.");

                        // ログ記録
                        $pdo->prepare("INSERT INTO finance_logs (type, amount, action) VALUES (?, ?, ?)")
                            ->execute(['bank_balance', $amount, "revert_transfer_req_{$id}"]);
                    }
                } catch (Exception $e) {
                    error_log("Funds revert error: " . $e->getMessage());
                    if ($pdo->inTransaction())
                        $pdo->rollBack();
                    return json_ng('資金データの更新（巻き戻し）に失敗しました: ' . $e->getMessage(), 500);
                }
            }

            // 監査ログに記録
            try {
                $user_role = $_SESSION['role'] ?? 'FINANCE';
                $pdo->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')
                    ->execute([$id, $user_role, 'STATE', $next]);
                error_log("Audit log created successfully");
            } catch (Exception $e) {
                error_log("Audit log creation failed: " . $e->getMessage());
                // 監査ログの失敗は致命的ではないので続行（トランザクション内だが、これは必須ではないと判断する場合はcatchして握りつぶすか、あるいはロールバックするか）
                // ここでは続行する方針
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
                error_log("Transaction committed successfully");
            }

            return json_ok();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                error_log("Transaction rolled back due to error: " . $e->getMessage());
            }
            error_log("State update error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('状態更新中にエラーが発生しました: ' . $e->getMessage(), 500);
        }
    }

    private static function can_transition($id, $next)
    {
        try {
            $cur = db()->prepare('SELECT state, expects_network FROM requests WHERE id=?');
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) {
                error_log("Can transition failed: Request not found - ID: $id");
                return false;
            }

            $state = $row['state'];
            $net = $row['expects_network'] ?? 'NONE';  // デフォルト値を設定

            error_log("Can transition - Current state: '$state', Network: '$net', Target: '$next'");

            $flows = [
                'NONE' => [
                    'NEW=>ACCEPTED',
                    'ACCEPTED=>CASH_GIVEN',
                    'CASH_GIVEN=>COLLECTED',
                    'COLLECTED=>RECEIPT_DONE',
                    'RECEIPT_DONE=>FINALIZED',
                    'COLLECTED=>FINALIZED'  // レシート記入完了で直接FINALIZEDに遷移可能
                ],
                'CONVENIENCE' => [
                    'NEW=>ACCEPTED',
                    'ACCEPTED=>CASH_GIVEN',
                    'CASH_GIVEN=>COLLECTED',
                    'COLLECTED=>RECEIPT_DONE',
                    'RECEIPT_DONE=>FINALIZED',
                    'COLLECTED=>FINALIZED'  // レシート記入完了で直接FINALIZEDに遷移可能
                ],
                'BANK_TRANSFER' => [
                    'NEW=>ACCEPTED',
                    'ACCEPTED=>TRANSFERRED',
                    'TRANSFERRED=>COLLECTED',
                    'COLLECTED=>RECEIPT_DONE',
                    'RECEIPT_DONE=>FINALIZED',
                    'COLLECTED=>FINALIZED'  // レシート記入完了で直接FINALIZEDに遷移可能
                ]
            ];

            $transition = "$state=>$next";
            $allowed = in_array($transition, $flows[$net] ?? [], true);

            error_log("Can transition check - ID: $id, From: $state, To: $next, Network: $net, Allowed: " . ($allowed ? 'yes' : 'no'));

            return $allowed;
        } catch (Exception $e) {
            error_log("Can transition error: " . $e->getMessage());
            return false;
        }
    }




    static function recalc($id)
    {
        require_role(['FINANCE', 'ADMIN']);
        $r = db()->prepare('SELECT expects_network,cash_given,expected_total FROM requests WHERE id=?');
        $r->execute([$id]);
        $row = $r->fetch();
        if (!$row)
            return json_ng('not found', 404);
        $sum = db()->prepare('SELECT COALESCE(SUM(total),0) AS t, COALESCE(SUM(change_returned),0) AS c FROM receipts WHERE request_id=?');
        $sum->execute([$id]);
        $s = $sum->fetch();
        if ($row['expects_network'] === 'NONE') {
            $diff = round(((float) $row['cash_given']) - ($s['t'] + $s['c']), 2);
        } else {
            $diff = round(((float) $row['expected_total']) - $s['t'], 2);
        }
        db()->prepare('UPDATE requests SET diff_amount=? WHERE id=?')->execute([$diff, $id]);
        return json_ok(['sum_total' => (float) $s['t'], 'sum_change' => (float) $s['c'], 'diff' => $diff]);
    }

    static function view_excel($id)
    {
        require_role(['FINANCE', 'ADMIN']);
        $st = db()->prepare('SELECT excel_path FROM requests WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        $file_path = $row['excel_path'];

        // 相対パスの場合は絶対パスに変換
        if (!file_exists($file_path)) {
            // /var/www/html/app/../../storage/... の形式を /var/www/html/storage/... に変換
            $file_path = str_replace('/var/www/html/app/../../', '/var/www/html/', $file_path);
        }

        if (!file_exists($file_path)) {
            http_response_code(404);
            echo 'File not found: ' . $file_path;
            return;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed'
        ];

        $mime_type = $mime_types[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . filesize($file_path));

        readfile($file_path);
    }

    static function view_receipt_image()
    {
        require_role(['FINANCE', 'ADMIN']);
        $file_path = $_GET['path'] ?? '';
        if (!$file_path) {
            http_response_code(400);
            echo 'File path required';
            return;
        }

        // 相対パスの場合は絶対パスに変換
        if (!file_exists($file_path)) {
            $file_path = str_replace('/var/www/html/app/../../', '/var/www/html/', $file_path);
        }

        if (!file_exists($file_path)) {
            http_response_code(404);
            echo 'File not found: ' . $file_path;
            return;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf'
        ];

        $mime_type = $mime_types[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . filesize($file_path));

        readfile($file_path);
    }

    static function get_receipts($id)
    {
        require_role(['FINANCE', 'ADMIN']);

        try {
            error_log("=== Get Receipts Start ===");
            error_log("Request ID: $id");

            // まずテーブルが存在するかチェック
            $table_check = db()->query("SHOW TABLES LIKE 'receipts'")->fetch();
            if (!$table_check) {
                error_log("Receipts table does not exist");
                return json_ok([]); // 空の配列を返す
            }



            // 基本的なクエリで試行（行事名も取得）
            $st = db()->prepare('
            SELECT r.*, e.name as event_name 
            FROM receipts r 
            LEFT JOIN events e ON r.event_id = e.id 
            WHERE r.request_id = ? 
            ORDER BY r.id DESC
        ');
            $st->execute([$id]);
            $receipts = $st->fetchAll();


            return json_ok($receipts);

        } catch (Exception $e) {
            error_log("get_receipts error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('Receipts query failed: ' . $e->getMessage(), 500);
        }
    }

    static function update_receipt_status($id)
    {
        require_role(['FINANCE', 'ADMIN']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $receipt_id = (int) ($in['receipt_id'] ?? 0);
        $is_completed = (int) ($in['is_completed'] ?? 0);

        if ($receipt_id <= 0)
            return json_ng('invalid receipt id', 400);

        // レシートの存在確認
        $st = db()->prepare('SELECT id FROM receipts WHERE id = ? AND request_id = ?');
        $st->execute([$receipt_id, $id]);
        if (!$st->fetch())
            return json_ng('receipt not found', 404);

        // レシートの記入状態を更新
        $update = db()->prepare('UPDATE receipts SET is_completed = ? WHERE id = ?');
        $update->execute([$is_completed, $receipt_id]);

        // 全てのレシートが記入済みかチェック
        $check = db()->prepare('SELECT COUNT(*) as total, SUM(is_completed) as completed FROM receipts WHERE request_id = ?');
        $check->execute([$id]);
        $result = $check->fetch();

        // 自動状態遷移は無効化（手動の記入完了ボタンのみで状態変更）
        error_log("Receipt status updated - Request ID: $id, Receipt ID: $receipt_id, Completed: $is_completed, All completed: " . ($result['total'] == $result['completed'] ? 'yes' : 'no'));

        return json_ok(['is_completed' => $is_completed, 'all_completed' => $result['total'] == $result['completed']]);
    }

    static function delete_receipt($request_id)
    {
        require_role(['FINANCE', 'ADMIN']);

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $receipt_id = $input['receipt_id'] ?? null;

            error_log("Delete receipt debug - Request ID: $request_id, Receipt ID: " . ($receipt_id ?? 'null'));
            error_log("Input data: " . json_encode($input));

            if (!$receipt_id) {
                return json_ng('receipt_id is required', 400);
            }

            // レシートの存在確認
            $check = db()->prepare('SELECT id, file_path FROM receipts WHERE id = ? AND request_id = ?');
            $check->execute([$receipt_id, $request_id]);
            $receipt = $check->fetch();

            if (!$receipt) {
                return json_ng('レシートが見つかりません', 404);
            }

            // ファイルを削除
            if ($receipt['file_path'] && file_exists($receipt['file_path'])) {
                unlink($receipt['file_path']);
            }

            // データベースから削除
            $st = db()->prepare('DELETE FROM receipts WHERE id = ? AND request_id = ?');
            $st->execute([$receipt_id, $request_id]);

            if ($st->rowCount() === 0) {
                return json_ng('レシートの削除に失敗しました', 500);
            }

            // 処理済み額を更新
            self::updateProcessedAmount($request_id);

            return json_ok(['message' => 'レシートを削除しました']);

        } catch (Exception $e) {
            error_log("Delete receipt error: " . $e->getMessage());
            return json_ng('レシートの削除中にエラーが発生しました: ' . $e->getMessage(), 500);
        }
    }

    static function delete($id)
    {
        require_role(['ADMIN']);

        try {
            error_log("=== Delete Request Start ===");
            error_log("Request ID: $id");

            // リクエスト情報を取得（削除前に資金を戻すために必要な情報も取得）
            $st = db()->prepare('SELECT excel_path, cash_given, state, expects_network FROM requests WHERE id=?');
            $st->execute([$id]);
            $request = $st->fetch();
            if (!$request) {
                error_log("Delete failed: Request not found - ID: $id");
                return json_ng('リクエストが見つかりません', 404);
            }

            error_log("Request found, Excel path: " . ($request['excel_path'] ?? 'null'));

            // 渡し額が決まっていて処理が完了していないリクエストの場合、理論所持金額に戻す
            $cashGiven = (float) ($request['cash_given'] ?? 0);
            $state = $request['state'] ?? '';
            $expectsNetwork = $request['expects_network'] ?? '';

            if (
                $cashGiven > 0 &&
                in_array($state, ['CASH_GIVEN', 'TRANSFERRED', 'COLLECTED']) &&
                !in_array($state, ['FINALIZED', 'REJECTED'])
            ) {

                error_log("Returning cash given amount: {$cashGiven} for request {$id}");

                try {
                    $pdo = db();

                    // 現在のfundsテーブルの状態を取得
                    $fundsStmt = $pdo->query("SELECT bank_balance, cash_on_hand FROM funds ORDER BY updated_at DESC LIMIT 1");
                    $fundsData = $fundsStmt->fetch(PDO::FETCH_ASSOC);
                    $currentBankBalance = $fundsData ? (float) $fundsData['bank_balance'] : 0;
                    $currentCashOnHand = $fundsData ? (float) $fundsData['cash_on_hand'] : 0;

                    // 銀行振込の場合は銀行残高に戻し、持ち出し額から減算
                    // それ以外は持ち出し額に戻す
                    if ($expectsNetwork === 'BANK_TRANSFER') {
                        // 銀行残高に戻し、持ち出し額から減算
                        $newBankBalance = $currentBankBalance + $cashGiven;
                        $newCashOnHand = $currentCashOnHand - $cashGiven;
                        $updateStmt = $pdo->prepare("UPDATE funds SET bank_balance = ?, cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
                        $updateResult = $updateStmt->execute([$newBankBalance, $newCashOnHand]);

                        if ($updateStmt->rowCount() == 0) {
                            // レコードが存在しない場合は新規作成
                            $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                            $insertStmt->execute([$newBankBalance, $newCashOnHand]);
                        }

                        error_log("Bank balance increased by {$cashGiven} for deleted request {$id}. New balance: {$newBankBalance}. Cash on hand decreased by {$cashGiven}. New cash on hand: {$newCashOnHand}");
                    } else {
                        // 持ち出し額に戻す
                        $newCashOnHand = $currentCashOnHand + $cashGiven;
                        $updateStmt = $pdo->prepare("UPDATE funds SET cash_on_hand = ?, updated_at = NOW() WHERE id = 1");
                        $updateResult = $updateStmt->execute([$newCashOnHand]);

                        if ($updateStmt->rowCount() == 0) {
                            // レコードが存在しない場合は新規作成
                            $insertStmt = $pdo->prepare("INSERT INTO funds (id, bank_balance, cash_on_hand, updated_at) VALUES (1, ?, ?, NOW())");
                            $insertStmt->execute([$currentBankBalance, $newCashOnHand]);
                        }

                        error_log("Cash on hand increased by {$cashGiven} for deleted request {$id}. New amount: {$newCashOnHand}");
                    }
                } catch (Exception $e) {
                    error_log("Funds return error: " . $e->getMessage());
                    // 資金戻しの失敗は致命的ではないので続行
                }
            }

            // レシートファイルを取得して削除
            $receipts = db()->prepare('SELECT file_path FROM receipts WHERE request_id=?');
            $receipts->execute([$id]);
            $receiptCount = 0;
            while ($receipt = $receipts->fetch()) {
                $receiptCount++;
                if ($receipt['file_path'] && file_exists($receipt['file_path'])) {
                    if (unlink($receipt['file_path'])) {
                        error_log("Deleted receipt file: " . $receipt['file_path']);
                    } else {
                        error_log("Failed to delete receipt file: " . $receipt['file_path']);
                    }
                }
            }
            error_log("Processed $receiptCount receipt files");

            // レシートディレクトリを削除（空の場合）
            if ($request['excel_path']) {
                $request_dir = dirname($request['excel_path']);
                if (is_dir($request_dir) && count(scandir($request_dir)) <= 2) {
                    if (rmdir($request_dir)) {
                        error_log("Deleted empty directory: " . $request_dir);
                    } else {
                        error_log("Failed to delete directory: " . $request_dir);
                    }
                }
            }

            // Excelファイルを削除
            if ($request['excel_path'] && file_exists($request['excel_path'])) {
                if (unlink($request['excel_path'])) {
                    error_log("Deleted Excel file: " . $request['excel_path']);
                } else {
                    error_log("Failed to delete Excel file: " . $request['excel_path']);
                }
            }

            // 関連するレシートレコードを削除
            $receipt_delete = db()->prepare('DELETE FROM receipts WHERE request_id = ?');
            $receipt_result = $receipt_delete->execute([$id]);
            if (!$receipt_result) {
                error_log("Receipt deletion failed: " . json_encode($receipt_delete->errorInfo()));
                return json_ng('レシートデータの削除に失敗しました', 500);
            }
            $deleted_receipts = $receipt_delete->rowCount();
            error_log("Deleted $deleted_receipts receipt records");

            // リクエストを削除
            $st = db()->prepare('DELETE FROM requests WHERE id = ?');
            $result = $st->execute([$id]);

            if (!$result) {
                error_log("Delete failed: Database error - " . json_encode($st->errorInfo()));
                return json_ng('データベース削除に失敗しました', 500);
            }

            if ($st->rowCount() === 0) {
                error_log("Delete failed: No rows deleted - ID: $id");
                return json_ng('リクエストの削除に失敗しました', 500);
            }

            error_log("Delete successful - ID: $id");
            $message = 'リクエストを削除しました';
            if ($deleted_receipts > 0) {
                $message .= "（関連するレシート{$deleted_receipts}件も削除されました）";
            }
            return json_ok(['message' => $message]);

        } catch (Exception $e) {
            error_log("Delete error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('削除処理中にエラーが発生しました: ' . $e->getMessage(), 500);
        }
    }
    // 不足しているメソッドを追加
    static function get($id)
    {
        require_role(['FINANCE', 'ADMIN', 'OFFICER']);
        $st = db()->prepare('
        SELECT r.*, m.name as member_name, d.name as dept_name
        FROM requests r
        LEFT JOIN members m ON r.member_id = m.id
        LEFT JOIN departments d ON r.department_id = d.id
        WHERE r.id = ?
    ');
        $st->execute([$id]);
        $request = $st->fetch();
        if (!$request) {
            return json_ng('Request not found', 404);
        }
        return json_ok($request);
    }


    static function download_excel($id)
    {
        require_role(['FINANCE', 'ADMIN', 'OFFICER']);
        return self::view_excel($id);
    }

    // 画像なしレシート登録機能
    static function add_receipt($id)
    {
        require_role(['FINANCE', 'ADMIN']);

        try {
            error_log("=== Add Receipt Start ===");
            error_log("Request ID: $id");

            // 入力データの取得
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            error_log("Input data: " . json_encode($input));

            $total = (float) ($input['total'] ?? 0);
            $change = (float) ($input['change_returned'] ?? 0);

            // デバッグ用：入力データの型を確認
            error_log("Input total: " . $input['total'] . " (type: " . gettype($input['total']) . ")");
            error_log("Converted total: " . $total . " (type: " . gettype($total) . ")");
            $event_id = (int) ($input['event_id'] ?? 0);
            $subject = trim((string) ($input['subject'] ?? ''));
            $purpose = (string) ($input['purpose'] ?? '');
            $payer = trim((string) ($input['payer'] ?? ''));
            $receipt_date = (string) ($input['receipt_date'] ?? date('Y-m-d'));


            if ($total <= 0) {
                error_log("Validation failed: total amount required");
                return json_ng('合計金額を入力してください');
            }

            // リクエストの存在確認
            $st = db()->prepare('SELECT id FROM requests WHERE id = ?');
            $st->execute([$id]);
            if (!$st->fetch()) {
                error_log("Add receipt failed: Request not found - ID: $id");
                return json_ng('リクエストが見つかりません', 404);
            }

            // 管理番号を生成（受付番号-連番の形式）
            $request_no = db()->prepare('SELECT request_no FROM requests WHERE id = ?');
            $request_no->execute([$id]);
            $request_data = $request_no->fetch();

            if (!$request_data) {
                error_log("Add receipt failed: Request not found for receipt generation - ID: $id");
                return json_ng('リクエストが見つかりません', 404);
            }

            // 同じリクエストのレシート数を取得して連番を決定
            $count = db()->prepare('SELECT COUNT(*) as cnt FROM receipts WHERE request_id = ?');
            $count->execute([$id]);
            $count_result = $count->fetch();
            $receipt_sequence = $count_result['cnt'] + 1;

            $receipt_no = $request_data['request_no'] . '-' . $receipt_sequence;

            // activeな計上期間を取得
            $accounting_period_id = null;
            $period_st = db()->prepare('SELECT id FROM accounting_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
            $period_st->execute();
            $period_result = $period_st->fetch();
            if ($period_result) {
                $accounting_period_id = (int) $period_result['id'];
            }

            // レシートをデータベースに登録（画像なし）
            $ins = db()->prepare('INSERT INTO receipts(request_id,receipt_no,kind,total,change_returned,file_path,taken_at,event_id,subject,purpose,payer,receipt_date,accounting_period_id,memo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

            $result = $ins->execute([
                $id,
                $receipt_no,
                'RECEIPT',
                $total,
                $change,
                null, // 画像なし
                $receipt_date, // 支払日をtaken_atに設定
                $event_id ?: null,
                $subject ?: null,
                $purpose ?: null,
                $payer ?: null,
                $receipt_date,
                $accounting_period_id, // activeな計上期間のid
                null // memo（現在は未使用）
            ]);

            if (!$result) {
                error_log("Add receipt failed: Database insert failed - " . json_encode($ins->errorInfo()));
                return json_ng('レシートの登録に失敗しました');
            }

            // 処理済み額を更新
            self::updateProcessedAmount($id);

            return json_ok(['message' => 'レシートを登録しました']);

        } catch (Exception $e) {
            error_log("Add receipt error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('レシート登録中にエラーが発生しました: ' . $e->getMessage(), 500);
        }
    }



    static function receipt($id)
    {
        require_role(['FINANCE', 'ADMIN']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return self::add_receipt($id);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            return self::delete_receipt($id);
        }
        return json_ng('Method not allowed', 405);
    }

    // 処理済み額を更新するメソッド
    static function updateProcessedAmount($request_id)
    {
        try {
            // レシートの合計金額を計算
            $st = db()->prepare('SELECT COALESCE(SUM(total), 0) as total_amount FROM receipts WHERE request_id = ?');
            $st->execute([$request_id]);
            $result = $st->fetch();
            $processed_amount = (float) $result['total_amount'];

            // requestsテーブルのprocessed_amountを更新
            $update = db()->prepare('UPDATE requests SET processed_amount = ? WHERE id = ?');
            $update->execute([$processed_amount, $request_id]);

            error_log("Updated processed amount for request $request_id: $processed_amount");

        } catch (Exception $e) {
            error_log("Update processed amount error: " . $e->getMessage());
            // エラーが発生しても処理は続行（致命的ではない）
        }
    }

    // 全リクエストの処理済み額を再計算するメソッド
    static function recalculateAllProcessedAmounts()
    {
        require_role(['ADMIN']);

        try {
            // 全リクエストを取得
            $st = db()->prepare('SELECT id FROM requests');
            $st->execute();
            $requests = $st->fetchAll();

            $updated_count = 0;
            $error_count = 0;

            foreach ($requests as $request) {
                try {
                    self::updateProcessedAmount($request['id']);
                    $updated_count++;
                } catch (Exception $e) {
                    error_log("Failed to update processed amount for request {$request['id']}: " . $e->getMessage());
                    $error_count++;
                }
            }

            return json_ok([
                'message' => '処理済み額の再計算が完了しました',
                'updated_count' => $updated_count,
                'error_count' => $error_count
            ]);

        } catch (Exception $e) {
            error_log("Recalculate all processed amounts error: " . $e->getMessage());
            return json_ng('処理済み額の再計算中にエラーが発生しました: ' . $e->getMessage(), 500);
        }
    }
}

