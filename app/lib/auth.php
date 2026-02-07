<?php
require_once __DIR__.'/../config.php';
session_name((require __DIR__.'/../config.php')['session_name']);
session_start();


function login_role(string $role, string $password): bool {
    error_log("login_role called - Role: $role, Password length: " . strlen($password));
    
    if(!in_array($role,['OFFICER','FINANCE','ADMIN','AUDIT'],true)) {
        error_log("Invalid role: $role");
        return false;
    }
    
    $st = db()->prepare('SELECT pass_hash FROM passwords WHERE role=?');
    $st->execute([$role]);
    $row = $st->fetch();
    
    if(!$row) {
        error_log("No password record found for role: $role");
        return false;
    }
    
    error_log("Password hash found for $role: " . substr($row['pass_hash'], 0, 20) . "...");
    
    $passwordMatch = password_verify($password, $row['pass_hash']);
    error_log("Password verification result for $role: " . ($passwordMatch ? 'MATCH' : 'NO MATCH'));
    
    if(!$passwordMatch) {
        return false;
    }
    
    // rotate session id
    try {
        session_regenerate_id(true);
        $_SESSION['role'] = $role;
        $_SESSION['last'] = time();
        error_log("Session variables set for role: $role");
    } catch (Exception $e) {
        error_log("Session error in login_role: " . $e->getMessage());
        return false;
    }
    
    error_log("Login successful for role: $role");
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
    error_log("require_role called - Required roles: " . json_encode($roles) . ", Current role: " . ($cur ?? 'NULL'));
    error_log("Session data: " . json_encode($_SESSION));
    
    if(!$cur || !in_array($cur,$roles,true)){
        error_log("Authorization failed - Current role: " . ($cur ?? 'NULL') . ", Required: " . json_encode($roles));
        http_response_code(401);
        echo json_encode(['ok'=>false,'message'=>'unauthorized']);
        exit;
    }
    error_log("Authorization successful for role: $cur");
}


function is_logged_in(): bool {
    return current_role() !== null;
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

