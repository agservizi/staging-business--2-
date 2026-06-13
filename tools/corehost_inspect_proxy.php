<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
foreach (['shop.agenziaplinio.it', 'app.lexcorestudio.it'] as $domain) {
    $list = $c->request('GET', '/websites');
    foreach (($list['body']['data'] ?? []) as $w) {
        if (($w['domain'] ?? '') !== $domain) {
            continue;
        }
        $id = $w['id'];
        echo "=== {$domain} ({$id}) ===\n";
        echo json_encode($c->request('GET', "/websites/{$id}")['body']['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
