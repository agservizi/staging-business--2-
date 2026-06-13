<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$appId = 'cmqbe9lbd07dm6ht4wonxnkk1';
$c = new CoreHostClient();

$attempts = [
    ['repository' => 'ssh://git@gitea:22/Carmine/staging-business--2-.git', 'branch' => 'production', 'giteaManaged' => true, 'giteaRepo' => 'Carmine/staging-business--2-'],
    ['repository' => 'git@github.com:agservizi/staging-business--2-.git', 'branch' => 'production', 'giteaManaged' => true, 'giteaRepo' => 'Carmine/staging-business--2-'],
    ['repository' => 'ssh://git@gitea:22/Carmine/staging-business--2-.git', 'branch' => 'production'],
];

foreach ($attempts as $i => $payload) {
    echo ">>> PATCH attempt " . ($i + 1) . "\n";
    $r = $c->request('PATCH', "/node-apps/{$appId}", $payload);
    echo "HTTP {$r['status']} " . substr($r['raw'], 0, 300) . "\n";
    if ($r['status'] < 300) {
        echo "Redeploy...\n";
        $d = $c->request('POST', "/node-apps/{$appId}/deploy");
        echo "deploy HTTP {$d['status']}\n";
        break;
    }
}
