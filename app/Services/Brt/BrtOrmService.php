<?php
declare(strict_types=1);

namespace App\Services\Brt;

use function is_array;
use function is_string;
use function rawurlencode;
use function sprintf;
use function trim;

final class BrtOrmService
{
    private BrtConfig $config;

    private BrtHttpClient $client;

    public function __construct(?BrtConfig $config = null)
    {
        $this->config = $config ?? new BrtConfig();
        $headers = [];
        $apiKey = $this->config->getOrmApiKey();
        if ($apiKey !== null && $apiKey !== '') {
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }
        $this->client = new BrtHttpClient($this->config->getOrmBaseUrl(), $headers, $this->config->getCaBundlePath());
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    public function createOrders(array $orders): array
    {
        if (!$this->hasApiKey()) {
            throw new BrtException('Configurare BRT_ORM_API_KEY (o BRT_API_KEY di fallback) per utilizzare le API ORM.');
        }

        if ($orders === [] || !$this->isSequentialArray($orders)) {
            throw new BrtException('La richiesta ORM deve contenere un array di ordini valido.');
        }

        $response = $this->client->request('POST', '/colreqs', null, $orders);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']) ?: sprintf('Creazione ordine di ritiro BRT non riuscita (HTTP %d).', $response['status']);
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body) || !$this->isSequentialArray($body)) {
            throw new BrtException('Risposta inattesa dal servizio ORM BRT.');
        }

        foreach ($body as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['errors']) && is_array($item['errors']) && $item['errors'] !== []) {
                $firstError = $item['errors'][0] ?? null;
                if (is_array($firstError)) {
                    $message = (string) ($firstError['message'] ?? 'Errore nella creazione dell\'ordine di ritiro.');
                    throw new BrtException($message);
                }
            }
        }

        return $body;
    }

    public function cancelOrder(string $reservationNumber): bool
    {
        if (!$this->hasApiKey()) {
            throw new BrtException('Configurare BRT_ORM_API_KEY (o BRT_API_KEY di fallback) per utilizzare le API ORM.');
        }

        $reservationNumber = $this->sanitizeReservationNumber($reservationNumber);

        $response = $this->client->request('DELETE', '/colreqs/' . rawurlencode($reservationNumber));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']) ?: sprintf('Cancellazione ORM BRT fallita (HTTP %d).', $response['status']);
            throw new BrtException($message);
        }

        $body = $response['body'];
        if ($body === null) {
            // Alcune implementazioni restituiscono boolean nel body, altre no.
            return true;
        }
        if (is_array($body)) {
            if (isset($body['success'])) {
                return (bool) $body['success'];
            }
            if (isset($body['result'])) {
                return (bool) $body['result'];
            }
        }

        if ($body === 'true') {
            return true;
        }
        if ($body === 'false') {
            return false;
        }

        return (bool) $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $reservationNumber): array
    {
        if (!$this->hasApiKey()) {
            throw new BrtException('Configurare BRT_ORM_API_KEY (o BRT_API_KEY di fallback) per utilizzare le API ORM.');
        }

        $reservationNumber = $this->sanitizeReservationNumber($reservationNumber);

        $response = $this->client->request('GET', '/colreqs/' . rawurlencode($reservationNumber));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']) ?: sprintf('Recupero prenotazione ORM BRT non riuscito (HTTP %d).', $response['status']);
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body)) {
            throw new BrtException('Risposta inattesa durante il recupero della prenotazione ORM BRT.');
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function updateOrder(string $reservationNumber, array $order): array
    {
        if (!$this->hasApiKey()) {
            throw new BrtException('Configurare BRT_ORM_API_KEY (o BRT_API_KEY di fallback) per utilizzare le API ORM.');
        }

        if ($order === []) {
            throw new BrtException('Il payload di aggiornamento ORM non può essere vuoto.');
        }

        $reservationNumber = $this->sanitizeReservationNumber($reservationNumber);

        $response = $this->client->request('PUT', '/colreqs/' . rawurlencode($reservationNumber), null, $order);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']) ?: sprintf('Aggiornamento prenotazione ORM BRT non riuscito (HTTP %d).', $response['status']);
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body)) {
            throw new BrtException('Risposta inattesa durante l\'aggiornamento della prenotazione ORM BRT.');
        }

        return $body;
    }

    /**
     * @param array<mixed> $items
     */
    private function isSequentialArray(array $items): bool
    {
        if ($items === []) {
            return true;
        }
        $expectedKeys = range(0, count($items) - 1);
        return array_keys($items) === $expectedKeys;
    }

    private function hasApiKey(): bool
    {
        $apiKey = $this->config->getOrmApiKey();
        return $apiKey !== null && $apiKey !== '';
    }

    private function sanitizeReservationNumber(string $reservationNumber): string
    {
        $trimmed = trim($reservationNumber);
        if ($trimmed === '') {
            throw new BrtException('Il numero di prenotazione ORM è obbligatorio.');
        }
        return $trimmed;
    }

    /**
     * @param mixed $body
     */
    private function extractErrorMessage($body): ?string
    {
        if (is_array($body)) {
            if (isset($body['message'])) {
                return (string) $body['message'];
            }
            if (isset($body[0]) && is_array($body[0]) && isset($body[0]['errors'])) {
                $errors = $body[0]['errors'];
                if (is_array($errors) && isset($errors[0]['message'])) {
                    return (string) $errors[0]['message'];
                }
            }
        }

        if (is_string($body)) {
            return $body;
        }

        return null;
    }
}
