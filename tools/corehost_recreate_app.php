<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$oldId = 'cmqbe9lbd07dm6ht4wonxnkk1';

echo "Delete old app...\n";
try {
    $d = $c->request('DELETE', "/node-apps/{$oldId}");
    echo "DELETE HTTP {$d['status']}\n";
} catch (Throwable $e) {
    echo 'DELETE ERR: ' . $e->getMessage() . "\n";
}

$payload = [
    'name' => 'coresuite-business',
    'runtime' => 'PHP',
    'repository' => 'git@github.com:agservizi/staging-business--2-.git',
    'branch' => 'production',
    'installCmd' => 'composer install --no-dev --no-interaction',
    'startCmd' => 'php -S 0.0.0.0:8080 -t .',
    'healthPath' => '/',
    'autoDeploy' => true,
    'memoryLimit' => '512m',
];
echo "Create app like dentallab...\n";
$r = $c->request('POST', '/node-apps', $payload);
echo "POST HTTP {$r['status']}\n";
echo json_encode($r['body']['data'] ?? $r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$newId = (string) ($r['body']['data']['id'] ?? '');
if ($newId !== '') {
    sleep(3);
    $dep = $c->request('POST', "/node-apps/{$newId}/deploy");
    echo "deploy HTTP {$dep['status']}\n";
}
