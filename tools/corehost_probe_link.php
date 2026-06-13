<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$wid = (string) env('COREHOST_WEBSITE_ID', 'cmqbyvouc00ge101chhl9i5bn');

try {
    $r = $c->request('PATCH', '/node-apps/' . $appId, ['websiteId' => $wid]);
    echo 'OK ' . json_encode($r['body']['data']['websiteId'] ?? null) . "\n";
} catch (Throwable $e) {
    echo 'ERR: ' . $e->getMessage() . "\n";
}

// Try partial PATCH fields
$fields = [
    ['websiteId' => $wid],
    ['name' => 'coresuite-business', 'websiteId' => $wid],
    ['websiteId' => $wid, 'startCmd' => 'php -S 0.0.0.0:80 -t .'],
];
foreach ($fields as $body) {
    $r = $c->request('PATCH', '/node-apps/' . $appId, $body);
    echo 'PATCH ' . json_encode($body) . ' -> ' . $r['status'] . ' websiteId=' . ($r['body']['data']['websiteId'] ?? 'null') . "\n";
    if ($r['status'] >= 400) {
        echo substr($r['raw'], 0, 200) . "\n";
    }
}
