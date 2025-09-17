<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/util.php';


class AuthController{
static function login(){
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$role = (string)($in['role'] ?? '');
$pass = (string)($in['password'] ?? '');
if(!$role || !$pass) return json_ng('role and password required',400);
if(login_role($role,$pass)) return json_ok(['role'=>$role]);
return json_ng('invalid credentials',401);
}
static function logout(){ logout(); return json_ok(); }
}