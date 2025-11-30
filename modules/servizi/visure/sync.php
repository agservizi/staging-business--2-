<?php

use App\Services\ServiziWeb\OpenApiCatastoClient;
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

if (trim((string) (env('OPENAPI_CATASTO_API_KEY') ?? env('OPENAPI_SANDBOX_API_KEY') ?? '')) === ''
    || trim((string) (env('OPENAPI_CATASTO_TOKEN') ?? env('OPENAPI_CATASTO_SANDBOX_TOKEN') ?? '')) === '') {
    add_flash('warning', 'Configura le credenziali OpenAPI Catasto prima di avviare la sincronizzazione.');
    header('Location: index.php');
    exit;
}

try {
    $service = new VisureService($pdo, dirname(__DIR__, 3));
    $client = new OpenApiCatastoClient();

    $autoDownload = isset($_POST['auto_download']) && (string) $_POST['auto_download'] === '1';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $result = $service->sync($client, $userId, $autoDownload);

    $parts = [];
    if ($result['created'] > 0) {
        $parts[] = $result['created'] . ' nuove';
    }
    if ($result['updated'] > 0) {
        $parts[] = $result['updated'] . ' aggiornate';
    }
    if ($result['details'] > 0) {
        $parts[] = $result['details'] . ' dettagli aggiornati';
    }
    if ($result['downloads'] > 0) {
        $parts[] = $result['downloads'] . ' documenti scaricati';
    }

    $message = 'Sincronizzazione completata.';
    if ($parts) {
        $message .= ' (' . implode(', ', $parts) . ')';
    }

    add_flash('success', $message);

    if ($result['errors']) {
        $excerpt = array_slice($result['errors'], 0, 3);
        add_flash('warning', 'Sono stati riscontrati alcuni problemi: ' . implode(' | ', $excerpt));
    }
} catch (Throwable $exception) {
    error_log('VisureService sync error: ' . $exception->getMessage());
    add_flash('danger', 'Errore durante la sincronizzazione: ' . $exception->getMessage());
}

header('Location: index.php');
exit;
