<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../app/lib/db.php';
require_once __DIR__.'/../app/lib/auth.php';
require_once __DIR__.'/../app/lib/util.php';
require_once __DIR__.'/../app/controllers/AuthController.php';
require_once __DIR__.'/../app/controllers/MasterController.php';
require_once __DIR__.'/../app/controllers/RequestController.php';


$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);


// 静的HTMLを返す場合（/pages/*）はWebサーバで配信推奨。ここではAPIのみを扱う。


try {
if ($method==='POST' && $path==='/api/auth/login') return AuthController::login();
if ($method==='POST' && $path==='/api/auth/logout') return AuthController::logout();


if ($method==='GET' && $path==='/api/masters/departments') return MasterController::departments_list();
if ($method==='GET' && $path==='/api/masters/members') return MasterController::members_list();
if ($method==='POST' && $path==='/api/masters/department') return MasterController::upsert_department();
if ($method==='POST' && $path==='/api/masters/member') return MasterController::upsert_member();
if ($method==='POST' && $path==='/api/masters/bulk-upload') return MasterController::bulk_upload();
if ($method==='DELETE' && $path==='/api/masters/department') return MasterController::delete_department();
if ($method==='DELETE' && $path==='/api/masters/member') return MasterController::delete_member();


if ($method==='POST' && $path==='/api/requests') return RequestController::create();
if ($method==='GET' && $path==='/api/requests') return RequestController::list();
if ($method==='POST' && preg_match('#^/api/requests/(\d+)/accept$#',$path,$m)) return RequestController::accept((int)$m[1]);
if ($method==='POST' && preg_match('#^/api/requests/(\d+)/reject$#',$path,$m)) return RequestController::reject((int)$m[1]);
if ($method==='POST' && preg_match('#^/api/requests/(\d+)/state$#',$path,$m)) return RequestController::state((int)$m[1]);
if ($method==='POST' && preg_match('#^/api/requests/(\d+)/cash$#',$path,$m)) return RequestController::set_cash((int)$m[1]);
if ($method==='POST' && preg_match('#^/api/requests/(\d+)/receipt$#',$path,$m)) return RequestController::upload_receipt((int)$m[1]);
if ($method==='GET' && preg_match('#^/api/requests/(\d+)/receipts$#',$path,$m)) return RequestController::get_receipts((int)$m[1]);
if ($method==='DELETE' && preg_match('#^/api/requests/(\d+)/receipt$#',$path,$m)) return RequestController::delete_receipt((int)$m[1]);
if ($method==='GET' && preg_match('#^/api/requests/(\d+)/recalc$#',$path,$m)) return RequestController::recalc((int)$m[1]);
if ($method==='GET' && preg_match('#^/api/requests/(\d+)/excel$#',$path,$m)) return RequestController::view_excel((int)$m[1]);
if ($method==='GET' && $path==='/api/requests/receipt-image') return RequestController::view_receipt_image();
if ($method==='DELETE' && preg_match('#^/api/requests/(\d+)$#',$path,$m)) return RequestController::delete((int)$m[1]);


http_response_code(404); echo json_encode(['ok'=>false,'message'=>'not found']);
} catch (Throwable $e) {
http_response_code(500);
echo json_encode(['ok'=>false,'message'=>'server error','trace_id'=>uniqid('e')]);
}