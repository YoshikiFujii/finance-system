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
        
        // 現在のパスワードの検証
        if(!login_role($role, $currentPassword)) {
            return json_ng('current password is incorrect', 401);
        }
        
        // 新しいパスワードの検証
        if(strlen($newPassword) < 6) {
            return json_ng('new password must be at least 6 characters', 400);
        }
        
        // パスワードをハッシュ化
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // データベースのパスワードを更新
        try {
            $stmt = db()->prepare("UPDATE passwords SET password_hash = ? WHERE role = ?");
            $result = $stmt->execute([$hashedPassword, $role]);
            
            if($result) {
                return json_ok(['message' => 'Password changed successfully']);
            } else {
                return json_ng('failed to update password', 500);
            }
        } catch(Exception $e) {
            error_log("Password change error: " . $e->getMessage());
            return json_ng('database error', 500);
        }
    }
}