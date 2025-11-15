<?php
declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        abstract class TestCase
        {
            public function assertSame($expected, $actual, string $message = ''): void {}
            public function assertIsArray($actual, string $message = ''): void {}
            public function assertCount(int $expected, $haystack, string $message = ''): void {}
            public function assertContains($needle, $haystack, string $message = ''): void {}
        }
    }
}

namespace Tests\Services\ServiziWeb {

use App\Services\ServiziWeb\TelegrammiService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TelegrammiServiceTest extends TestCase
{
    private PDO $pdo;
    private TelegrammiService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec('CREATE TABLE clienti (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ragione_sociale TEXT,
            nome TEXT,
            cognome TEXT,
            email TEXT
        )');

        $this->pdo->exec('CREATE TABLE servizi_telegrammi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegramma_id TEXT NOT NULL UNIQUE,
            cliente_id INTEGER NULL,
            riferimento TEXT NULL,
            prodotto TEXT NOT NULL,
            stato TEXT NOT NULL,
            substate TEXT NULL,
            confirmed INTEGER NOT NULL DEFAULT 0,
            creation_timestamp TEXT NULL,
            update_timestamp TEXT NULL,
            sending_timestamp TEXT NULL,
            sent_timestamp TEXT NULL,
            confirmed_timestamp TEXT NULL,
            guid_utente TEXT NULL,
            richiesta_id TEXT NULL,
            last_error TEXT NULL,
            last_error_timestamp TEXT NULL,
            mittente_json TEXT NULL,
            destinatari_json TEXT NULL,
            documento_testo TEXT NULL,
            opzioni_json TEXT NULL,
            documento_validato_json TEXT NULL,
            pricing_json TEXT NULL,
            callback_json TEXT NULL,
            raw_payload TEXT NULL,
            note TEXT NULL,
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
        )');

        $this->pdo->exec('CREATE TRIGGER servizi_telegrammi_updated_at AFTER UPDATE ON servizi_telegrammi
            BEGIN
                UPDATE servizi_telegrammi SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
            END;');

        $this->pdo->exec('CREATE TABLE servizi_telegrammi_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            servizi_telegrammi_id INTEGER NOT NULL,
            evento TEXT NOT NULL,
            messaggio TEXT NULL,
            meta_json TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (servizi_telegrammi_id) REFERENCES servizi_telegrammi(id) ON DELETE CASCADE
        )');

        $this->pdo->exec("INSERT INTO clienti (id, ragione_sociale) VALUES (1, 'ACME S.p.A.')");
        $this->pdo->exec("INSERT INTO clienti (id, ragione_sociale) VALUES (2, 'Globex Ltd')");

        $this->service = new TelegrammiService($this->pdo);
    }

    public function testPersistFromApiCreatesTelegramAndLogsCreation(): void
    {
        $payload = $this->makePayload('TG100', 'NEW');

        $records = $this->service->persistFromApi($payload, 1, 99, 'ORD-100');
        $record = $records[0];

        $this->assertSame('TG100', $record['telegramma_id']);
        $this->assertSame(1, (int) $record['cliente_id']);
        $this->assertSame('ORD-100', $record['riferimento']);
        $this->assertSame('NEW', $record['stato']);
        $this->assertSame(0, (int) $record['confirmed']);
        $this->assertIsArray($record['mittente']);
        $this->assertIsArray($record['destinatari']);

        $logs = $this->service->logs((int) $record['id']);
        $this->assertCount(1, $logs);
        $this->assertSame('creato', $logs[0]['evento']);
    }

    public function testPersistFromApiUpdatesStateAndKeepsRiferimento(): void
    {
        $this->service->persistFromApi($this->makePayload('TG200', 'NEW'), 1, 10, 'ORD-200');

        $updatePayload = $this->makePayload('TG200', 'SENT');
        $updatePayload['data']['confirmed'] = true;
        $updatePayload['data']['note'] = 'Aggiornato';
        $updatePayload['data']['error'] = null;

        $records = $this->service->persistFromApi($updatePayload, null, 77, null);
        $record = $records[0];

        $this->assertSame('TG200', $record['telegramma_id']);
        $this->assertSame('ORD-200', $record['riferimento']);
        $this->assertSame('SENT', $record['stato']);
        $this->assertSame(1, (int) $record['confirmed']);
        $this->assertSame('Aggiornato', $record['note']);

    $logs = $this->service->logs((int) $record['id']);
    $this->assertCount(2, $logs);
    $eventi = array_column($logs, 'evento');
    $this->assertContains('stato_aggiornato', $eventi);
    }

    public function testAttachClienteWritesLog(): void
    {
        $records = $this->service->persistFromApi($this->makePayload('TG300', 'NEW'), null, 5, null);
        $record = $records[0];

        $this->service->attachCliente((int) $record['id'], 2, 12);

        $updated = $this->service->find((int) $record['id']);
        $this->assertSame(2, (int) $updated['cliente_id']);

    $logs = $this->service->logs((int) $record['id']);
    $this->assertCount(2, $logs);
    $eventi = array_column($logs, 'evento');
    $this->assertContains('assegnazione_cliente', $eventi);
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function makePayload(string $telegrammaId, string $state): array
    {
        return [
            'data' => [
                'id' => $telegrammaId,
                'prodotto' => 'telegramma',
                'state' => $state,
                'creation_timestamp' => '2025-02-01T10:00:00Z',
                'update_timestamp' => '2025-02-01T10:05:00Z',
                'mittente' => [
                    'nome' => 'Mario Rossi',
                    'indirizzo' => [
                        'via' => 'Via Roma 1',
                        'cap' => '00100',
                        'citta' => 'Roma',
                        'provincia' => 'RM',
                    ],
                ],
                'destinatari' => [
                    [
                        'nome' => 'Ufficio Anagrafe',
                        'indirizzo' => [
                            'via' => 'Piazza Municipio 10',
                            'cap' => '00100',
                            'citta' => 'Roma',
                            'provincia' => 'RM',
                        ],
                    ],
                ],
                'documento' => 'Testo di prova',
            ],
        ];
    }
}

}
