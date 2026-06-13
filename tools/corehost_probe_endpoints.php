<?php
declare(strict_types=1);

require_once __DIR__ . '/corehost_client.php';

$paths = [
    '/websites',
    '/node-apps',
    '/node-apps?limit=50',
    '/vps',
    '/ftp-accounts',
    '/ssl',
    '/backups',
    '/notifications',
];

$client = new CoreHostClient();
foreach ($paths as $path) {
    try {
        $res = $client->request('GET', $path);
        echo $path . ' -> HTTP ' . $res['status'] . PHP_EOL;
        if ($res['status'] === 200) {
            $data = $res['body']['data'] ?? $res['body'];
            if (is_array($data)) {
                echo '  items: ' . count($data) . PHP_EOL;
                foreach (array_slice($data, 0, 8) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $label = $item['name'] ?? $item['domain'] ?? $item['id'] ?? json_encode($item);
                    echo '  - ' . $label . PHP_EOL;
                }
            }
        } else {
            echo '  ' . substr($res['raw'], 0, 120) . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo $path . ' -> ERR ' . $e->getMessage() . PHP_EOL;
    }
    echo PHP_EOL;
}
