<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$w = $c->request('GET', '/websites/cmqbddt4v078v6ht4hm8posiz');
$data = $w['body']['data'] ?? [];
echo 'status=' . ($data['status'] ?? '?') . PHP_EOL;
echo 'containerId=' . ($data['containerId'] ?? 'null') . PHP_EOL;
echo 'gitRepo=' . ($data['gitRepo'] ?? '') . PHP_EOL;
echo 'gitBranch=' . ($data['gitBranch'] ?? '') . PHP_EOL;
echo 'previewSlug=' . ($data['previewSlug'] ?? '') . PHP_EOL;
$d = $c->request('GET', '/databases');
foreach (($d['body']['data'] ?? []) as $db) {
    if (!is_array($db)) {
        continue;
    }
    $dom = $db['website']['domain'] ?? '';
    if (($db['name'] ?? '') === 'coresuite_business' || $dom === 'business.coresuite.it') {
        echo 'DB: ' . json_encode($db, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
