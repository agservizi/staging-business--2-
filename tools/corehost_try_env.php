<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$appId = 'cmqbe9lbd07dm6ht4wonxnkk1';
$c = new CoreHostClient();
$payload = ['key' => 'APP_ENV', 'value' => 'production', 'isSecret' => false];
$paths = [
    ['POST', "/node-apps/{$appId}/env", $payload],
    ['POST', "/node-apps/{$appId}/env-vars", $payload],
    ['POST', "/env-vars", array_merge($payload, ['nodeAppId' => $appId])],
    ['PATCH', "/node-apps/{$appId}", ['envVars' => [$payload]]],
];
foreach ($paths as [$method, $path, $body]) {
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 200) . "\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n";
    }
}
