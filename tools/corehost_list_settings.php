<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$r = $c->request('GET', '/settings');
foreach (($r['body']['data'] ?? []) as $s) {
    $k = (string) ($s['key'] ?? '');
    if (stripos($k, 'dns') !== false || stripos($k, 'gitea') !== false || stripos($k, 'git') !== false || stripos($k, 'network') !== false) {
        echo $k . '=' . ($s['value'] ?? '') . "\n";
    }
}
echo "\nAll keys:\n";
foreach (($r['body']['data'] ?? []) as $s) {
    echo ($s['key'] ?? '') . "\n";
}
