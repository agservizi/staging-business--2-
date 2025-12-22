<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Debug temporaneo prima di require_role
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo "Debug: Prima di require_role\n";
    echo "User ID: " . ($_SESSION['user_id'] ?? 'null') . "\n";
    echo "Role: " . ($_SESSION['role'] ?? 'null') . "\n";
    exit;
}

require_role('Admin', 'Manager', 'Operatore', 'Collaboratore');

// Debug temporaneo
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo "Debug: Ruolo verificato\n";
    echo "User ID: " . ($_SESSION['user_id'] ?? 'null') . "\n";
    echo "Role: " . ($_SESSION['role'] ?? 'null') . "\n";
    exit;
}

require_once __DIR__ . '/../../app/Services/IliadCredentialsService.php';

$iliadService = new IliadCredentialsService($pdo);