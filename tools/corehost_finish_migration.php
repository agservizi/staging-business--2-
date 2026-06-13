#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/corehost_client.php';

final class CoreHostOrchestrator
{
    private CoreHostClient $client;
    private string $websiteId;
    private string $dbId;
    private string $domain;

    public function __construct()
    {
        $this->client = new CoreHostClient();
        $this->websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
        $this->dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
        $this->domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');
    }

    public function run(): int
    {
        echo "=== CoreHost finish migration ===\n\n";

        $this->linkDatabaseToWebsite();
        $this->patchWebsiteEnvHints();
        $this->tryActions([
            ['POST', "/websites/{$this->websiteId}/restart", null, 'restart'],
            ['POST', "/websites/{$this->websiteId}/start", null, 'start'],
            ['POST', "/websites/{$this->websiteId}/rebuild", null, 'rebuild'],
            ['POST', "/websites/{$this->websiteId}/git/deploy", null, 'git-deploy'],
            ['POST', "/websites/{$this->websiteId}/git/pull", null, 'git-pull'],
            ['POST', "/websites/{$this->websiteId}/deployments", null, 'deployments'],
            ['POST', '/node-apps', [
                'name' => 'coresuite-business',
                'runtime' => 'PHP',
                'repositoryUrl' => (string) env('COREHOST_GIT_REPO'),
                'branch' => (string) env('COREHOST_GIT_BRANCH', 'production'),
                'domain' => $this->domain,
            ], 'create-php-node-app'],
        ]);

        $this->requestSsl();
        $this->printStatus();
        $this->printDbCredentials();

        return 0;
    }

    private function linkDatabaseToWebsite(): void
    {
        echo ">>> Link DB to website\n";
        try {
            $res = $this->client->request('PATCH', '/databases/' . $this->dbId, [
                'websiteId' => $this->websiteId,
            ]);
            echo 'PATCH database HTTP ' . $res['status'] . "\n";
        } catch (Throwable $e) {
            echo 'PATCH database ERR: ' . $e->getMessage() . "\n";
        }
    }

    private function patchWebsiteEnvHints(): void
    {
        echo ">>> Patch website git + notes\n";
        try {
            $res = $this->client->request('PATCH', '/websites/' . $this->websiteId, [
                'gitRepo' => (string) env('COREHOST_GIT_REPO'),
                'gitBranch' => (string) env('COREHOST_GIT_BRANCH', 'production'),
                'buildCmd' => 'composer install --no-dev --no-interaction || true',
                'notes' => 'Coresuite Business WOW - auto migrated',
            ]);
            echo 'PATCH website HTTP ' . $res['status'] . ' status=' . ($res['body']['data']['status'] ?? '?') . "\n";
        } catch (Throwable $e) {
            echo 'PATCH website ERR: ' . $e->getMessage() . "\n";
        }
    }

    /**
     * @param array<int, array{0:string,1:string,2:?array,3:string}> $actions
     */
    private function tryActions(array $actions): void
    {
        foreach ($actions as [$method, $path, $body, $label]) {
            echo "\n>>> {$label}\n";
            try {
                $res = $this->client->request($method, $path, $body);
                echo "HTTP {$res['status']}\n";
                echo substr(json_encode($res['body'], JSON_UNESCAPED_UNICODE), 0, 500) . "\n";
                if ($res['status'] >= 200 && $res['status'] < 300 && in_array($label, ['restart', 'start', 'rebuild', 'git-deploy', 'git-pull'], true)) {
                    sleep(5);
                }
            } catch (Throwable $e) {
                echo 'ERR: ' . $e->getMessage() . "\n";
            }
        }
    }

    private function requestSsl(): void
    {
        echo "\n>>> SSL request\n";
        foreach ([
            ['POST', '/ssl', ['domain' => $this->domain, 'websiteId' => $this->websiteId]],
            ['POST', "/websites/{$this->websiteId}/ssl/request", null],
            ['POST', "/websites/{$this->websiteId}/certificates", ['provider' => 'letsencrypt']],
        ] as [$method, $path, $body]) {
            try {
                $res = $this->client->request($method, $path, $body);
                echo "{$method} {$path} -> {$res['status']}\n";
                if ($res['status'] < 400) {
                    break;
                }
            } catch (Throwable $e) {
                echo "{$path} ERR: {$e->getMessage()}\n";
            }
        }
    }

    private function printStatus(): void
    {
        echo "\n=== Stato finale sito ===\n";
        $w = $this->client->request('GET', '/websites/' . $this->websiteId);
        $d = $w['body']['data'] ?? [];
        foreach (['status', 'containerId', 'gitRepo', 'gitBranch', 'sslStatus', 'previewSlug', 'port'] as $k) {
            echo "{$k}=" . ($d[$k] ?? '') . "\n";
        }
    }

    private function printDbCredentials(): void
    {
        echo "\n=== Database ===\n";
        try {
            $res = $this->client->request('GET', '/databases/' . $this->dbId);
            $db = $res['body']['data'] ?? [];
            echo 'name=' . ($db['name'] ?? '') . ' status=' . ($db['status'] ?? '') . "\n";
            echo 'host=' . ($db['host'] ?? '') . ' port=' . ($db['port'] ?? '') . "\n";

            $users = $this->client->request('GET', '/databases/' . $this->dbId . '/users');
            echo "users HTTP {$users['status']}\n";
            echo substr(json_encode($users['body'], JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
        } catch (Throwable $e) {
            echo 'DB detail ERR: ' . $e->getMessage() . "\n";
        }
    }
}

try {
    exit((new CoreHostOrchestrator())->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
