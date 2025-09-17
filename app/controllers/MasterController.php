<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/util.php';


class MasterController{
static function departments_list(){ require_role(['FINANCE','ADMIN']);
$rows = db()->query('SELECT id,name,is_active FROM departments ORDER BY id')->fetchAll();
return json_ok($rows);
}
static function members_list(){ require_role(['FINANCE','ADMIN']);
$rows = db()->query('SELECT m.id,m.name,m.department_id,d.name AS department_name,m.is_active
FROM members m JOIN departments d ON d.id=m.department_id
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
}