<?php
declare(strict_types=1);

use App\Services\ServiziWeb\TelegrammiService;
use App\Services\ServiziWeb\UfficioPostaleClient;
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

$telegrammaId = isset($_POST['telegramma_id']) ? trim((string) $_POST['telegramma_id']) : '';
$confirmedValue = isset($_POST['confirmed']) ? (string) $_POST['confirmed'] : '1';
$confirmed = in_array(strtolower($confirmedValue), ['1', 'true', 'yes'], true);

if ($telegrammaId === '') {
    add_flash('warning', 'Specifica il telegramma da confermare.');
    header('Location: index.php');
    exit;
}

$tokenValue = env('UFFICIO_POSTALE_TOKEN') ?? env('UFFICIO_POSTALE_SANDBOX_TOKEN') ?? '';
if (trim((string) $tokenValue) === '') {
    add_flash('warning', 'Configura il token Ufficio Postale prima di confermare un invio.');
    header('Location: index.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($userId !== null && $userId <= 0) {
    $userId = null;
}

try {
    $client = new UfficioPostaleClient();
    $service = new TelegrammiService($pdo);

    $response = $client->confirmTelegram($telegrammaId, $confirmed);
    $service->persistFromApi($response, null, $userId, null);

    if ($confirmed) {
        add_flash('success', 'Telegramma ' . $telegrammaId . ' confermato correttamente.');
    } else {
        add_flash('success', 'Telegramma ' . $telegrammaId . ' segnato come non confermato.');
    }

    header('Location: view.php?id=' . urlencode($telegrammaId));
    exit;
} catch (Throwable $exception) {
    error_log('Telegrammi confirm error: ' . $exception->getMessage());
    add_flash('danger', 'Impossibile aggiornare la conferma: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}
