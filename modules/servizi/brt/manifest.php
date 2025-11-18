<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use Throwable;

define('CORESUITE_BRT_BOOTSTRAP', true);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

try {
    ensure_brt_tables();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('Database BRT non configurato: ' . $exception->getMessage());
}

$manifestId = (int) ($_GET['id'] ?? 0);
if ($manifestId <= 0) {
    http_response_code(400);
    exit('ID borderò non valido.');
}

$manifest = brt_get_manifest($manifestId);
if ($manifest === null) {
    http_response_code(404);
    exit('Borderò non trovato.');
}

try {
    $config = new BrtConfig();
} catch (Throwable $exception) {
    http_response_code(500);
    exit('Configurazione BRT non valida: ' . $exception->getMessage());
}

$paths = brt_ensure_manifest_pdf($manifest, $config);
if ($paths === null || empty($paths['absolute_path']) || !is_file($paths['absolute_path'])) {
    http_response_code(404);
    exit('Borderò non disponibile.');
}

$absolutePath = $paths['absolute_path'];
$relativePath = $paths['relative_path'] ?? '';
$filename = $relativePath !== '' ? basename(str_replace('\\', '/', $relativePath)) : sprintf('bordero_brt_%d.pdf', $manifestId);
$filesize = @filesize($absolutePath);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
if ($filesize !== false) {
    header('Content-Length: ' . $filesize);
}
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$handle = fopen($absolutePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Impossibile aprire il file del borderò.');
}

fpassthru($handle);
fclose($handle);
exit;
