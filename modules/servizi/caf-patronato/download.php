<?php
declare(strict_types=1);

use App\Services\CAFPatronato\PracticesService;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Patronato');

$documentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$source = isset($_GET['source']) ? strtolower((string) $_GET['source']) : 'document';

if ($documentId <= 0) {
    abort_download(400, 'Identificativo documento non valido.');
}

try {
    $projectRoot = project_root_path();
    $service = new PracticesService($pdo, $projectRoot);
} catch (Throwable $exception) {
    error_log('CAF/Patronato download bootstrap error: ' . $exception->getMessage());
    abort_download(500, 'Modulo non disponibile.');
}

$hasServiceManageCapability = current_user_has_capability('services.manage');
$isPatronatoUser = current_user_can('Patronato');
$role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'Operatore';
$canCreatePractices = in_array($role, ['Admin', 'Manager', 'Operatore'], true);
$canManagePractices = $isPatronatoUser || $canCreatePractices;
$canConfigure = $hasServiceManageCapability;
if (!$canManagePractices && !$canConfigure) {
    abort_download(403, 'Non hai i permessi per scaricare questo allegato.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$operatorId = null;
try {
    $operatorId = $service->findOperatorIdByUser($userId);
} catch (Throwable) {
    $operatorId = null;
}
$canViewAll = $canManagePractices || $canConfigure;

try {
    $payload = fetch_practice_document($pdo, $documentId);
    $practiceId = (int) ($payload['pratica_id'] ?? 0);
    if ($practiceId <= 0) {
        abort_download(404, 'Documento non associato a una pratica valida.');
    }

    try {
        $service->getPractice($practiceId, $canViewAll, $operatorId);
    } catch (RuntimeException $exception) {
        abort_download(403, $exception->getMessage());
    }

    $relativePath = (string) ($payload['file_path'] ?? '');
    $fileName = (string) ($payload['file_name'] ?? '');
    $mimeType = (string) ($payload['mime_type'] ?? '');
    $fileSize = (int) ($payload['file_size'] ?? 0);
} catch (RuntimeException $exception) {
    abort_download(404, $exception->getMessage());
}

$absolutePath = caf_patronato_absolute_path($projectRoot, $relativePath);
if (!is_file($absolutePath)) {
    $candidates = [];

    $publicCandidate = public_path($relativePath);
    if ($publicCandidate !== '' && $publicCandidate !== $absolutePath) {
        $candidates[] = $publicCandidate;
    }

    if (str_starts_with($relativePath, CAF_PATRONATO_UPLOAD_DIR . '/')) {
        $apiCandidate = caf_patronato_absolute_path($projectRoot, 'api/' . $relativePath);
        if ($apiCandidate !== $absolutePath) {
            $candidates[] = $apiCandidate;
        }

        $publicApiCandidate = public_path('api/' . $relativePath);
        if ($publicApiCandidate !== '' && $publicApiCandidate !== $absolutePath) {
            $candidates[] = $publicApiCandidate;
        }
    }

    if (str_ends_with($relativePath, CAF_PATRONATO_ENCRYPTION_SUFFIX)) {
        $withoutSuffix = substr($relativePath, 0, -strlen(CAF_PATRONATO_ENCRYPTION_SUFFIX));
        $plainCandidate = caf_patronato_absolute_path($projectRoot, $withoutSuffix);
        if ($plainCandidate !== $absolutePath) {
            $candidates[] = $plainCandidate;
        }

        $publicPlainCandidate = public_path($withoutSuffix);
        if ($publicPlainCandidate !== '' && $publicPlainCandidate !== $absolutePath) {
            $candidates[] = $publicPlainCandidate;
        }

        if (str_starts_with($relativePath, CAF_PATRONATO_UPLOAD_DIR . '/')) {
            $apiPlainCandidate = caf_patronato_absolute_path($projectRoot, 'api/' . $withoutSuffix);
            if ($apiPlainCandidate !== $absolutePath) {
                $candidates[] = $apiPlainCandidate;
            }

            $publicApiPlainCandidate = public_path('api/' . $withoutSuffix);
            if ($publicApiPlainCandidate !== '' && $publicApiPlainCandidate !== $absolutePath) {
                $candidates[] = $publicApiPlainCandidate;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            $absolutePath = $candidate;
            break;
        }
    }

    if (!is_file($absolutePath)) {
        abort_download(404, 'Allegato non trovato.');
    }
}

try {
    $contents = caf_patronato_decrypt_file($absolutePath);
} catch (Throwable $exception) {
    error_log('CAF/Patronato decrypt error: ' . $exception->getMessage());
    abort_download(500, 'Impossibile decifrare l\'allegato.');
}

$downloadName = sanitize_filename($fileName ?: 'allegato');
$mime = $mimeType !== '' ? $mimeType : 'application/octet-stream';
$length = strlen($contents);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string) $length);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: no-cache');

echo $contents;
exit;

function abort_download(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $message;
    exit;
}

function fetch_practice_document(PDO $pdo, int $documentId): array
{
    $stmt = $pdo->prepare('SELECT * FROM pratiche_documenti WHERE id = :id LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Documento non disponibile.');
    }
    $stmt->execute([':id' => $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Documento non trovato.');
    }

    return $row;
}

function caf_patronato_absolute_path(string $projectRoot, string $relativePath): string
{
    $cleanRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $cleanRelative = ltrim(str_replace('\\', '/', $relativePath), '/');

    return $cleanRoot . '/' . $cleanRelative;
}

