<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

use App\Services\Automata\AutomataService;

$service = new AutomataService();
echo 'Enabled: ' . ($service->isEnabled() ? 'yes' : 'no') . PHP_EOL;

try {
    $items = $service->suggestCafDocumentChecklist('CAF', 'ISEE 2026', []);
    echo 'Checklist items: ' . count($items) . PHP_EOL;
    foreach (array_slice($items, 0, 3) as $item) {
        echo ' - ' . ($item['label'] ?? '') . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
