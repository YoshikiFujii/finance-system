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
}