<?php

namespace App\Services\ServiziWeb;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

class VisureService
{
    private PDO $pdo;
    private string $projectRoot;
    private string $storageDir;
    private bool $logEnabled;
    private bool $documentsEnabled;
    private bool $documentVersionsEnabled;

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->storageDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'visure';

        if (!$this->tableExists('servizi_visure')) {
            throw new RuntimeException('Tabella servizi_visure mancante. Esegui la migrazione relativa alle visure catastali.');
        }

        $this->logEnabled = $this->tableExists('servizi_visure_log');
        $this->documentsEnabled = $this->tableExists('documents');
        $this->documentVersionsEnabled = $this->tableExists('document_versions');

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('Impossibile creare la directory di archiviazione visure: ' . $this->storageDir);
        }
    }

    /**
     * @return array{created:int,updated:int,details:int,downloads:int,errors:array<int,string>}
     */
    public function sync(OpenApiCatastoClient $client, int $userId, bool $autoDownload = false): array
    {
        $summaryList = $client->listVisure();
        $created = 0;
        $updated = 0;
        $details = 0;
        $downloads = 0;
        $errors = [];

        foreach ($summaryList as $item) {
            $visuraId = isset($item['id']) ? (string) $item['id'] : '';
            if ($visuraId === '') {
                continue;
            }

            try {
                $summaryResult = $this->upsertSummary($visuraId, $item, $userId);
                if ($summaryResult['created']) {
                    $created++;
                }
                if ($summaryResult['updated']) {
                    $updated++;
                }

                if ($summaryResult['needs_detail']) {
                    $detail = $client->getVisura($visuraId);
                    $this->storeDetail($visuraId, $detail, $userId);
                    $details++;

                    $documentReady = isset($detail['documento']) && $detail['documento'] !== null;
                    if ($autoDownload && $documentReady) {
                        $record = $this->getRecord($visuraId);
                        if ($record !== null && $record['documento_path'] === null) {
                            $binary = $client->downloadVisuraDocument($visuraId);
                            $this->storeDocument(
                                $visuraId,
                                $binary['content'],
                                $binary['content_type'],
                                (int) ($binary['content_length'] ?? strlen($binary['content'])),
                                $userId,
                                $detail
                            );
                            $downloads++;
                        }
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'details' => $details,
            'downloads' => $downloads,
            'errors' => $errors,
        ];
    }

    public function persistVisura(string $visuraId, array $summary, array $detail, int $userId): void
    {
        $this->upsertSummary($visuraId, $summary, $userId);
        $this->storeDetail($visuraId, $detail, $userId);
    }

    public function createVisura(OpenApiCatastoClient $client, array $input, int $userId): array
    {
        $requestType = strtolower(trim((string) ($input['request_type'] ?? 'immobile')));
        $payload = [];

        $tipoVisura = strtolower((string) ($input['tipo_visura'] ?? 'ordinaria')) === 'storica' ? 'storica' : 'ordinaria';
        $richiedente = trim((string) ($input['richiedente'] ?? ''));

        if ($requestType === 'soggetto') {
            $tipoSoggetto = strtolower(trim((string) ($input['tipo_soggetto'] ?? 'persona_fisica')));
            if (!in_array($tipoSoggetto, ['persona_fisica', 'persona_giuridica'], true)) {
                throw new RuntimeException('Tipo soggetto non valido.');
            }

            $cfPiva = strtoupper(trim((string) ($input['codice_fiscale'] ?? '')));
            if ($cfPiva === '') {
                throw new RuntimeException('Codice fiscale / P.IVA obbligatorio per la richiesta soggetto.');
            }

            $provincia = strtoupper(trim((string) ($input['provincia_soggetto'] ?? '')));
            if ($provincia === '') {
                throw new RuntimeException('Provincia del soggetto obbligatoria.');
            }

            $payload = [
                'entita' => 'soggetto',
                'tipo_soggetto' => $tipoSoggetto,
                'cf_piva' => $cfPiva,
                'provincia' => $provincia,
                'tipo_visura' => $tipoVisura,
            ];

            $comune = trim((string) ($input['comune_soggetto'] ?? ''));
            if ($comune !== '') {
                $payload['comune'] = $comune;
            }

            $tipoCatasto = strtoupper(trim((string) ($input['tipo_catasto_soggetto'] ?? 'TF')));
            if ($tipoCatasto !== '') {
                $payload['tipo_catasto'] = $tipoCatasto;
            }

            if ($richiedente === '') {
                $richiedente = $cfPiva;
            }
        } else {
            $tipoCatasto = strtoupper(trim((string) ($input['tipo_catasto'] ?? '')));
            if (!in_array($tipoCatasto, ['F', 'T'], true)) {
                throw new RuntimeException('Tipo catasto non valido. Valori ammessi: F o T.');
            }

            $provincia = strtoupper(trim((string) ($input['provincia'] ?? '')));
            $comune = trim((string) ($input['comune'] ?? ''));
            $foglio = trim((string) ($input['foglio'] ?? ''));
            $particella = trim((string) ($input['particella'] ?? ''));

            if ($provincia === '' || $comune === '' || $foglio === '' || $particella === '') {
                throw new RuntimeException('Provincia, comune, foglio e particella sono obbligatori.');
            }

            $payload = [
                'entita' => 'immobile',
                'tipo_catasto' => $tipoCatasto,
                'provincia' => $provincia,
                'comune' => $comune,
                'foglio' => $foglio,
                'particella' => $particella,
                'tipo_visura' => $tipoVisura,
            ];

            foreach (['subalterno', 'sezione', 'sezione_urbana'] as $field) {
                $value = trim((string) ($input[$field] ?? ''));
                if ($value !== '') {
                    $payload[$field] = $value;
                }
            }
        }

        if ($richiedente !== '') {
            $payload['richiedente'] = $richiedente;
        }

        $callbackUrl = trim((string) ($input['callback_url'] ?? ''));
        if ($callbackUrl !== '') {
            $method = strtoupper(trim((string) ($input['callback_method'] ?? '')));
            if ($method === '') {
                $method = 'POST';
            }
            $callback = [
                'url' => $callbackUrl,
                'method' => $method,
            ];

            $callbackField = trim((string) ($input['callback_field'] ?? ''));
            if ($callbackField !== '') {
                $callback['field'] = $callbackField;
            }

            if (!empty($input['callback_payload']) && is_array($input['callback_payload'])) {
                $callback['payload'] = $input['callback_payload'];
            }

            $payload['callback'] = $callback;
        }

        $visura = $client->createVisura($payload);

        if (!isset($visura['parametri']) || !is_array($visura['parametri'])) {
            $visura['parametri'] = $payload;
        }

        $summary = [
            'id' => (string) $visura['id'],
            'entita' => $visura['entita'] ?? ($payload['entita'] ?? 'immobile'),
            'stato' => $visura['stato'] ?? 'in_erogazione',
            'timestamp' => $visura['timestamp'] ?? time(),
            'owner' => $visura['owner'] ?? ($visura['richiedente'] ?? ($payload['richiedente'] ?? null)),
        ];

        $this->persistVisura($summary['id'], $summary, $visura, $userId);

        return $visura;
    }

    public function listVisure(array $filters = []): array
    {
        $sql = 'SELECT v.*,
                       c.nome,
                       c.cognome,
                       c.email,
                       c.cf_piva,
                       c.ragione_sociale
                FROM servizi_visure v
                LEFT JOIN clienti c ON c.id = v.cliente_id';

        $conditions = [];
        $params = [];

        if (!empty($filters['search'])) {
            $needle = '%' . $filters['search'] . '%';
            $searchColumns = ['v.visura_id', 'v.owner', 'c.ragione_sociale', 'c.cognome', 'c.nome'];
            $searchParts = [];
            foreach ($searchColumns as $index => $column) {
                $placeholderName = 'search_' . $index;
                $placeholder = ':' . $placeholderName;
                $searchParts[] = $column . ' LIKE ' . $placeholder;
                $params[$placeholderName] = $needle;
            }
            $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
        }

        if (!empty($filters['status']) && in_array($filters['status'], ['in_erogazione', 'evasa', 'errore'], true)) {
            $conditions[] = 'v.stato = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['codice_fiscale'])) {
            $value = strtoupper(trim((string) $filters['codice_fiscale']));
            $paramParametriName = 'codice_fiscale_parametri';
            $paramRisultatoName = 'codice_fiscale_risultato';
            $paramParametri = ':' . $paramParametriName;
            $paramRisultato = ':' . $paramRisultatoName;
            $conditions[] = '(
                UPPER(v.parametri_json) LIKE ' . $paramParametri . ' OR
                UPPER(v.risultato_json) LIKE ' . $paramRisultato . '
            )';
            $params[$paramParametriName] = '%' . $value . '%';
            $params[$paramRisultatoName] = '%' . $value . '%';
        }

        if (!empty($filters['foglio'])) {
            $value = trim((string) $filters['foglio']);
            $paramParametriName = 'foglio_parametri';
            $paramRisultatoName = 'foglio_risultato';
            $paramParametri = ':' . $paramParametriName;
            $paramRisultato = ':' . $paramRisultatoName;
            $conditions[] = '(
                v.parametri_json LIKE ' . $paramParametri . ' OR
                v.risultato_json LIKE ' . $paramRisultato . '
            )';
            $params[$paramParametriName] = '%' . $value . '%';
            $params[$paramRisultatoName] = '%' . $value . '%';
        }

        if (!empty($filters['particella'])) {
            $value = trim((string) $filters['particella']);
            $paramParametriName = 'particella_parametri';
            $paramRisultatoName = 'particella_risultato';
            $paramParametri = ':' . $paramParametriName;
            $paramRisultato = ':' . $paramRisultatoName;
            $conditions[] = '(
                v.parametri_json LIKE ' . $paramParametri . ' OR
                v.risultato_json LIKE ' . $paramRisultato . '
            )';
            $params[$paramParametriName] = '%' . $value . '%';
            $params[$paramRisultatoName] = '%' . $value . '%';
        }

        if (!empty($filters['subalterno'])) {
            $value = trim((string) $filters['subalterno']);
            $paramParametriName = 'subalterno_parametri';
            $paramRisultatoName = 'subalterno_risultato';
            $paramParametri = ':' . $paramParametriName;
            $paramRisultato = ':' . $paramRisultatoName;
            $conditions[] = '(
                v.parametri_json LIKE ' . $paramParametri . ' OR
                v.risultato_json LIKE ' . $paramRisultato . '
            )';
            $params[$paramParametriName] = '%' . $value . '%';
            $params[$paramRisultatoName] = '%' . $value . '%';
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY v.updated_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['cliente_display'] = $this->buildClientDisplay($row);
        }

        return $rows;
    }

    public function getVisura(string $visuraId, bool $withLogs = false): array
    {
        $record = $this->getRecord($visuraId);
        if ($record === null) {
            throw new RuntimeException('Visura non trovata.');
        }

        if ($withLogs && $this->logEnabled) {
            $stmt = $this->pdo->prepare('SELECT evento, messaggio, created_at
                FROM servizi_visure_log
                WHERE servizi_visure_id = :id
                ORDER BY created_at DESC');
            $stmt->execute([':id' => $record['id']]);
            $record['logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $record['cliente_display'] = $this->buildClientDisplay($record);

        return $record;
    }

    public function assignClient(string $visuraId, ?int $clientId, int $userId): void
    {
        $record = $this->getRecord($visuraId);
        if ($record === null) {
            throw new RuntimeException('Visura non trovata.');
        }

        $stmt = $this->pdo->prepare('UPDATE servizi_visure SET cliente_id = :cliente_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':cliente_id' => $clientId,
            ':id' => $record['id'],
        ]);

        $logMessage = $clientId === null ? 'Associazione cliente rimossa' : 'Associato cliente #' . $clientId;
        $this->logEvent((int) $record['id'], 'associazione_cliente', $logMessage, $userId);
    }

    public function storeDocument(
        string $visuraId,
        string $binary,
        string $mime,
        int $size,
        int $userId,
        ?array $detail = null
    ): array {
        $visuraId = trim($visuraId);
        if ($visuraId === '') {
            throw new RuntimeException('ID visura non valido.');
        }

        $record = $this->getRecord($visuraId);
        if ($record === null) {
            if ($detail === null) {
                throw new RuntimeException('Dettaglio visura assente. Sincronizza prima di archiviare il documento.');
            }
            $this->upsertSummary($visuraId, [
                'id' => $visuraId,
                'entita' => $detail['entita'] ?? 'immobile',
                'stato' => $detail['stato'] ?? 'in_erogazione',
                'timestamp' => $detail['timestamp'] ?? time(),
                'owner' => $detail['owner'] ?? null,
            ], $userId);
            $this->storeDetail($visuraId, $detail, $userId);
            $record = $this->getRecord($visuraId);
        }

        if ($record === null) {
            throw new RuntimeException('Impossibile archiviare la visura: record non disponibile.');
        }

        $safeName = $this->buildFileName($visuraId, $mime);
        $directory = $this->storageDir . DIRECTORY_SEPARATOR . $visuraId;
        $this->ensureDirectory($directory);

        $absolute = $directory . DIRECTORY_SEPARATOR . $safeName;
        if (file_put_contents($absolute, $binary) === false) {
            throw new RuntimeException('Salvataggio del file visura fallito.');
        }

        $relativePath = 'assets/uploads/visure/' . $visuraId . '/' . $safeName;
        $hash = hash('sha256', $binary);

        $stmt = $this->pdo->prepare('UPDATE servizi_visure SET
                documento_nome = :nome,
                documento_path = :path,
                documento_mime = :mime,
                documento_hash = :hash,
                documento_size = :size,
                documento_aggiornato_il = NOW(),
                updated_at = NOW()
            WHERE visura_id = :visura_id');
        $stmt->execute([
            ':nome' => $safeName,
            ':path' => $relativePath,
            ':mime' => $mime,
            ':hash' => $hash,
            ':size' => $size,
            ':visura_id' => $visuraId,
        ]);

        $this->logEvent((int) $record['id'], 'documento_archiviato', 'Documento archiviato localmente', $userId);

        $documentArchive = null;
        if ($this->documentsEnabled && $this->documentVersionsEnabled) {
            $documentArchive = $this->storeInDocumentArchive($record, $safeName, $absolute, $mime, $size, $userId);
        }

        return [
            'path' => $relativePath,
            'hash' => $hash,
            'document_archive' => $documentArchive,
        ];
    }

    public function registerNotification(string $visuraId, string $recipient, int $userId): void
    {
        $record = $this->getRecord($visuraId);
        if ($record === null) {
            throw new RuntimeException('Visura non trovata.');
        }

        $stmt = $this->pdo->prepare('UPDATE servizi_visure SET notificata_il = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $record['id']]);
        $this->logEvent((int) $record['id'], 'notifica', 'Invio notifica a ' . $recipient, $userId);
    }

    public function markCompleted(string $visuraId, int $userId): void
    {
        $record = $this->getRecord($visuraId);
        if ($record === null || $record['completata_il'] !== null) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE servizi_visure SET completata_il = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $record['id']]);
        $this->logEvent((int) $record['id'], 'completata', 'Visura marcata come completata', $userId);
    }

    public function deleteVisura(string $visuraId, int $userId): void
    {
        $visuraId = trim($visuraId);
        if ($visuraId === '') {
            throw new RuntimeException('ID visura non valido.');
        }

        $record = $this->getRecord($visuraId);
        if ($record === null) {
            throw new RuntimeException('Visura non trovata.');
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare('DELETE FROM servizi_visure WHERE id = :id');
            $stmt->execute([':id' => $record['id']]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Impossibile eliminare la visura selezionata.', 0, $exception);
        }

        $this->cleanupStorageDirectory($visuraId);
    }

    private function upsertSummary(string $visuraId, array $data, int $userId): array
    {
        $existing = $this->getRecord($visuraId);
    $timestamp = $this->normalizeTimestamp($data['timestamp'] ?? null);
        $owner = isset($data['owner']) ? (string) $data['owner'] : null;
        $entita = isset($data['entita']) && in_array($data['entita'], ['immobile', 'soggetto'], true) ? (string) $data['entita'] : 'immobile';
        $stato = isset($data['stato']) && in_array($data['stato'], ['in_erogazione', 'evasa'], true) ? (string) $data['stato'] : 'in_erogazione';

        if ($existing === null) {
            $stmt = $this->pdo->prepare('INSERT INTO servizi_visure (visura_id, entita, stato, owner, richiesta_timestamp, sincronizzata_il, created_at, updated_at)
                VALUES (:visura_id, :entita, :stato, :owner, FROM_UNIXTIME(:timestamp), NOW(), NOW(), NOW())');
            $stmt->execute([
                ':visura_id' => $visuraId,
                ':entita' => $entita,
                ':stato' => $stato,
                ':owner' => $owner,
                ':timestamp' => $timestamp ?? time(),
            ]);

            $this->logEvent((int) $this->pdo->lastInsertId(), 'creata', 'Visura importata', $userId);

            return ['created' => true, 'updated' => false, 'needs_detail' => true];
        }

        $needsDetail = false;
        $fields = [];
        $params = [
            ':id' => $existing['id'],
            ':owner' => $owner,
        ];

        if ($existing['stato'] !== $stato) {
            $fields[] = 'stato = :stato';
            $params[':stato'] = $stato;
            $needsDetail = true;
            $this->logEvent((int) $existing['id'], 'stato', 'Stato aggiornato da ' . $existing['stato'] . ' a ' . $stato, $userId);
        }

        if ($timestamp !== null) {
            $fields[] = 'richiesta_timestamp = FROM_UNIXTIME(:timestamp)';
            $params[':timestamp'] = $timestamp;
        }

        $fields[] = 'owner = :owner';
        $fields[] = 'sincronizzata_il = NOW()';
        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE servizi_visure SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);

        if (empty($existing['tipo_visura']) || $existing['stato'] === 'in_erogazione') {
            $needsDetail = true;
        }

        return ['created' => false, 'updated' => true, 'needs_detail' => $needsDetail];
    }

    private function storeDetail(string $visuraId, array $detail, int $userId): void
    {
        $record = $this->getRecord($visuraId);
        if ($record === null) {
            return;
        }

        $tipoVisura = isset($detail['tipo_visura']) ? (string) $detail['tipo_visura'] : ($record['tipo_visura'] ?? '');
        $richiedente = isset($detail['richiedente']) ? (string) $detail['richiedente'] : ($record['richiedente'] ?? '');
        $stato = isset($detail['stato']) ? (string) $detail['stato'] : ($record['stato'] ?? 'in_erogazione');
        $esito = isset($detail['esito']) ? (string) $detail['esito'] : ($record['esito'] ?? null);
        $documentoNome = isset($detail['documento']) ? (string) $detail['documento'] : ($record['documento_nome'] ?? null);
    $timestamp = $this->normalizeTimestamp($detail['timestamp'] ?? null);

        $parametri = isset($detail['parametri']) ? json_encode($detail['parametri'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $record['parametri_json'];
        $risultato = isset($detail['risultato']) ? json_encode($detail['risultato'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $record['risultato_json'];

        $completata = null;
        if ($timestamp !== null && $stato === 'evasa') {
            $completata = (new DateTimeImmutable('@' . $timestamp))->format('Y-m-d H:i:s');
        }

        $stmt = $this->pdo->prepare('UPDATE servizi_visure SET
                tipo_visura = :tipo_visura,
                richiedente = :richiedente,
                stato = :stato,
                esito = :esito,
                documento_nome = :documento,
                parametri_json = :parametri,
                risultato_json = :risultato,
                completata_il = COALESCE(:completata, completata_il),
                updated_at = NOW()
            WHERE visura_id = :visura_id');
        $stmt->execute([
            ':tipo_visura' => $tipoVisura,
            ':richiedente' => $richiedente,
            ':stato' => $stato,
            ':esito' => $esito,
            ':documento' => $documentoNome,
            ':parametri' => $parametri,
            ':risultato' => $risultato,
            ':completata' => $completata,
            ':visura_id' => $visuraId,
        ]);

        $this->logEvent((int) $record['id'], 'dettaglio', 'Dettaglio visura aggiornato', $userId);
    }

    private function storeInDocumentArchive(array $visura, string $storedName, string $sourcePath, string $mime, int $size, int $userId): array
    {
        $title = 'Visura catastale ' . $visura['visura_id'];
        $module = 'Visure Catasto';
        $description = 'Documento Catasto sincronizzato automaticamente (' . strtoupper($visura['tipo_visura'] ?? 'ordinaria') . ')';
        $clientId = $visura['cliente_id'] ?? null;

        $stmt = $this->pdo->prepare('SELECT id FROM documents WHERE titolo = :titolo AND modulo = :modulo LIMIT 1');
        $stmt->execute([
            ':titolo' => $title,
            ':modulo' => $module,
        ]);
        $documentId = $stmt->fetchColumn();

        if ($documentId === false) {
            $insert = $this->pdo->prepare('INSERT INTO documents (titolo, descrizione, cliente_id, modulo, stato, owner_id, created_at, updated_at)
                VALUES (:titolo, :descrizione, :cliente_id, :modulo, :stato, :owner_id, NOW(), NOW())');
            $insert->execute([
                ':titolo' => $title,
                ':descrizione' => $description,
                ':cliente_id' => $clientId,
                ':modulo' => $module,
                ':stato' => 'Pubblicato',
                ':owner_id' => $userId,
            ]);
            $documentId = $this->pdo->lastInsertId();
        }

        $documentId = (int) $documentId;

        $stmt = $this->pdo->prepare('SELECT MAX(versione) FROM document_versions WHERE document_id = :document_id');
        $stmt->execute([':document_id' => $documentId]);
        $nextVersion = (int) $stmt->fetchColumn() + 1;
        if ($nextVersion <= 0) {
            $nextVersion = 1;
        }

        $docDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documenti' . DIRECTORY_SEPARATOR . $documentId;
        $this->ensureDirectory($docDir);

        $baseName = pathinfo($storedName, PATHINFO_FILENAME);
        $extension = pathinfo($storedName, PATHINFO_EXTENSION) ?: 'pdf';
        $versionedName = sprintf('v%d_%s.%s', $nextVersion, $baseName, $extension);
        $destination = $docDir . DIRECTORY_SEPARATOR . $versionedName;

        if (!copy($sourcePath, $destination)) {
            throw new RuntimeException('Impossibile copiare il file nella libreria documenti.');
        }

        $relativeDocPath = 'assets/uploads/documenti/' . $documentId . '/' . $versionedName;

        $insertVersion = $this->pdo->prepare('INSERT INTO document_versions (document_id, versione, file_name, file_path, mime_type, file_size, uploaded_by, created_at)
            VALUES (:document_id, :versione, :file_name, :file_path, :mime_type, :file_size, :uploaded_by, NOW())');
        $insertVersion->execute([
            ':document_id' => $documentId,
            ':versione' => $nextVersion,
            ':file_name' => $storedName,
            ':file_path' => $relativeDocPath,
            ':mime_type' => $mime,
            ':file_size' => $size,
            ':uploaded_by' => $userId,
        ]);

        return [
            'document_id' => $documentId,
            'versione' => $nextVersion,
            'file_path' => $relativeDocPath,
        ];
    }

    private function getRecord(string $visuraId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT v.*, c.nome, c.cognome, c.email, c.cf_piva, c.ragione_sociale
            FROM servizi_visure v
            LEFT JOIN clienti c ON c.id = v.cliente_id
            WHERE v.visura_id = :visura_id
            LIMIT 1');
        $stmt->execute([':visura_id' => $visuraId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Impossibile creare la cartella: ' . $path);
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function logEvent(int $visuraPk, string $event, string $message, int $userId): void
    {
        if (!$this->logEnabled) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO servizi_visure_log (servizi_visure_id, evento, messaggio, created_at)
            VALUES (:id, :evento, :messaggio, NOW())');
        $stmt->execute([
            ':id' => $visuraPk,
            ':evento' => $event,
            ':messaggio' => sprintf('%s (utente #%d)', $message, $userId),
        ]);
    }

    private function normalizeTimestamp(mixed $value): ?int
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

    private function buildClientDisplay(array $row): string
    {
        $parts = [];
        if (!empty($row['ragione_sociale'])) {
            $parts[] = trim((string) $row['ragione_sociale']);
        }

        $name = trim(((string) ($row['nome'] ?? '')) . ' ' . ((string) ($row['cognome'] ?? '')));
        if ($name !== '') {
            $parts[] = $name;
        }

        if (!empty($row['email'])) {
            $parts[] = (string) $row['email'];
        }

        if (!empty($row['cf_piva'])) {
            $parts[] = (string) $row['cf_piva'];
        }

        return $parts ? implode(' ? ', array_unique($parts)) : 'Non associato';
    }

    private function cleanupStorageDirectory(string $visuraId): void
    {
        $directory = $this->storageDir . DIRECTORY_SEPARATOR . $visuraId;
        if (!is_dir($directory)) {
            return;
        }

        $this->cleanupDirectory($directory);
    }

    private function cleanupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->cleanupDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    private function buildFileName(string $visuraId, string $mime): string
    {
        $extension = 'pdf';
        if (str_contains($mime, '/')) {
            $candidate = strtolower(substr($mime, strrpos($mime, '/') + 1));
            $candidate = preg_replace('/[^a-z0-9]/', '', $candidate ?? '') ?: 'pdf';
            $extension = $candidate;
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $visuraId);
        return 'visura_' . $sanitized . '.' . $extension;
    }
}


