<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = $argv[1] ?? 'cmqbddt4v078v6ht4hm8posiz';
$client = new CoreHostClient();
$paths = [
    ['POST', "/websites/{$id}/restart"],
    ['POST', "/websites/{$id}/provision"],
    ['POST', "/websites/{$id}/git-pull"],
    ['POST', "/websites/{$id}/sync"],
    ['POST', "/deployments", ['websiteId' => $id]],
    ['POST', "/websites/{$id}/ssl"],
    ['GET', "/websites/{$id}"],
];
foreach ($paths as $item) {
    [$method, $path] = $item;
    $body = $item[2] ?? null;
    try {
        $r = $client->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 120) . PHP_EOL;
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR " . $e->getMessage() . PHP_EOL;
    }
}
