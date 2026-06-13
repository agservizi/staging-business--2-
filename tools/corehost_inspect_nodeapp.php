<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$list = $c->request('GET', '/node-apps');
foreach (($list['body']['data'] ?? []) as $app) {
    if (($app['name'] ?? '') === 'lumina' || ($app['runtime'] ?? '') === 'PHP') {
        $id = $app['id'];
        echo "=== {$app['name']} ===\n";
        echo json_encode($c->request('GET', "/node-apps/{$id}")['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";
        break;
    }
}
if (empty($list['body']['data'][0])) {
    echo json_encode($list['body'], JSON_PRETTY_PRINT);
} else {
    $first = $list['body']['data'][0];
    echo "\n=== first app {$first['name']} ===\n";
    echo json_encode($c->request('GET', '/node-apps/' . $first['id'])['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
}
