<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');
$c = new CoreHostClient();

$rules = <<<'NGINX'
location ^~ /assets/ {
    add_header Cache-Control "public, max-age=300, must-revalidate" always;
}
NGINX;

$r = $c->request('PATCH', '/websites/' . $websiteId, [
    'nginxRules' => $rules,
]);
echo 'PATCH nginxRules HTTP ' . $r['status'] . PHP_EOL;
echo substr($r['raw'], 0, 400) . PHP_EOL;

$r2 = $c->request('POST', '/websites/' . $websiteId . '/restart');
echo 'restart HTTP ' . $r2['status'] . PHP_EOL;
