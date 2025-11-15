<?php
declare(strict_types=1);

use App\Services\ServiziWeb\TelegrammiService;
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

$telegrammaPk = isset($_POST['telegramma_pk']) ? (int) $_POST['telegramma_pk'] : 0;
$clienteId = isset($_POST['cliente_id']) && $_POST['cliente_id'] !== '' ? (int) $_POST['cliente_id'] : null;

if ($telegrammaPk <= 0) {
    add_flash('warning', 'Telegramma non valido.');
    header('Location: index.php');
    exit;
}

if ($clienteId !== null && $clienteId <= 0) {
    $clienteId = null;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($userId !== null && $userId <= 0) {
    $userId = null;
}

$service = null;
try {
    $service = new TelegrammiService($pdo);
    $service->attachCliente($telegrammaPk, $clienteId, $userId);
    add_flash('success', $clienteId === null ? 'Associazione cliente rimossa.' : 'Cliente associato correttamente.');
} catch (Throwable $exception) {
    add_flash('danger', 'Impossibile aggiornare l\'associazione: ' . $exception->getMessage());
}

$redirectId = isset($_POST['redirect_id']) ? trim((string) $_POST['redirect_id']) : '';
if ($redirectId === '') {
    $record = null;
    try {
        $service = $service ?? new TelegrammiService($pdo);
        $record = $service->find($telegrammaPk);
    } catch (Throwable $ignored) {
        $record = null;
    }
    $redirectId = $record['telegramma_id'] ?? '';
}

if ($redirectId !== '') {
    header('Location: view.php?id=' . urlencode((string) $redirectId));
    exit;
}

header('Location: index.php');
exit;
