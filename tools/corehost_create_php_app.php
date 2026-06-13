<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$payload = [
    'name' => 'coresuite-business',
    'runtime' => 'PHP',
    'repository' => (string) env('COREHOST_GIT_REPO'),
    'branch' => (string) env('COREHOST_GIT_BRANCH', 'production'),
    'startCmd' => 'php -S 0.0.0.0:${PORT} -t .',
    'installCmd' => 'composer install --no-dev --no-interaction || true',
    'healthPath' => '/',
    'autoDeploy' => true,
];
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
$r = $c->request('POST', '/node-apps', $payload);
echo 'HTTP ' . $r['status'] . "\n";
echo json_encode($r['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";

if (($r['body']['data']['id'] ?? null) && $r['status'] < 300) {
    $appId = $r['body']['data']['id'];
    echo "\nDeploy app...\n";
    try {
        $d = $c->request('POST', "/node-apps/{$appId}/deploy");
        echo 'deploy HTTP ' . $d['status'] . "\n";
        echo substr(json_encode($d['body']), 0, 500) . "\n";
    } catch (Throwable $e) {
        echo 'deploy ERR: ' . $e->getMessage() . "\n";
    }
}
