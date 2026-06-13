<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$id = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
foreach ([
    ['POST', "/node-apps/{$id}/rebuild", null],
    ['POST', "/node-apps/{$id}/redeploy", null],
    ['DELETE', "/node-apps/{$id}/cache", null],
    ['POST', "/node-apps/{$id}/build", null],
] as [$m, $p, $b]) {
    try {
        $r = $c->request($m, $p, $b);
        echo "{$m} {$p} -> {$r['status']} " . substr($r['raw'], 0, 120) . "\n";
    } catch (Throwable $e) {
        echo "{$m} {$p} ERR\n";
    }
}
