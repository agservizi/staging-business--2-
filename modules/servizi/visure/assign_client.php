<?php

use App\Services\ServiziWeb\VisureService;
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
$clientValue = isset($_POST['cliente_id']) ? trim((string) $_POST['cliente_id']) : '';

if ($visuraId === '') {
    add_flash('warning', 'Identificativo visura mancante.');
    header('Location: index.php');
    exit;
}

try {
    $service = new VisureService($pdo, dirname(__DIR__, 3));
    $clientId = $clientValue === '' ? null : (int) $clientValue;
    $service->assignClient($visuraId, $clientId, (int) ($_SESSION['user_id'] ?? 0));

    if ($clientId === null) {
        add_flash('success', 'Associazione cliente rimossa con successo.');
    } else {
        add_flash('success', 'Cliente #' . $clientId . ' associato alla visura.');
    }
} catch (Throwable $exception) {
    error_log('VisureService assign error: ' . $exception->getMessage());
    add_flash('danger', 'Impossibile aggiornare l\'associazione: ' . $exception->getMessage());
}

header('Location: view.php?id=' . urlencode($visuraId));
exit;
