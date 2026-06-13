#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/corehost_client.php';

$id = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$client = new CoreHostClient();

echo "Trigger restart (short timeout, fire-and-forget)...\n";
try {
    $client->request('POST', "/websites/{$id}/restart", null);
} catch (Throwable $e) {
    echo 'restart trigger: ' . $e->getMessage() . "\n";
}

for ($i = 1; $i <= 12; $i++) {
    sleep(15);
    $w = $client->request('GET', "/websites/{$id}");
    $status = (string) ($w['body']['data']['status'] ?? '?');
    $container = (string) ($w['body']['data']['containerId'] ?? 'null');
    echo "[{$i}/12] status={$status} container=" . ($container !== '' ? substr($container, 0, 12) : 'null') . "\n";
    if ($status === 'RUNNING' && $container !== '' && $container !== 'null') {
        echo "Container avviato.\n";
        exit(0);
    }
}

echo "Container non avviato entro 3 minuti. Server probabilmente sotto carico.\n";
exit(1);
