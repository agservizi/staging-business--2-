<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$id = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$c = new CoreHostClient();

echo ">>> Fix startCmd to use assigned PORT\n";
$c->request('PATCH', "/node-apps/{$id}", [
    'startCmd' => 'php -S 0.0.0.0:${PORT} -t .',
    'nodeVersion' => '8.4',
    'branch' => 'production',
    'repository' => 'git@github.com:agservizi/staging-business--2-.git',
]);

echo ">>> Sync from GitHub (giteaManaged)\n";
$c->request('POST', "/node-apps/{$id}/deploy");
sleep(20);

$c->request('PATCH', "/node-apps/{$id}", [
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => 'production',
    'startCmd' => 'php -S 0.0.0.0:${PORT} -t .',
]);
$c->request('POST', "/node-apps/{$id}/deploy");

for ($i = 1; $i <= 24; $i++) {
    sleep(10);
    $a = $c->request('GET', "/node-apps/{$id}");
    $d = $a['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $commit = substr((string)($dep['commitSha'] ?? ''), 0, 7);
    echo "[{$i}] app=" . ($d['status'] ?? '?') . ' deploy=' . ($dep['status'] ?? '?') . " commit={$commit} port=" . ($d['port'] ?? '?') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string)($dep['logs'] ?? ''), -800) . "\n";
        exit(1);
    }
}

$port = (int) ($d['port'] ?? 0);
$c->request('PATCH', "/websites/{$websiteId}", ['type' => 'REVERSE_PROXY', 'port' => $port]);
try { $c->request('POST', "/websites/{$websiteId}/restart"); } catch (Throwable $e) {}
echo "website port={$port}\n";
