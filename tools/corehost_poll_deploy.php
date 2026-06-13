<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$appId = $argv[1] ?? 'cmqbe9lbd07dm6ht4wonxnkk1';
$c = new CoreHostClient();
for ($i = 1; $i <= 20; $i++) {
    $a = $c->request('GET', "/node-apps/{$appId}");
    $d = $a['body']['data'] ?? [];
    $status = (string) ($d['status'] ?? '?');
    $container = substr((string) ($d['containerId'] ?? 'null'), 0, 12);
    $deploys = $d['deployments'] ?? [];
    $last = $deploys[0] ?? [];
    $depStatus = (string) ($last['status'] ?? '');
    echo "[{$i}/20] app={$status} container={$container} lastDeploy={$depStatus}\n";
    if ($status === 'RUNNING' && $container !== 'null' && $container !== '') {
        if ($depStatus === '' || $depStatus === 'SUCCESS' || $depStatus === 'COMPLETED') {
            echo "OK\n";
            if (!empty($last['logs'])) {
                echo substr((string) $last['logs'], -1500) . "\n";
            }
            exit(0);
        }
    }
    if ($depStatus === 'FAILED') {
        echo substr((string) ($last['logs'] ?? $last['error'] ?? json_encode($last)), 0, 2000) . "\n";
        exit(1);
    }
    sleep(15);
}
echo "Timeout\n";
exit(1);
