<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/util.php';


class RequestController{
    static function create(){ // 提出（OFFICER用）
    require_role(['OFFICER','FINANCE','ADMIN']);
    $cfg = require __DIR__.'/../config.php';
    // multipart
    $member_id = (int)($_POST['member_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $summary = trim((string)($_POST['summary'] ?? ''));
    $expects = (string)($_POST['expects_network'] ?? 'NONE');
    if(!$member_id||!$department_id||$summary==='') return json_ng('missing fields');
    if(!isset($_FILES['excel']) || $_FILES['excel']['error']!==UPLOAD_ERR_OK) return json_ng('excel required');
    // 保存先
    $no = date('YmdHis').sprintf('%03d', random_int(100,999));
    $basedir = $cfg['upload_dir'].'/'.date('Y').'/'.date('m').'/'.$no;
    ensure_dir($basedir);
    // 拡張子/サイズ/MIMEチェック（簡易）
    $f = $_FILES['excel'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['xlsx','xls'];
    if(!in_array($ext,$allowed,true)) return json_ng('invalid excel extension');
    if($f['size'] > 10*1024*1024) return json_ng('file too large');
    $fname = safe_filename(pathinfo($f['name'], PATHINFO_FILENAME)).'_'.date('His').'.'.$ext;
    $path = $basedir.'/'.$fname;
    move_uploaded_file($f['tmp_name'],$path);


    $st = db()->prepare('INSERT INTO requests(request_no,member_id,department_id,submitted_at,summary,expects_network,excel_path) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$no,$member_id,$department_id,date('Y-m-d H:i:s'),$summary,$expects,$path]);


    db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (LAST_INSERT_ID(),?,?,?)')
    ->execute(['OFFICER','CREATE','created request']);
    return json_ok(['request_no'=>$no]);
}


static function list(){ // 財務/管理者向けリスト
require_role(['FINANCE','ADMIN']);
$q = $_GET['q'] ?? '';
$state = $_GET['state'] ?? '';
$dep = $_GET['dept'] ?? '';
$where = [];$args=[];
if($q!=''){ $where[]='(r.summary LIKE ? OR m.name LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
if($state!=''){ $where[]='r.state=?'; $args[]=$state; }
if($dep!=''){ $where[]='r.department_id=?'; $args[]=(int)$dep; }
$sql = 'SELECT r.id,r.request_no,r.submitted_at,r.summary,r.expects_network,r.state,r.cash_given,r.expected_total, m.name AS member_name, d.name AS dept_name
FROM requests r JOIN members m ON m.id=r.member_id JOIN departments d ON d.id=r.department_id';
if($where) $sql .= ' WHERE '.implode(' AND ', $where);
$sql .= ' ORDER BY r.submitted_at DESC LIMIT 200';
$st = db()->prepare($sql); $st->execute($args); $rows=$st->fetchAll();
return json_ok($rows);
}


static function accept($id){ require_role(['FINANCE','ADMIN']);
db()->prepare('UPDATE requests SET state="ACCEPTED" WHERE id=? AND state IN ("NEW")')->execute([$id]);
db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')->execute([$id,'FINANCE','STATE','ACCEPTED']);
return json_ok();
}

static function reject($id){ require_role(['FINANCE','ADMIN']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$reason = trim((string)($in['reason'] ?? ''));
if($reason==='') return json_ng('reason required');
db()->prepare('UPDATE requests SET state="REJECTED", rejected_reason=? WHERE id=? AND state IN ("NEW","ACCEPTED")')->execute([$reason,$id]);
db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')->execute([$id,'FINANCE','STATE','REJECTED']);
return json_ok();
}


static function set_cash($id){ require_role(['FINANCE','ADMIN']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$cash = (float)($in['cash_given'] ?? 0);
db()->prepare('UPDATE requests SET cash_given=? WHERE id=?')->execute([$cash,$id]);
return json_ok();
}


static function state($id){ require_role(['FINANCE','ADMIN']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$next = (string)($in['next_state'] ?? '');
$ok = self::can_transition($id,$next);
if(!$ok) return json_ng('invalid transition',409);
db()->prepare('UPDATE requests SET state=? WHERE id=?')->execute([$next,$id]);
db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')->execute([$id,'FINANCE','STATE',$next]);
return json_ok();
}


private static function can_transition($id,$next){
$cur = db()->prepare('SELECT state, expects_network FROM requests WHERE id=?');
$cur->execute([$id]); $row=$cur->fetch(); if(!$row) return false;
$state=$row['state']; $net=$row['expects_network'];
$flows = [
'NONE' => [ 'NEW=>ACCEPTED','ACCEPTED=>CASH_GIVEN','CASH_GIVEN=>COLLECTED','COLLECTED=>RECEIPT_DONE' ],
'CONVENIENCE' => [ 'NEW=>ACCEPTED','ACCEPTED=>CASH_WITHDRAWN','CASH_WITHDRAWN=>PAID','PAID=>RECEIPT_DONE' ],
'BANK_TRANSFER' => [ 'NEW=>ACCEPTED','ACCEPTED=>TRANSFERRED','TRANSFERRED=>RECEIPT_DONE' ]
];
return in_array("$state=>$next", $flows[$net] ?? [], true);
}


static function upload_receipt($id){ require_role(['FINANCE','ADMIN']);
if(!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) return json_ng('file required');
$total = (float)($_POST['total'] ?? 0);
$change = (float)($_POST['change_returned'] ?? 0);
$kind = (string)($_POST['kind'] ?? 'RECEIPT');
$taken_at = (string)($_POST['taken_at'] ?? date('Y-m-d H:i:s'));
if($total<=0) return json_ng('total required');


$cfg = require __DIR__.'/../config.php';
// request取得→アップロード先
$st=db()->prepare('SELECT request_no,submitted_at FROM requests WHERE id=?'); $st->execute([$id]); $r=$st->fetch(); if(!$r) return json_ng('not found',404);
$basedir = $cfg['upload_dir'].'/'.date('Y',strtotime($r['submitted_at'])).'/'.date('m',strtotime($r['submitted_at'])).'/'.$r['request_no'];
ensure_dir($basedir);


$f=$_FILES['file']; $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
$allowed=['jpg','jpeg','png','pdf']; if(!in_array($ext,$allowed,true)) return json_ng('invalid file type');
if($f['size']>10*1024*1024) return json_ng('file too large');
$fname=safe_filename(pathinfo($f['name'],PATHINFO_FILENAME)).'_'.date('His').'.'.$ext;
$path=$basedir.'/'.$fname; move_uploaded_file($f['tmp_name'],$path);


$ins=db()->prepare('INSERT INTO receipts(request_id,kind,total,change_returned,file_path,taken_at) VALUES (?,?,?,?,?,?)');
$ins->execute([$id,$kind,$total,$change,$path,$taken_at]);


return json_ok();
}


static function recalc($id){ require_role(['FINANCE','ADMIN']);
$r = db()->prepare('SELECT expects_network,cash_given,expected_total FROM requests WHERE id=?');
$r->execute([$id]); $row=$r->fetch(); if(!$row) return json_ng('not found',404);
$sum = db()->prepare('SELECT COALESCE(SUM(total),0) AS t, COALESCE(SUM(change_returned),0) AS c FROM receipts WHERE request_id=?');
$sum->execute([$id]); $s=$sum->fetch();
if($row['expects_network']==='NONE'){
$diff = round(((float)$row['cash_given']) - ($s['t'] + $s['c']), 2);
}else{
$diff = round(((float)$row['expected_total']) - $s['t'], 2);
}
db()->prepare('UPDATE requests SET diff_amount=? WHERE id=?')->execute([$diff,$id]);
return json_ok(['sum_total'=>(float)$s['t'],'sum_change'=>(float)$s['c'],'diff'=>$diff]);
}

static function delete($id){ require_role(['ADMIN']);
    // リクエストに関連するレシートが存在するかチェック
    $st = db()->prepare("SELECT COUNT(*) FROM receipts WHERE request_id = ?");
    $st->execute([$id]);
    $receipt_count = $st->fetchColumn();
    if($receipt_count > 0) return json_ng('このリクエストに関連するレシートが存在するため削除できません');
    
    // リクエストに関連するアイテムが存在するかチェック
    $st = db()->prepare("SELECT COUNT(*) FROM request_items WHERE request_id = ?");
    $st->execute([$id]);
    $item_count = $st->fetchColumn();
    if($item_count > 0) return json_ng('このリクエストに関連するアイテムが存在するため削除できません');
    
    // リクエストを削除
    $st = db()->prepare('DELETE FROM requests WHERE id = ?');
    $st->execute([$id]);
    
    if($st->rowCount() === 0) return json_ng('リクエストが見つかりません');
    
    return json_ok();
}
}

