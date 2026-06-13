#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Deploy CLI CoreHost per coresuite-business.
 * Usage: php tools/corehost_deploy_cli.php [deploy|status|restart]
 */

require_once __DIR__ . '/corehost_client.php';

$action = $argv[1] ?? 'deploy';
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$client = new CoreHostClient();

$internalRepo = 'ssh://git@gitea:22/Carmine/coresuite-business.git';
$githubRepo = 'git@github.com:agservizi/staging-business--2-.git';
$branch = (string) env('COREHOST_GIT_BRANCH', 'production');

function print_app_status(CoreHostClient $client, string $appId): void
{
    $a = $client->request('GET', '/node-apps/' . $appId);
    $d = $a['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo 'app.status=' . ($d['status'] ?? '?') . "\n";
    echo 'app.repository=' . ($d['repository'] ?? '?') . "\n";
    echo 'app.giteaManaged=' . (($d['giteaManaged'] ?? false) ? 'true' : 'false') . "\n";
    echo 'app.giteaRepo=' . ($d['giteaRepo'] ?? 'null') . "\n";
    echo 'deploy.status=' . ($dep['status'] ?? 'none') . "\n";
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string) ($dep['logs'] ?? ''), -900) . "\n";
    }
}

switch ($action) {
    case 'status':
        print_app_status($client, $appId);
        $w = $client->request('GET', '/websites/' . $websiteId);
        $wd = $w['body']['data'] ?? [];
        echo 'website.status=' . ($wd['status'] ?? '?') . ' port=' . ($wd['port'] ?? '?') . "\n";
        exit(0);

    case 'restart':
        foreach ([
            ['POST', "/node-apps/{$appId}/restart", null],
            ['POST', "/websites/{$websiteId}/restart", null],
        ] as [$method, $path, $body]) {
            try {
                $r = $client->request($method, $path, $body);
                echo "{$method} {$path} HTTP {$r['status']}\n";
            } catch (Throwable $e) {
                echo "{$method} {$path} ERR {$e->getMessage()}\n";
            }
        }
        exit(0);

    case 'deploy':
        echo "=== CoreHost deploy CLI ===\n";
        echo "App: {$appId}\n";

        // Prefer internal Gitea (works when GitHub DNS is down on server)
        $repos = [$internalRepo, $githubRepo];
        foreach ($repos as $repo) {
            echo "\n>>> PATCH repository: {$repo}\n";
            $client->request('PATCH', '/node-apps/' . $appId, [
                'repository' => $repo,
                'branch' => $branch,
            ]);

            echo ">>> POST deploy\n";
            $res = $client->request('POST', '/node-apps/' . $appId . '/deploy');
            echo 'queued HTTP ' . $res['status'] . "\n";

            for ($i = 1; $i <= 24; $i++) {
                sleep(10);
                $a = $client->request('GET', '/node-apps/' . $appId);
                $d = $a['body']['data'] ?? [];
                $dep = $d['deployments'][0] ?? [];
                $depStatus = (string) ($dep['status'] ?? '');
                echo "[{$i}/24] app=" . ($d['status'] ?? '?') . " deploy={$depStatus}\n";

                if ($depStatus === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
                    echo "\nDeploy completato con successo.\n";
                    echo 'preview: https://panel.coresuite.it/preview/' . ($d['previewSlug'] ?? '') . "\n";
                    exit(0);
                }
                if ($depStatus === 'FAILED') {
                    $logs = (string) ($dep['logs'] ?? '');
                    if (str_contains($logs, 'Cannot find repository') && $repo === $internalRepo) {
                        echo "Repo Gitea interno assente, provo GitHub...\n";
                        break;
                    }
                    if (str_contains($logs, 'Could not resolve hostname') && $repo === $githubRepo) {
                        echo "GitHub DNS down su server.\n";
                        break;
                    }
                    echo substr($logs, -1000) . "\n";
                    break;
                }
            }
        }

        echo "\nDeploy non riuscito.\n";
        print_app_status($client, $appId);
        exit(1);

    default:
        fwrite(STDERR, "Usage: php tools/corehost_deploy_cli.php [deploy|status|restart]\n");
        exit(1);
}
