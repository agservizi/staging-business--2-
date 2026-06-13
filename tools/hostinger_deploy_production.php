#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Automazione deploy produzione:
 * 1) Verifica siti Hostinger via API
 * 2) Avvia GitHub Actions FTP deploy su branch production
 */

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('HOSTINGER_API_TOKEN', ''));
$base = rtrim((string) env('HOSTINGER_API_BASE_URI', 'https://developers.hostinger.com'), '/');
$repo = 'agservizi/staging-business--2-';

if ($token === '') {
    fwrite(STDERR, "HOSTINGER_API_TOKEN mancante in .env\n");
    exit(1);
}

function api_get(string $base, string $token, string $path): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => json_decode((string) $raw, true),
        'raw' => (string) $raw,
    ];
}

echo "=== Hostinger: siti coresuite/business ===\n";
$page = 1;
$foundBusiness = false;
$foundDemo = false;

do {
    $res = api_get($base, $token, '/api/hosting/v1/websites?page=' . $page . '&per_page=50');
    if ($res['status'] !== 200) {
        fwrite(STDERR, 'API websites HTTP ' . $res['status'] . "\n");
        break;
    }
    $items = $res['body']['data'] ?? [];
    foreach ($items as $website) {
        $domain = (string) ($website['domain'] ?? '');
        $root = (string) ($website['root_directory'] ?? '');
        if (stripos($domain, 'business') !== false || stripos($domain, 'coresuite') !== false) {
            echo $domain . ' -> ' . $root . "\n";
        }
        if ($domain === 'business.coresuite.it') {
            $foundBusiness = true;
        }
        if ($domain === 'demobusiness.coresuite.it') {
            $foundDemo = true;
        }
    }
    $lastPage = (int) ($res['body']['meta']['last_page'] ?? $page);
    $page++;
} while ($page <= $lastPage && $page <= 10);

echo "\n";
if (!$foundBusiness) {
    echo "ATTENZIONE: business.coresuite.it non risulta come vhost separato in API.\n";
    echo "Deploy FTP target atteso: /public_html/business/\n";
}
if ($foundDemo) {
    echo "NOTA: demobusiness.coresuite.it esiste come vhost separato (staging/demo).\n";
}

echo "\n=== GitHub Actions: deploy production ===\n";
$gh = trim((string) shell_exec('gh --version 2>&1'));
if ($gh === '') {
    fwrite(STDERR, "gh CLI non disponibile.\n");
    exit(1);
}

$cmd = 'gh workflow run "Deploy to Hostinger" --ref production -R ' . escapeshellarg($repo) . ' 2>&1';
echo '$ ' . $cmd . "\n";
passthru($cmd, $code);
if ($code !== 0) {
    exit($code);
}

sleep(3);
$runs = shell_exec('gh run list --workflow=deploy.yml -R ' . escapeshellarg($repo) . ' --limit 3 2>&1');
echo (string) $runs . "\n";
echo "Monitor: gh run watch -R {$repo}\n";
