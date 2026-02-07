<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/util.php';


class MasterController{
    static function departments_list(){ require_role(['OFFICER','FINANCE','ADMIN']);
        $rows = db()->query('SELECT id,name,is_active FROM departments WHERE is_active=1 ORDER BY id')->fetchAll();
        return json_ok($rows);
    }
    static function accounting_periods_list(){ require_role(['OFFICER','FINANCE','ADMIN','AUDIT']);
        $rows = db()->query('SELECT id,name,is_active FROM accounting_periods ORDER BY id')->fetchAll();
        return json_ok($rows);
    }
    static function members_list(){ require_role(['OFFICER','FINANCE','ADMIN']);
    $rows = db()->query('SELECT m.id,m.name,m.department_id,d.name AS department_name,m.is_active
        FROM members m JOIN departments d ON d.id=m.department_id
        WHERE m.is_active=1 AND d.is_active=1
        ORDER BY m.id')->fetchAll();
    return json_ok($rows);
    }
    static function events_list(){ require_role(['OFFICER','FINANCE','ADMIN','AUDIT']);
        // 日付条件と計上期間を取得
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
        $accounting_period_id = $_GET['accounting_period_id'] ?? null;
        
        // 日付条件と計上期間を考慮したSQLを構築
        $join_conditions = 'e.id = r.event_id';
        $params = [];
        
        // 日付条件がある場合はJOIN条件に追加
        if ($start_date) {
            $join_conditions .= ' AND r.receipt_date >= ?';
            $params[] = $start_date;
        }
        if ($end_date) {
            $join_conditions .= ' AND r.receipt_date <= ?';
            $params[] = $end_date;
        }
        // 計上期間がある場合はJOIN条件に追加（複数対応）
        if ($accounting_period_id !== null && $accounting_period_id !== '') {
            // カンマ区切りの複数のIDを処理
            if (strpos($accounting_period_id, ',') !== false) {
                $period_ids = array_filter(array_map('trim', explode(',', $accounting_period_id)), function($id) {
                    return $id !== '' && is_numeric($id);
                });
                if (!empty($period_ids)) {
                    $placeholders = str_repeat('?,', count($period_ids) - 1) . '?';
                    $join_conditions .= ' AND r.accounting_period_id IN (' . $placeholders . ')';
                    foreach ($period_ids as $id) {
                        $params[] = (int)$id;
                    }
                }
            } else {
                $join_conditions .= ' AND r.accounting_period_id = ?';
                $params[] = (int)$accounting_period_id;
            }
        }
        
        $sql = '
            SELECT e.id, e.name, e.is_active, 
                   COALESCE(SUM(r.total), 0) as total_amount,
                   COUNT(CASE WHEN r.storaged = 0 THEN 1 END) as has_unstoraged
            FROM events e
            LEFT JOIN receipts r ON ' . $join_conditions . '
            WHERE e.is_active = 1
            GROUP BY e.id, e.name, e.is_active
            ORDER BY e.id
        ';
        
        if (empty($params)) {
            $rows = db()->query($sql)->fetchAll();
        } else {
            $st = db()->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();
        }
        
        // has_unstoragedを整数に変換
        foreach ($rows as &$row) {
            $row['has_unstoraged'] = (int)$row['has_unstoraged'];
        }
        unset($row);
        
        // デバッグ用：レシートテーブルの構造とデータを確認
        $receipts_check = db()->query('SELECT COUNT(*) as count FROM receipts')->fetch();
        error_log("Total receipts count: " . $receipts_check['count']);
        
        $receipts_with_events = db()->query('SELECT COUNT(*) as count FROM receipts WHERE event_id IS NOT NULL')->fetch();
        error_log("Receipts with event_id: " . $receipts_with_events['count']);
        
        $sample_receipts = db()->query('SELECT id, event_id, total FROM receipts LIMIT 5')->fetchAll();
        error_log("Sample receipts: " . json_encode($sample_receipts));
        
        // レシートのtotalカラムの型を確認
        $column_info = db()->query("DESCRIBE receipts")->fetchAll();
        error_log("Receipts table structure: " . json_encode($column_info));
        
        // 特定の行事のレシート合計をテスト
        $test_query = db()->query('
            SELECT e.id, e.name, 
                   COALESCE(SUM(r.total), 0) as total_amount,
                   COUNT(r.id) as receipt_count
            FROM events e
            LEFT JOIN receipts r ON e.id = r.event_id
            WHERE e.is_active = 1
            GROUP BY e.id, e.name
            ORDER BY e.id
        ')->fetchAll();
        error_log("Test query result: " . json_encode($test_query));
        
        // デバッグ用：クエリ結果をログ出力
        error_log("Events list query result: " . json_encode($rows));
        
        return json_ok($rows);
    }
    static function upsert_department(){ 
        require_role(['ADMIN']);
        
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        
        // 削除アクションの処理
        if(isset($in['action']) && $in['action'] === 'delete' && isset($in['id'])) {
            return self::delete_department();
        }
        
        $id = $in['id'] ?? null; 
        $name = trim((string)($in['name'] ?? '')); 
        $active = (int)($in['is_active'] ?? 1);
        
        if($name==='') return json_ng('name required');
        
        if($id){ 
            $st=db()->prepare('UPDATE departments SET name=?, is_active=? WHERE id=?'); 
            $st->execute([$name,$active,$id]); 
        } else { 
            $st=db()->prepare('INSERT INTO departments(name,is_active) VALUES(?,?)'); 
            $st->execute([$name,$active]); 
        }
        return json_ok();
    }
    static function upsert_member(){ 
        require_role(['ADMIN']);
        
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        
        // 削除アクションの処理
        if(isset($in['action']) && $in['action'] === 'delete' && isset($in['id'])) {
            return self::delete_member();
        }
        
        $id=$in['id']??null; 
        $name=trim((string)($in['name']??'')); 
        $dep=(int)($in['department_id']??0); 
        $active=(int)($in['is_active']??1);
        
        if(!$name||!$dep) return json_ng('name and department_id required');
        
        if($id){ 
            $st=db()->prepare('UPDATE members SET name=?, department_id=?, is_active=? WHERE id=?'); 
            $st->execute([$name,$dep,$active,$id]); 
        } else { 
            $st=db()->prepare('INSERT INTO members(name,department_id,is_active) VALUES(?,?,?)'); 
            $st->execute([$name,$dep,$active]); 
        }
        return json_ok();
    }
    static function delete_department(){ 
        require_role(['ADMIN']);

        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        // POSTリクエストで削除アクションを処理
        if(isset($input['action']) && $input['action'] === 'delete' && isset($input['id'])) {
            $id = (int)$input['id'];
        } else {
            // 従来のDELETEリクエストの処理
            $id = (int)($input['id'] ?? 0);
        }

        if(!$id) return json_ng('id required');

        // 部署に関連するメンバーが存在するかチェック
        $st = db()->prepare("SELECT COUNT(*) FROM members WHERE department_id = ?");
        $st->execute([$id]);
        $member_count = $st->fetchColumn();
        if($member_count > 0) return json_ng('部署に関連するメンバーが存在するため削除できません');

        // 部署に関連するリクエストが存在するかチェック
        $st = db()->prepare("SELECT COUNT(*) FROM requests WHERE department_id = ?");
        $st->execute([$id]);
        $request_count = $st->fetchColumn();
        if($request_count > 0) return json_ng('部署に関連するリクエストが存在するため削除できません');

        // 部署を削除
        $st = db()->prepare('DELETE FROM departments WHERE id = ?');
        $st->execute([$id]);

        if($st->rowCount() === 0) return json_ng('部署が見つかりません');

        return json_ok(['message' => '部署を削除しました']);
    }

    static function delete_member(){ 
        require_role(['ADMIN']);

        error_log("delete_member called - Method: " . $_SERVER['REQUEST_METHOD']);
        
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        error_log("delete_member input: " . json_encode($input));

        // POSTリクエストで削除アクションを処理
        if(isset($input['action']) && $input['action'] === 'delete' && isset($input['id'])) {
            $id = (int)$input['id'];
        } else {
            // 従来のDELETEリクエストの処理
            $id = (int)($input['id'] ?? 0);
        }

        error_log("delete_member - ID: " . $id);
        if(!$id) return json_ng('id required');

        // メンバーに関連するリクエストが存在するかチェック
        $st = db()->prepare("SELECT COUNT(*) FROM requests WHERE member_id = ?");
        $st->execute([$id]);
        $request_count = $st->fetchColumn();
        if($request_count > 0) return json_ng('この役員に関連するリクエストが存在するため削除できません');

        // メンバーを削除
        $st = db()->prepare('DELETE FROM members WHERE id = ?');
        $st->execute([$id]);

        if($st->rowCount() === 0) return json_ng('役員が見つかりません');

        return json_ok(['message' => '役員を削除しました']);
    }

    static function bulk_upload(){ 
        require_role(['ADMIN']);

        try {
            // PhpSpreadsheetのautoloadを読み込み
            $autoload_path = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoload_path)) {
                require_once $autoload_path;
            } else {
                error_log("Autoload file not found: " . $autoload_path);
                return json_ng('PhpSpreadsheet library not found. Please install via composer.');
            }

            if(!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
                return json_ng('excel file required');
            }

            $file = $_FILES['excel'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['xlsx', 'xls'];
            if(!in_array($ext, $allowed, true)) {
                return json_ng('invalid excel extension');
            }

            // 一時ファイルを読み込み
            $tempFile = $file['tmp_name'];
            $data = self::parseExcelFile($tempFile);

            if(empty($data)) {
                return json_ng('no data found in excel file');
            }

            $departments_created = 0;
            $members_created = 0;
            $errors = [];
            $department_map = []; // 部署名 -> ID のマッピング

            // 既存の部署を取得
            $existing_deps = db()->query('SELECT id, name FROM departments WHERE is_active=1')->fetchAll();
            foreach($existing_deps as $dep) {
                $department_map[mb_convert_encoding(trim($dep['name']), 'UTF-8', 'auto')] = $dep['id'];
            }

            foreach($data as $row_num => $row) {
                $dept_name = mb_convert_encoding(trim($row['department']), 'UTF-8', 'auto');
                $member_name = mb_convert_encoding(trim($row['member']), 'UTF-8', 'auto');

                if(empty($dept_name) || empty($member_name)) {
                    $errors[] = "行{$row_num}: 部署名または氏名が空です";
                    continue;
                }

                // 部署が存在しない場合は作成
                if(!isset($department_map[$dept_name])) {
                    try {
                        $st = db()->prepare('INSERT INTO departments(name, is_active) VALUES(?, 1)');
                        $st->execute([$dept_name]);
                        $dept_id = db()->lastInsertId();
                        $department_map[$dept_name] = $dept_id;
                        $departments_created++;
                    } catch(Exception $e) {
                        $errors[] = "行{$row_num}: 部署「{$dept_name}」の作成に失敗しました: " . $e->getMessage();
                        continue;
                    }
                }

                $dept_id = $department_map[$dept_name];

                // メンバーが既に存在するかチェック
                $st = db()->prepare('SELECT COUNT(*) FROM members WHERE name = ? AND department_id = ?');
                $st->execute([$member_name, $dept_id]);
                if($st->fetchColumn() > 0) {
                    $errors[] = "行{$row_num}: メンバー「{$member_name}」は既に存在します";
                    continue;
                }

                // メンバーを作成
                try {
                    $st = db()->prepare('INSERT INTO members(name, department_id, is_active) VALUES(?, ?, 1)');
                    $st->execute([$member_name, $dept_id]);
                    $members_created++;
                } catch(Exception $e) {
                    $errors[] = "行{$row_num}: メンバー「{$member_name}」の作成に失敗しました: " . $e->getMessage();
                }
            }

            return json_ok([
                'departments_created' => $departments_created,
                'members_created' => $members_created,
                'errors' => $errors
            ]);

        } catch(Exception $e) {
            error_log("Bulk upload error: " . $e->getMessage());
            return json_ng('一括登録中にエラーが発生しました: ' . $e->getMessage());
        }
    }

    private static function parseExcelFile($filePath) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = [];
            
            $highestRow = $worksheet->getHighestRow();
            
            for ($row = 1; $row <= $highestRow; $row++) {
                $department = $worksheet->getCell('A' . $row)->getValue();
                $member = $worksheet->getCell('B' . $row)->getValue();
                
                // 空の行をスキップ
                if (empty($department) && empty($member)) {
                    continue;
                }
                
                $data[] = [
                    'department' => trim($department),
                    'member' => trim($member)
                ];
            }
            
            return $data;
        } catch (Exception $e) {
            throw new Exception('Excelファイルの読み込みに失敗しました: ' . $e->getMessage());
        }
    }
    static function upsert_event(){ require_role(['ADMIN']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = $in['id'] ?? null; $name = trim((string)($in['name'] ?? '')); $active = (int)($in['is_active'] ?? 1);
        if($name==='') return json_ng('name required');
        if($id){ $st=db()->prepare('UPDATE events SET name=?, is_active=? WHERE id=?'); $st->execute([$name,$active,$id]); }
        else { $st=db()->prepare('INSERT INTO events(name,is_active) VALUES(?,?)'); $st->execute([$name,$active]); }
        return json_ok();
    }
    static function delete_event(){ 
        require_role(['ADMIN']);

        // POSTリクエストで削除アクションを処理
        $input = json_decode(file_get_contents('php://input'), true);
        if(isset($input['action']) && $input['action'] === 'delete' && isset($input['id'])) {
            $id = (int)$input['id'];

            // 行事に関連するレシートを削除
            $st = db()->prepare("DELETE FROM receipts WHERE event_id = ?");
            $st->execute([$id]);
            $deleted_receipts = $st->rowCount();
            
            // 行事を削除
            $st = db()->prepare('DELETE FROM events WHERE id=?');
            $st->execute([$id]);

            if($st->rowCount() === 0) return json_ng('行事が見つかりません');

            $message = '行事を削除しました';
            if($deleted_receipts > 0) {
                $message .= "（関連するレシート{$deleted_receipts}件も削除されました）";
            }
            return json_ok(['message' => $message]);
        }

        // 従来のDELETEリクエストの処理
        $id = (int)($_GET['id'] ?? 0);
        if(!$id) return json_ng('id required',400);

        // 行事に関連するレシートを削除
        $st = db()->prepare("DELETE FROM receipts WHERE event_id = ?");
        $st->execute([$id]);
        $deleted_receipts = $st->rowCount();
        
        // 行事を削除
        $st = db()->prepare('DELETE FROM events WHERE id=?');
        $st->execute([$id]);

        if($st->rowCount() === 0) return json_ng('行事が見つかりません');

        $message = '行事を削除しました';
        if($deleted_receipts > 0) {
            $message .= "（関連するレシート{$deleted_receipts}件も削除されました）";
        }
        return json_ok(['message' => $message]);
    }

    static function get_event_receipts($event_id) {
        require_role(['ADMIN']);
        
        try {
            // 日付条件と計上期間を取得
            $start_date = $_GET['start_date'] ?? null;
            $end_date = $_GET['end_date'] ?? null;
            $accounting_period_id = $_GET['accounting_period_id'] ?? null;
            
            // 指定された行事に関連するレシートを取得
            $sql = '
                SELECT r.*, req.request_no, req.member_id, m.name as member_name
                FROM receipts r
                JOIN requests req ON r.request_id = req.id
                JOIN members m ON req.member_id = m.id
                WHERE r.event_id = ?
            ';
            $params = [$event_id];
            
            // 日付条件を追加
            if ($start_date) {
                $sql .= ' AND r.receipt_date >= ?';
                $params[] = $start_date;
            }
            if ($end_date) {
                $sql .= ' AND r.receipt_date <= ?';
                $params[] = $end_date;
            }
            // 計上期間を追加（複数対応）
            if ($accounting_period_id !== null && $accounting_period_id !== '') {
                // カンマ区切りの複数のIDを処理
                if (strpos($accounting_period_id, ',') !== false) {
                    $period_ids = array_filter(array_map('trim', explode(',', $accounting_period_id)), function($id) {
                        return $id !== '' && is_numeric($id);
                    });
                    if (!empty($period_ids)) {
                        $placeholders = str_repeat('?,', count($period_ids) - 1) . '?';
                        $sql .= ' AND r.accounting_period_id IN (' . $placeholders . ')';
                        foreach ($period_ids as $id) {
                            $params[] = (int)$id;
                        }
                    }
                } else {
                    $sql .= ' AND r.accounting_period_id = ?';
                    $params[] = (int)$accounting_period_id;
                }
            }
            
            $sql .= ' ORDER BY r.id DESC';
            
            $st = db()->prepare($sql);
            $st->execute($params);
            $receipts = $st->fetchAll();
            
            return json_ok($receipts);
            
        } catch (Exception $e) {
            error_log("get_event_receipts error: " . $e->getMessage());
            return json_ng('レシートの取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }
    
    static function get_event_subject_breakdown($event_id) {
        require_role(['ADMIN','AUDIT']);
        
        try {
            // 日付条件と計上期間を取得
            $start_date = $_GET['start_date'] ?? null;
            $end_date = $_GET['end_date'] ?? null;
            $accounting_period_id = $_GET['accounting_period_id'] ?? null;
            
            // 指定された行事の科目別合計金額を取得
            $sql = '
                SELECT 
                    COALESCE(r.subject, "未設定") as subject,
                    SUM(r.total) as total_amount,
                    COUNT(*) as count,
                    COUNT(CASE WHEN r.storaged = 0 THEN 1 END) as has_unstoraged
                FROM receipts r
                WHERE r.event_id = ?
            ';
            $params = [$event_id];
            
            // 日付条件を追加
            if ($start_date) {
                $sql .= ' AND r.receipt_date >= ?';
                $params[] = $start_date;
            }
            if ($end_date) {
                $sql .= ' AND r.receipt_date <= ?';
                $params[] = $end_date;
            }
            // 計上期間を追加（複数対応）
            if ($accounting_period_id !== null && $accounting_period_id !== '') {
                // カンマ区切りの複数のIDを処理
                if (strpos($accounting_period_id, ',') !== false) {
                    $period_ids = array_filter(array_map('trim', explode(',', $accounting_period_id)), function($id) {
                        return $id !== '' && is_numeric($id);
                    });
                    if (!empty($period_ids)) {
                        $placeholders = str_repeat('?,', count($period_ids) - 1) . '?';
                        $sql .= ' AND r.accounting_period_id IN (' . $placeholders . ')';
                        foreach ($period_ids as $id) {
                            $params[] = (int)$id;
                        }
                    }
                } else {
                    $sql .= ' AND r.accounting_period_id = ?';
                    $params[] = (int)$accounting_period_id;
                }
            }
            
            $sql .= ' GROUP BY r.subject ORDER BY total_amount DESC';
            
            $st = db()->prepare($sql);
            $st->execute($params);
            $breakdown = $st->fetchAll();
            
            // has_unstoragedを整数に変換
            foreach ($breakdown as &$item) {
                $item['has_unstoraged'] = (int)$item['has_unstoraged'];
            }
            unset($item);
            
            return json_ok($breakdown);
            
        } catch (Exception $e) {
            error_log("get_event_subject_breakdown error: " . $e->getMessage());
            return json_ng('科目別合計の取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }
    
    static function get_event_subjects($event_id) {
        require_role(['FINANCE','ADMIN']);
        
        try {
            // 指定された行事に登録されている科目一覧を取得
            $st = db()->prepare('
                SELECT DISTINCT subject
                FROM receipts
                WHERE event_id = ? AND subject IS NOT NULL AND subject != ""
                ORDER BY subject
            ');
            $st->execute([$event_id]);
            $subjects = $st->fetchAll(PDO::FETCH_COLUMN);
            
            return json_ok($subjects);
            
        } catch (Exception $e) {
            error_log("get_event_subjects error: " . $e->getMessage());
            return json_ng('科目一覧の取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }
    
    static function get_event_receipts_by_subject($event_id, $subject) {
        require_role(['FINANCE','ADMIN','AUDIT']);
        
        try {
            // 日付条件と計上期間を取得
            $start_date = $_GET['start_date'] ?? null;
            $end_date = $_GET['end_date'] ?? null;
            $accounting_period_id = $_GET['accounting_period_id'] ?? null;
            
            // 指定された行事と科目のレシート一覧を取得
            $sql = '
                SELECT 
                    r.id,
                    r.request_id,
                    r.receipt_no,
                    r.subject,
                    r.purpose,
                    r.total,
                    r.payer,
                    r.receipt_date,
                    r.is_completed,
                    r.storaged,
                    req.request_no,
                    m.name as member_name
                FROM receipts r
                JOIN requests req ON r.request_id = req.id
                JOIN members m ON req.member_id = m.id
                WHERE r.event_id = ? AND r.subject = ?
            ';
            $params = [$event_id, $subject];
            
            // 日付条件を追加
            if ($start_date) {
                $sql .= ' AND r.receipt_date >= ?';
                $params[] = $start_date;
            }
            if ($end_date) {
                $sql .= ' AND r.receipt_date <= ?';
                $params[] = $end_date;
            }
            // 計上期間を追加（複数対応）
            if ($accounting_period_id !== null && $accounting_period_id !== '') {
                // カンマ区切りの複数のIDを処理
                if (strpos($accounting_period_id, ',') !== false) {
                    $period_ids = array_filter(array_map('trim', explode(',', $accounting_period_id)), function($id) {
                        return $id !== '' && is_numeric($id);
                    });
                    if (!empty($period_ids)) {
                        $placeholders = str_repeat('?,', count($period_ids) - 1) . '?';
                        $sql .= ' AND r.accounting_period_id IN (' . $placeholders . ')';
                        foreach ($period_ids as $id) {
                            $params[] = (int)$id;
                        }
                    }
                } else {
                    $sql .= ' AND r.accounting_period_id = ?';
                    $params[] = (int)$accounting_period_id;
                }
            }
            
            $sql .= ' ORDER BY r.receipt_date DESC, r.id DESC';
            
            $st = db()->prepare($sql);
            $st->execute($params);
            $receipts = $st->fetchAll(PDO::FETCH_ASSOC);
            
            // storagedを整数に変換（bit型やTINYINTの値を確実に0または1にする）
            foreach ($receipts as &$receipt) {
                if (isset($receipt['storaged'])) {
                    $val = $receipt['storaged'];
                    // バイナリ値の場合も考慮
                    if (is_string($val) && strlen($val) > 0) {
                        $val = ord($val) > 0 ? 1 : 0;
                    } else {
                        $val = (int)$val;
                    }
                    $receipt['storaged'] = $val;
                } else {
                    $receipt['storaged'] = 0;
                }
            }
            unset($receipt);
            
            return json_ok($receipts);
            
        } catch (Exception $e) {
            error_log("get_event_receipts_by_subject error: " . $e->getMessage());
            return json_ng('科目別レシート一覧の取得に失敗しました: ' . $e->getMessage(), 500);
        }
    }
    
    static function upsert_accounting_period(){ 
        require_role(['ADMIN']);
        
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        
        // 削除アクションの処理
        if(isset($in['action']) && $in['action'] === 'delete' && isset($in['id'])) {
            return self::delete_accounting_period();
        }
        
        $id = $in['id'] ?? null; 
        $name = trim((string)($in['name'] ?? '')); 
        $active = (int)($in['is_active'] ?? 1);
        
        if($id){ 
            // 更新の場合
            if($name === '') {
                // nameが指定されていない場合は既存のnameを取得
                $st = db()->prepare('SELECT name FROM accounting_periods WHERE id = ?');
                $st->execute([$id]);
                $existing = $st->fetch();
                if(!$existing) return json_ng('計上期間が見つかりません');
                $name = $existing['name'];
            }
            
            // activeに変更する場合、他のすべての期間をinactiveにする
            if($active == 1) {
                $st = db()->prepare('UPDATE accounting_periods SET is_active = 0 WHERE id != ?');
                $st->execute([$id]);
            }
            
            $st=db()->prepare('UPDATE accounting_periods SET name=?, is_active=? WHERE id=?'); 
            $st->execute([$name,$active,$id]); 
        } else { 
            // 新規作成の場合
            if($name==='') return json_ng('name required');
            
            // activeで作成する場合、他のすべての期間をinactiveにする
            if($active == 1) {
                $st = db()->prepare('UPDATE accounting_periods SET is_active = 0');
                $st->execute();
            }
            
            $st=db()->prepare('INSERT INTO accounting_periods(name,is_active) VALUES(?,?)'); 
            $st->execute([$name,$active]); 
        }
        return json_ok();
    }
    
    static function delete_accounting_period(){ 
        require_role(['ADMIN']);

        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        // POSTリクエストで削除アクションを処理
        if(isset($input['action']) && $input['action'] === 'delete' && isset($input['id'])) {
            $id = (int)$input['id'];
        } else {
            // 従来のDELETEリクエストの処理
            $id = (int)($input['id'] ?? 0);
        }

        if(!$id) return json_ng('id required');

        // 計上期間に関連するレシートが存在するかチェック
        $st = db()->prepare("SELECT COUNT(*) FROM receipts WHERE accounting_period_id = ?");
        $st->execute([$id]);
        $receipt_count = $st->fetchColumn();
        if($receipt_count > 0) return json_ng('計上期間に関連するレシートが存在するため削除できません');

        // 計上期間を削除
        $st = db()->prepare('DELETE FROM accounting_periods WHERE id = ?');
        $st->execute([$id]);

        if($st->rowCount() === 0) return json_ng('計上期間が見つかりません');

        return json_ok(['message' => '計上期間を削除しました']);
    }
    
    static function toggle_receipt_storaged($receipt_id) {
        require_role(['FINANCE','ADMIN','AUDIT']);
        
        try {
            // 現在のstoraged値を取得
            $st = db()->prepare('SELECT storaged FROM receipts WHERE id = ?');
            $st->execute([$receipt_id]);
            $receipt = $st->fetch(PDO::FETCH_ASSOC);
            
            if (!$receipt) {
                return json_ng('レシートが見つかりません', 404);
            }
            
            // storagedをトグル（0→1、1→0）
            // 値を確実に整数として扱う
            $current_storaged = (int)$receipt['storaged'];
            // バイナリ値の場合も考慮
            if (is_string($receipt['storaged']) && ord($receipt['storaged']) > 0) {
                $current_storaged = 1;
            }
            
            $new_storaged = ($current_storaged == 1) ? 0 : 1;
            
            // 更新（明示的に整数として設定）
            $update_st = db()->prepare('UPDATE receipts SET storaged = ? WHERE id = ?');
            $result = $update_st->execute([$new_storaged, $receipt_id]);
            
            if (!$result) {
                return json_ng('更新に失敗しました', 500);
            }
            
            // 更新後の値を確認
            $verify_st = db()->prepare('SELECT storaged FROM receipts WHERE id = ?');
            $verify_st->execute([$receipt_id]);
            $verified = $verify_st->fetch(PDO::FETCH_ASSOC);
            $verified_storaged = (int)$verified['storaged'];
            if (is_string($verified['storaged']) && ord($verified['storaged']) > 0) {
                $verified_storaged = 1;
            }
            
            return json_ok(['storaged' => $verified_storaged]);
            
        } catch (Exception $e) {
            error_log("toggle_receipt_storaged error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return json_ng('レシートの保存状態の更新に失敗しました: ' . $e->getMessage(), 500);
        }
    }
}