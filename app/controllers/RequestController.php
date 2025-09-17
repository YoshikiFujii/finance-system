<?php


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
}