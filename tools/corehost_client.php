<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

final class CoreHostClient
{
    private string $baseUrl;
    private string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) env('COREHOST_API_BASE_URL', 'https://panel.coresuite.it'), '/');
        $this->token = trim($token ?? (string) env('COREHOST_API_TOKEN', ''));
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    /**
     * @return array{status:int,body:mixed,raw:string}
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->token === '') {
            throw new RuntimeException('COREHOST_API_TOKEN mancante');
        }

        $url = $this->baseUrl . '/api/v1' . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init failed');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ca = realpath(__DIR__ . '/../certs/cacert.pem');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => true,
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
            throw new RuntimeException('CoreHost API error: ' . $err);
        }

        return [
            'status' => $status,
            'body' => json_decode((string) $raw, true),
            'raw' => (string) $raw,
        ];
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $client = new CoreHostClient();
    $command = $argv[1] ?? 'sites';

    try {
        switch ($command) {
            case 'sites':
                $res = $client->request('GET', '/sites');
                break;
            case 'apps':
                $res = $client->request('GET', '/apps');
                break;
            case 'me':
                $res = $client->request('GET', '/auth/me');
                break;
            case 'deploy-app':
                $appId = (int) ($argv[2] ?? 0);
                if ($appId <= 0) {
                    throw new RuntimeException('Usage: php tools/corehost_client.php deploy-app <app_id>');
                }
                $res = $client->request('POST', '/apps/' . $appId . '/deploy');
                break;
            default:
                throw new RuntimeException('Comandi: sites | apps | me | deploy-app <id>');
        }

        echo 'HTTP ' . $res['status'] . PHP_EOL;
        echo json_encode($res['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit($res['status'] >= 200 && $res['status'] < 300 ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
