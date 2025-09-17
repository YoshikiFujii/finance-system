<?php
require_once __DIR__.'/../config.php';
session_name((require __DIR__.'/../config.php')['session_name']);
session_start();


function login_role(string $role, string $password): bool {
if(!in_array($role,['OFFICER','FINANCE','ADMIN'],true)) return false;
$st = db()->prepare('SELECT pass_hash FROM passwords WHERE role=?');
$st->execute([$role]);
$row = $st->fetch();
if(!$row) return false;
if(!password_verify($password, $row['pass_hash'])) return false;
// rotate session id
session_regenerate_id(true);
$_SESSION['role'] = $role;
$_SESSION['last'] = time();
return true;
}


function current_role(): ?string {
if(empty($_SESSION['role'])) return null;
// timeout
$cfg = require __DIR__.'/../config.php';
if(time() - ($_SESSION['last'] ?? 0) > $cfg['session_lifetime']){
session_destroy();
return null;
}
$_SESSION['last'] = time();
return $_SESSION['role'];
}


function require_role(array $roles){
$cur = current_role();
if(!$cur || !in_array($cur,$roles,true)){
http_response_code(401);
echo json_encode(['ok'=>false,'message'=>'unauthorized']);
exit;
}
}


function logout(){
$_SESSION = [];
if (ini_get('session.use_cookies')) {
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000,
$params['path'], $params['domain'], $params['secure'], $params['httponly']
);
}
session_destroy();
}