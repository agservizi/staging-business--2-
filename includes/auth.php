<?php
session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db_connect.php';

// Bypass authentication for webhooks and testing
if (!defined('BYPASS_AUTH') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/express_webhook.php')) {
    if (!isset($_SESSION['user_id'])) {
        $auditLogger = new \App\Security\SecurityAuditLogger($pdo);
        attempt_remembered_login($pdo, $auditLogger);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_valid_csrf();
    }

    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
} elseif (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/express_webhook.php') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // For webhooks, skip CSRF but still validate webhook secret (done in webhook file)
}

function require_role(string ...$roles): void
{
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        header('Location: dashboard.php');
        exit;
    }
}
