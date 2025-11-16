<?php
declare(strict_types=1);

use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Manager', 'Operatore');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$consulenzaId = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
if ($consulenzaId <= 0) {
    add_flash('warning', 'Consulenza non valida.');
    header('Location: index.php');
    exit;
}

$service = consulenza_fiscale_service($pdo);

try {
    $service->delete($consulenzaId);
    add_flash('success', 'Consulenza rimossa correttamente.');
} catch (Throwable $exception) {
    add_flash('danger', 'Impossibile eliminare la consulenza: ' . $exception->getMessage());
    error_log('Consulenza Fiscale delete error: ' . $exception->getMessage());
}

header('Location: index.php');
exit;
