<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

function current_admin_name() {
    return $_SESSION['admin_name'] ?? 'Admin';
}

function current_admin_role() {
    return $_SESSION['admin_role'] ?? 'Administrator';
}

function current_admin_initial() {
    $name = current_admin_name();
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') $initials .= strtoupper($p[0]);
    }
    return $initials !== '' ? $initials : 'A';
}
?>