<?php
function json_ok($data=[]){ echo json_encode(['ok'=>true,'data'=>$data]); }
function json_ng($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'message'=>$msg]); }
function ensure_dir($path){ if(!is_dir($path)) mkdir($path,0775,true); }
function safe_filename($name){
$name = preg_replace('/[^A-Za-z0-9_\.-]+/','_', $name);
return substr($name,0,120);
}