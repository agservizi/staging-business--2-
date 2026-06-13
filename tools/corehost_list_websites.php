<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$r = $c->request('GET', '/websites');
foreach (($r['body']['data'] ?? []) as $w) {
    echo ($w['domain'] ?? '?') . ' | ' . ($w['type'] ?? '?') . ' | ' . ($w['status'] ?? '?')
        . ' | container=' . substr((string) ($w['containerId'] ?? 'null'), 0, 12)
        . ' | nodeApp=' . ($w['nodeApp']['name'] ?? ($w['nodeAppId'] ?? 'null'))
        . "\n";
}
