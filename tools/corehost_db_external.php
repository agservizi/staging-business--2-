<?php
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$id = 'cmqbdh0iw079g6ht49c20gmw3';

$r = $c->request('PATCH', '/databases/' . $id, ['externalAccess' => true]);
echo 'PATCH externalAccess: HTTP ' . $r['status'] . PHP_EOL;
echo json_encode($r['body']['data'] ?? $r['body'], JSON_PRETTY_PRINT) . PHP_EOL;

$r2 = $c->request('GET', '/databases/' . $id);
$d = $r2['body']['data'] ?? [];
echo 'host=' . ($d['host'] ?? '') . ' port=' . ($d['port'] ?? '') . ' external=' . json_encode($d['externalAccess'] ?? null) . PHP_EOL;
