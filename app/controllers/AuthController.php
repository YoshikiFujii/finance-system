<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/util.php';


class AuthController{
    static function login(){
        // JSONデータまたはPOSTデータから取得
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $role = (string)($in['role'] ?? $_POST['role'] ?? '');
        $pass = (string)($in['password'] ?? $_POST['password'] ?? '');
        if(!$role || !$pass) return json_ng('role and password required',400);
        if(login_role($role,$pass)) return json_ok(['role'=>$role]);
        return json_ng('invalid credentials',401);
    }
    static function logout(){ logout(); return json_ok(); }
    
    static function check(){
        if(!is_logged_in()) return json_ng('not authenticated', 401);
        return json_ok(['role' => $_SESSION['role'] ?? '']);
    }
    
    static function change_password(){
        require_role(['ADMIN']); // 管理者のみパスワード変更可能
        
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $role = (string)($in['role'] ?? '');
        $currentPassword = (string)($in['current_password'] ?? '');
        $newPassword = (string)($in['new_password'] ?? '');
        
        if(!$role || !$currentPassword || !$newPassword) {
            return json_ng('role, current_password, and new_password are required', 400);
        }
        
        // ロールの検証
        $validRoles = ['ADMIN', 'FINANCE', 'OFFICER'];
        if(!in_array($role, $validRoles)) {
            return json_ng('invalid role', 400);
        }
        
        // データベースの現在の状態を確認
        $checkStmt = db()->prepare("SELECT role, pass_hash FROM passwords WHERE role = ?");
        $checkStmt->execute([$role]);
        $currentRecord = $checkStmt->fetch();
        error_log("Current password record for $role: " . json_encode($currentRecord));
        
        // パスワード検証のテスト
        if ($currentRecord) {
            $testVerify = password_verify($currentPassword, $currentRecord['pass_hash']);
            error_log("Direct password_verify test: " . ($testVerify ? 'SUCCESS' : 'FAILED'));
        }
        
        // 現在のパスワードの検証
        error_log("Password change attempt - Role: $role, Current password length: " . strlen($currentPassword));
        
        // デバッグ情報をレスポンスに含める（開発用）
        $debugInfo = [
            'role' => $role,
            'password_length' => strlen($currentPassword),
            'has_record' => !empty($currentRecord),
            'session_id' => session_id(),
            'session_data' => $_SESSION
        ];
        
        if(!login_role($role, $currentPassword)) {
            error_log("Password verification failed for role: $role");
            return json_ng('current password is incorrect. Debug: ' . json_encode($debugInfo), 401);
        }
        error_log("Password verification successful for role: $role");
        
        // 新しいパスワードの検証
        if(strlen($newPassword) < 6) {
            return json_ng('new password must be at least 6 characters', 400);
        }
        
        // パスワードをハッシュ化
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // データベースのパスワードを更新
        try {
            $stmt = db()->prepare("UPDATE passwords SET pass_hash = ? WHERE role = ?");
            $result = $stmt->execute([$hashedPassword, $role]);
            
            error_log("Password update query executed - Rows affected: " . $stmt->rowCount());
            
            if($result) {
                error_log("Password successfully updated for role: $role");
                return json_ok(['message' => 'Password changed successfully']);
            } else {
                error_log("Password update failed - No rows affected");
                return json_ng('failed to update password', 500);
            }
        } catch(Exception $e) {
            error_log("Password change database error: " . $e->getMessage());
            return json_ng('database error: ' . $e->getMessage(), 500);
        }
    }
}