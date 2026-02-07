<?php
function csrf_token(){
    if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function verify_csrf($t){
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t);
}