<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$c = new CoreHostClient();
echo json_encode($c->request('GET', "/websites/{$id}")['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
