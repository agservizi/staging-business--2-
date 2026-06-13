<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$wid = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$payloads = [
    ['proxyConfig' => (object)[]],
    ['proxyConfig' => []],
    ['type' => 'REVERSE_PROXY', 'port' => 10008, 'phpVersion' => null, 'proxyConfig' => (object)[]],
    ['type' => 'REVERSE_PROXY', 'port' => 10008, 'rootPath' => null],
    ['type' => 'NODE_APP', 'port' => 10008],
    ['type' => 'APP', 'port' => 10008],
];

foreach ($payloads as $i => $body) {
    try {
        $r = $c->request('PATCH', '/websites/' . $wid, $body);
        $pc = $r['body']['data']['proxyConfig'] ?? 'n/a';
        echo "#{$i} HTTP {$r['status']} type=" . ($r['body']['data']['type'] ?? '?') . ' proxy=' . json_encode($pc) . "\n";
        if ($r['status'] >= 400) {
            echo '  err=' . substr($r['raw'], 0, 150) . "\n";
        }
    } catch (Throwable $e) {
        echo "#{$i} ERR {$e->getMessage()}\n";
    }
}
