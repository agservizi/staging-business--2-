#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/corehost_client.php';

final class CoreHostBusinessFinish
{
    private CoreHostClient $client;
    private string $websiteId;
    private string $dbId;
    private string $appId;
    private string $domain;

    public function __construct()
    {
        $this->client = new CoreHostClient();
        $this->websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
        $this->dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
        $this->appId = (string) env('COREHOST_APP_ID', 'cmqbek0kf07gt6ht4dhwubi16');
        $this->domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');
    }

    public function run(): int
    {
        echo "=== CoreHost business finish ===\n\n";
        $this->cleanupTestApps();
        $this->configureApp();
        $this->setEnvVars();
        $this->linkDatabase();
        $this->configureWebsite();
        $deployOk = $this->deployWithRetries(5);
        $this->requestSsl();
        $this->printSummary($deployOk);
        return $deployOk ? 0 : 1;
    }

    private function cleanupTestApps(): void
    {
        $list = $this->client->request('GET', '/node-apps');
        foreach (($list['body']['data'] ?? []) as $app) {
            if (!in_array($app['name'] ?? '', ['coresuite-business-test'], true)) {
                continue;
            }
            $id = (string) $app['id'];
            echo "Delete test app {$id}\n";
            $this->client->request('DELETE', "/node-apps/{$id}");
        }
    }

    private function configureApp(): void
    {
        echo ">>> Configure app\n";
        $res = $this->client->request('PATCH', '/node-apps/' . $this->appId, [
            'repository' => 'git@github.com:agservizi/staging-business--2-.git',
            'branch' => 'production',
            'installCmd' => 'composer install --no-dev --no-interaction',
            'startCmd' => 'php -S 0.0.0.0:8080 -t .',
            'healthPath' => '/',
            'autoDeploy' => true,
            'memoryLimit' => '512m',
        ]);
        echo 'PATCH app HTTP ' . $res['status'] . "\n";
    }

    private function setEnvVars(): void
    {
        echo "\n>>> Env vars\n";
        $db = $this->client->request('GET', '/databases/' . $this->dbId);
        $dbData = $db['body']['data'] ?? [];
        $dbUser = $dbData['dbUsers'][0] ?? [];
        $vars = [
            'APP_ENV' => 'production',
            'APP_URL' => 'https://' . $this->domain,
            'DB_HOST' => (string) ($dbData['host'] ?? ''),
            'DB_PORT' => (string) ($dbData['port'] ?? '23307'),
            'DB_DATABASE' => (string) ($dbData['name'] ?? 'coresuite_business'),
            'DB_USERNAME' => (string) ($dbUser['username'] ?? ''),
            'DB_PASSWORD' => (string) ($dbUser['password'] ?? ''),
            'CAF_PATRONATO_ENCRYPTION_KEY' => (string) env('CAF_PATRONATO_ENCRYPTION_KEY', ''),
            'AUTOMATA_BASE_URL' => (string) env('AUTOMATA_BASE_URL', 'https://automa.coresuite.it'),
        ];
        foreach ($vars as $key => $value) {
            if ($value === '') {
                continue;
            }
            $res = $this->client->request('POST', '/env-vars', [
                'nodeAppId' => $this->appId,
                'key' => $key,
                'value' => $value,
                'isSecret' => in_array($key, ['DB_PASSWORD', 'CAF_PATRONATO_ENCRYPTION_KEY'], true),
            ]);
            echo "  {$key}: HTTP {$res['status']}\n";
        }
    }

    private function linkDatabase(): void
    {
        echo "\n>>> Link DB to app\n";
        foreach ([
            ['PATCH', '/databases/' . $this->dbId, ['nodeAppId' => $this->appId]],
            ['PATCH', '/databases/' . $this->dbId, ['websiteId' => $this->websiteId]],
        ] as [$method, $path, $body]) {
            $res = $this->client->request($method, $path, $body);
            echo "{$method} {$path} HTTP {$res['status']}\n";
        }
    }

    private function configureWebsite(): void
    {
        echo "\n>>> Configure website\n";
        $app = $this->client->request('GET', '/node-apps/' . $this->appId);
        $port = (int) ($app['body']['data']['port'] ?? 80);
        $res = $this->client->request('PATCH', '/websites/' . $this->websiteId, [
            'type' => 'REVERSE_PROXY',
            'port' => $port,
            'gitRepo' => 'git@github.com:agservizi/staging-business--2-.git',
            'gitBranch' => 'production',
            'buildCmd' => null,
            'forceHttps' => true,
        ]);
        echo 'PATCH website HTTP ' . $res['status'] . " port={$port}\n";
    }

    private function deployWithRetries(int $maxAttempts): bool
    {
        echo "\n>>> Deploy (max {$maxAttempts} tentativi)\n";
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            echo "\n--- Tentativo {$attempt}/{$maxAttempts} ---\n";
            $res = $this->client->request('POST', '/node-apps/' . $this->appId . '/deploy');
            echo 'deploy queued HTTP ' . $res['status'] . "\n";
            for ($i = 1; $i <= 18; $i++) {
                sleep(10);
                $a = $this->client->request('GET', '/node-apps/' . $this->appId);
                $d = $a['body']['data'] ?? [];
                $status = (string) ($d['status'] ?? '?');
                $dep = $d['deployments'][0] ?? [];
                $depStatus = (string) ($dep['status'] ?? '');
                echo "[{$i}/18] app={$status} deploy={$depStatus}\n";
                if ($depStatus === 'SUCCESS' && in_array($status, ['RUNNING', 'BUILDING'], true)) {
                    if ($status === 'RUNNING') {
                        echo "Deploy OK\n";
                        return true;
                    }
                }
                if ($depStatus === 'FAILED') {
                    $logs = (string) ($dep['logs'] ?? '');
                    if (str_contains($logs, 'Could not resolve hostname')) {
                        echo "DNS ancora rotto, attendo prima del prossimo tentativo...\n";
                        break;
                    }
                    echo substr($logs, -1000) . "\n";
                    break;
                }
            }
            if ($attempt < $maxAttempts) {
                sleep(30);
            }
        }
        return false;
    }

    private function requestSsl(): void
    {
        echo "\n>>> SSL\n";
        try {
            $res = $this->client->request('POST', '/ssl', [
                'domain' => $this->domain,
                'websiteId' => $this->websiteId,
            ]);
            echo 'POST /ssl HTTP ' . $res['status'] . "\n";
        } catch (Throwable $e) {
            echo 'SSL: ' . $e->getMessage() . "\n";
        }
    }

    private function printSummary(bool $deployOk): void
    {
        echo "\n=== Riepilogo ===\n";
        $w = $this->client->request('GET', '/websites/' . $this->websiteId);
        $wd = $w['body']['data'] ?? [];
        $a = $this->client->request('GET', '/node-apps/' . $this->appId);
        $ad = $a['body']['data'] ?? [];
        echo 'deploy=' . ($deployOk ? 'OK' : 'FAILED') . "\n";
        echo "website: status={$wd['status']} type={$wd['type']} port={$wd['port']}\n";
        echo "app: status={$ad['status']} giteaManaged=" . (($ad['giteaManaged'] ?? false) ? 'true' : 'false') . "\n";
        echo "preview: https://panel.coresuite.it/preview/" . ($ad['previewSlug'] ?? '') . "\n";
        echo "live: https://{$this->domain}\n";
    }
}

try {
    exit((new CoreHostBusinessFinish())->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
