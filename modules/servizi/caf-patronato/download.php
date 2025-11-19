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

    $rawRelativePath = (string) ($payload['file_path'] ?? '');
    $fileName = (string) ($payload['file_name'] ?? '');
    $mimeType = (string) ($payload['mime_type'] ?? '');
    $fileSize = (int) ($payload['file_size'] ?? 0);
} catch (RuntimeException $exception) {
    abort_download(404, $exception->getMessage());
}

$practicePathVariants = caf_patronato_initial_path_variants($rawRelativePath);
try {
    $practiceStoragePath = $service->storagePathForPractice($practiceId);
} catch (Throwable $exception) {
    $practiceStoragePath = '';
}

$practiceStoragePath = $practiceStoragePath !== '' ? str_replace('\\', '/', rtrim($practiceStoragePath, '\\/')) : '';
$pathQueue = $practicePathVariants;
$processedVariants = [];
$candidates = [];

while ($pathQueue) {
    $variant = array_shift($pathQueue);
    if (!is_string($variant)) {
        continue;
    }

    $normalizedVariant = str_replace('\\', '/', trim($variant));
    while (str_starts_with($normalizedVariant, './')) {
        $normalizedVariant = substr($normalizedVariant, 2);
    }
    while (str_starts_with($normalizedVariant, '../')) {
        $normalizedVariant = substr($normalizedVariant, 3);
    }
    $normalizedVariant = ltrim($normalizedVariant, '/');

    if ($normalizedVariant === '' || isset($processedVariants[$normalizedVariant])) {
        continue;
    }

    $processedVariants[$normalizedVariant] = true;

    $absoluteCandidate = caf_patronato_absolute_path($projectRoot, $normalizedVariant);
    $candidates[] = $absoluteCandidate;

    $documentBasename = basename($normalizedVariant);
    if ($practiceStoragePath !== '' && $documentBasename !== '') {
        $storageCandidate = $practiceStoragePath . '/' . $documentBasename;
        $candidates[] = $storageCandidate;
    }

    $publicCandidate = public_path($normalizedVariant);
    if ($publicCandidate !== '') {
        $candidates[] = $publicCandidate;
    }

    if (str_starts_with($normalizedVariant, CAF_PATRONATO_UPLOAD_DIR . '/')) {
        $apiVariant = 'api/' . $normalizedVariant;
        $candidates[] = caf_patronato_absolute_path($projectRoot, $apiVariant);
        $publicApiCandidate = public_path($apiVariant);
        if ($publicApiCandidate !== '') {
            $candidates[] = $publicApiCandidate;
        }
    }

    if (!str_starts_with($normalizedVariant, CAF_PATRONATO_UPLOAD_DIR . '/')) {
        $uploadNeedle = CAF_PATRONATO_UPLOAD_DIR . '/';
        $needlePos = strpos($normalizedVariant, $uploadNeedle);
        if ($needlePos > 0) {
            $suffixVariant = substr($normalizedVariant, $needlePos);
            if ($suffixVariant !== false && $suffixVariant !== '') {
                $pathQueue[] = $suffixVariant;
            }
        }

        if (str_starts_with($normalizedVariant, 'caf-patronato/')) {
            $prefixedVariant = CAF_PATRONATO_UPLOAD_DIR . '/' . substr($normalizedVariant, strlen('caf-patronato/'));
            if ($prefixedVariant !== '' && !isset($processedVariants[$prefixedVariant])) {
                $pathQueue[] = $prefixedVariant;
            }
        } else {
            $cafNeedle = 'caf-patronato/';
            $cafPos = strpos($normalizedVariant, $cafNeedle);
            if ($cafPos > 0) {
                $suffix = substr($normalizedVariant, $cafPos);
                if ($suffix !== false && $suffix !== '') {
                    $pathQueue[] = $suffix;
                }
            }
        }
    }

    if (str_ends_with($normalizedVariant, CAF_PATRONATO_ENCRYPTION_SUFFIX)) {
        $withoutSuffix = substr($normalizedVariant, 0, -strlen(CAF_PATRONATO_ENCRYPTION_SUFFIX));
        if ($withoutSuffix !== false && $withoutSuffix !== '' && !isset($processedVariants[$withoutSuffix])) {
            $pathQueue[] = $withoutSuffix;
        }

        if (str_starts_with($normalizedVariant, CAF_PATRONATO_UPLOAD_DIR . '/')) {
            $plainApiVariant = 'api/' . $withoutSuffix;
            $candidates[] = caf_patronato_absolute_path($projectRoot, $plainApiVariant);
            $publicPlainApiCandidate = public_path($plainApiVariant);
            if ($publicPlainApiCandidate !== '') {
                $candidates[] = $publicPlainApiCandidate;
            }
        }
    }
}

$candidates = array_values(array_unique(array_filter($candidates, static fn($path) => is_string($path) && $path !== '')));

$absolutePath = '';
foreach ($candidates as $candidate) {
    if ($candidate !== '' && is_file($candidate)) {
        $absolutePath = $candidate;
        break;
    }
}

if ($absolutePath === '' || !is_file($absolutePath)) {
    abort_download(404, 'Allegato non trovato.');
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

/**
 * @return array<int,string>
 */
function caf_patronato_initial_path_variants(string $rawPath): array
{
    $trimmed = trim($rawPath);
    if ($trimmed === '') {
        return [];
    }

    $normalized = str_replace('\\', '/', $trimmed);
    $variants = [$normalized];

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $normalized) === 1) {
        $urlPath = parse_url($normalized, PHP_URL_PATH);
        if (is_string($urlPath) && $urlPath !== '') {
            $variants[] = $urlPath;
        }
    }

    $processed = [];
    foreach ($variants as $variant) {
        if (!is_string($variant) || $variant === '') {
            continue;
        }

        $candidate = str_replace('\\', '/', trim($variant));
        $cutPos = strcspn($candidate, '?#');
        if ($cutPos < strlen($candidate)) {
            $candidate = substr($candidate, 0, $cutPos);
        }

        while (str_starts_with($candidate, './')) {
            $candidate = substr($candidate, 2);
        }
        while (str_starts_with($candidate, '../')) {
            $candidate = substr($candidate, 3);
        }

        $candidate = ltrim($candidate, '/');
        if ($candidate !== '') {
            $processed[] = $candidate;
        }
    }

    return array_values(array_unique($processed));
}

