<?php
declare(strict_types=1);

namespace Tests\Services\CAFPatronato;

use App\Services\CAFPatronato\PracticesService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use DateTimeImmutable;
use Tests\Support\MailStubRecorder;

final class PracticesServiceTest extends TestCase
{
    private PDO $pdo;
    private PracticesService $service;
    private string $testRootDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->testRootDir = sys_get_temp_dir() . '/caf_patronato_test_' . uniqid();
        mkdir($this->testRootDir, 0755, true);

        $this->createTestSchema();
        $this->insertTestData();

        require_once dirname(__DIR__, 2) . '/Support/MailStubRecorder.php';
        require_once dirname(__DIR__, 2) . '/Support/CafPatronatoMailStubs.php';

        $this->service = new PracticesService($this->pdo, $this->testRootDir);

        MailStubRecorder::reset();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testRootDir)) {
            $this->deleteDirectory($this->testRootDir);
        }
    }

    private function createTestSchema(): void
    {
        $schema = [
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL,
                nome TEXT,
                cognome TEXT,
                ruolo TEXT DEFAULT "Operatore",
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE clienti (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ragione_sociale TEXT,
                nome TEXT,
                cognome TEXT,
                email TEXT,
                telefono TEXT,
                cf_piva TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE tipologie_pratiche (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                categoria TEXT NOT NULL CHECK (categoria IN (\'CAF\', \'PATRONATO\')),
                campi_personalizzati TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE pratiche_stati (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                codice TEXT NOT NULL UNIQUE,
                nome TEXT NOT NULL,
                colore TEXT DEFAULT "secondary",
                ordering INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE utenti_caf_patronato (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                nome TEXT NOT NULL,
                cognome TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT,
                ruolo TEXT NOT NULL CHECK (ruolo IN (\'CAF\', \'PATRONATO\')),
                attivo INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
            'CREATE TABLE pratiche (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                titolo TEXT NOT NULL,
                descrizione TEXT,
                tipo_pratica INTEGER NOT NULL,
                categoria TEXT NOT NULL CHECK (categoria IN (\'CAF\', \'PATRONATO\')),
                stato TEXT NOT NULL,
                data_creazione TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                data_aggiornamento TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                scadenza TEXT,
                id_admin INTEGER NOT NULL,
                id_utente_caf_patronato INTEGER,
                cliente_id INTEGER,
                allegati TEXT,
                note TEXT,
                metadati TEXT,
                tracking_code TEXT UNIQUE,
                tracking_steps TEXT,
                FOREIGN KEY (tipo_pratica) REFERENCES tipologie_pratiche(id) ON DELETE RESTRICT,
                FOREIGN KEY (id_admin) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (id_utente_caf_patronato) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
                FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
            )',
            'CREATE TABLE pratiche_note (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pratica_id INTEGER NOT NULL,
                autore_user_id INTEGER,
                autore_operatore_id INTEGER,
                contenuto TEXT NOT NULL,
                visibile_admin INTEGER NOT NULL DEFAULT 1,
                visibile_operatore INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
                FOREIGN KEY (autore_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (autore_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL
            )',
            'CREATE TABLE pratiche_documenti (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pratica_id INTEGER NOT NULL,
                file_name TEXT NOT NULL,
                file_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                uploaded_by INTEGER,
                uploaded_operatore_id INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
                FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (uploaded_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL
            )',
            'CREATE TABLE pratiche_eventi (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pratica_id INTEGER NOT NULL,
                evento TEXT NOT NULL,
                messaggio TEXT,
                payload TEXT,
                creato_da INTEGER,
                creato_operatore_id INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
                FOREIGN KEY (creato_da) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (creato_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL
            )',
            'CREATE TABLE pratiche_notifiche (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pratica_id INTEGER NOT NULL,
                destinatario_user_id INTEGER,
                destinatario_operatore_id INTEGER,
                tipo TEXT NOT NULL,
                messaggio TEXT NOT NULL,
                channel TEXT NOT NULL DEFAULT "dashboard",
                stato TEXT NOT NULL DEFAULT \'nuova\' CHECK (stato IN (\'nuova\', \'letta\')),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at TEXT,
                FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
                FOREIGN KEY (destinatario_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (destinatario_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL
            )',
            'CREATE TABLE entrate_uscite (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cliente_id INTEGER NULL,
                descrizione TEXT NOT NULL,
                riferimento TEXT,
                metodo TEXT,
                stato TEXT,
                tipo_movimento TEXT,
                importo TEXT NOT NULL DEFAULT "0",
                quantita INTEGER NOT NULL DEFAULT 1,
                prezzo_unitario TEXT NOT NULL DEFAULT "0",
                data_scadenza TEXT,
                data_pagamento TEXT,
                note TEXT,
                allegato_path TEXT,
                allegato_hash TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        ];

        foreach ($schema as $sql) {
            $this->pdo->exec($sql);
        }
    }

    private function insertTestData(): void
    {
        // Insert test users
        $this->pdo->exec("INSERT INTO users (id, username, email, nome, cognome, ruolo) VALUES 
            (1, 'admin', 'admin@test.com', 'Admin', 'User', 'Admin'),
            (2, 'operator1', 'op1@test.com', 'Mario', 'Rossi', 'Operatore')");

        // Insert test clients
        $this->pdo->exec("INSERT INTO clienti (id, ragione_sociale, nome, cognome, email) VALUES 
            (1, 'ACME S.p.A.', NULL, NULL, 'info@acme.com'),
            (2, NULL, 'Giovanni', 'Bianchi', 'g.bianchi@email.com')");

        // Insert practice types with custom fields schema
        $cafFields = json_encode([
            ['slug' => 'servizio', 'label' => 'Servizio richiesto', 'type' => 'text'],
            ['slug' => 'nominativo', 'label' => 'Nominativo', 'type' => 'text'],
            ['slug' => 'importo', 'label' => 'Importo', 'type' => 'number'],
            ['slug' => 'email_contatto', 'label' => 'Email contatto', 'type' => 'text'],
            ['slug' => 'note_interne', 'label' => 'Note interne', 'type' => 'textarea'],
            ['slug' => 'privacy_consenso', 'label' => 'Consenso privacy', 'type' => 'checkbox'],
            ['slug' => 'data_appuntamento', 'label' => 'Data appuntamento', 'type' => 'date'],
            ['slug' => 'elenco_documenti', 'label' => 'Documenti consegnati', 'type' => 'text'],
        ], JSON_THROW_ON_ERROR);

        $patronatoFields = json_encode([
            ['slug' => 'email_contatto', 'label' => 'Email contatto', 'type' => 'text'],
        ], JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare('INSERT INTO tipologie_pratiche (id, nome, categoria, campi_personalizzati) VALUES (:id, :nome, :categoria, :campi)');
        $stmt->execute([':id' => 1, ':nome' => 'Dichiarazione dei redditi', ':categoria' => 'CAF', ':campi' => $cafFields]);
        $stmt->execute([':id' => 2, ':nome' => 'Richiesta pensione', ':categoria' => 'PATRONATO', ':campi' => $patronatoFields]);

        // Insert practice statuses
        $this->pdo->exec("INSERT INTO pratiche_stati (codice, nome, colore, ordering) VALUES 
            ('in_lavorazione', 'In lavorazione', 'primary', 10),
            ('completata', 'Completata', 'success', 20),
            ('sospesa', 'Sospesa', 'warning', 30)");

        // Insert test operators
        $this->pdo->exec("INSERT INTO utenti_caf_patronato (id, user_id, nome, cognome, email, ruolo) VALUES 
            (1, 2, 'Mario', 'Rossi', 'mario.rossi@test.com', 'CAF'),
            (2, NULL, 'Anna', 'Verdi', 'anna.verdi@test.com', 'PATRONATO')");
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testListPracticesReturnsEmptyWhenNoPractices(): void
    {
        $result = $this->service->listPractices([], null, true);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertArrayHasKey('pagination', $result);
        self::assertArrayHasKey('summaries', $result);
        self::assertEmpty($result['items']);
        self::assertEquals(0, $result['pagination']['total']);
    }

    public function testCreatePracticeSuccessfully(): void
    {
        $practiceData = [
            'titolo' => 'Test Practice',
            'descrizione' => 'Test description',
            'tipo_pratica' => 1,
            'categoria' => 'CAF',
            'stato' => 'in_lavorazione',
            'note' => 'Test notes',
            'cliente_id' => 1,
            'id_utente_caf_patronato' => 1
        ];

        $practice = $this->service->createPractice($practiceData, 1);

        self::assertIsArray($practice);
        self::assertEquals('Test Practice', $practice['titolo']);
        self::assertEquals('CAF', $practice['categoria']);
        self::assertEquals('in_lavorazione', $practice['stato']);
        self::assertEquals(1, $practice['cliente']['id']);
        self::assertEquals(1, $practice['assegnatario']['id']);
    }

    public function testCreatePracticeRegistersFinancialMovement(): void
    {
        $practiceData = [
            'titolo' => 'Mario Rossi - ISEE',
            'tipo_pratica' => 1,
            'categoria' => 'CAF',
            'cliente_id' => 1,
            'metadati' => [
                'servizio' => 'ISEE',
                'nominativo' => 'Mario Rossi',
                'importo' => '120,50',
            ],
        ];

        $practice = $this->service->createPractice($practiceData, 1);

        $stmt = $this->pdo->query('SELECT * FROM entrate_uscite');
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $entries);
        $entry = $entries[0];

        self::assertEquals($practice['tracking_code'], $entry['riferimento']);
        self::assertEquals('Entrata', $entry['tipo_movimento']);
        self::assertEquals('In lavorazione', $entry['stato']);
        self::assertEquals('Bonifico', $entry['metodo']);
        self::assertEquals(1, (int) $entry['cliente_id']);
        self::assertEquals(1, (int) $entry['quantita']);
        self::assertEquals(120.5, (float) $entry['importo']);
        self::assertEquals(120.5, (float) $entry['prezzo_unitario']);
        self::assertNull($entry['data_scadenza']);
        self::assertStringContainsString('ISEE', $entry['descrizione']);
        self::assertStringContainsString('Mario Rossi', $entry['descrizione']);
        self::assertStringContainsString((string) $practice['id'], (string) $entry['note']);
    }

    public function testEnsureFinancialMovementForExistingPractice(): void
    {
        $metadata = json_encode([
            'servizio' => 'ISEE',
            'nominativo' => 'Carmine Cavaliere',
            'importo' => '5,00€',
        ], JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare('INSERT INTO pratiche (
            titolo,
            descrizione,
            tipo_pratica,
            categoria,
            stato,
            data_creazione,
            data_aggiornamento,
            scadenza,
            id_admin,
            id_utente_caf_patronato,
            cliente_id,
            allegati,
            note,
            metadati,
            tracking_code,
            tracking_steps
        ) VALUES (
            :titolo,
            :descrizione,
            :tipo_pratica,
            :categoria,
            :stato,
            :data_creazione,
            :data_aggiornamento,
            :scadenza,
            :id_admin,
            NULL,
            :cliente_id,
            :allegati,
            NULL,
            :metadati,
            :tracking_code,
            :tracking_steps
        )');

        $stmt->execute([
            ':titolo' => 'Carmine Cavaliere - ISEE',
            ':descrizione' => 'Pratica creata dal modulo legacy',
            ':tipo_pratica' => 1,
            ':categoria' => 'CAF',
            ':stato' => 'in_lavorazione',
            ':data_creazione' => '2025-01-01 10:00:00',
            ':data_aggiornamento' => '2025-01-01 10:00:00',
            ':scadenza' => '2025-12-31',
            ':id_admin' => 1,
            ':cliente_id' => 1,
            ':allegati' => json_encode([], JSON_THROW_ON_ERROR),
            ':metadati' => $metadata,
            ':tracking_code' => 'CAFTEST-123',
            ':tracking_steps' => json_encode([], JSON_THROW_ON_ERROR),
        ]);

        $practiceId = (int) $this->pdo->lastInsertId();

        $this->service->ensureFinancialMovementForPractice($practiceId);
        $this->service->ensureFinancialMovementForPractice($practiceId);

        $entries = $this->pdo->query('SELECT * FROM entrate_uscite')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertEquals('CAFTEST-123', $entry['riferimento']);
        self::assertEquals('Entrata', $entry['tipo_movimento']);
        self::assertEquals(1, (int) $entry['cliente_id']);
        self::assertEquals(5.0, (float) $entry['importo']);
        self::assertStringContainsString('Carmine Cavaliere', $entry['descrizione']);
        self::assertStringContainsString('ISEE', $entry['descrizione']);
    }

    public function testDeletePracticeRemovesRecordsAndMovements(): void
    {
        $practice = $this->service->createPractice([
            'titolo' => 'Pratica da eliminare',
            'tipo_pratica' => 1,
            'categoria' => 'CAF',
            'cliente_id' => 1,
            'metadati' => [
                'servizio' => 'ISEE',
                'nominativo' => 'Cliente Test',
                'importo' => '35,00',
            ],
        ], 1);

        $practiceId = (int) $practice['id'];
        self::assertTrue($practiceId > 0);

        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM pratiche')->fetchColumn();
        self::assertSame(1, $countBefore);

        $movementBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM entrate_uscite')->fetchColumn();
        self::assertSame(1, $movementBefore);

        $this->service->deletePractice($practiceId, true, null);

        $countAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM pratiche')->fetchColumn();
        self::assertSame(0, $countAfter);

        $movementAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM entrate_uscite')->fetchColumn();
        self::assertSame(0, $movementAfter);
    }

    public function testEnsureFinancialMovementDoesNotDuplicateWhenTrackingCodeAssignedLater(): void
    {
        $metadata = json_encode([
            'servizio' => 'ISEE',
            'nominativo' => 'Erika Conti',
            'importo' => '75,00',
        ], JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare('INSERT INTO pratiche (
            titolo,
            descrizione,
            tipo_pratica,
            categoria,
            stato,
            data_creazione,
            data_aggiornamento,
            scadenza,
            id_admin,
            id_utente_caf_patronato,
            cliente_id,
            allegati,
            note,
            metadati,
            tracking_steps
        ) VALUES (
            :titolo,
            :descrizione,
            :tipo_pratica,
            :categoria,
            :stato,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP,
            NULL,
            1,
            NULL,
            1,
            :allegati,
            NULL,
            :metadati,
            :steps
        )');
        $stmt->execute([
            ':titolo' => 'Erika Conti - ISEE',
            ':descrizione' => 'Pratica generata dal modulo legacy.',
            ':tipo_pratica' => 1,
            ':categoria' => 'CAF',
            ':stato' => 'in_lavorazione',
            ':allegati' => json_encode([], JSON_THROW_ON_ERROR),
            ':metadati' => $metadata,
            ':steps' => json_encode([], JSON_THROW_ON_ERROR),
        ]);

        $practiceId = (int) $this->pdo->lastInsertId();
        self::assertTrue($practiceId > 0);

        $this->service->ensureFinancialMovementForPractice($practiceId);
        $initialEntries = (int) $this->pdo->query('SELECT COUNT(*) FROM entrate_uscite')->fetchColumn();
        self::assertEquals(1, $initialEntries);

        $update = $this->pdo->prepare('UPDATE pratiche SET tracking_code = :tracking_code WHERE id = :id');
        $update->execute([
            ':tracking_code' => 'CAF-PRAT-90001',
            ':id' => $practiceId,
        ]);

        $this->service->ensureFinancialMovementForPractice($practiceId);

        $entries = $this->pdo->query('SELECT riferimento FROM entrate_uscite')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $entries);
        self::assertEquals('PRATICA-' . $practiceId, $entries[0]);
    }

    public function testSyncMissingFinancialMovementsBackfillsEntries(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO pratiche (
            titolo,
            descrizione,
            tipo_pratica,
            categoria,
            stato,
            data_creazione,
            data_aggiornamento,
            scadenza,
            id_admin,
            id_utente_caf_patronato,
            cliente_id,
            allegati,
            note,
            metadati,
            tracking_code,
            tracking_steps
        ) VALUES (
            :titolo,
            :descrizione,
            :tipo_pratica,
            :categoria,
            :stato,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP,
            NULL,
            1,
            NULL,
            1,
            :allegati,
            NULL,
            :metadati,
            NULL,
            :steps
        )');
        $stmt->execute([
            ':titolo' => 'Backfill Practice',
            ':descrizione' => 'Pratica senza movimento.',
            ':tipo_pratica' => 1,
            ':categoria' => 'CAF',
            ':stato' => 'in_lavorazione',
            ':allegati' => json_encode([], JSON_THROW_ON_ERROR),
            ':metadati' => json_encode([
                'servizio' => 'ISEE',
                'nominativo' => 'Arianna Test',
                'importo' => '45,00',
            ], JSON_THROW_ON_ERROR),
            ':steps' => json_encode([], JSON_THROW_ON_ERROR),
        ]);

        $result = $this->service->syncMissingFinancialMovements();

        self::assertSame(1, $result['scanned']);
        self::assertSame(1, $result['created']);
        self::assertSame(0, $result['remaining']);

        $entries = $this->pdo->query('SELECT COUNT(*) FROM entrate_uscite')->fetchColumn();
        self::assertEquals(1, (int) $entries);
    }

    public function testCreatePracticeSendsCustomerMail(): void
    {
        $practiceData = [
            'titolo' => 'Pratica con email',
            'descrizione' => 'Descrizione cliente',
            'tipo_pratica' => 1,
            'categoria' => 'CAF',
            'stato' => 'in_lavorazione',
            'metadati' => [
                'email_contatto' => 'cliente+alias@example.test',
                'note_interne' => 'Questa nota non deve essere inclusa.',
                'privacy_consenso' => '1',
                'data_appuntamento' => '2025-05-10',
                'elenco_documenti' => ['Carta identita', 'IBAN'],
            ],
        ];

        $practice = $this->service->createPractice($practiceData, 1);

        $messages = MailStubRecorder::messages();
        self::assertCount(1, $messages);

        $mail = MailStubRecorder::last();
        self::assertNotNull($mail);
        self::assertEquals('cliente+alias@example.test', $mail['recipient']);
        self::assertStringContainsString($practice['tracking_code'], $mail['subject']);
        $expectedLink = 'https://test.local/tracking.php?code=' . rawurlencode($practice['tracking_code']);
        self::assertStringContainsString($expectedLink, $mail['body']);
        self::assertStringContainsString('Apri il portale di tracking', $mail['body']);
        self::assertStringContainsString('Riepilogo pratica', $mail['body']);
        self::assertStringContainsString('Informazioni aggiuntive', $mail['body']);
        self::assertStringContainsString('Ultimi aggiornamenti', $mail['body']);
        self::assertStringContainsString('Email contatto', $mail['body']);
        self::assertStringContainsString('cliente+alias@example.test', $mail['body']);
        self::assertStringContainsString('Data appuntamento', $mail['body']);
        self::assertStringContainsString('10/05/2025', $mail['body']);
        self::assertStringContainsString('Documenti consegnati', $mail['body']);
        self::assertStringContainsString('Carta identita, IBAN', $mail['body']);
        self::assertStringNotContainsString('Note interne', $mail['body']);
        self::assertStringNotContainsString('Consenso privacy', $mail['body']);

        self::assertNotEmpty($practice['tracking_steps']);
        $lastStep = $practice['tracking_steps'][array_key_last($practice['tracking_steps'])];
        self::assertEquals('Email di conferma inviata al cliente', $lastStep['descrizione']);
        self::assertFalse($lastStep['pubblico']);

        $stmt = $this->pdo->prepare('SELECT evento, payload FROM pratiche_eventi WHERE pratica_id = :id AND evento = :evento ORDER BY id DESC LIMIT 1');
        $stmt->execute([':id' => $practice['id'], ':evento' => 'notifica_cliente']);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($event);
        self::assertEquals('notifica_cliente', $event['evento']);

        $payload = json_decode((string) $event['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals('cliente+alias@example.test', $payload['email']);
        self::assertEquals($practice['tracking_code'], $payload['tracking_code']);
    }

    public function testCreatePracticeWithInvalidTypeThrowsException(): void
    {
        $practiceData = [
            'titolo' => 'Invalid Practice',
            'tipo_pratica' => 999, // Non-existent type
            'categoria' => 'CAF'
        ];

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Tipologia non trovata');
        
        $this->service->createPractice($practiceData, 1);
    }

    public function testUpdatePracticeStatus(): void
    {
        // First create a practice
        $practiceData = [
            'titolo' => 'Status Test Practice',
            'tipo_pratica' => 1,
            'categoria' => 'CAF'
        ];
        $practice = $this->service->createPractice($practiceData, 1);

        // Update its status
        $updatedPractice = $this->service->updateStatus($practice['id'], 'completata', 1, null, true);

        self::assertEquals('completata', $updatedPractice['stato']);
    }

    public function testAddNoteToExistingPractice(): void
    {
        // Create a practice first
        $practiceData = [
            'titolo' => 'Note Test Practice',
            'tipo_pratica' => 1,
            'categoria' => 'CAF'
        ];
        $practice = $this->service->createPractice($practiceData, 1);

        // Add a note
        $notes = $this->service->addNote($practice['id'], 'Test note content', 1, null, true, true);

        self::assertIsArray($notes);
        self::assertNotEmpty($notes);
        self::assertEquals('Test note content', $notes[0]['contenuto']);
        self::assertTrue($notes[0]['visibile_admin']);
        self::assertTrue($notes[0]['visibile_operatore']);
    }

    public function testListTypesFiltersByCategory(): void
    {
        $cafTypes = $this->service->listTypes('CAF');
        self::assertCount(1, $cafTypes);
        self::assertEquals('CAF', $cafTypes[0]['categoria']);

        $patronatoTypes = $this->service->listTypes('PATRONATO');
        self::assertCount(1, $patronatoTypes);
        self::assertEquals('PATRONATO', $patronatoTypes[0]['categoria']);

        $allTypes = $this->service->listTypes();
        self::assertCount(2, $allTypes);
    }

    public function testCreateOperatorWithValidData(): void
    {
        $operatorData = [
            'nome' => 'Test',
            'cognome' => 'Operator',
            'email' => 'test.operator@test.com',
            'ruolo' => 'CAF',
            'attivo' => true,
            'password' => 'test123'
        ];

        $operator = $this->service->saveOperator(null, $operatorData);

        self::assertIsArray($operator);
        self::assertEquals('Test', $operator['nome']);
        self::assertEquals('Operator', $operator['cognome']);
        self::assertEquals('CAF', $operator['ruolo']);
        self::assertTrue($operator['attivo']);
    }

    public function testToggleOperatorStatus(): void
    {
        // Create operator
        $operatorData = [
            'nome' => 'Toggle',
            'cognome' => 'Test',
            'email' => 'toggle@test.com',
            'ruolo' => 'CAF',
            'attivo' => true
        ];
        $operator = $this->service->saveOperator(null, $operatorData);

        // Disable operator
        $this->service->toggleOperator($operator['id'], false);
        $updatedOperator = $this->service->getOperator($operator['id']);
        self::assertFalse($updatedOperator['attivo']);

        // Re-enable operator
        $this->service->toggleOperator($operator['id'], true);
        $reEnabledOperator = $this->service->getOperator($operator['id']);
        self::assertTrue($reEnabledOperator['attivo']);
    }

    public function testCreateStatusWithValidData(): void
    {
        $statusData = [
            'codice' => 'test_status',
            'nome' => 'Test Status',
            'colore' => 'info',
            'ordering' => 100
        ];

        $status = $this->service->createStatus($statusData);

        self::assertEquals('test_status', $status['codice']);
        self::assertEquals('Test Status', $status['nome']);
        self::assertEquals('info', $status['colore']);
        self::assertEquals(100, $status['ordering']);
    }

    public function testCreateDuplicateStatusThrowsException(): void
    {
        $statusData = [
            'codice' => 'in_lavorazione', // Already exists in test data
            'nome' => 'Duplicate Status'
        ];

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Esiste già uno stato con questo codice');
        
        $this->service->createStatus($statusData);
    }

    public function testFindOperatorByUserId(): void
    {
        $operatorId = $this->service->findOperatorIdByUser(2); // User ID 2 is linked to operator ID 1
        self::assertEquals(1, $operatorId);

        $nonExistentOperatorId = $this->service->findOperatorIdByUser(999);
        self::assertNull($nonExistentOperatorId);
    }

    public function testFilterPracticesBySearchTerm(): void
    {
        // Create some test practices
        $this->service->createPractice(['titolo' => 'Unique Search Title', 'tipo_pratica' => 1], 1);
        $this->service->createPractice(['titolo' => 'Another Practice', 'tipo_pratica' => 1], 1);

        $result = $this->service->listPractices(['search' => 'Unique'], null, true);
        
        self::assertCount(1, $result['items']);
        self::assertEquals('Unique Search Title', $result['items'][0]['titolo']);
    }

    public function testPaginationWorksCorrectly(): void
    {
        // Create multiple practices
        for ($i = 1; $i <= 25; $i++) {
            $this->service->createPractice([
                'titolo' => "Practice $i",
                'tipo_pratica' => 1
            ], 1);
        }

        // Test first page
        $page1 = $this->service->listPractices(['page' => 1, 'per_page' => 10], null, true);
        self::assertCount(10, $page1['items']);
        self::assertEquals(1, $page1['pagination']['page']);
        self::assertEquals(25, $page1['pagination']['total']);

        // Test second page
        $page2 = $this->service->listPractices(['page' => 2, 'per_page' => 10], null, true);
        self::assertCount(10, $page2['items']);
        self::assertEquals(2, $page2['pagination']['page']);

        // Test last page
        $page3 = $this->service->listPractices(['page' => 3, 'per_page' => 10], null, true);
        self::assertCount(5, $page3['items']); // Remaining 5 items
    }

    public function testStoragePathCreationWorksCorrectly(): void
    {
        $practiceId = 123;
        $path = $this->service->storagePathForPractice($practiceId);

        self::assertStringContainsString('caf-patronato', $path);
        self::assertStringContainsString((string) $practiceId, $path);
        self::assertTrue(is_dir($path), 'Storage path should be created automatically');
    }

    public function testListNotificationsFiltersCorrectly(): void
    {
        // This would require creating notifications first
        // For now, just test that the method works with empty data
        $notifications = $this->service->listNotifications(1, null, false);
        self::assertIsArray($notifications);
        self::assertEmpty($notifications); // No notifications created yet
    }
}