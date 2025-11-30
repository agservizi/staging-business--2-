<?php
declare(strict_types=1);

namespace App\Services;

use CurlHandle;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

use function base_url;
use function env;
use function error_log;
use function filter_var;
use function function_exists;
use function json_decode;
use function json_encode;
use function preg_match;
use function sprintf;
use function strlen;
use function trim;

use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_EMAIL;

class GoogleCalendarService
{
    public const CONFIRMED_STATUS = 'Confermato';

    public const ACTIVE_STATUSES = ['Programmato', self::CONFIRMED_STATUS, 'In corso'];

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar';

    private bool $enabled;

    private ?string $calendarId;

    private ?string $credentialsPath;

    private ?string $credentialsJson;

    private string $timeZone;

    private ?string $impersonateUser;

    private int $defaultDurationMinutes;

    private bool $inviteClient;

    private ?string $colorId;

    private string $sendUpdates;

    private ?string $caBundlePath;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $credentials = null;

    private ?string $accessToken = null;

    private ?int $accessTokenExpiresAt = null;

    public function __construct()
    {
        $this->enabled = filter_var(env('GOOGLE_CALENDAR_ENABLED', false), FILTER_VALIDATE_BOOL) === true;
        $this->calendarId = $this->clean(env('GOOGLE_CALENDAR_CALENDAR_ID') ?? env('GOOGLE_CALENDAR_CALENDAR_EMAIL'));
        $this->credentialsPath = $this->clean(env('GOOGLE_CALENDAR_CREDENTIALS_PATH'));
        $this->credentialsJson = $this->clean(env('GOOGLE_CALENDAR_CREDENTIALS_JSON'));
        $timeZone = $this->clean(env('GOOGLE_CALENDAR_TIMEZONE', date_default_timezone_get()));
        $this->timeZone = $timeZone ?: (date_default_timezone_get() ?: 'UTC');
        $this->impersonateUser = $this->clean(env('GOOGLE_CALENDAR_IMPERSONATE'));
    $this->caBundlePath = $this->clean(env('GOOGLE_CALENDAR_CA_BUNDLE'));

        $duration = (int) env('GOOGLE_CALENDAR_DEFAULT_DURATION', 60);
        $duration = max(15, min($duration, 480));
        $this->defaultDurationMinutes = $duration;

        $this->inviteClient = filter_var(env('GOOGLE_CALENDAR_INVITE_CLIENT', false), FILTER_VALIDATE_BOOL) === true;
        $this->colorId = $this->clean(env('GOOGLE_CALENDAR_COLOR_ID'));

        $sendUpdatesRaw = strtolower($this->clean(env('GOOGLE_CALENDAR_SEND_UPDATES', 'none')) ?? 'none');
        if (!in_array($sendUpdatesRaw, ['all', 'externalonly', 'none'], true)) {
            $sendUpdatesRaw = 'none';
        }
        $this->sendUpdates = $sendUpdatesRaw === 'externalonly' ? 'externalOnly' : $sendUpdatesRaw;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    public function isEnabled(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->calendarId === null || $this->calendarId === '') {
            return false;
        }

        try {
            $this->ensureCredentialsLoaded();
        } catch (RuntimeException $exception) {
            error_log('Google Calendar non configurato: ' . $exception->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $appointment
     * @return array{eventId:string,syncedAt:DateTimeImmutable,action:string}
     */
    public function syncAppointment(array $appointment): array
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('Google Calendar non è configurato.');
        }

        $this->ensureCredentialsLoaded();

        $start = $this->parseDateTime($appointment['data_inizio'] ?? null);
        if ($start === null) {
            throw new RuntimeException('Data di inizio appuntamento non valida.');
        }
        $end = $this->parseDateTime($appointment['data_fine'] ?? null);
        if ($end === null) {
            $end = $start->add(new DateInterval('PT' . $this->defaultDurationMinutes . 'M'));
        }

    $payload = $this->buildEventPayload($appointment, $start, $end);
    $canFallbackAttendees = isset($payload['attendees']);

        $calendarId = rawurlencode($this->calendarId ?? 'primary');
        $existingEventId = $this->clean($appointment['google_event_id'] ?? null);

        $queryParams = [];
        if ($this->sendUpdates !== 'none') {
            $queryParams['sendUpdates'] = $this->sendUpdates;
        }

        $syncedAt = new DateTimeImmutable('now');

        if ($existingEventId !== null) {
            $patchUrl = sprintf('https://www.googleapis.com/calendar/v3/calendars/%s/events/%s', $calendarId, rawurlencode($existingEventId));
            $response = $this->makeRequest('PATCH', $patchUrl, $payload, $queryParams);
            if ($canFallbackAttendees && $this->isAttendeePermissionErrorResponse($response)) {
                $canFallbackAttendees = false;
                unset($payload['attendees'], $payload['guestsCanSeeOtherGuests']);
                error_log('Google Calendar: rimozione invitati per mancanza delega di dominio.');
                $response = $this->makeRequest('PATCH', $patchUrl, $payload, $queryParams);
            }
            if ($response['status'] === 404) {
                $existingEventId = null;
            } elseif ($response['status'] >= 200 && $response['status'] < 300) {
                $eventId = (string) ($response['body']['id'] ?? $existingEventId);
                return [
                    'eventId' => $eventId,
                    'syncedAt' => $syncedAt,
                    'action' => 'updated',
                ];
            } else {
                $message = $this->extractErrorMessage($response['body'], $response['raw']);
                throw new RuntimeException($message ?: 'Aggiornamento evento Google Calendar non riuscito.');
            }
        }

        $postUrl = sprintf('https://www.googleapis.com/calendar/v3/calendars/%s/events', $calendarId);
        $response = $this->makeRequest('POST', $postUrl, $payload, $queryParams);
        if ($canFallbackAttendees && $this->isAttendeePermissionErrorResponse($response)) {
            unset($payload['attendees'], $payload['guestsCanSeeOtherGuests']);
            error_log('Google Calendar: creazione evento senza invitati per mancanza delega di dominio.');
            $response = $this->makeRequest('POST', $postUrl, $payload, $queryParams);
        }
        if ($response['status'] >= 200 && $response['status'] < 300) {
            $eventId = (string) ($response['body']['id'] ?? '');
            if ($eventId === '') {
                throw new RuntimeException('Risposta Google Calendar priva di eventId.');
            }
            return [
                'eventId' => $eventId,
                'syncedAt' => $syncedAt,
                'action' => 'created',
            ];
        }

        $message = $this->extractErrorMessage($response['body'], $response['raw']);
        throw new RuntimeException($message ?: 'Creazione evento Google Calendar non riuscita.');
    }

    /**
     * @param array<string, mixed> $appointment
     */
    public function removeAppointmentEvent(array $appointment): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $eventId = $this->clean($appointment['google_event_id'] ?? null);
        if ($eventId === null) {
            return;
        }

        $calendarId = rawurlencode($this->calendarId ?? 'primary');
        $deleteUrl = sprintf(
            'https://www.googleapis.com/calendar/v3/calendars/%s/events/%s',
            $calendarId,
            rawurlencode($eventId)
        );

        $queryParams = [];
        if ($this->sendUpdates !== 'none') {
            $queryParams['sendUpdates'] = $this->sendUpdates;
        }

        $response = $this->makeRequest('DELETE', $deleteUrl, null, $queryParams);
        if ($response['status'] === 404) {
            return;
        }
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return;
        }

        $message = $this->extractErrorMessage($response['body'], $response['raw']);
        throw new RuntimeException($message ?: 'Eliminazione evento Google Calendar non riuscita.');
    }

    /**
     * @return array{status:int,body:array<string, mixed>,raw:string}
     */
    private function makeRequest(string $method, string $url, ?array $payload = null, array $query = []): array
    {
        $accessToken = $this->getAccessToken();

        $queryString = $query ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        $targetUrl = $url . ($queryString !== '' ? '?' . $queryString : '');

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];
        $body = null;
        if ($payload !== null) {
            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException('Payload JSON non valido: ' . $exception->getMessage());
            }
            $headers[] = 'Content-Type: application/json';
        }

        $handle = curl_init($targetUrl);
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta HTTP verso Google Calendar.');
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 15);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $this->configureCurlCaBundle($handle);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            if ($this->caBundlePath === null && str_contains($error, 'SSL certificate')) {
                $error .= ' — configura GOOGLE_CALENDAR_CA_BUNDLE con il percorso di un file cacert.pem valido.';
            }

            throw new RuntimeException('Errore di rete nella comunicazione con Google Calendar: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = [];
        if ($responseBody !== '') {
            try {
                /** @var array<string, mixed> $decodedResponse */
                $decodedResponse = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                $decoded = $decodedResponse;
            } catch (JsonException $exception) {
                $decoded = ['error' => ['message' => 'Impossibile interpretare la risposta JSON: ' . $exception->getMessage()]];
            }
        }

        return [
            'status' => $status,
            'body' => $decoded,
            'raw' => $responseBody,
        ];
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && $this->accessTokenExpiresAt !== null && ($this->accessTokenExpiresAt - 60) > time()) {
            return $this->accessToken;
        }

        $credentials = $this->ensureCredentialsLoaded();
        $jwt = $this->createSignedJwt($credentials);
        $response = $this->requestAccessToken($jwt);

        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Risposta token Google Calendar priva di access_token.');
        }

        $expiresIn = (int) ($response['expires_in'] ?? 3600);
        $this->accessToken = $token;
        $this->accessTokenExpiresAt = time() + max(60, $expiresIn);
        return $token;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function createSignedJwt(array $credentials): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => self::CALENDAR_SCOPE,
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        if ($this->impersonateUser) {
            $claims['sub'] = $this->impersonateUser;
        }

        try {
            $headerJson = json_encode($header, JSON_THROW_ON_ERROR);
            $claimsJson = json_encode($claims, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Impossibile serializzare il token JWT: ' . $exception->getMessage());
        }

        $segments = [
            $this->base64UrlEncode($headerJson),
            $this->base64UrlEncode($claimsJson),
        ];

        $privateKey = $this->normalizePrivateKey((string) $credentials['private_key']);
        $signatureInput = implode('.', $segments);
        $signature = '';
        $success = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new RuntimeException('Impossibile firmare il token JWT per Google Calendar.');
        }

        $segments[] = $this->base64UrlEncode($signature);
        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestAccessToken(string $jwt): array
    {
        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ], '', '&', PHP_QUERY_RFC3986);

        $handle = curl_init(self::TOKEN_ENDPOINT);
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta token Google.');
        }

        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 15);
        curl_setopt($handle, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $postFields);

        $this->configureCurlCaBundle($handle);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            if ($this->caBundlePath === null && str_contains($error, 'SSL certificate')) {
                $error .= ' — configura GOOGLE_CALENDAR_CA_BUNDLE con il percorso di un file cacert.pem valido.';
            }

            throw new RuntimeException('Errore durante il recupero del token Google: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Risposta token non valida: ' . $exception->getMessage());
        }

        if ($status < 200 || $status >= 300) {
            $message = $this->extractErrorMessage($decoded, $responseBody) ?: 'Richiesta token Google Calendar fallita.';
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function configureCurlCaBundle(CurlHandle $handle): void
    {
        if ($this->caBundlePath === null) {
            return;
        }

        $path = $this->caBundlePath;
        if (is_file($path)) {
            curl_setopt($handle, CURLOPT_CAINFO, $path);
        } elseif (is_dir($path)) {
            curl_setopt($handle, CURLOPT_CAPATH, $path);
        }
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function buildEventPayload(array $appointment, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $summary = trim((string) ($appointment['titolo'] ?? ''));
        if ($summary === '') {
            $summary = sprintf('Appuntamento #%d', (int) ($appointment['id'] ?? 0));
        }

        $descriptionLines = [];
        $clientName = $this->buildClientName($appointment);
        $descriptionLines[] = 'Cliente: ' . $clientName;
        $clientEmail = $this->clean($appointment['cliente_email'] ?? null);
        if ($clientEmail) {
            $descriptionLines[] = 'Email cliente: ' . $clientEmail;
        }
        $serviceType = $this->clean($appointment['tipo_servizio'] ?? null);
        if ($serviceType) {
            $descriptionLines[] = 'Tipologia: ' . $serviceType;
        }
        $responsabile = $this->clean($appointment['responsabile'] ?? null);
        if ($responsabile) {
            $descriptionLines[] = 'Responsabile interno: ' . $responsabile;
        }
        $note = $this->clean($appointment['note'] ?? null);
        if ($note) {
            $descriptionLines[] = '';
            $descriptionLines[] = 'Note:';
            $descriptionLines[] = $note;
        }

        $description = trim(implode("\n", array_filter($descriptionLines, static fn($line) => $line !== null)));

        $timeZone = new DateTimeZone($this->timeZone);
        $startLocal = $start->setTimezone($timeZone);
        $endLocal = $end->setTimezone($timeZone);

        $payload = [
            'summary' => $summary,
            'start' => [
                'dateTime' => $startLocal->format(DateTimeInterface::RFC3339),
                'timeZone' => $this->timeZone,
            ],
            'end' => [
                'dateTime' => $endLocal->format(DateTimeInterface::RFC3339),
                'timeZone' => $this->timeZone,
            ],
            'status' => 'confirmed',
            'visibility' => 'private',
            'extendedProperties' => [
                'private' => [
                    'coresuiteAppointmentId' => (string) ($appointment['id'] ?? ''),
                ],
            ],
        ];

        if ($description !== '') {
            $payload['description'] = $description;
        }

        $location = $this->clean($appointment['luogo'] ?? null);
        if ($location) {
            $payload['location'] = $location;
        }

        if ($this->colorId) {
            $payload['colorId'] = $this->colorId;
        }

        if ($this->inviteClient && $clientEmail && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $payload['attendees'] = [[
                'email' => $clientEmail,
                'displayName' => $clientName,
            ]];
            $payload['guestsCanSeeOtherGuests'] = false;
        }

        if (function_exists('base_url')) {
            $sourceUrl = base_url('modules/servizi/appuntamenti/view.php?id=' . (int) ($appointment['id'] ?? 0));
            $payload['source'] = [
                'title' => 'Coresuite Business',
                'url' => $sourceUrl,
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function buildClientName(array $appointment): string
    {
        $company = $this->clean($appointment['cliente_ragione_sociale'] ?? null);
        if ($company) {
            return $company;
        }

        $first = $this->clean($appointment['cliente_nome'] ?? null) ?? '';
        $last = $this->clean($appointment['cliente_cognome'] ?? null) ?? '';
        $full = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }

        return 'Cliente';
    }

    private function parseDateTime($value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value, new DateTimeZone($this->timeZone));
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizePrivateKey(string $key): string
    {
        if (str_contains($key, '\\n')) {
            $key = str_replace('\\n', "\n", $key);
        }
        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        $encoded = base64_encode($value);
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    private function extractErrorMessage(array $response, string $raw): ?string
    {
        if (isset($response['error']) && is_array($response['error'])) {
            $error = $response['error'];
            if (isset($error['errors']) && is_array($error['errors'])) {
                foreach ($error['errors'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $reason = isset($item['reason']) && is_string($item['reason']) ? strtolower($item['reason']) : '';
                    if ($reason === 'notfound') {
                        return 'Calendario Google non trovato oppure accesso non autorizzato. Condividi il calendario con l\'account di servizio o verifica GOOGLE_CALENDAR_CALENDAR_ID.';
                    }
                }
            }
            if (isset($error['code']) && (int) $error['code'] === 404) {
                return 'Calendario Google non trovato oppure accesso non autorizzato. Condividi il calendario con l\'account di servizio o verifica GOOGLE_CALENDAR_CALENDAR_ID.';
            }
        }
        if (isset($response['error']['message']) && is_string($response['error']['message'])) {
            return $response['error']['message'];
        }
        if (isset($response['error_description']) && is_string($response['error_description'])) {
            return $response['error_description'];
        }
        if ($raw !== '') {
            return 'Google Calendar API error: ' . substr($raw, 0, 240);
        }
        return null;
    }

    /**
     * @param array{status:int,body:array<string, mixed>,raw:string} $response
     */
    private function isAttendeePermissionErrorResponse(array $response): bool
    {
        if (!$this->inviteClient || empty($response['body'])) {
            return false;
        }

        return $this->isAttendeePermissionError($response['body'], $response['raw'] ?? '');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function isAttendeePermissionError(array $body, string $raw): bool
    {
        $message = '';
        if (isset($body['error']['message']) && is_string($body['error']['message'])) {
            $message = $body['error']['message'];
        }
        if ($message !== '') {
            $lower = strtolower($message);
            if (str_contains($lower, 'service accounts cannot invite attendees')) {
                return true;
            }
        }

        if (isset($body['error']['errors']) && is_array($body['error']['errors'])) {
            foreach ($body['error']['errors'] as $errorItem) {
                if (!is_array($errorItem)) {
                    continue;
                }
                $reason = isset($errorItem['reason']) && is_string($errorItem['reason']) ? strtolower($errorItem['reason']) : '';
                if ($reason === 'forbidden' && isset($errorItem['message']) && is_string($errorItem['message'])) {
                    if (str_contains(strtolower($errorItem['message']), 'service accounts cannot invite attendees')) {
                        return true;
                    }
                }
            }
        }

        if ($raw !== '') {
            $lowRaw = strtolower($raw);
            if (str_contains($lowRaw, 'service accounts cannot invite attendees')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureCredentialsLoaded(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $json = null;
        if ($this->credentialsPath && is_file($this->credentialsPath)) {
            $json = file_get_contents($this->credentialsPath) ?: null;
        }
        if ($json === null && $this->credentialsJson !== null) {
            $jsonCandidate = $this->credentialsJson;
            $decoded = null;
            if ($this->looksLikeBase64($jsonCandidate)) {
                $decoded = base64_decode($jsonCandidate, true);
            }

            if ($decoded !== null && $decoded !== false) {
                $json = $decoded;
            } else {
                $json = str_replace('\n', "\n", $jsonCandidate);
            }
        }

        if ($json === null) {
            throw new RuntimeException('File di credenziali Google Calendar non trovato.');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Impossibile leggere il file di credenziali Google: ' . $exception->getMessage());
        }

        if (empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new RuntimeException('Credenziali Google Calendar mancanti di client_email o private_key.');
        }

        $this->credentials = $decoded;
        return $decoded;
    }

    private function looksLikeBase64(string $value): bool
    {
        $length = strlen($value);
        if ($length === 0 || ($length % 4) !== 0) {
            return false;
        }

        return (bool) preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $value);
    }
}
