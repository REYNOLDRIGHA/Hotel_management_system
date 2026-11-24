<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const SESSION_TIMEOUT_SECONDS = 900; // 15 minutes (FR-005)[cite: 1]

function enforceSessionTimeout(): void
{
    if (!empty($_SESSION['user_id'])) {
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
            session_unset();
            session_destroy();
            header('Location: /hms/auth/login.php?timeout=1');
            exit;
        }
        $_SESSION['last_activity'] = $now;
    }
}

function requireLogin(): void
{
    enforceSessionTimeout();
    if (empty($_SESSION['user_id'])) {
        header('Location: /hms/auth/login.php');
        exit;
    }
}

function requireRole(array $allowedRoles): void
{
    requireLogin();
    $currentRole = $_SESSION['role'] ?? '';
    if (!in_array($currentRole, $allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied for your role.');
    }
}