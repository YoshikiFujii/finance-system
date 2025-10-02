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
    // 保存先（受付番号を簡略化）
    $no = date('ymd').sprintf('%03d', random_int(1,999));
    $basedir = $cfg['upload_dir'].'/'.date('Y').'/'.date('m').'/'.$no;
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
    // 拡張子/サイズ/MIMEチェック（簡易）
    $f = $_FILES['excel'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['xlsx','xls'];
    if(!in_array($ext,$allowed,true)) return json_ng('invalid excel extension');
    if($f['size'] > 10*1024*1024) return json_ng('file too large');

    // ファイル名を元の名前のまま保持（重複回避のためタイムスタンプとランダム数を追加）
    $original_name = pathinfo($f['name'], PATHINFO_FILENAME);
    $timestamp = date('His');
    $random = sprintf('%03d', random_int(100,999));
    $fname = $original_name . '_' . $timestamp . '_' . $random . '.' . $ext;
    $path = $basedir.'/'.$fname;
    
    if(!move_uploaded_file($f['tmp_name'],$path)) {
        error_log("File upload failed: " . $f['tmp_name'] . " -> " . $path);
        return json_ng('ファイルのアップロードに失敗しました。管理者にお問い合わせください。');
    }


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
$valid_states = ['NEW','ACCEPTED','REJECTED','CASH_GIVEN','COLLECTED','RECEIPT_DONE'];
if(!in_array($next, $valid_states, true)) return json_ng('invalid state',400);

// 管理者の場合は状態遷移制限を無視
$user_role = $_SESSION['role'] ?? '';
if($user_role !== 'ADMIN') {
    $ok = self::can_transition($id,$next);
    if(!$ok) return json_ng('invalid transition',409);
}

db()->prepare('UPDATE requests SET state=? WHERE id=?')->execute([$next,$id]);
db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (?,?,?,?)')->execute([$id,$user_role,'STATE',$next]);
return json_ok();
}


private static function can_transition($id,$next){
$cur = db()->prepare('SELECT state, expects_network FROM requests WHERE id=?');
$cur->execute([$id]); $row=$cur->fetch(); if(!$row) return false;
$state=$row['state']; $net=$row['expects_network'];
$flows = [
'NONE' => [ 'NEW=>ACCEPTED','ACCEPTED=>CASH_GIVEN','CASH_GIVEN=>COLLECTED','COLLECTED=>RECEIPT_DONE' ],
'CONVENIENCE' => [ 'NEW=>ACCEPTED','ACCEPTED=>CASH_GIVEN','CASH_GIVEN=>COLLECTED','COLLECTED=>RECEIPT_DONE' ],
'BANK_TRANSFER' => [ 'NEW=>ACCEPTED','ACCEPTED=>CASH_GIVEN','CASH_GIVEN=>COLLECTED','COLLECTED=>RECEIPT_DONE' ]
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
$fname=$f['name'];
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

static function view_excel($id){ require_role(['FINANCE','ADMIN']);
    $st = db()->prepare('SELECT excel_path FROM requests WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if(!$row) {
        http_response_code(404);
        echo 'File not found';
        return;
    }
    
    $file_path = $row['excel_path'];
    
    // 相対パスの場合は絶対パスに変換
    if(!file_exists($file_path)) {
        // /var/www/html/app/../../storage/... の形式を /var/www/html/storage/... に変換
        $file_path = str_replace('/var/www/html/app/../../', '/var/www/html/', $file_path);
    }
    
    if(!file_exists($file_path)) {
        http_response_code(404);
        echo 'File not found: ' . $file_path;
        return;
    }
    
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $mime_types = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel'
    ];
    
    $mime_type = $mime_types[$ext] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
    header('Content-Length: ' . filesize($file_path));
    
    readfile($file_path);
}

static function view_receipt_image(){ require_role(['FINANCE','ADMIN']);
    $file_path = $_GET['path'] ?? '';
    if(!$file_path) {
        http_response_code(400);
        echo 'File path required';
        return;
    }
    
    // 相対パスの場合は絶対パスに変換
    if(!file_exists($file_path)) {
        $file_path = str_replace('/var/www/html/app/../../', '/var/www/html/', $file_path);
    }
    
    if(!file_exists($file_path)) {
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

static function get_receipts($id){ require_role(['FINANCE','ADMIN']);
    $st = db()->prepare('SELECT id, kind, total, change_returned, file_path, taken_at, memo FROM receipts WHERE request_id = ? ORDER BY taken_at DESC');
    $st->execute([$id]);
    $receipts = $st->fetchAll();
    return json_ok($receipts);
}

static function delete_receipt($id){ require_role(['FINANCE','ADMIN']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $receipt_id = (int)($in['receipt_id'] ?? 0);
    if(!$receipt_id) return json_ng('receipt_id required');
    
    // レシート情報を取得
    $st = db()->prepare('SELECT file_path FROM receipts WHERE id = ? AND request_id = ?');
    $st->execute([$receipt_id, $id]);
    $receipt = $st->fetch();
    if(!$receipt) return json_ng('レシートが見つかりません');
    
    // レシートを削除
    $st = db()->prepare('DELETE FROM receipts WHERE id = ? AND request_id = ?');
    $st->execute([$receipt_id, $id]);
    
    if($st->rowCount() === 0) return json_ng('レシートの削除に失敗しました');
    
    // ファイルも削除
    if(file_exists($receipt['file_path'])) {
        unlink($receipt['file_path']);
    }
    
    return json_ok();
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

