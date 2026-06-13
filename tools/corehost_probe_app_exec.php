<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$cmds = [
    'curl -sI http://127.0.0.1:80/ | head -5',
    'curl -sI http://127.0.0.1:8080/ | head -5',
    'curl -sI http://127.0.0.1:${PORT:-10008}/ | head -5',
    'ss -lntp 2>/dev/null || netstat -lntp 2>/dev/null',
    'echo PORT=$PORT COREHOST_INTERNAL_PORT=$COREHOST_INTERNAL_PORT',
    'ls -la index.php',
];

foreach ($cmds as $command) {
    echo ">>> {$command}\n";
    foreach ([
        ['POST', "/node-apps/{$appId}/exec", ['command' => $command]],
        ['POST', "/node-apps/{$appId}/terminal", ['command' => $command]],
    ] as [$method, $path, $body]) {
        try {
            $r = $c->request($method, $path, $body);
            echo "{$method} {$path} HTTP {$r['status']}\n";
            echo substr($r['raw'], 0, 600) . "\n\n";
            if ($r['status'] >= 200 && $r['status'] < 300) {
                break;
            }
        } catch (Throwable $e) {
            echo "{$method} ERR {$e->getMessage()}\n";
        }
    }
}

echo ">>> link-app\n";
try {
    $r = $c->request('POST', "/websites/{$websiteId}/link-app", ['nodeAppId' => $appId]);
    echo 'link-app HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 300) . "\n";
} catch (Throwable $e) {
    echo 'link-app ERR ' . $e->getMessage() . "\n";
}
