#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Migrazione completa business.coresuite.it su CoreHost Panel.
 */

require_once __DIR__ . '/corehost_client.php';

final class CoreHostBusinessMigration
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
        echo "=== Migrazione completa CoreHost ===\n\n";

        $this->ensureGiteaMirror();
        $this->configureAppRepository();
        $this->configureEnvVars();
        $this->configureWebsite();
        $this->deployApp();
        $this->requestSsl();
        $this->printSummary();

        return 0;
    }

    private function ensureGiteaMirror(): void
    {
        echo ">>> Gitea mirror\n";
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/gitea_push_production.php');
        passthru($cmd, $code);
        if ($code !== 0) {
            echo "WARN: gitea push exit={$code}, provo deploy con repo interno comunque\n";
        }
    }

    private function configureAppRepository(): void
    {
        echo "\n>>> Configura repository app (Gitea interno)\n";
        $res = $this->client->request('PATCH', '/node-apps/' . $this->appId, [
            'repository' => 'ssh://git@gitea:22/Carmine/staging-business--2-.git',
            'branch' => 'production',
            'installCmd' => 'composer install --no-dev --no-interaction',
            'startCmd' => 'php -S 0.0.0.0:8080 -t .',
            'healthPath' => '/',
            'autoDeploy' => true,
        ]);
        echo 'PATCH app HTTP ' . $res['status'] . "\n";
    }

    private function configureEnvVars(): void
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

    private function configureWebsite(): void
    {
        echo "\n>>> Configura sito REVERSE_PROXY\n";
        $app = $this->client->request('GET', '/node-apps/' . $this->appId);
        $port = (int) ($app['body']['data']['port'] ?? 80);

        $res = $this->client->request('PATCH', '/websites/' . $this->websiteId, [
            'type' => 'REVERSE_PROXY',
            'port' => $port,
            'gitRepo' => 'ssh://git@gitea:22/Carmine/staging-business--2-.git',
            'gitBranch' => 'production',
            'buildCmd' => null,
            'forceHttps' => true,
        ]);
        echo 'PATCH website HTTP ' . $res['status'] . ' port=' . $port . "\n";

        foreach ([
            ['POST', "/websites/{$this->websiteId}/restart", null],
            ['POST', "/node-apps/{$this->appId}/restart", null],
        ] as [$method, $path, $body]) {
            try {
                $r = $this->client->request($method, $path, $body);
                echo "{$method} {$path} HTTP {$r['status']}\n";
            } catch (Throwable $e) {
                echo "{$method} {$path} ERR: {$e->getMessage()}\n";
            }
        }
    }

    private function deployApp(): void
    {
        echo "\n>>> Deploy app\n";
        $res = $this->client->request('POST', '/node-apps/' . $this->appId . '/deploy');
        echo 'deploy HTTP ' . $res['status'] . "\n";
        echo substr(json_encode($res['body'], JSON_UNESCAPED_UNICODE), 0, 400) . "\n";

        for ($i = 1; $i <= 24; $i++) {
            sleep(10);
            $a = $this->client->request('GET', '/node-apps/' . $this->appId);
            $d = $a['body']['data'] ?? [];
            $status = (string) ($d['status'] ?? '?');
            $dep = $d['deployments'][0] ?? [];
            $depStatus = (string) ($dep['status'] ?? '');
            echo "[{$i}/24] app={$status} deploy={$depStatus}\n";
            if ($depStatus === 'SUCCESS' && $status === 'RUNNING') {
                echo "Deploy completato.\n";
                return;
            }
            if ($depStatus === 'FAILED') {
                echo substr((string) ($dep['logs'] ?? ''), -1200) . "\n";
                return;
            }
        }
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
            echo 'SSL ERR: ' . $e->getMessage() . "\n";
        }
    }

    private function printSummary(): void
    {
        echo "\n=== Riepilogo ===\n";
        $w = $this->client->request('GET', '/websites/' . $this->websiteId);
        $wd = $w['body']['data'] ?? [];
        $a = $this->client->request('GET', '/node-apps/' . $this->appId);
        $ad = $a['body']['data'] ?? [];
        echo "website: {$wd['status']} type={$wd['type']} port={$wd['port']} ssl={$wd['sslStatus']}\n";
        echo "app: {$ad['status']} port={$ad['port']} giteaRepo=" . ($ad['giteaRepo'] ?? 'null') . "\n";
        echo "preview: https://panel.coresuite.it/preview/" . ($ad['previewSlug'] ?? '') . "\n";
        echo "live: https://{$this->domain}\n";
    }
}

try {
    exit((new CoreHostBusinessMigration())->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
