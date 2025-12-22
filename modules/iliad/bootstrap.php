<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

// require_role('Admin', 'Manager', 'Operatore', 'Collaboratore'); // Temporaneamente commentato per debug

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