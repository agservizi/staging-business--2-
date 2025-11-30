<?php

use App\Services\ServiziWeb\OpenApiCatastoClient;
use App\Services\ServiziWeb\VisureService;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
require_valid_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$requestId = isset($_POST['request_id']) ? trim((string) $_POST['request_id']) : '';
$redirectTarget = 'index.php';
if (isset($_POST['redirect']) && $_POST['redirect'] === 'create') {
    $redirectTarget = 'create.php';
}
$archive = false;
if (isset($_POST['archive'])) {
    $value = (string) $_POST['archive'];
    $archive = in_array($value, ['1', 'true', 'on'], true);
}

if ($requestId === '') {
    add_flash('danger', 'Specifica l\'ID della visura da scaricare.');
    header('Location: ' . $redirectTarget);
    exit;
}

try {
    $client = new OpenApiCatastoClient();
    $service = new VisureService($pdo, dirname(__DIR__, 3));

    $detail = null;
    try {
        $detail = $client->getVisura($requestId);
        $summary = [
            'id' => $detail['id'] ?? $requestId,
            'entita' => $detail['entita'] ?? null,
            'stato' => $detail['stato'] ?? null,
            'timestamp' => $detail['timestamp'] ?? time(),
            'owner' => $detail['owner'] ?? null,
        ];
        $service->persistVisura($requestId, $summary, $detail, (int) ($_SESSION['user_id'] ?? 0));
    } catch (Throwable $detailException) {
        error_log('VisureService detail update failed: ' . $detailException->getMessage());
    }

    $document = $client->downloadVisuraDocument($requestId);

    if ($archive) {
        try {
            $service->storeDocument(
                $requestId,
                $document['content'],
                $document['content_type'] ?? 'application/pdf',
                (int) ($document['content_length'] ?? strlen($document['content'])),
                (int) ($_SESSION['user_id'] ?? 0),
                $detail
            );
        } catch (Throwable $storeException) {
            error_log('VisureService store document failed: ' . $storeException->getMessage());
        }
    }
} catch (Throwable $exception) {
    error_log('Scaricamento visura Catasto fallito: ' . $exception->getMessage());
    add_flash('danger', 'Impossibile scaricare la visura: ' . $exception->getMessage());
    header('Location: ' . $redirectTarget);
    exit;
}

$filename = sanitize_filename('visura_' . $document['request_id'] . '.pdf');
$contentType = $document['content_type'] ?? 'application/pdf';
$contentLength = isset($document['content_length']) ? (int) $document['content_length'] : strlen($document['content']);

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $contentLength);

echo $document['content'];
exit;
