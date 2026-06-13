<?php
declare(strict_types=1);
/**
 * Mette business.coresuite.it in manutenzione su CoreHost per ripristino Hostinger.
 */
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');
$appId = (string) env('COREHOST_APP_ID', 'cmqbzop1t00rk101c788vjnmd');

$r = $c->request('PATCH', '/websites/' . $websiteId, ['maintenance' => true]);
echo 'website maintenance HTTP ' . $r['status'] . PHP_EOL;

try {
    $c->request('POST', '/websites/' . $websiteId . '/stop');
    echo "website stop queued\n";
} catch (Throwable $e) {
    echo 'website stop skip: ' . $e->getMessage() . PHP_EOL;
}

try {
    $c->request('POST', '/node-apps/' . $appId . '/stop');
    echo "app stop queued\n";
} catch (Throwable $e) {
    echo 'app stop skip: ' . $e->getMessage() . PHP_EOL;
}

$w = $c->request('GET', '/websites/' . $websiteId)['body']['data'] ?? [];
echo 'status=' . ($w['status'] ?? '?') . ' maintenance=' . json_encode($w['maintenance'] ?? null) . PHP_EOL;
