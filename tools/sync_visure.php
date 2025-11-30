<?php
declare(strict_types=1);

use App\Services\ServiziWeb\OpenApiCatastoClient;
use App\Services\ServiziWeb\VisureService;
use Throwable;

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$projectRoot = __DIR__ . '/..';

try {
    $service = new VisureService($pdo, $projectRoot);
    $client = new OpenApiCatastoClient();

    $result = $service->sync($client, 0, true);

    $output = [
        'created' => $result['created'],
        'updated' => $result['updated'],
        'details' => $result['details'],
        'downloads' => $result['downloads'],
        'errors' => count($result['errors']),
    ];

    echo '[' . date('c') . "] Sincronizzazione completata:\n";
    foreach ($output as $key => $value) {
        echo ' - ' . ucfirst($key) . ': ' . $value . PHP_EOL;
    }

    if ($result['errors']) {
        echo "\nDettagli errori:\n";
        foreach ($result['errors'] as $error) {
            echo ' * ' . $error . PHP_EOL;
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date('c') . '] Errore: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
