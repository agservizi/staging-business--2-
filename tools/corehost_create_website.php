<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$payload = [
    'domain' => (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it'),
    'type' => 'PHP',
    'phpVersion' => '8.4',
    'notes' => 'Coresuite Business WOW production',
];
$r = $c->request('POST', '/websites', $payload);
echo 'HTTP ' . $r['status'] . PHP_EOL;
echo json_encode($r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
