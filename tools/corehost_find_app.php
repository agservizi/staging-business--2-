<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$name = $argv[1] ?? 'dentallab-suite';
$c = new CoreHostClient();
$list = $c->request('GET', '/node-apps');
foreach (($list['body']['data'] ?? []) as $app) {
    if (($app['name'] ?? '') === $name) {
        echo json_encode($c->request('GET', '/node-apps/' . $app['id'])['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit(0);
    }
}
echo "App {$name} not found\n";
exit(1);
