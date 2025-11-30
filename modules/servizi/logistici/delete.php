<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    add_flash('danger', 'Richiesta non valida.');
    header('Location: index.php');
    exit;
}

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    define('CORESUITE_PICKUP_BOOTSTRAP', true);
}

require_once __DIR__ . '/functions.php';

try {
    if (delete_package($id)) {
        add_flash('success', 'Pickup eliminato correttamente.');
    } else {
        add_flash('warning', 'Il pickup non è stato trovato o era già eliminato.');
    }
} catch (Throwable $exception) {
    error_log('Delete pickup failed: ' . $exception->getMessage());
    add_flash('danger', 'Impossibile eliminare il pickup.');
}

header('Location: index.php');
exit;
