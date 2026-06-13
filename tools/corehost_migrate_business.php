#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Migra business.coresuite.it su CoreHost Panel.
 * Uso: php tools/corehost_migrate_business.php [--dry-run]
 */

require_once __DIR__ . '/corehost_client.php';

$dryRun = in_array('--dry-run', $argv, true);
$domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');
$dbName = (string) env('COREHOST_DB_NAME', 'coresuite_business');
$repo = (string) env('COREHOST_GIT_REPO', 'https://github.com/agservizi/staging-business--2-.git');
$branch = (string) env('COREHOST_GIT_BRANCH', 'production');

$client = new CoreHostClient();

function step(string $label): void
{
    echo "\n>>> {$label}\n";
}

function existing_website(CoreHostClient $client, string $domain): ?array
{
    $res = $client->request('GET', '/websites');
    foreach (($res['body']['data'] ?? []) as $site) {
        if (is_array($site) && (($site['domain'] ?? '') === $domain)) {
            return $site;
        }
    }
    return null;
}

try {
    step('Verifica account');
    $me = $client->request('GET', '/auth/me');
    echo 'Utente: ' . ($me['body']['data']['email'] ?? '?') . ' (' . ($me['body']['data']['role'] ?? '?') . ")\n";

    step('Cerca sito ' . $domain);
    $website = existing_website($client, $domain);
    if ($website) {
        echo 'Sito già presente: id=' . ($website['id'] ?? '?') . "\n";
    } else {
        $payload = [
            'domain' => $domain,
            'type' => 'PHP',
            'phpVersion' => '8.4',
            'gitRepo' => $repo,
            'gitBranch' => $branch,
            'notes' => 'Coresuite Business WOW production',
        ];
        echo 'Payload create website: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
        if ($dryRun) {
            echo "[dry-run] skip POST /websites\n";
        } else {
            $created = $client->request('POST', '/websites', $payload);
            echo 'HTTP ' . $created['status'] . "\n";
            echo json_encode($created['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            $website = $created['body']['data'] ?? null;
        }
    }

    if (!$dryRun && is_array($website) && !empty($website['id'])) {
        step('Deploy sito');
        $deploy = $client->request('POST', '/websites/' . $website['id'] . '/deploy');
        echo 'HTTP ' . $deploy['status'] . "\n";
        echo json_encode($deploy['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    step('Database MySQL');
    $dbs = $client->request('GET', '/databases');
    $hasDb = false;
    foreach (($dbs['body']['data'] ?? []) as $db) {
        if (is_array($db) && (($db['name'] ?? '') === $dbName)) {
            $hasDb = true;
            echo "DB già presente: {$dbName} ({$db['type']})\n";
            break;
        }
    }
    if (!$hasDb && !$dryRun && is_array($website) && !empty($website['id'])) {
        $dbPayload = [
            'name' => $dbName,
            'type' => 'MYSQL',
            'websiteId' => $website['id'],
        ];
        $dbRes = $client->request('POST', '/databases', $dbPayload);
        echo 'HTTP ' . $dbRes['status'] . "\n";
        echo json_encode($dbRes['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } elseif (!$hasDb) {
        echo "[dry-run] creerei DB MySQL {$dbName}\n";
    }

    step('Prossimi passi DNS');
    echo "- In Cloudflare: punta {$domain} al tunnel CoreHost (o IP del nodo 192.168.1.50 via tunnel)\n";
    echo "- Importa dump Hostinger in MySQL CoreHost\n";
    echo "- php tools/migrate.php sul container/sito\n";
    echo "- Verifica: https://{$domain}/assets/js/staff-notifications.js\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
