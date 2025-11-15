<?php
declare(strict_types=1);

use App\Services\CAFPatronato\PracticesService;
use JsonException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../modules/servizi/caf-patronato/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$hasServiceManageCapability = current_user_has_capability('services.manage');
$isPatronatoUser = current_user_can('Patronato');
$role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'Operatore';
$canCreatePractices = in_array($role, ['Admin', 'Manager', 'Operatore'], true);

if (!$hasServiceManageCapability && !$isPatronatoUser && !$canCreatePractices) {
    http_response_code(403);
    echo json_encode(['error' => 'Non hai i permessi per accedere al modulo CAF/Patronato.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$service = new PracticesService($pdo, project_root_path());
$userId = (int) ($_SESSION['user_id'] ?? 0);
$canConfigureModule = $hasServiceManageCapability;
$canManagePractices = $isPatronatoUser || $canCreatePractices;
$operatorId = $service->findOperatorIdByUser($userId);
$canViewAll = $canManagePractices || $canConfigureModule;
$isAdminContext = $canConfigureModule;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$bodyParams = [];

if ($method !== 'GET') {
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $bodyParams = $decoded;
                }
            } catch (JsonException $exception) {
                respondWithError('Payload JSON non valido: ' . $exception->getMessage(), 400);
            }
        }
    } else {
        $bodyParams = $_POST;
    }
}

$action = '';
if ($method === 'GET') {
    $action = isset($_GET['action']) ? (string) $_GET['action'] : 'list_practices';
} elseif (isset($bodyParams['action'])) {
    $action = (string) $bodyParams['action'];
} elseif (isset($_GET['action'])) {
    $action = (string) $_GET['action'];
}

$action = trim($action);
if ($action === '') {
    respondWithError('Azione non specificata.', 400);
}

try {
    switch ($action) {
        case 'list_practices':
            $filters = collectFilters($_GET);
            $result = $service->listPractices($filters, $operatorId, $canViewAll);
            respondWithData($result);
            break;

        case 'get_practice':
            $practiceId = (int) ($_GET['id'] ?? ($bodyParams['id'] ?? 0));
            if ($practiceId <= 0) {
                respondWithError('ID pratica mancante.', 400);
            }
            $practice = $service->getPractice($practiceId, $canViewAll, $operatorId);
            respondWithData($practice);
            break;

        case 'create_practice':
            if (!$canCreatePractices) {
                respondWithError('Solo gli utenti autorizzati possono creare pratiche.', 403);
            }
            $practice = $service->createPractice($bodyParams, $userId);
            respondWithData($practice, 201);
            break;

        case 'update_practice':
            $practiceId = (int) ($bodyParams['id'] ?? 0);
            if ($practiceId <= 0) {
                respondWithError('ID pratica mancante.', 400);
            }
            if (!$canManagePractices) {
                respondWithError('Solo gli operatori Patronato possono aggiornare le pratiche.', 403);
            }
            $practice = $service->updatePractice($practiceId, $bodyParams, $userId, $canManagePractices, $operatorId);
            respondWithData($practice);
            break;

        case 'update_status':
            $practiceId = (int) ($bodyParams['id'] ?? 0);
            $statusCode = (string) ($bodyParams['status'] ?? '');
            if ($practiceId <= 0 || $statusCode === '') {
                respondWithError('Parametri mancanti per l\'aggiornamento dello stato.', 400);
            }
            if (!$canManagePractices) {
                respondWithError('Solo gli operatori Patronato possono aggiornare lo stato delle pratiche.', 403);
            }
            $authorRole = $isPatronatoUser ? 'patronato' : strtolower($role);
            $practice = $service->updateStatus($practiceId, $statusCode, $userId, $operatorId, $canManagePractices, $authorRole, true);
            respondWithData($practice);
            break;

        case 'resend_customer_mail':
            if (!$canCreatePractices) {
                respondWithError('Solo gli operatori autorizzati possono reinviare la mail al cliente.', 403);
            }
            $practiceId = (int) ($bodyParams['id'] ?? 0);
            if ($practiceId <= 0) {
                respondWithError('ID pratica non valido.', 400);
            }
            $recipient = isset($bodyParams['recipient']) ? trim((string) $bodyParams['recipient']) : null;
            if ($recipient === '') {
                $recipient = null;
            }
            $sent = $service->sendCustomerConfirmationMail($practiceId, $userId, $recipient);
            if (!$sent) {
                respondWithError('Impossibile inviare l\'email al cliente. Verificare la configurazione.', 422);
            }
            respondWithData(['sent' => true]);
            break;

        case 'add_tracking_step':
            $practiceId = (int) ($bodyParams['id'] ?? 0);
            $description = (string) ($bodyParams['description'] ?? '');
            $isPublic = !empty($bodyParams['public']);
            if ($practiceId <= 0) {
                respondWithError('ID pratica non valido.', 400);
            }
            if (trim($description) === '') {
                respondWithError('La descrizione del passaggio è obbligatoria.', 400);
            }
            if (!$canManagePractices) {
                respondWithError('Solo gli operatori autorizzati possono aggiornare la timeline.', 403);
            }
            $authorRole = $isPatronatoUser ? 'patronato' : strtolower($role);
            $steps = $service->addTrackingStep($practiceId, $description, $userId, $operatorId, $canManagePractices, $authorRole, $isPublic);
            respondWithData(['steps' => $steps]);
            break;

        case 'add_note':
            $practiceId = (int) ($bodyParams['id'] ?? 0);
            $content = (string) ($bodyParams['content'] ?? '');
            $visibleAdmin = !empty($bodyParams['visible_admin']);
            $visibleOperator = !empty($bodyParams['visible_operator']);
            if ($practiceId <= 0) {
                respondWithError('ID pratica non valido.', 400);
            }
            if (!$canManagePractices) {
                respondWithError('Solo gli operatori Patronato possono aggiungere note.', 403);
            }
            $notes = $service->addNote($practiceId, $content, $userId, $operatorId, $visibleAdmin, $visibleOperator);
            respondWithData(['notes' => $notes]);
            break;

        case 'delete_note':
            $practiceId = (int) ($bodyParams['practice_id'] ?? 0);
            $noteId = (int) ($bodyParams['note_id'] ?? 0);
            if ($practiceId <= 0 || $noteId <= 0) {
                respondWithError('Parametri nota non validi.', 400);
            }
            if (!$canManagePractices) {
                respondWithError('Solo gli operatori Patronato possono eliminare note.', 403);
            }
            $service->deleteNote($noteId, $practiceId, $canManagePractices, $operatorId);
            respondWithData(['deleted' => true]);
            break;

        case 'add_document':
            $practiceId = (int) ($_POST['id'] ?? 0);
            if ($practiceId <= 0) {
                respondWithError('ID pratica non valido per l\'upload.', 400);
            }
            if (!isset($_FILES['document'])) {
                respondWithError('Nessun file allegato.', 400);
            }
            if (!$canManagePractices) {
                respondWithError('Solo gli operatori Patronato possono caricare documenti.', 403);
            }
            $documents = $service->addDocument($practiceId, $_FILES['document'], $userId, $operatorId);
            respondWithData(['documents' => $documents]);
            break;

        case 'delete_document':
            $practiceId = (int) ($bodyParams['practice_id'] ?? 0);
            $documentId = (int) ($bodyParams['document_id'] ?? 0);
            if ($practiceId <= 0 || $documentId <= 0) {
                respondWithError('Parametri documento non validi.', 400);
            }
            if (!$canManagePractices) {
                respondWithError('Solo gli operatori Patronato possono eliminare documenti.', 403);
            }
            $service->deleteDocument($documentId, $practiceId, $canManagePractices, $operatorId);
            respondWithData(['deleted' => true]);
            break;

        case 'list_types':
            $categoria = isset($_GET['categoria']) ? (string) $_GET['categoria'] : null;
            $types = $service->listTypes($categoria !== '' ? $categoria : null);
            respondWithData(['types' => $types]);
            break;

        case 'create_type':
            requireAdmin($isAdminContext);
            $type = $service->createType($bodyParams);
            respondWithData($type, 201);
            break;

        case 'update_type':
            requireAdmin($isAdminContext);
            $typeId = (int) ($bodyParams['id'] ?? 0);
            if ($typeId <= 0) {
                respondWithError('ID tipologia non valido.', 400);
            }
            $type = $service->updateType($typeId, $bodyParams);
            respondWithData($type);
            break;

        case 'delete_type':
            requireAdmin($isAdminContext);
            $typeId = (int) ($bodyParams['id'] ?? 0);
            if ($typeId <= 0) {
                respondWithError('ID tipologia non valido.', 400);
            }
            $service->deleteType($typeId);
            respondWithData(['deleted' => true]);
            break;

        case 'list_statuses':
            $statuses = $service->listStatuses();
            respondWithData(['statuses' => $statuses]);
            break;

        case 'create_status':
            requireAdmin($isAdminContext);
            $status = $service->createStatus($bodyParams);
            respondWithData($status, 201);
            break;

        case 'update_status_definition':
            requireAdmin($isAdminContext);
            $statusId = (int) ($bodyParams['id'] ?? 0);
            if ($statusId <= 0) {
                respondWithError('ID stato non valido.', 400);
            }
            $status = $service->updateStatusDefinition($statusId, $bodyParams);
            respondWithData($status);
            break;

        case 'delete_status_definition':
            requireAdmin($isAdminContext);
            $statusId = (int) ($bodyParams['id'] ?? 0);
            if ($statusId <= 0) {
                respondWithError('ID stato non valido.', 400);
            }
            $service->deleteStatus($statusId);
            respondWithData(['deleted' => true]);
            break;

        case 'list_operators':
            if (!$canConfigureModule && !$canManagePractices) {
                respondWithError('Operazione non autorizzata.', 403);
            }
            $categoria = isset($_GET['categoria']) ? (string) $_GET['categoria'] : null;
            $onlyActive = isset($_GET['only_active']) ? (bool) $_GET['only_active'] : true;
            if (!$canConfigureModule) {
                $categoria = 'PATRONATO';
                $onlyActive = true;
            }
            $operators = $service->listOperators($categoria !== '' ? $categoria : null, $onlyActive);
            respondWithData(['operators' => $operators]);
            break;

        case 'save_operator':
            requireAdmin($isAdminContext);
            $operatorIdParam = isset($bodyParams['id']) ? (int) $bodyParams['id'] : null;
            if ($operatorIdParam !== null && $operatorIdParam <= 0) {
                $operatorIdParam = null;
            }
            $operator = $service->saveOperator($operatorIdParam, $bodyParams);
            respondWithData($operator, $operatorIdParam === null ? 201 : 200);
            break;

        case 'toggle_operator':
            requireAdmin($isAdminContext);
            $operatorIdParam = (int) ($bodyParams['id'] ?? 0);
            $enable = !empty($bodyParams['enable']);
            if ($operatorIdParam <= 0) {
                respondWithError('ID operatore non valido.', 400);
            }
            $service->toggleOperator($operatorIdParam, $enable);
            respondWithData(['updated' => true]);
            break;

        case 'list_notifications':
            $showRead = !empty($_GET['show_read']);
            $notifications = $service->listNotifications($userId, $operatorId, $showRead);
            respondWithData(['notifications' => $notifications]);
            break;

        case 'mark_notification':
            $notificationId = (int) ($bodyParams['id'] ?? 0);
            if ($notificationId <= 0) {
                respondWithError('ID notifica non valido.', 400);
            }
            $service->markNotificationRead($notificationId, $userId, $operatorId);
            respondWithData(['updated' => true]);
            break;

        default:
            respondWithError('Azione non riconosciuta: ' . $action, 404);
    }
} catch (RuntimeException $exception) {
    respondWithError($exception->getMessage(), 400);
} catch (Throwable $exception) {
    error_log('CAF/Patronato API error: ' . $exception->getMessage());
    respondWithError('Errore interno al server.', 500);
}

function respondWithData($data, int $status = 200): void
{
    http_response_code($status);
    try {
        echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        http_response_code(500);
        $safeMessage = addslashes($exception->getMessage());
        echo '{"error":"Errore di serializzazione: ' . $safeMessage . '"}';
    }
    exit;
}

function respondWithError(string $message, int $status = 400): void
{
    http_response_code($status);
    try {
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        echo '{"error":"' . addslashes($message) . '"}';
    }
    exit;
}

/**
 * @param array<string,mixed> $query
 * @return array<string,mixed>
 */
function collectFilters(array $query): array
{
    $filters = [];
    $allowed = ['categoria', 'stato', 'tipo_pratica', 'operatore', 'cliente_id', 'search', 'dal', 'al', 'page', 'per_page', 'order', 'assegnata', 'tracking_code'];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $query)) {
            continue;
        }
        $value = $query[$key];
        if ($value === '' || $value === null) {
            continue;
        }
        $filters[$key] = is_string($value) ? trim($value) : $value;
    }

    return $filters;
}

function requireAdmin(bool $isAdminContext): void
{
    if ($isAdminContext) {
        return;
    }

    respondWithError('Solo gli amministratori possono eseguire questa operazione.', 403);
}
