<?php
// Tmp test script to verify processed amount logic
require_once __DIR__ . '/app/lib/util.php';
require_once __DIR__ . '/app/lib/db.php';
require_once __DIR__ . '/app/lib/auth.php'; // Defines require_role, env, etc.
require_once __DIR__ . '/app/controllers/FinanceController.php';
require_once __DIR__ . '/app/controllers/FundController.php';

// Mock session to bypass require_role
$_SESSION['role'] = 'ADMIN';

echo "=== Verifying Active Year ===\n";
$pdo = db();
$stmt = $pdo->query("SELECT id, name FROM years WHERE is_active=1 LIMIT 1");
$activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Active Year: " . json_encode($activeYear) . "\n\n";


echo "=== FinanceController recalculate_processed ===\n";
$financeResult = FinanceController::recalculate_processed();
echo json_encode($financeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== FundController recalculateProcessedAmount ===\n";
$fundResult = FundController::recalculateProcessedAmount();
echo json_encode($fundResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
