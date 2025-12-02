<?php
declare(strict_types=1);

use App\Services\Certi\CertiRequestRepository;
use App\Services\Certi\CertiWorkflowService;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = [];
if ($method !== 'GET') {
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $body = json_decode($raw, true) ?: [];
        }
    } else {
        $body = $_POST;
    }
}

$action = $method === 'GET' ? ($_GET['action'] ?? 'list') : ($body['action'] ?? ($_GET['action'] ?? ''));
$action = (string) $action;
if ($action === '') {
    respondWithError('Azione non specificata', 400);
}

require_role('Admin', 'Manager', 'Operatore');

$repository = new CertiRequestRepository($pdo);
$workflow = new CertiWorkflowService($pdo, ['repository' => $repository]);
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    switch ($action) {
        case 'list':
            $result = $repository->listRequests($_GET);
            respondWithData($result);
            break;
        case 'create':
            $request = $workflow->createRequest($body, $userId);
            respondWithData($request, 201);
            break;
        case 'update':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                respondWithError('ID richiesta mancante', 400);
            }
            $request = $workflow->updateRequest($id, $body, $userId);
            respondWithData($request);
            break;
        case 'assign':
            $id = (int) ($body['id'] ?? 0);
            $operatorId = (int) ($body['operator_id'] ?? 0);
            if ($id <= 0 || $operatorId <= 0) {
                respondWithError('Parametri assegnazione mancanti', 400);
            }
            $request = $workflow->assignOperator($id, $operatorId, $userId);
            respondWithData($request);
            break;
        case 'status':
            $id = (int) ($body['id'] ?? 0);
            $status = (string) ($body['status'] ?? '');
            if ($id <= 0 || $status === '') {
                respondWithError('Parametri stato mancanti', 400);
            }
            $request = $workflow->updateStatus($id, $status, $userId);
            respondWithData($request);
            break;
        case 'submit':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                respondWithError('ID richiesta mancante', 400);
            }
            $request = $workflow->submitToProvider($id, $body['payload'] ?? [], $userId);
            respondWithData($request);
            break;
        case 'fetch_document':
            $id = (int) ($body['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) {
                respondWithError('ID richiesta mancante', 400);
            }
            $request = $workflow->fetchProviderDocument($id, $userId);
            respondWithData($request);
            break;
        case 'upload_certificate':
            $id = (int) ($body['id'] ?? ($_POST['id'] ?? 0));
            if ($id <= 0) {
                respondWithError('ID richiesta mancante', 400);
            }
            if (empty($_FILES['file'])) {
                respondWithError('File certificato mancante', 400);
            }
            $request = $workflow->storeUploadedCertificate($id, $_FILES['file'], $userId);
            respondWithData($request);
            break;
        case 'get_certificate':
            $id = (int) ($body['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) {
                respondWithError('ID richiesta mancante', 400);
            }
            $file = $workflow->getCertificateFile($id);
            $contents = file_get_contents($file['path']);
            if ($contents === false) {
                respondWithError('Impossibile leggere il certificato.', 500);
            }
            respondWithData([
                'name' => $file['name'],
                'content' => base64_encode($contents),
                'content_type' => 'application/pdf',
            ]);
            break;
        default:
            respondWithError('Azione non supportata', 400);
    }
} catch (Throwable $exception) {
    respondWithError($exception->getMessage(), 422);
}
