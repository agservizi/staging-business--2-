<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$list = $c->request('GET', '/node-apps');
foreach (($list['body']['data'] ?? []) as $app) {
    echo ($app['runtime'] ?? '?') . ' | ' . ($app['name'] ?? '?') . ' | ' . ($app['status'] ?? '?') . ' | ' . ($app['repository'] ?? '') . "\n";
}
