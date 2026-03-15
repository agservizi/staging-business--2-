<?php
session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db_connect.php';

// Bypass authentication for webhooks and testing
if (!defined('BYPASS_AUTH')) {
    if (!isset($_SESSION['user_id'])) {
        $auditLogger = new \App\Security\SecurityAuditLogger($pdo);
        attempt_remembered_login($pdo, $auditLogger);
    }

    // Debug per generate_pdf.php
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', 'generate_pdf.php') && isset($_GET['debug'])) {
        header('Content-Type: text/plain');
        echo "Auth Debug:\n";
        echo "Session user_id: " . ($_SESSION['user_id'] ?? 'null') . "\n";
        echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'null') . "\n";
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_valid_csrf();
    }

    if (!isset($_SESSION['user_id'])) {
        if (wants_json_response()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Sessione scaduta. Effettua di nuovo l\'accesso.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Location: ' . login_url());
        exit;
    }
}

function require_role(string ...$roles): void
{
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        if (wants_json_response()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Permessi insufficienti.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Location: ' . dashboard_url());
        exit;
    }
}
