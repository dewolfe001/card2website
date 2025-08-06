<?php
session_start();

function require_login(bool $redirect = true): bool {
    if (!isset($_SESSION['user_id'])) {
        if ($redirect) {
            header('Location: login.php');
            exit;
        }
        return false;
    }
    return true;
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function current_user_is_admin() {
    return !empty($_SESSION['is_admin']);
}

function require_admin() {
    require_login();
    if (!current_user_is_admin()) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}
?>
