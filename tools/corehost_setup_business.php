<?php
declare(strict_types=1);

require_once __DIR__ . '/corehost_client.php';

$client = new CoreHostClient();
$domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');
$dbName = (string) env('COREHOST_DB_NAME', 'coresuite_business');

$sites = $client->request('GET', '/websites');
$website = null;
foreach (($sites['body']['data'] ?? []) as $site) {
    if (is_array($site) && ($site['domain'] ?? '') === $domain) {
        $website = $site;
        break;
    }
}

if (!$website || empty($website['id'])) {
    fwrite(STDERR, "Sito {$domain} non trovato su CoreHost\n");
    exit(1);
}

$id = (string) $website['id'];
echo "Sito: {$domain} id={$id} status=" . ($website['status'] ?? '?') . "\n";

$actions = [
    ['POST', "/websites/{$id}/start", null, 'start'],
    ['POST', "/websites/{$id}/deploy", null, 'deploy'],
    ['PATCH', "/websites/{$id}", [
        'gitRepo' => (string) env('COREHOST_GIT_REPO', 'https://github.com/agservizi/staging-business--2-.git'),
        'gitBranch' => (string) env('COREHOST_GIT_BRANCH', 'production'),
    ], 'patch-git'],
];

foreach ($actions as [$method, $path, $body, $label]) {
    echo "\n>>> {$label} {$method} {$path}\n";
    try {
        $res = $client->request($method, $path, $body);
        echo 'HTTP ' . $res['status'] . "\n";
        echo substr(json_encode($res['body'], JSON_UNESCAPED_UNICODE), 0, 800) . "\n";
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
}

echo "\n>>> create database\n";
$dbs = $client->request('GET', '/databases');
$exists = false;
foreach (($dbs['body']['data'] ?? []) as $db) {
    if (is_array($db) && ($db['name'] ?? '') === $dbName) {
        $exists = true;
        echo "DB già presente: {$dbName}\n";
        break;
    }
}
if (!$exists) {
    try {
        $dbRes = $client->request('POST', '/databases', [
            'name' => $dbName,
            'type' => 'MYSQL',
            'websiteId' => $id,
        ]);
        echo 'HTTP ' . $dbRes['status'] . "\n";
        echo json_encode($dbRes['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo 'ERR DB: ' . $e->getMessage() . "\n";
    }
}

echo "\nPreview URL: https://panel.coresuite.it/preview/" . ($website['previewSlug'] ?? '') . "\n";
