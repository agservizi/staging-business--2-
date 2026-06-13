#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Deploy Coresuite Business su CoreHost:
 * 1) Crea node-app PHP (se assente)
 * 2) Collega website business.coresuite.it come REVERSE_PROXY
 * 3) Imposta env DB + deploy
 */

require_once __DIR__ . '/corehost_client.php';

final class BusinessAppDeployer
{
    private CoreHostClient $client;
    private string $websiteId;
    private string $dbId;
    private string $domain;
    private string $appName = 'coresuite-business';

    public function __construct()
    {
        $this->client = new CoreHostClient();
        $this->websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
        $this->dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
        $this->domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');
    }

    public function run(): int
    {
        echo "=== CoreHost Business App Deploy ===\n\n";

        $appId = $this->findOrCreateApp();
        if ($appId === '') {
            return 1;
        }

        $this->setEnvVars($appId);
        $this->linkWebsiteToApp($appId);
        $this->deployApp($appId);
        $this->requestSsl();
        $this->printFinalStatus($appId);

        return 0;
    }

    private function findOrCreateApp(): string
    {
        $list = $this->client->request('GET', '/node-apps');
        foreach (($list['body']['data'] ?? []) as $app) {
            if (($app['name'] ?? '') === $this->appName) {
                echo "App esistente: {$app['id']} status={$app['status']}\n";
                return (string) $app['id'];
            }
        }

        echo ">>> Creazione node-app PHP\n";
        $payload = [
            'name' => $this->appName,
            'runtime' => 'PHP',
            'repository' => (string) env('COREHOST_GIT_REPO', 'https://github.com/agservizi/staging-business--2-.git'),
            'branch' => (string) env('COREHOST_GIT_BRANCH', 'production'),
            'installCmd' => 'composer install --no-dev --no-interaction',
            'startCmd' => 'php -S 0.0.0.0:8080 -t .',
            'healthPath' => '/',
            'autoDeploy' => true,
            'memoryLimit' => '512m',
            'cpuLimit' => '1',
        ];

        $res = $this->client->request('POST', '/node-apps', $payload);
        echo 'POST /node-apps HTTP ' . $res['status'] . "\n";
        echo substr(json_encode($res['body'], JSON_UNESCAPED_UNICODE), 0, 800) . "\n";

        return (string) ($res['body']['data']['id'] ?? '');
    }

    private function setEnvVars(string $appId): void
    {
        echo "\n>>> Env vars node-app\n";
        $db = $this->client->request('GET', '/databases/' . $this->dbId);
        $dbData = $db['body']['data'] ?? [];
        $dbUser = $dbData['dbUsers'][0] ?? [];

        $vars = [
            'APP_ENV' => 'production',
            'DB_HOST' => (string) ($dbData['host'] ?? ''),
            'DB_PORT' => (string) ($dbData['port'] ?? '23307'),
            'DB_DATABASE' => (string) ($dbData['name'] ?? 'coresuite_business'),
            'DB_USERNAME' => (string) ($dbUser['username'] ?? env('DB_USERNAME', '')),
            'DB_PASSWORD' => (string) ($dbUser['password'] ?? env('DB_PASSWORD', '')),
            'CAF_PATRONATO_ENCRYPTION_KEY' => (string) env('CAF_PATRONATO_ENCRYPTION_KEY', ''),
        ];

        foreach ($vars as $key => $value) {
            if ($value === '') {
                continue;
            }
            try {
                $res = $this->client->request('POST', "/node-apps/{$appId}/env", [
                    'key' => $key,
                    'value' => $value,
                    'isSecret' => in_array($key, ['DB_PASSWORD', 'CAF_PATRONATO_ENCRYPTION_KEY'], true),
                ]);
                echo "  {$key} HTTP {$res['status']}\n";
            } catch (Throwable $e) {
                echo "  {$key} ERR: {$e->getMessage()}\n";
            }
        }
    }

    private function linkWebsiteToApp(string $appId): void
    {
        echo "\n>>> Collegamento website → node-app\n";

        $app = $this->client->request('GET', "/node-apps/{$appId}");
        $port = (int) ($app['body']['data']['port'] ?? 0);
        echo "App port={$port}\n";

        $paths = [
            ['PATCH', "/node-apps/{$appId}", ['websiteId' => $this->websiteId]],
            ['PATCH', '/websites/' . $this->websiteId, [
                'type' => 'REVERSE_PROXY',
                'port' => $port > 0 ? $port : null,
                'buildCmd' => null,
            ]],
        ];

        foreach ($paths as [$method, $path, $body]) {
            try {
                $res = $this->client->request($method, $path, array_filter($body, static fn ($v) => $v !== null));
                echo "{$method} {$path} HTTP {$res['status']}\n";
            } catch (Throwable $e) {
                echo "{$method} {$path} ERR: {$e->getMessage()}\n";
            }
        }
    }

    private function deployApp(string $appId): void
    {
        echo "\n>>> Deploy node-app\n";
        try {
            $res = $this->client->request('POST', "/node-apps/{$appId}/deploy");
            echo "HTTP {$res['status']}\n";
            $status = $res['body']['data']['status'] ?? $res['body']['status'] ?? '';
            echo 'deploy status=' . $status . "\n";
            echo substr(json_encode($res['body'], JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
        } catch (Throwable $e) {
            echo 'deploy ERR: ' . $e->getMessage() . "\n";
        }
    }

    private function requestSsl(): void
    {
        echo "\n>>> SSL\n";
        try {
            $res = $this->client->request('POST', '/ssl', ['domain' => $this->domain, 'websiteId' => $this->websiteId]);
            echo 'POST /ssl HTTP ' . $res['status'] . "\n";
        } catch (Throwable $e) {
            echo 'SSL ERR: ' . $e->getMessage() . "\n";
        }
    }

    private function printFinalStatus(string $appId): void
    {
        echo "\n=== Stato finale ===\n";
        $w = $this->client->request('GET', '/websites/' . $this->websiteId);
        $d = $w['body']['data'] ?? [];
        echo "website: type={$d['type']} status={$d['status']} port={$d['port']} ssl={$d['sslStatus']}\n";
        echo "preview: https://panel.coresuite.it/preview/" . ($d['previewSlug'] ?? '') . "\n";

        $a = $this->client->request('GET', "/node-apps/{$appId}");
        $ad = $a['body']['data'] ?? [];
        echo "app: status={$ad['status']} port={$ad['port']} preview={$ad['previewSlug']}\n";
        echo "preview app: https://panel.coresuite.it/preview/" . ($ad['previewSlug'] ?? '') . "\n";
    }
}

try {
    exit((new BusinessAppDeployer())->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
