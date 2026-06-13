<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$repo = 'ssh://git@gitea:22/Carmine/coresuite-business.git';
$branches = ['production', 'main'];
$c = new CoreHostClient();

foreach ($branches as $branch) {
    echo ">>> branch={$branch}\n";
    $c->request('PATCH', "/node-apps/{$id}", ['repository' => $repo, 'branch' => $branch]);
    $c->request('POST', "/node-apps/{$id}/deploy");
    echo "deploy queued\n";
    for ($i = 1; $i <= 30; $i++) {
        sleep(10);
        $a = $c->request('GET', "/node-apps/{$id}");
        $d = $a['body']['data'] ?? [];
        $dep = $d['deployments'][0] ?? [];
        echo "[{$i}/30] app=" . ($d['status'] ?? '?') . ' deploy=' . ($dep['status'] ?? '?') . "\n";
        if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
            echo "OK branch={$branch} preview=https://panel.coresuite.it/preview/" . ($d['previewSlug'] ?? '') . "\n";
            exit(0);
        }
        if (($dep['status'] ?? '') === 'FAILED') {
            $logs = (string) ($dep['logs'] ?? '');
            if (str_contains($logs, 'Remote branch') && str_contains($logs, 'not found')) {
                echo "branch {$branch} assente, provo successivo...\n";
                break;
            }
            echo substr($logs, -1500) . "\n";
            exit(1);
        }
    }
}
echo "Nessun branch deployabile\n";
exit(1);
