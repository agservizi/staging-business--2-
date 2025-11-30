<?php
declare(strict_types=1);

use App\Services\ServiziWeb\TelegrammiService;
use App\Services\ServiziWeb\UfficioPostaleClient;
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

$tokenValue = env('UFFICIO_POSTALE_TOKEN') ?? env('UFFICIO_POSTALE_SANDBOX_TOKEN') ?? '';
if (trim((string) $tokenValue) === '') {
    add_flash('warning', 'Configura il token Ufficio Postale per poter sincronizzare gli invii.');
    header('Location: index.php');
    exit;
}

$telegrammaId = isset($_POST['telegramma_id']) ? trim((string) $_POST['telegramma_id']) : '';
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($userId !== null && $userId <= 0) {
    $userId = null;
}

try {
    $client = new UfficioPostaleClient();
    $service = new TelegrammiService($pdo);

    if ($telegrammaId !== '') {
        $response = $client->getTelegram($telegrammaId);
        if (!isset($response['data'])) {
            throw new RuntimeException('Il servizio non ha restituito il telegramma richiesto.');
        }
        $records = $service->persistFromApi($response, null, $userId, null);
        $message = count($records) > 0
            ? 'Telegramma ' . $telegrammaId . ' sincronizzato correttamente.'
            : 'Nessun dato aggiornato per il telegramma ' . $telegrammaId . '.';
        add_flash('success', $message);
        header('Location: view.php?id=' . urlencode($telegrammaId));
        exit;
    }

    $response = $client->listTelegram();
    if (!isset($response['data']) || !$response['data']) {
        add_flash('info', 'Nessun telegramma disponibile da sincronizzare in questo momento.');
        header('Location: index.php');
        exit;
    }

    $records = $service->persistFromApi($response, null, $userId, null);
    $count = count($records);

    if ($count === 0) {
        add_flash('info', 'La sincronizzazione non ha prodotto aggiornamenti.');
    } else {
        add_flash('success', 'Sincronizzazione completata: ' . $count . ' telegrammi aggiornati.');
    }

    header('Location: index.php');
    exit;
} catch (Throwable $exception) {
    error_log('Telegrammi sync error: ' . $exception->getMessage());
    add_flash('danger', 'Sincronizzazione fallita: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}
