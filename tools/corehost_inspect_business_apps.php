<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
foreach ($c->request('GET', '/node-apps')['body']['data'] ?? [] as $app) {
    if (!str_contains((string)($app['name'] ?? ''), 'coresuite-business')) {
        continue;
    }
    $d = $c->request('GET', '/node-apps/' . $app['id'])['body']['data'] ?? [];
    echo $app['name'] . ' status=' . ($d['status'] ?? '?') . "\n";
    echo '  id=' . ($d['id'] ?? '') . "\n";
    echo '  branch=' . ($d['branch'] ?? '') . "\n";
    echo '  repository=' . ($d['repository'] ?? '') . "\n";
    echo '  giteaManaged=' . json_encode($d['giteaManaged'] ?? null) . "\n";
    echo '  lastDeploy=' . json_encode($d['lastDeploy'] ?? $d['lastDeployment'] ?? null) . "\n";
    echo '  commit=' . json_encode($d['commit'] ?? $d['commitHash'] ?? $d['gitCommit'] ?? null) . "\n";
    echo '  deployStatus=' . json_encode($d['deployStatus'] ?? null) . "\n";
}
