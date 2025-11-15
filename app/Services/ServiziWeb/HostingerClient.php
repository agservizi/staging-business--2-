<?php
declare(strict_types=1);

namespace App\Services\ServiziWeb;

use RuntimeException;

class HostingerClient
{
    private string $baseUri;
    private string $token;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;

    public function __construct(string $token, ?string $baseUri = null, ?array $options = null)
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Hostinger API token mancante.');
        }

        $defaultBase = 'https://developers.hostinger.com';
        if (function_exists('env')) {
            $defaultBase = (string) (env('HOSTINGER_API_BASE_URI', $defaultBase) ?: $defaultBase);
        }

        $this->token = $token;
        $this->baseUri = rtrim($baseUri !== null ? $baseUri : $defaultBase, '/');
        $options = $options ?? [];
        $timeout = (int) ($options['timeout'] ?? 30);
        $this->timeout = $timeout > 0 ? $timeout : 30;
        $this->verifySsl = !array_key_exists('verify_ssl', $options) || (bool) $options['verify_ssl'];
        $caBundle = isset($options['ca_path']) ? trim((string) $options['ca_path']) : '';
        $this->caBundle = $caBundle !== '' ? $caBundle : null;
    }

    public function checkDomainAvailability(array $domains): array
    {
        $sanitized = array_values(array_filter(array_map(static function ($domain) {
            $domain = strtolower(trim((string) $domain));
            return $domain !== '' ? $domain : null;
        }, $domains)));

        if (!$sanitized) {
            throw new RuntimeException('Nessun dominio fornito per la verifica.');
        }

        $results = [];

        foreach ($sanitized as $fullDomain) {
            $parts = explode('.', $fullDomain, 2);
            if (count($parts) !== 2) {
                throw new RuntimeException('Dominio non valido: ' . $fullDomain);
            }

            [$name, $tld] = $parts;
            $name = trim($name);
            $tld = trim($tld);

            if ($name === '' || $tld === '') {
                throw new RuntimeException('Dominio non valido: ' . $fullDomain);
            }

            $payload = [
                'domain' => $name,
                'tlds' => [$tld],
            ];

            $response = $this->request('POST', '/api/domains/v1/availability', $payload);

            if (!is_array($response)) {
                continue;
            }

            foreach ($response as $item) {
                if (is_array($item)) {
                    if (!isset($item['domain'])) {
                        $item['domain'] = $fullDomain;
                    }
                    $results[] = $item;
                }
            }
        }

        return $results;
    }

    public function listDatacenters(): array
    {
        $response = $this->request('GET', '/api/hosting/v1/datacenters');

        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return is_array($response) ? $response : [];
    }

    public function listCatalog(?string $category = null): array
    {
        $params = null;
        if ($category !== null && $category !== '') {
            $params = ['category' => $category];
        }

        $response = $this->request('GET', '/api/billing/v1/catalog', $params);

        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return is_array($response) ? $response : [];
    }

    public function createOrder(array $payload): array
    {
        if (empty($payload['items'])) {
            throw new RuntimeException('Nessuna voce fornita per la creazione dell\'ordine.');
        }

        $response = $this->request('POST', '/api/billing/v1/orders', $payload);

        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return is_array($response) ? $response : [];
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUri . '/' . ltrim($path, '/');
        $method = strtoupper($method);

        if ($method === 'GET' && $payload) {
            $query = http_build_query($payload);
            if ($query !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $query;
            }
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta Hostinger.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($this->verifySsl === false) {
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $encoded = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : '';
            if ($encoded === false) {
                curl_close($handle);
                throw new RuntimeException('Impossibile serializzare il payload della richiesta.');
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle) ?: 'Errore sconosciuto';
            curl_close($handle);
            throw new RuntimeException('Richiesta Hostinger fallita: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = $response === '' ? [] : json_decode($response, true);
        if ($response !== '' && ($decoded === null || !is_array($decoded)) && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Risposta Hostinger non valida o non in formato JSON.');
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error'] ?? $decoded['message'] ?? 'Status ' . $status) : 'Status ' . $status;
            throw new RuntimeException('Errore Hostinger: ' . $message, $status);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
