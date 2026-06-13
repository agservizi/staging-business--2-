#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Migra/deploy Coresuite Business su CoreHost Panel.
 *
 * Uso:
 *   php tools/corehost_deploy_business.php --probe
 *   php tools/corehost_deploy_business.php --create-app
 *   php tools/corehost_deploy_business.php --deploy-existing <app_id>
 */

require_once __DIR__ . '/corehost_client.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--probe') {
        $options['probe'] = true;
    } elseif ($arg === '--create-app') {
        $options['create-app'] = true;
    } elseif (str_starts_with($arg, '--deploy-existing=')) {
        $options['deploy-existing'] = (int) substr($arg, 18);
    }
}

$client = new CoreHostClient();
if (!$client->hasToken()) {
    fwrite(STDERR, "Imposta COREHOST_API_TOKEN in .env (token chk_... dal pannello)\n");
    exit(1);
}

$repo = (string) env('COREHOST_GIT_REPO', 'https://github.com/agservizi/staging-business--2-.git');
$branch = (string) env('COREHOST_GIT_BRANCH', 'production');
$domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');
$appName = (string) env('COREHOST_APP_NAME', 'coresuite-business');

function print_response(array $res, string $label): void
{
    echo "=== {$label} (HTTP {$res['status']}) ===\n";
    echo json_encode($res['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}

try {
    if (isset($options['probe'])) {
        foreach (['/auth/me', '/sites', '/apps', '/databases'] as $path) {
            try {
                print_response($client->request('GET', $path), $path);
            } catch (Throwable $e) {
                echo "=== {$path} ERRORE ===\n" . $e->getMessage() . "\n\n";
            }
        }
        exit(0);
    }

    if (isset($options['create-app'])) {
        $payload = [
            'name' => $appName,
            'runtime' => 'PHP',
            'repositoryUrl' => $repo,
            'branch' => $branch,
            'domain' => $domain,
            'env' => [
                'APP_URL' => 'https://' . $domain,
                'APP_TIMEZONE' => 'Europe/Rome',
            ],
        ];
        $res = $client->request('POST', '/apps', $payload);
        print_response($res, 'create-app');
        exit($res['status'] >= 200 && $res['status'] < 300 ? 0 : 1);
    }

    if (!empty($options['deploy-existing'])) {
        $id = (int) $options['deploy-existing'];
        $res = $client->request('POST', '/apps/' . $id . '/deploy');
        print_response($res, 'deploy-app');
        exit($res['status'] >= 200 && $res['status'] < 300 ? 0 : 1);
    }

    fwrite(STDERR, "Uso: php tools/corehost_deploy_business.php --probe|--create-app|--deploy-existing=<id>\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
