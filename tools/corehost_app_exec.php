<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$appId = (string) env('COREHOST_APP_ID', 'cmqbzop1t00rk101c788vjnmd');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');
$c = new CoreHostClient();

$cmds = [
    'ls -la assets/uploads/branding 2>/dev/null || echo no-branding',
    'ls -la assets/uploads 2>/dev/null | head -20',
    'wc -c assets/css/custom.css',
    'head -n 2 assets/css/custom.css',
];

foreach ($cmds as $command) {
    echo ">>> $command\n";
    foreach ([
        ['POST', "/node-apps/{$appId}/exec", ['command' => $command]],
        ['POST', "/node-apps/{$appId}/terminal", ['command' => $command]],
    ] as [$method, $path, $body]) {
        try {
            $r = $c->request($method, $path, $body);
            if ($r['status'] >= 200 && $r['status'] < 300) {
                echo substr($r['raw'], 0, 800) . "\n\n";
                break;
            }
            echo "{$method} HTTP {$r['status']}\n";
        } catch (Throwable $e) {
            echo "{$method} ERR {$e->getMessage()}\n";
        }
    }
}

echo ">>> restart website\n";
try {
    $r = $c->request('POST', "/websites/{$websiteId}/restart");
    echo 'restart HTTP ' . $r['status'] . "\n";
} catch (Throwable $e) {
    echo 'restart ERR ' . $e->getMessage() . "\n";
}
