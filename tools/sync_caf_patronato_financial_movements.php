<?php
declare(strict_types=1);

if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'CLI';
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

use App\Services\CAFPatronato\PracticesService;

$rootPath = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__);
$service = new PracticesService($pdo, $rootPath);

$limit = null;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $limit = (int) $argv[1];
    if ($limit <= 0) {
        $limit = null;
    }
}

try {
    $result = $service->syncMissingFinancialMovements($limit);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Errore durante la sincronizzazione: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Pratiche analizzate: ' . $result['scanned'] . PHP_EOL;
echo 'Movimenti creati: ' . $result['created'] . PHP_EOL;
echo 'Movimenti ancora mancanti: ' . $result['remaining'] . PHP_EOL;
