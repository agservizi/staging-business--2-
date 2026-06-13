<?php
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$w = $c->request('GET', '/websites/cmqbz5nuq00ju101cegni8luy')['body']['data'] ?? [];
$a = $c->request('GET', '/node-apps/cmqbzop1t00rk101c788vjnmd')['body']['data'] ?? [];
echo json_encode([
    'website_status' => $w['status'] ?? null,
    'website_port' => $w['port'] ?? null,
    'proxyConfig' => $w['proxyConfig'] ?? null,
    'app_status' => $a['status'] ?? null,
    'app_port' => $a['port'] ?? null,
    'startCmd' => $a['startCmd'] ?? null,
], JSON_PRETTY_PRINT) . PHP_EOL;
