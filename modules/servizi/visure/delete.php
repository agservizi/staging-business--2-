<?php

use App\Services\ServiziWeb\VisureService;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$visuraId = isset($_POST['visura_id']) ? trim((string) $_POST['visura_id']) : '';
if ($visuraId === '') {
    add_flash('warning', 'Identificativo visura mancante.');
    header('Location: index.php');
    exit;
}

try {
    $service = new VisureService($pdo, dirname(__DIR__, 3));
    $service->deleteVisura($visuraId, (int) ($_SESSION['user_id'] ?? 0));
    add_flash('success', 'Visura eliminata correttamente.');
} catch (RuntimeException $exception) {
    add_flash('warning', $exception->getMessage());
} catch (Throwable $exception) {
    error_log('VisureService delete error: ' . $exception->getMessage());
    add_flash('danger', 'Errore durante l\'eliminazione della visura.');
}

header('Location: index.php');
exit;
