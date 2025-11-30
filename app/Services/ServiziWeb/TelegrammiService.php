<?php
declare(strict_types=1);

namespace App\Services\ServiziWeb;

use PDO;
use RuntimeException;
use Throwable;

final class TelegrammiService
{
    private PDO $pdo;
    private bool $logEnabled;
    private string $driver;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

    $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if (!$this->tableExists('servizi_telegrammi')) {
            throw new RuntimeException('Tabella servizi_telegrammi mancante. Esegui la migrazione dedicata ai telegrammi.');
        }

        $this->logEnabled = $this->tableExists('servizi_telegrammi_log');
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $sql = 'SELECT t.*, c.ragione_sociale, c.nome, c.cognome, c.email AS cliente_email
                FROM servizi_telegrammi t
                LEFT JOIN clienti c ON c.id = t.cliente_id';

        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $needle = '%' . trim((string) $filters['search']) . '%';
            $where[] = '(t.telegramma_id LIKE :search
                        OR t.riferimento LIKE :search
                        OR t.guid_utente LIKE :search
                        OR t.richiesta_id LIKE :search
                        OR c.ragione_sociale LIKE :search
                        OR c.cognome LIKE :search
                        OR c.nome LIKE :search)';
            $params[':search'] = $needle;
        }

        if (!empty($filters['stato'])) {
            $where[] = 't.stato = :stato';
            $params[':stato'] = strtoupper((string) $filters['stato']);
        }

        if (!empty($filters['prodotto'])) {
            $where[] = 't.prodotto = :prodotto';
            $params[':prodotto'] = (string) $filters['prodotto'];
        }

        if (isset($filters['confirmed']) && $filters['confirmed'] !== '') {
            $where[] = 't.confirmed = :confirmed';
            $params[':confirmed'] = (int) ((bool) $filters['confirmed']);
        }

        if (!empty($filters['cliente_id'])) {
            $where[] = 't.cliente_id = :cliente_id';
            $params[':cliente_id'] = (int) $filters['cliente_id'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY t.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row = $this->hydrateRow($row);
        }

        return $rows;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT t.*, c.ragione_sociale, c.nome, c.cognome, c.email AS cliente_email
            FROM servizi_telegrammi t
            LEFT JOIN clienti c ON c.id = t.cliente_id
            WHERE t.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    public function findByTelegrammaId(string $telegrammaId, bool $hydrate = true): ?array
    {
        $stmt = $this->pdo->prepare('SELECT t.*, c.ragione_sociale, c.nome, c.cognome, c.email AS cliente_email
            FROM servizi_telegrammi t
            LEFT JOIN clienti c ON c.id = t.cliente_id
            WHERE t.telegramma_id = :id');
        $stmt->execute([':id' => $telegrammaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $hydrate ? $this->hydrateRow($row) : $row;
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    public function persistFromApi(array $payload, ?int $clienteId, ?int $userId, ?string $riferimento = null): array
    {
        $entries = $this->extractEntries($payload);
        if (!$entries) {
            throw new RuntimeException('Risposta API telegrammi vuota o non valida.');
        }

        $records = [];
        foreach ($entries as $entry) {
            $records[] = $this->persistEntry($entry, $clienteId, $userId, $riferimento);
        }

        return $records;
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    public function syncFromApi(array $payload, ?int $userId = null): array
    {
        return $this->persistFromApi($payload, null, $userId, null);
    }

    public function attachCliente(int $telegramPk, ?int $clienteId, ?int $userId = null): void
    {
    $stmt = $this->pdo->prepare('UPDATE servizi_telegrammi SET cliente_id = :cliente_id, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':updated_by' => $userId,
            ':id' => $telegramPk,
        ]);

        if ($this->logEnabled) {
            $this->logEvent($telegramPk, 'assegnazione_cliente', $clienteId === null ? 'Cliente rimosso' : 'Cliente assegnato #' . $clienteId, $userId);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function logs(int $telegramPk): array
    {
        if (!$this->logEnabled) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT evento, messaggio, meta_json, created_by, created_at
            FROM servizi_telegrammi_log
            WHERE servizi_telegrammi_id = :id
            ORDER BY created_at DESC');
        $stmt->execute([':id' => $telegramPk]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['meta'] = $this->decodeJson($row['meta_json']);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function persistEntry(array $entry, ?int $clienteId, ?int $userId, ?string $riferimento): array
    {
        $telegrammaId = trim((string) ($entry['id'] ?? ''));
        if ($telegrammaId === '') {
            throw new RuntimeException('Record telegramma senza identificatore.');
        }

        $existingRaw = $this->findByTelegrammaId($telegrammaId, false);
        $existingClienteId = $existingRaw['cliente_id'] ?? null;
        $existingState = $existingRaw['stato'] ?? null;
        $existingPk = $existingRaw['id'] ?? null;

        if ($clienteId === null && $existingClienteId !== null) {
            $clienteId = (int) $existingClienteId;
        }

        $riferimentoValue = $riferimento ?? ($existingRaw['riferimento'] ?? null);
        $noteValue = array_key_exists('note', $entry) ? $entry['note'] : ($existingRaw['note'] ?? null);

        $clienteIdValue = $clienteId !== null ? (int) $clienteId : null;
        if ($clienteIdValue !== null && $clienteIdValue <= 0) {
            $clienteIdValue = null;
        }

        $commonData = [
            'cliente_id' => $clienteIdValue,
            'riferimento' => $this->nullableString($riferimentoValue),
            'prodotto' => (string) ($entry['prodotto'] ?? 'telegramma'),
            'stato' => strtoupper((string) ($entry['state'] ?? 'NEW')),
            'substate' => $this->nullableString($entry['substate'] ?? null),
            'confirmed' => !empty($entry['confirmed']) ? 1 : 0,
            'creation_timestamp' => $this->normalizeDatetime($entry['creation_timestamp'] ?? null),
            'update_timestamp' => $this->normalizeDatetime($entry['update_timestamp'] ?? null),
            'sending_timestamp' => $this->normalizeDatetime($entry['sending_timestamp'] ?? null),
            'sent_timestamp' => $this->normalizeDatetime($entry['sent_timestamp'] ?? null),
            'confirmed_timestamp' => $this->normalizeDatetime($entry['confirmed_timestamp'] ?? null),
            'guid_utente' => $this->nullableString($entry['GuidUtente'] ?? $entry['guid_utente'] ?? null),
            'richiesta_id' => $this->nullableString($entry['IDRichiesta'] ?? $entry['richiesta_id'] ?? null),
            'last_error' => $this->nullableString($entry['error'] ?? null),
            'last_error_timestamp' => $this->normalizeDatetime($entry['error_timestamp'] ?? null),
            'mittente_json' => $this->encodeJson($entry['mittente'] ?? null),
            'destinatari_json' => $this->encodeJson($this->normalizeDestinatari($entry['destinatari'] ?? null)),
            'documento_testo' => $this->extractDocumento($entry['documento'] ?? null),
            'opzioni_json' => $this->encodeJson($entry['opzioni'] ?? null),
            'documento_validato_json' => $this->encodeJson($entry['documento_validato'] ?? null),
            'pricing_json' => $this->encodeJson($entry['pricing'] ?? null),
            'callback_json' => $this->encodeJson($entry['callback'] ?? null),
            'raw_payload' => $this->encodeJson($entry),
            'note' => $this->nullableString($noteValue),
            'updated_by' => $userId,
        ];

        $affected = 0;

        if ($existingPk === null) {
            $insertData = array_merge([
                'telegramma_id' => $telegrammaId,
                'created_by' => $userId,
            ], $commonData);

            $columns = array_keys($insertData);
            $placeholders = array_map(static fn (string $column) => ':' . $column, $columns);

            $sql = 'INSERT INTO servizi_telegrammi (' . implode(', ', $columns) . ')
                    VALUES (' . implode(', ', $placeholders) . ')';

            $params = [];
            foreach ($insertData as $column => $value) {
                $params[':' . $column] = $value;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();
        } else {
            $updateData = $commonData;
            $setParts = [];
            foreach (array_keys($updateData) as $column) {
                $setParts[] = $column . ' = :' . $column;
            }
            $setParts[] = 'updated_at = CURRENT_TIMESTAMP';

            $sql = 'UPDATE servizi_telegrammi SET ' . implode(', ', $setParts) . ' WHERE id = :id';
            $params = [':id' => $existingPk];
            foreach ($updateData as $column => $value) {
                $params[':' . $column] = $value;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();
        }

        $record = $this->findByTelegrammaId($telegrammaId);
        if ($record === null) {
            throw new RuntimeException('Impossibile recuperare il telegramma dopo il salvataggio.');
        }

        if ($this->logEnabled) {
            $newState = $record['stato'];
            if ($existingPk === null) {
                $this->logEvent((int) $record['id'], 'creato', 'Telegramma registrato via API', $userId, [
                    'stato' => $newState,
                    'confirmed' => $record['confirmed'],
                ]);
            } elseif ($existingState !== $newState) {
                $this->logEvent((int) $record['id'], 'stato_aggiornato', 'Aggiornato stato da ' . $existingState . ' a ' . $newState, $userId, [
                    'stato_precedente' => $existingState,
                    'stato_nuovo' => $newState,
                ]);
            } elseif ($affected > 0 && $userId !== null) {
                $this->logEvent((int) $record['id'], 'aggiornato', 'Telegramma aggiornato', $userId, [
                    'confirmed' => $record['confirmed'],
                ]);
            }
        }

        return $record;
    }

    /**
     * @param array<string,mixed>|array<int,mixed>|null $value
     * @return array<int,mixed>|array<string,mixed>|null
     */
    private function normalizeDestinatari($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            $isAssoc = $this->isAssoc($value);
            if ($isAssoc) {
                return [$value];
            }

            return $value;
        }

        return [$value];
    }

    /**
     * @param array<string,mixed>|array<int,array<string,mixed>> $payload
     * @return array<int,array<string,mixed>>
     */
    private function extractEntries(array $payload): array
    {
        $entries = [];
        $this->collectTelegramEntries($payload, $entries);
        return $entries;
    }

    /**
     * @param mixed $node
     * @param array<int,array<string,mixed>> $entries
     */
    private function collectTelegramEntries($node, array &$entries): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->looksLikeTelegramEntry($node)) {
            $entries[] = $node;
            return;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectTelegramEntries($value, $entries);
            }
        }
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function looksLikeTelegramEntry(array $candidate): bool
    {
        $identifier = isset($candidate['id']) ? trim((string) $candidate['id']) : '';
        if ($identifier === '') {
            return false;
        }

        $signatureKeys = [
            'prodotto',
            'mittente',
            'destinatari',
            'documento',
            'opzioni',
            'pricing',
            'callback',
            'confirmed',
            'creation_timestamp',
            'update_timestamp',
            'sending_timestamp',
            'sent_timestamp',
            'confirmed_timestamp',
        ];

        foreach ($signatureKeys as $key) {
            if (array_key_exists($key, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateRow(array $row): array
    {
        $row['mittente'] = $this->decodeJson($row['mittente_json']);
        $row['destinatari'] = $this->decodeJson($row['destinatari_json']);
        $row['opzioni'] = $this->decodeJson($row['opzioni_json']);
        $row['documento_validato'] = $this->decodeJson($row['documento_validato_json']);
        $row['pricing'] = $this->decodeJson($row['pricing_json']);
        $row['callback'] = $this->decodeJson($row['callback_json']);
        $row['raw'] = $this->decodeJson($row['raw_payload']);
        $row['cliente_display'] = $this->buildClientDisplay($row);

        return $row;
    }

    private function buildClientDisplay(array $row): string
    {
        $parts = [];
        if (!empty($row['ragione_sociale'])) {
            $parts[] = trim((string) $row['ragione_sociale']);
        }

        $nome = trim(((string) ($row['nome'] ?? '')) . ' ' . ((string) ($row['cognome'] ?? '')));
        if ($nome !== '') {
            $parts[] = $nome;
        }

        if (!empty($row['cliente_email'])) {
            $parts[] = (string) $row['cliente_email'];
        }

        return $parts ? implode(' • ', array_unique($parts)) : 'Non associato';
    }

    private function logEvent(int $telegramPk, string $event, string $message, ?int $userId = null, ?array $meta = null): void
    {
        if (!$this->logEnabled) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO servizi_telegrammi_log (servizi_telegrammi_id, evento, messaggio, meta_json, created_by, created_at)
            VALUES (:id, :evento, :messaggio, :meta, :created_by, CURRENT_TIMESTAMP)');
        $stmt->execute([
            ':id' => $telegramPk,
            ':evento' => $event,
            ':messaggio' => $message,
            ':meta' => $this->encodeJson($meta),
            ':created_by' => $userId,
        ]);
    }

    private function tableExists(string $table): bool
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute([':table' => $table]);
            return (bool) $stmt->fetchColumn();
        }

        if ($this->driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute([':table' => $table]);
            return (bool) $stmt->fetchColumn();
        }

        try {
            $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function normalizeDatetime($value): ?string
    {
        $timestamp = $this->normalizeTimestamp($value);
        if ($timestamp === null) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    private function encodeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Impossibile serializzare dati telegramma.');
        }

        return $encoded;
    }

    private function decodeJson(?string $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    private function extractDocumento($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $flattened = array_map(static fn ($item) => trim((string) $item), $value);
            $flattened = array_filter($flattened, static fn ($item) => $item !== '');
            return $flattened ? implode(PHP_EOL . PHP_EOL, $flattened) : null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
