<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use RuntimeException;

final class CoresuiteExpressClient
{
    private Client $client;
    private string $baseUrl;
    private ?string $token;

    public function __construct(?Client $client = null)
    {
        $this->baseUrl = rtrim((string) env('CORESUITE_BASE_URL', ''), '/');
        if ($this->baseUrl === '') {
            throw new RuntimeException('CORESUITE_BASE_URL non configurato.');
        }

        $this->client = $client ?? new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => (float) env('CORESUITE_TIMEOUT', 15),
            'verify' => (bool) env('CORESUITE_VERIFY_SSL', true),
            'headers' => [
                'User-Agent' => 'CoresuiteBusiness/1.0',
                'Accept' => 'application/json',
            ],
        ]);

        $this->token = null;

        // Auto-login se credenziali disponibili
        $username = (string) env('CORESUITE_USERNAME', '');
        $password = (string) env('CORESUITE_PASSWORD', '');
        if ($username !== '' && $password !== '') {
            $this->login($username, $password);
        }
    }

    /**
     * Effettua login e ottiene token.
     */
    public function login(string $username, string $password): string
    {
        try {
            $response = $this->client->post('/public/index.php?page=api/auth', [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!isset($data['success']) || !$data['success'] || !isset($data['token'])) {
                throw new RuntimeException('Login fallito: ' . ($data['error'] ?? 'Risposta invalida'));
            }

            $this->token = $data['token'];
            return $this->token;
        } catch (RequestException $exception) {
            throw new RuntimeException('Errore durante il login: ' . $exception->getMessage());
        }
    }

    /**
     * Imposta token manualmente.
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    /**
     * Crea una vendita SIM in Express.
     */
    public function createSimSale(array $saleData): array
    {
        $this->ensureAuthenticated();

        $required = ['customer_id', 'product', 'iccid', 'amount', 'sale_date'];
        foreach ($required as $field) {
            if (!isset($saleData[$field])) {
                throw new InvalidArgumentException("Campo obbligatorio mancante: {$field}");
            }
        }

        // Mappa i campi per l'API Express
        $payload = [
            'customer_id' => $saleData['customer_id'],
            'product' => $saleData['product'],
            'iccid' => $saleData['iccid'],
            'amount' => $saleData['amount'],
            'sale_date' => $saleData['sale_date'],
            'quantity' => 1, // SIM singola
            'price' => $saleData['amount'], // Prezzo unitario
        ];

        if (isset($saleData['payment_method'])) {
            $payload['payment_method'] = $saleData['payment_method'];
        }
        if (isset($saleData['notes'])) {
            $payload['notes'] = $saleData['notes'];
        }

        try {
            $response = $this->client->post('/public/index.php?page=api/sales', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
                'json' => $payload,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!isset($data['success']) || !$data['success']) {
                throw new RuntimeException('Creazione vendita fallita: ' . ($data['error'] ?? 'Risposta invalida'));
            }

            return $data;
        } catch (RequestException $exception) {
            throw new RuntimeException('Errore durante la creazione della vendita: ' . $exception->getMessage());
        }
    }

    /**
     * Crea cliente in Express se non esiste.
     */
    public function createCustomer(array $customerData): array
    {
        $this->ensureAuthenticated();

        $required = ['name', 'email'];
        foreach ($required as $field) {
            if (!isset($customerData[$field])) {
                throw new InvalidArgumentException("Campo obbligatorio mancante: {$field}");
            }
        }

        // Mappa 'name' a 'fullname' se necessario
        $payload = $customerData;
        if (isset($payload['name'])) {
            $payload['fullname'] = $payload['name'];
            unset($payload['name']);
        }

        try {
            $response = $this->client->post('/public/index.php?page=api/customers', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
                'json' => $payload,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!isset($data['success']) || !$data['success']) {
                throw new RuntimeException('Creazione cliente fallita: ' . ($data['error'] ?? 'Risposta invalida'));
            }

            return $data;
        } catch (RequestException $exception) {
            throw new RuntimeException('Errore durante la creazione del cliente: ' . $exception->getMessage());
        }
    }

    /**
     * Cerca cliente per email.
     */
    public function findCustomerByEmail(string $email): ?array
    {
        $this->ensureAuthenticated();

        try {
            $response = $this->client->get('/public/index.php?page=api/customers&email=' . urlencode($email), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!isset($data['success']) || !$data['success'] || !isset($data['data'])) {
                return null;
            }

            return $data['data'];
        } catch (RequestException $exception) {
            throw new RuntimeException('Errore durante la ricerca del cliente: ' . $exception->getMessage());
        }
    }

    private function ensureAuthenticated(): void
    {
        if ($this->token === null) {
            throw new RuntimeException('Token di autenticazione mancante. Effettuare login prima.');
        }
    }
}
?>