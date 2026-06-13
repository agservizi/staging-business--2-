<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$wid = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
foreach ([
    ['PATCH', '/websites/' . $wid, ['status' => 'STOPPED']],
    ['PATCH', '/websites/' . $wid, ['maintenance' => true]],
    ['POST', '/websites/' . $wid . '/stop', null],
    ['DELETE', '/websites/' . $wid, null],
] as [$m, $p, $b]) {
    try {
        $r = $c->request($m, $p, $b);
        echo "{$m} {$p} -> {$r['status']} " . substr($r['raw'], 0, 120) . "\n";
    } catch (Throwable $e) {
        echo "{$m} {$p} ERR\n";
    }
}
