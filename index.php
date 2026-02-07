<?php
// 文字エンコーディングの設定
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
mb_regex_encoding('UTF-8');

// CORSヘッダーの設定
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// OPTIONSリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// エラーハンドリングを有効化
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('html_errors', 0);

// レンタルサーバー用のパス設定
$app_dir = __DIR__ . '/app';

// ファイルの存在確認と読み込み
$required_files = [
    $app_dir . '/lib/env.php',
    $app_dir . '/lib/db.php',
    $app_dir . '/lib/auth.php',
    $app_dir . '/lib/util.php',
    $app_dir . '/controllers/AuthController.php',
    $app_dir . '/controllers/MasterController.php',
    $app_dir . '/controllers/RequestController.php',
    $app_dir . '/controllers/BackupController.php',
    $app_dir . '/controllers/FinanceController.php'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        require_once $file;
    } else {
        error_log("Required file not found: $file");
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'server error: missing file ' . basename($file)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 環境変数の読み込み
    if (function_exists('load_env')) {
        load_env(__DIR__ . '/.env');
    }
}

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role']) && isset($_POST['password'])) {
    try {
        echo AuthController::login();
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Login failed'], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// パスの取得
$path = $_GET['path'] ?? '';

// デバッグ情報
error_log("Method: " . $_SERVER['REQUEST_METHOD'] . ", Path: $path, REQUEST_URI: " . $_SERVER['REQUEST_URI']);

// ページルーティング（API以外）
if ($path === '' || $path === '/' || $path === '/login') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/pages/login.html');
    exit;
} elseif ($path === '/admin') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/pages/admin.html');
    exit;
} elseif ($path === '/officer') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/pages/officer.html');
    exit;
} elseif ($path === '/finance') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/pages/finance.html');
    exit;
} elseif ($path === '/audit') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/pages/audit.html');
    exit;
}

try {
    // 認証関連
    if ($path === '/api/auth/login') {
        echo AuthController::login();
    } elseif ($path === '/api/auth/logout') {
        echo AuthController::logout();
    } elseif ($path === '/api/auth/check') {
        echo AuthController::check();
    } elseif ($path === '/api/auth/change_password') {
        echo AuthController::change_password();
    }

    // 財務管理関連
    elseif ($path === '/api/finance') {
        echo FinanceController::get_finance_data();
    } elseif ($path === '/api/finance/bank-balance') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::update_bank_balance();
        }
    } elseif ($path === '/api/finance/cash-amount') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::update_cash_amount();
        }
    } elseif ($path === '/api/finance/bank-withdraw') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::bank_withdraw();
        }
    } elseif ($path === '/api/finance/bank-deposit') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::bank_deposit();
        }
    } elseif ($path === '/api/finance/cash-withdraw') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::cash_withdraw();
        }
    } elseif ($path === '/api/finance/cash-deposit') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::cash_deposit();
        }
    } elseif ($path === '/api/finance/recalculate-processed') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::recalculate_processed();
        }
    } elseif ($path === '/api/finance/logs') {
        echo FinanceController::get_finance_logs();
    } elseif ($path === '/api/finance/init') {
        echo FinanceController::init_finance_tables();
    } elseif ($path === '/api/finance/logs/delete') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::delete_finance_log();
        }
    } elseif ($path === '/api/finance/logs/clear') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::clear_finance_logs();
        }
    } elseif ($path === '/api/finance/actual-cash') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::update_actual_cash();
        }
    } elseif ($path === '/api/finance/actual-cash/init') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::init_actual_cash();
        }
    } elseif ($path === '/api/finance/init-bank-balance') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::init_bank_balance();
        }
    } elseif ($path === '/api/finance/init-cash-on-hand') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::init_cash_on_hand();
        }
    } elseif ($path === '/api/finance/init-with-values') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::init_finance_tables_with_initial_values();
        }
    } elseif ($path === '/api/finance/initial-balance') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo FinanceController::get_initial_bank_balance();
        }
    } elseif ($path === '/api/finance/pending-requests') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo FinanceController::get_pending_requests_amount();
        }
    } elseif ($path === '/api/finance/income') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::add_income();
        }
    } elseif ($path === '/api/finance/income/list') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo FinanceController::get_income_list();
        }
    } elseif ($path === '/api/finance/income/delete') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo FinanceController::delete_income();
        }
    } elseif ($path === '/api/debug/database') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo FinanceController::debug_database();
        }
    }

    // マスタ関連
    elseif ($path === '/api/masters/departments') {
        echo MasterController::departments_list();
    } elseif ($path === '/api/masters/members') {
        echo MasterController::members_list();
    } elseif ($path === '/api/masters/events') {
        echo MasterController::events_list();
    } elseif ($path === '/api/masters/accounting-periods') {
        echo MasterController::accounting_periods_list();
    } elseif ($path === '/api/masters/accounting-period') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo MasterController::upsert_accounting_period();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            echo MasterController::delete_accounting_period();
        }
    } elseif ($path === '/api/masters/department') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo MasterController::upsert_department();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            echo MasterController::delete_department();
        }
    } elseif ($path === '/api/masters/member') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo MasterController::upsert_member();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            echo MasterController::delete_member();
        }
    } elseif ($path === '/api/masters/event') {
        // JSONデータを確認して削除か追加/更新かを判定
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        if (isset($input['action']) && $input['action'] === 'delete') {
            // 削除処理
            echo MasterController::delete_event();
        } else {
            // 追加/更新処理
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo MasterController::upsert_event();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
                echo MasterController::delete_event();
            }
        }
    } elseif ($path === '/api/masters/bulk-upload') {
        echo MasterController::bulk_upload();
    }

    // リクエスト関連
    elseif ($path === '/api/requests') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo RequestController::create();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo RequestController::list();
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)$/', $path, $matches)) {
        $id = $matches[1];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo RequestController::get($id);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            echo RequestController::delete($id);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // POSTで削除リクエストの場合
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            if (isset($input['action']) && $input['action'] === 'delete') {
                echo RequestController::delete($id);
            } else {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
            }
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/accept$/', $path, $matches)) {
        try {
            error_log("Routing to RequestController::accept with ID: " . $matches[1]);
            $result = RequestController::accept((int) $matches[1]);
            echo $result;
        } catch (Exception $e) {
            error_log("Routing error in accept: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Internal server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/reject$/', $path, $matches)) {
        try {
            error_log("Routing to RequestController::reject with ID: " . $matches[1]);
            $result = RequestController::reject((int) $matches[1]);
            echo $result;
        } catch (Exception $e) {
            error_log("Routing error in reject: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Internal server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/state$/', $path, $matches)) {
        try {
            error_log("Routing to RequestController::state with ID: " . $matches[1]);
            $result = RequestController::state((int) $matches[1]);
            echo $result;
        } catch (Exception $e) {
            error_log("Routing error in state: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Internal server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/cash$/', $path, $matches)) {
        try {
            error_log("Routing to RequestController::set_cash with ID: " . $matches[1]);
            $result = RequestController::set_cash((int) $matches[1]);
            echo $result;
        } catch (Exception $e) {
            error_log("Routing error in set_cash: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Internal server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/receipt$/', $path, $matches)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // JSONデータを確認して削除か追加かを判定
            $input = json_decode(file_get_contents('php://input'), true) ?: [];

            if (isset($input['action']) && $input['action'] === 'delete') {
                // 削除処理
                echo RequestController::delete_receipt($matches[1]);
            } else {
                // レシート登録処理（画像なし）
                echo RequestController::add_receipt($matches[1]);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            echo RequestController::delete_receipt($matches[1]);
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/receipt\/delete$/', $path, $matches)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo RequestController::delete_receipt($matches[1]);
        }
    } elseif ($path === '/api/requests/recalculate-processed-amounts') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo RequestController::recalculateAllProcessedAmounts();
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/receipts$/', $path, $matches)) {
        try {
            error_log("Routing to RequestController::get_receipts with ID: " . $matches[1]);
            $result = RequestController::get_receipts((int) $matches[1]);
            echo $result;
        } catch (Exception $e) {
            error_log("Routing error in get_receipts: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Internal server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/receipt-status$/', $path, $matches)) {
        try {
            error_log("Routing to RequestController::update_receipt_status with ID: " . $matches[1]);
            $result = RequestController::update_receipt_status((int) $matches[1]);
            echo $result;
        } catch (Exception $e) {
            error_log("Routing error in update_receipt_status: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Internal server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/excel$/', $path, $matches)) {
        echo RequestController::download_excel($matches[1]);
    } elseif (preg_match('/^\/api\/requests\/(\d+)\/recalc$/', $path, $matches)) {
        echo RequestController::recalc($matches[1]);
    } elseif ($path === '/api/requests/receipt-image') {
        // レシート画像表示機能は削除されたため、エラーを返す
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Receipt image feature has been removed'], JSON_UNESCAPED_UNICODE);

        // バックアップ関連
    } elseif ($path === '/api/backups') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo BackupController::list();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo BackupController::create();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            echo BackupController::delete();
        }
    } elseif ($path === '/api/backups/restore') {
        echo BackupController::restore();
    } elseif ($path === '/api/backups/upload-restore') {
        echo BackupController::uploadRestore();
    } elseif ($path === '/api/backups/delete') {
        echo BackupController::delete();
    } elseif ($path === '/api/backups/download') {
        echo BackupController::download();
    } elseif (preg_match('/^\/api\/backups\/period$/', $path)) {
        echo BackupController::list_by_period();
    } elseif (preg_match('/^\/api\/events\/(\d+)\/receipts$/', $path, $matches)) {
        echo MasterController::get_event_receipts((int) $matches[1]);
    } elseif (preg_match('/^\/api\/events\/(\d+)\/subject-breakdown$/', $path, $matches)) {
        echo MasterController::get_event_subject_breakdown((int) $matches[1]);
    } elseif (preg_match('/^\/api\/events\/(\d+)\/subjects$/', $path, $matches)) {
        echo MasterController::get_event_subjects((int) $matches[1]);
    } elseif (preg_match('/^\/api\/events\/(\d+)\/receipts\?subject=(.+)$/', $path, $matches)) {
        echo MasterController::get_event_receipts_by_subject((int) $matches[1], urldecode($matches[2]));
    } elseif (preg_match('/^\/api\/receipts\/(\d+)\/toggle-storaged$/', $path, $matches)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo MasterController::toggle_receipt_storaged((int) $matches[1]);
        } else {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        }
    }

    // 404 Not Found
    else {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'not found'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Internal server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>