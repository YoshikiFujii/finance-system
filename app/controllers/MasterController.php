<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/util.php';


class MasterController{
    static function departments_list(){ require_role(['OFFICER','FINANCE','ADMIN']);
        $rows = db()->query('SELECT id,name,is_active FROM departments WHERE is_active=1 ORDER BY id')->fetchAll();
        return json_ok($rows);
    }
    static function members_list(){ require_role(['OFFICER','FINANCE','ADMIN']);
    $rows = db()->query('SELECT m.id,m.name,m.department_id,d.name AS department_name,m.is_active
        FROM members m JOIN departments d ON d.id=m.department_id
        WHERE m.is_active=1 AND d.is_active=1
        ORDER BY m.id')->fetchAll();
    return json_ok($rows);
    }
    static function upsert_department(){ require_role(['ADMIN']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = $in['id'] ?? null; $name = trim((string)($in['name'] ?? '')); $active = (int)($in['is_active'] ?? 1);
        if($name==='') return json_ng('name required');
        if($id){ $st=db()->prepare('UPDATE departments SET name=?, is_active=? WHERE id=?'); $st->execute([$name,$active,$id]); }
        else { $st=db()->prepare('INSERT INTO departments(name,is_active) VALUES(?,?)'); $st->execute([$name,$active]); }
        return json_ok();
    }
    static function upsert_member(){ require_role(['ADMIN']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id=$in['id']??null; $name=trim((string)($in['name']??'')); $dep=(int)($in['department_id']??0); $active=(int)($in['is_active']??1);
        if(!$name||!$dep) return json_ng('name and department_id required');
        if($id){ $st=db()->prepare('UPDATE members SET name=?, department_id=?, is_active=? WHERE id=?'); $st->execute([$name,$dep,$active,$id]); }
        else { $st=db()->prepare('INSERT INTO members(name,department_id,is_active) VALUES(?,?,?)'); $st->execute([$name,$dep,$active]); }
        return json_ok();
    }
    static function delete_department(){ require_role(['ADMIN']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($in['id'] ?? 0);
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
        
        return json_ok();
    }
    static function delete_member(){ require_role(['ADMIN']);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($in['id'] ?? 0);
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
        
        return json_ok();
    }

    static function bulk_upload(){ require_role(['ADMIN']);
        // PhpSpreadsheetのautoloadを読み込み
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
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
            $department_map[$dep['name']] = $dep['id'];
        }

        foreach($data as $row_num => $row) {
            $dept_name = $row['department'];
            $member_name = $row['member'];

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
                    $errors[] = "行{$row_num}: 部署「{$dept_name}」の作成に失敗しました";
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
                $errors[] = "行{$row_num}: メンバー「{$member_name}」の作成に失敗しました";
            }
        }

        return json_ok([
            'departments_created' => $departments_created,
            'members_created' => $members_created,
            'errors' => $errors
        ]);
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
}