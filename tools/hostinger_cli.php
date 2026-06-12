<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('HOSTINGER_API_TOKEN', ''));
if ($token === '') {
    fwrite(STDERR, "HOSTINGER_API_TOKEN mancante.\n");
    exit(1);
}

$command = $argv[1] ?? 'vms';
$base = rtrim((string) env('HOSTINGER_API_BASE_URI', 'https://developers.hostinger.com'), '/');

function hostinger_request(string $base, string $token, string $method, string $path, ?array $body = null): array
{
    $url = $base . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl init failed');
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $ca = realpath(__DIR__ . '/../certs/cacert.pem');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($ca !== false) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Hostinger API error: ' . $err);
    }

    $decoded = json_decode((string) $raw, true);
    return [
        'status' => $status,
        'body' => $decoded ?? $raw,
        'raw' => (string) $raw,
    ];
}

try {
    switch ($command) {
        case 'vms':
            $res = hostinger_request($base, $token, 'GET', '/api/vps/v1/virtual-machines');
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            break;

        case 'docker-projects':
            $vmId = (int) ($argv[2] ?? 0);
            if ($vmId <= 0) {
                throw new RuntimeException('Usage: php tools/hostinger_cli.php docker-projects <vm_id>');
            }
            $res = hostinger_request($base, $token, 'GET', '/api/vps/v1/virtual-machines/' . $vmId . '/docker');
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            break;

        case 'deploy':
            $vmId = (int) ($argv[2] ?? 0);
            $repo = trim((string) ($argv[3] ?? 'https://github.com/agservizi/staging-business--2-.git'));
            if ($vmId <= 0) {
                throw new RuntimeException('Usage: php tools/hostinger_cli.php deploy <vm_id> [git_url]');
            }
            $payload = [
                'url' => $repo,
                'branch' => 'main',
            ];
            $res = hostinger_request($base, $token, 'POST', '/api/vps/v1/virtual-machines/' . $vmId . '/docker', $payload);
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            break;

        default:
            fwrite(STDERR, "Comandi: vms | docker-projects <vm_id> | deploy <vm_id> [git_url]\n");
            exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
