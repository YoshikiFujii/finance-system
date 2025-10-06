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
    
    // 振込口座情報
    $bank_name = trim((string)($_POST['bank_name'] ?? ''));
    $branch_name = trim((string)($_POST['branch_name'] ?? ''));
    $account_type = trim((string)($_POST['account_type'] ?? ''));
    $account_number = trim((string)($_POST['account_number'] ?? ''));
    $account_holder = trim((string)($_POST['account_holder'] ?? ''));
    
    if(!$member_id||!$department_id||$summary==='') return json_ng('missing fields');
    
    // 振込選択時は振込口座情報が必須
    if($expects === 'BANK_TRANSFER') {
        error_log("Bank transfer validation - bank_name: '$bank_name', branch_name: '$branch_name', account_type: '$account_type', account_number: '$account_number', account_holder: '$account_holder'");
        if(!$bank_name || !$branch_name || !$account_type || !$account_number || !$account_holder) {
            error_log("Bank transfer validation failed - missing required fields");
            return json_ng('振込を選択した場合は、振込口座情報をすべて入力してください');
        }
    }
    // エクセルファイルは任意のため、ファイルがアップロードされている場合のみ処理
    $excel_file = null;
    if(isset($_FILES['excel']) && $_FILES['excel']['error'] === UPLOAD_ERR_OK) {
        $excel_file = $_FILES['excel'];
    }
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
    // エクセルファイルの処理（ファイルがアップロードされている場合のみ）
    $path = null;
    if($excel_file) {
        // 拡張子/サイズ/MIMEチェック（簡易）
        $f = $excel_file;
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
    }


    error_log("Inserting request with bank info - expects: '$expects', bank_name: '$bank_name', branch_name: '$branch_name', account_type: '$account_type', account_number: '$account_number', account_holder: '$account_holder'");
    $st = db()->prepare('INSERT INTO requests(request_no,member_id,department_id,submitted_at,summary,expects_network,excel_path,bank_name,branch_name,account_type,account_number,account_holder) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    try {
        $st->execute([$no,$member_id,$department_id,date('Y-m-d H:i:s'),$summary,$expects,$path,$bank_name ?: null,$branch_name ?: null,$account_type ?: null,$account_number ?: null,$account_holder ?: null]);
        error_log("Request inserted successfully");
    } catch (Exception $e) {
        error_log("Database insert error: " . $e->getMessage());
        return json_ng('データベースエラー: ' . $e->getMessage());
    }


    db()->prepare('INSERT INTO audit_logs(request_id,actor,action,detail) VALUES (LAST_INSERT_ID(),?,?,?)')
    ->execute(['OFFICER','CREATE','created request']);
    return json_ok(['request_no'=>$no]);
}


static function list(){ // 財務/管理者/役員向けリスト
$cur = current_role();
if(!$cur || !in_array($cur,['FINANCE','ADMIN','OFFICER'],true)){
    return json_ng('unauthorized', 401);
}
$q = $_GET['q'] ?? '';
$state = $_GET['state'] ?? '';
$dep = $_GET['dept'] ?? '';
$member_id = $_GET['member_id'] ?? '';
$department_id = $_GET['department_id'] ?? '';
$where = [];$args=[];
if($q!=''){ $where[]='(r.summary LIKE ? OR m.name LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
if($state!=''){
    // カンマ区切りで複数の状態を指定可能
    if(strpos($state, ',') !== false) {
        $states = explode(',', $state);
        $placeholders = str_repeat('?,', count($states) - 1) . '?';
        $where[] = "r.state IN ($placeholders)";
        foreach($states as $s) {
            $args[] = trim($s);
        }
    } else {
        $where[]='r.state=?'; 
        $args[]=$state;
    }
}
if($dep!=''){ $where[]='r.department_id=?'; $args[]=(int)$dep; }
if($member_id!=''){ $where[]='r.member_id=?'; $args[]=(int)$member_id; }
if($department_id!=''){ $where[]='r.department_id=?'; $args[]=(int)$department_id; }
$sql = 'SELECT r.id,r.request_no,r.submitted_at,r.summary,r.expects_network,r.state,r.cash_given,r.expected_total,r.rejected_reason,r.bank_name,r.branch_name,r.account_type,r.account_number,r.account_holder,r.excel_path, m.name AS member_name, d.name AS dept_name
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
$valid_states = ['NEW','ACCEPTED','REJECTED','CASH_GIVEN','COLLECTED','RECEIPT_DONE','TRANSFERRED','FINALIZED'];
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
'NONE' => [ 'NEW=>ACCEPTED','ACCEPTED=>CASH_GIVEN','CASH_GIVEN=>COLLECTED','COLLECTED=>RECEIPT_DONE','RECEIPT_DONE=>FINALIZED' ],
'CONVENIENCE' => [ 'NEW=>ACCEPTED','ACCEPTED=>CASH_GIVEN','CASH_GIVEN=>COLLECTED','COLLECTED=>RECEIPT_DONE','RECEIPT_DONE=>FINALIZED' ],
'BANK_TRANSFER' => [ 'NEW=>ACCEPTED','ACCEPTED=>TRANSFERRED','TRANSFERRED=>COLLECTED','COLLECTED=>RECEIPT_DONE','RECEIPT_DONE=>FINALIZED' ]
];
return in_array("$state=>$next", $flows[$net] ?? [], true);
}


static function upload_receipt($id){ require_role(['FINANCE','ADMIN']);

// デバッグログ追加
error_log("Upload receipt debug - ID: $id");
error_log("Upload receipt debug - FILES: " . print_r($_FILES, true));
error_log("Upload receipt debug - POST: " . print_r($_POST, true));

if(!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) {
    error_log("Upload receipt debug - File error: " . ($_FILES['file']['error'] ?? 'no file'));
    return json_ng('file required');
}

$total = (float)($_POST['total'] ?? 0);
$change = (float)($_POST['change_returned'] ?? 0);
  $event_id = (int)($_POST['event_id'] ?? 0);
  $subject = trim((string)($_POST['subject'] ?? ''));
  $purpose = (string)($_POST['purpose'] ?? '');
  $payer = trim((string)($_POST['payer'] ?? ''));
  $receipt_date = (string)($_POST['receipt_date'] ?? date('Y-m-d'));

error_log("Upload receipt debug - Total: $total, Change: $change, Event ID: $event_id, Subject: $subject, Purpose: $purpose, Payer: $payer, Receipt Date: $receipt_date");

if($total<=0) return json_ng('total required');


$cfg = require __DIR__.'/../config.php';
// request取得→アップロード先
$st=db()->prepare('SELECT request_no,submitted_at FROM requests WHERE id=?'); $st->execute([$id]); $r=$st->fetch(); if(!$r) return json_ng('not found',404);
$basedir = $cfg['upload_dir'].'/'.date('Y',strtotime($r['submitted_at'])).'/'.date('m',strtotime($r['submitted_at'])).'/'.$r['request_no'];
ensure_dir($basedir);


$f=$_FILES['file']; $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));

error_log("Upload receipt debug - File name: " . $f['name']);
error_log("Upload receipt debug - File extension: " . $ext);
error_log("Upload receipt debug - File size: " . $f['size']);
error_log("Upload receipt debug - Upload dir: " . $basedir);

$allowed=['jpg','jpeg','png','pdf']; 
if(!in_array($ext,$allowed,true)) {
    error_log("Upload receipt debug - Invalid file type: " . $ext);
    return json_ng('invalid file type');
}

if($f['size']>10*1024*1024) {
    error_log("Upload receipt debug - File too large: " . $f['size']);
    return json_ng('file too large');
}

$fname=$f['name'];
$path=$basedir.'/'.$fname; 

error_log("Upload receipt debug - Target path: " . $path);

if(!move_uploaded_file($f['tmp_name'],$path)) {
    error_log("Upload receipt debug - Failed to move uploaded file");
    return json_ng('failed to save file');
}

error_log("Upload receipt debug - File saved successfully");


  $ins=db()->prepare('INSERT INTO receipts(request_id,kind,total,change_returned,file_path,taken_at,event_id,subject,purpose,payer,receipt_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
  
  error_log("Upload receipt debug - About to insert into database");
  error_log("Upload receipt debug - Insert values: ID=$id, Total=$total, Change=$change, Path=$path, Event ID=$event_id, Subject=$subject, Purpose=$purpose, Payer=$payer, Receipt Date=$receipt_date");
  
  if(!$ins->execute([$id,'RECEIPT',$total,$change,$path,date('Y-m-d H:i:s'),$event_id ?: null,$subject ?: null,$purpose ?: null,$payer ?: null,$receipt_date])) {
      error_log("Upload receipt debug - Database insert failed: " . print_r($ins->errorInfo(), true));
      return json_ng('database error');
  }

error_log("Upload receipt debug - Database insert successful");
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
    $st = db()->prepare('
        SELECT r.id, r.kind, r.total, r.change_returned, r.file_path, r.taken_at, r.memo,
               r.event_id, r.subject, r.purpose, r.payer, r.receipt_date, r.is_completed,
               e.name as event_name
        FROM receipts r
        LEFT JOIN events e ON r.event_id = e.id
        WHERE r.request_id = ? 
        ORDER BY r.taken_at DESC
    ');
    $st->execute([$id]);
    $receipts = $st->fetchAll();
    return json_ok($receipts);
}

static function update_receipt_status($id){ require_role(['FINANCE','ADMIN']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $receipt_id = (int)($in['receipt_id'] ?? 0);
    $is_completed = (int)($in['is_completed'] ?? 0);
    
    if($receipt_id <= 0) return json_ng('invalid receipt id', 400);
    
    // レシートの存在確認
    $st = db()->prepare('SELECT id FROM receipts WHERE id = ? AND request_id = ?');
    $st->execute([$receipt_id, $id]);
    if(!$st->fetch()) return json_ng('receipt not found', 404);
    
    // レシートの記入状態を更新
    $update = db()->prepare('UPDATE receipts SET is_completed = ? WHERE id = ?');
    $update->execute([$is_completed, $receipt_id]);
    
    // 全てのレシートが記入済みかチェック
    $check = db()->prepare('SELECT COUNT(*) as total, SUM(is_completed) as completed FROM receipts WHERE request_id = ?');
    $check->execute([$id]);
    $result = $check->fetch();
    
    // 全てのレシートが記入済みの場合、リクエストの状態を記入完了に変更
    if($result['total'] > 0 && $result['total'] == $result['completed']) {
        $state_update = db()->prepare('UPDATE requests SET state = ? WHERE id = ? AND state = ?');
        $state_update->execute(['FINALIZED', $id, 'RECEIPT_DONE']);
    }
    
    return json_ok(['is_completed' => $is_completed, 'all_completed' => $result['total'] == $result['completed']]);
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
    // リクエスト情報を取得
    $st = db()->prepare('SELECT excel_path FROM requests WHERE id=?');
    $st->execute([$id]);
    $request = $st->fetch();
    if(!$request) return json_ng('リクエストが見つかりません', 404);
    
    // レシートファイルを取得して削除
    $receipts = db()->prepare('SELECT file_path FROM receipts WHERE request_id=?');
    $receipts->execute([$id]);
    while($receipt = $receipts->fetch()) {
        if($receipt['file_path'] && file_exists($receipt['file_path'])) {
            unlink($receipt['file_path']);
        }
    }
    
    // レシートディレクトリを削除（空の場合）
    if($request['excel_path']) {
        $request_dir = dirname($request['excel_path']);
        if(is_dir($request_dir) && count(scandir($request_dir)) <= 2) {
            rmdir($request_dir);
        }
    }
    
    // Excelファイルを削除
    if($request['excel_path'] && file_exists($request['excel_path'])) {
        unlink($request['excel_path']);
    }
    
    // データベースから削除（外部キー制約により関連データも自動削除）
    $st = db()->prepare('DELETE FROM requests WHERE id = ?');
    $st->execute([$id]);
    
    return json_ok();
}
}

