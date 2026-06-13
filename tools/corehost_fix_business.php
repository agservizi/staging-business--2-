<?php
declare(strict_types=1);
/**
 * Fix completo business.coresuite.it su CoreHost.
 */
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$github = 'https://github.com/agservizi/staging-business--2-.git';
$gitea = 'ssh://git@gitea:22/Carmine/coresuite-business.git';

function probe_site(): int
{
    $ch = curl_init('https://business.coresuite.it/');
    $ca = realpath(__DIR__ . '/../certs/cacert.pem');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => $ca ?: '',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

echo "=== CoreHost fix business ===\n";
echo 'probe iniziale: HTTP ' . probe_site() . "\n\n";

// Sync da GitHub (giteaManaged aggiorna mirror lato server)
echo ">>> Sync GitHub production\n";
$c->request('PATCH', "/node-apps/{$appId}", [
    'repository' => $github,
    'branch' => 'production',
    'nodeVersion' => '8.4',
    'startCmd' => 'sh deploy/corehost-start.sh',
    'installCmd' => 'composer install --no-dev --no-interaction',
    'healthPath' => '/',
    'websiteId' => $websiteId,
]);
$c->request('POST', "/node-apps/{$appId}/deploy");

for ($i = 1; $i <= 18; $i++) {
    sleep(10);
    $d = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $sha = substr((string)($dep['commitSha'] ?? ''), 0, 7);
    echo "[github {$i}] deploy=" . ($dep['status'] ?? '?') . " commit={$sha}\n";
    if (($dep['status'] ?? '') === 'SUCCESS') {
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string)($dep['logs'] ?? ''), -900) . "\n";
        break;
    }
}

// Torna su Gitea + redeploy
echo "\n>>> Redeploy da Gitea\n";
$c->request('PATCH', "/node-apps/{$appId}", [
    'repository' => $gitea,
    'branch' => 'production',
    'startCmd' => 'sh deploy/corehost-start.sh',
]);
$c->request('PATCH', "/websites/{$websiteId}", [
    'type' => 'REVERSE_PROXY',
    'port' => (int) ($d['port'] ?? 10008),
    'gitRepo' => null,
    'gitBranch' => null,
    'buildCmd' => null,
]);
$c->request('POST', "/node-apps/{$appId}/deploy");

for ($i = 1; $i <= 24; $i++) {
    sleep(10);
    $d = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $sha = substr((string)($dep['commitSha'] ?? ''), 0, 7);
    echo "[gitea {$i}] app=" . ($d['status'] ?? '?') . " deploy=" . ($dep['status'] ?? '?')
        . " commit={$sha} start=" . ($d['startCmd'] ?? '?') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo substr((string)($dep['logs'] ?? ''), -1000) . "\n";
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string)($dep['logs'] ?? ''), -1000) . "\n";
        exit(1);
    }
}

try {
    $c->request('POST', "/websites/{$websiteId}/restart");
} catch (Throwable $e) {
}
try {
    $c->request('POST', "/node-apps/{$appId}/restart");
} catch (Throwable $e) {
}

sleep(12);
$code = probe_site();
$w = $c->request('GET', "/websites/{$websiteId}")['body']['data'] ?? [];
echo "\nproxy=" . json_encode($w['proxyConfig'] ?? null) . "\n";
echo "probe finale: HTTP {$code}\n";
exit($code === 200 ? 0 : 1);
