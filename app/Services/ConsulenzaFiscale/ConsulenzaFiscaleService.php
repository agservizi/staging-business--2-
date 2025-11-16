<?php
declare(strict_types=1);

namespace App\Services\ConsulenzaFiscale;

use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class ConsulenzaFiscaleService
{
    private const MAX_UPLOAD_SIZE = 15728640; // 15 MB

    private PDO $pdo;
    private string $storagePath;
    private string $projectRoot;

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->storagePath = $this->projectRoot
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'consulenza-fiscale';

        if (!is_dir($this->storagePath)) {
            if (!mkdir($concurrentDirectory = $this->storagePath, 0775, true) && !is_dir($concurrentDirectory)) {
                throw new RuntimeException('Impossibile creare la cartella per i documenti di consulenza fiscale.');
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $sql = 'SELECT cf.*, c.ragione_sociale, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.cf_piva,
                (SELECT COUNT(*) FROM consulenze_fiscali_rate r WHERE r.consulenza_id = cf.id) AS rate_totali,
                (SELECT COUNT(*) FROM consulenze_fiscali_rate r WHERE r.consulenza_id = cf.id AND r.stato = "paid") AS rate_pag,
                (SELECT MIN(r.scadenza) FROM consulenze_fiscali_rate r WHERE r.consulenza_id = cf.id AND r.stato <> "paid") AS prossima_scadenza
            FROM consulenze_fiscali cf
            LEFT JOIN clienti c ON c.id = cf.cliente_id';

        $conditions = [];
        $params = [];

        if (!empty($filters['search'])) {
            $conditions[] = '(cf.codice LIKE :search OR cf.intestatario_nome LIKE :search OR cf.codice_fiscale LIKE :search OR cf.note LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['tipo_modello'])) {
            $conditions[] = 'cf.tipo_modello = :tipo_modello';
            $params[':tipo_modello'] = $filters['tipo_modello'];
        }

        if (!empty($filters['stato'])) {
            $conditions[] = 'cf.stato = :stato';
            $params[':stato'] = $filters['stato'];
        }

        if (!empty($filters['scadenza_dal'])) {
            $conditions[] = 'cf.prima_scadenza >= :scadenza_dal';
            $params[':scadenza_dal'] = $filters['scadenza_dal'];
        }

        if (!empty($filters['scadenza_al'])) {
            $conditions[] = 'cf.prima_scadenza <= :scadenza_al';
            $params[':scadenza_al'] = $filters['scadenza_al'];
        }

        if (!empty($filters['promemoria'])) {
            if ($filters['promemoria'] === 'oggi') {
                $conditions[] = 'cf.promemoria_scadenza = CURDATE()';
            } elseif ($filters['promemoria'] === 'settimana') {
                $conditions[] = 'cf.promemoria_scadenza BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
            } elseif ($filters['promemoria'] === 'scaduti') {
                $conditions[] = 'cf.promemoria_scadenza < CURDATE() AND (cf.promemoria_inviato_at IS NULL OR DATE(cf.promemoria_inviato_at) < CURDATE())';
            }
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY cf.updated_at DESC, cf.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $counts = array_fill_keys(array_keys(self::availableStatuses()), 0);

        $countStmt = $this->pdo->query('SELECT stato, COUNT(*) AS total FROM consulenze_fiscali GROUP BY stato');
        if ($countStmt) {
            foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['stato'] ?? '';
                if (isset($counts[$key])) {
                    $counts[$key] = (int) $row['total'];
                }
            }
        }

        $reminderStmt = $this->pdo->query('SELECT
                SUM(CASE WHEN promemoria_scadenza = CURDATE() THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN promemoria_scadenza BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN promemoria_scadenza < CURDATE() AND (promemoria_inviato_at IS NULL OR DATE(promemoria_inviato_at) < CURDATE()) THEN 1 ELSE 0 END) AS overdue
            FROM consulenze_fiscali');

        $reminders = ['today' => 0, 'upcoming' => 0, 'overdue' => 0];
        if ($reminderStmt) {
            $row = $reminderStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $reminders['today'] = (int) ($row['today'] ?? 0);
                $reminders['upcoming'] = (int) ($row['upcoming'] ?? 0);
                $reminders['overdue'] = (int) ($row['overdue'] ?? 0);
            }
        }

        $openRatesStmt = $this->pdo->query('SELECT COUNT(*) AS total FROM consulenze_fiscali_rate WHERE stato = "pending"');
        $openRates = 0;
        if ($openRatesStmt) {
            $openRates = (int) ($openRatesStmt->fetchColumn() ?: 0);
        }

        return [
            'statuses' => $counts,
            'reminders' => $reminders,
            'open_rates' => $openRates,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT cf.*, c.ragione_sociale, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email, c.cf_piva
            FROM consulenze_fiscali cf
            LEFT JOIN clienti c ON c.id = cf.cliente_id
            WHERE cf.id = :id');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($record === false) {
            return null;
        }

        $record['rate'] = $this->fetchRateSchedule($id);
        $record['documenti'] = $this->fetchDocuments($id);

        return $record;
    }

    public function create(array $payload, int $userId): int
    {
        $this->pdo->beginTransaction();
        try {
            $codice = $this->generateCodice();
            $stmt = $this->pdo->prepare('INSERT INTO consulenze_fiscali (
                    codice, cliente_id, intestatario_nome, codice_fiscale, tipo_modello, anno_riferimento, periodo_riferimento,
                    importo_totale, numero_rate, frequenza_rate, prima_scadenza, stato, promemoria_scadenza, note,
                    created_by, updated_by
                ) VALUES (
                    :codice, :cliente_id, :intestatario_nome, :codice_fiscale, :tipo_modello, :anno_riferimento, :periodo_riferimento,
                    :importo_totale, :numero_rate, :frequenza_rate, :prima_scadenza, :stato, :promemoria_scadenza, :note,
                    :created_by, :updated_by
                )');

            $stmt->execute([
                ':codice' => $codice,
                ':cliente_id' => $payload['cliente_id'] ?? null,
                ':intestatario_nome' => $payload['intestatario_nome'],
                ':codice_fiscale' => $payload['codice_fiscale'],
                ':tipo_modello' => $payload['tipo_modello'],
                ':anno_riferimento' => $payload['anno_riferimento'],
                ':periodo_riferimento' => $payload['periodo_riferimento'] ?? null,
                ':importo_totale' => $payload['importo_totale'],
                ':numero_rate' => $payload['numero_rate'],
                ':frequenza_rate' => $payload['frequenza_rate'],
                ':prima_scadenza' => $payload['prima_scadenza'],
                ':stato' => $payload['stato'] ?? 'bozza',
                ':promemoria_scadenza' => $payload['promemoria_scadenza'] ?? null,
                ':note' => $payload['note'] ?? null,
                ':created_by' => $userId,
                ':updated_by' => $userId,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->regenerateSchedule(
                $id,
                (float) $payload['importo_totale'],
                (int) $payload['numero_rate'],
                $payload['prima_scadenza'],
                $payload['frequenza_rate']
            );

            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function update(int $id, array $payload, int $userId, bool $regenerateSchedule = false): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE consulenze_fiscali SET
                cliente_id = :cliente_id,
                intestatario_nome = :intestatario_nome,
                codice_fiscale = :codice_fiscale,
                tipo_modello = :tipo_modello,
                anno_riferimento = :anno_riferimento,
                periodo_riferimento = :periodo_riferimento,
                importo_totale = :importo_totale,
                numero_rate = :numero_rate,
                frequenza_rate = :frequenza_rate,
                prima_scadenza = :prima_scadenza,
                stato = :stato,
                promemoria_scadenza = :promemoria_scadenza,
                note = :note,
                updated_by = :updated_by,
                updated_at = NOW()
            WHERE id = :id');

            $stmt->execute([
                ':cliente_id' => $payload['cliente_id'] ?? null,
                ':intestatario_nome' => $payload['intestatario_nome'],
                ':codice_fiscale' => $payload['codice_fiscale'],
                ':tipo_modello' => $payload['tipo_modello'],
                ':anno_riferimento' => $payload['anno_riferimento'],
                ':periodo_riferimento' => $payload['periodo_riferimento'] ?? null,
                ':importo_totale' => $payload['importo_totale'],
                ':numero_rate' => $payload['numero_rate'],
                ':frequenza_rate' => $payload['frequenza_rate'],
                ':prima_scadenza' => $payload['prima_scadenza'],
                ':stato' => $payload['stato'] ?? 'bozza',
                ':promemoria_scadenza' => $payload['promemoria_scadenza'] ?? null,
                ':note' => $payload['note'] ?? null,
                ':updated_by' => $userId,
                ':id' => $id,
            ]);

            if ($regenerateSchedule) {
                $this->regenerateSchedule(
                    $id,
                    (float) $payload['importo_totale'],
                    (int) $payload['numero_rate'],
                    $payload['prima_scadenza'],
                    $payload['frequenza_rate']
                );
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $documents = $this->fetchDocuments($id);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM consulenze_fiscali_rate WHERE consulenza_id = :id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM consulenze_fiscali_documenti WHERE consulenza_id = :id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM consulenze_fiscali WHERE id = :id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        foreach ($documents as $document) {
            $path = $document['file_path'] ?? '';
            if ($path && is_string($path)) {
                $absolute = $this->absoluteFromRelative($path);
                if ($absolute && is_file($absolute)) {
                    @unlink($absolute);
                }
            }
        }
    }

    public function toggleRateStatus(int $rateId, string $status): void
    {
        if (!array_key_exists($status, self::availableRateStatuses())) {
            throw new RuntimeException('Stato rata non valido.');
        }

        $rateStmt = $this->pdo->prepare('SELECT consulenza_id FROM consulenze_fiscali_rate WHERE id = :id');
        $rateStmt->execute([':id' => $rateId]);
        $rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
        if ($rate === false) {
            throw new RuntimeException('Rata non trovata.');
        }

        $params = [
            ':stato' => $status,
            ':pagato_il' => $status === 'paid' ? date('Y-m-d') : null,
            ':id' => $rateId,
        ];

        $this->pdo->prepare('UPDATE consulenze_fiscali_rate SET stato = :stato, pagato_il = :pagato_il WHERE id = :id')->execute($params);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function addDocument(int $consulenzaId, array $file, int $userId, bool $signed = true): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Caricamento documento non riuscito.');
        }

        if (!isset($file['tmp_name'], $file['name'], $file['size'])) {
            throw new RuntimeException('File non valido.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Upload non valido.');
        }

        if ((int) $file['size'] > self::MAX_UPLOAD_SIZE) {
            throw new RuntimeException('Il file supera il limite di 15 MB.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Formato file non supportato.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = $mime === 'application/pdf' ? 'pdf' : 'bin';
        }

        $filename = sprintf('CFIS_%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(4)), $extension);
        $relativePath = 'uploads/consulenza-fiscale/' . $filename;
        $absolutePath = $this->absoluteFromRelative($relativePath);
        if ($absolutePath === null) {
            throw new RuntimeException('Percorso file non disponibile.');
        }

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Impossibile salvare il documento.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO consulenze_fiscali_documenti (
            consulenza_id, file_name, file_path, mime_type, file_size, signed, uploaded_by
        ) VALUES (
            :consulenza_id, :file_name, :file_path, :mime_type, :file_size, :signed, :uploaded_by
        )');

        $stmt->execute([
            ':consulenza_id' => $consulenzaId,
            ':file_name' => $file['name'],
            ':file_path' => $relativePath,
            ':mime_type' => $mime,
            ':file_size' => (int) $file['size'],
            ':signed' => $signed ? 1 : 0,
            ':uploaded_by' => $userId,
        ]);

        return $this->fetchDocuments($consulenzaId);
    }

    public function deleteDocument(int $documentId): void
    {
        $stmt = $this->pdo->prepare('SELECT file_path FROM consulenze_fiscali_documenti WHERE id = :id');
        $stmt->execute([':id' => $documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($document === false) {
            return;
        }

        $this->pdo->prepare('DELETE FROM consulenze_fiscali_documenti WHERE id = :id')->execute([':id' => $documentId]);

        $absolute = $this->absoluteFromRelative($document['file_path'] ?? '');
        if ($absolute && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function markReminderSent(int $id): void
    {
        $this->pdo->prepare('UPDATE consulenze_fiscali SET promemoria_inviato_at = NOW() WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * @return array<string,string>
     */
    public static function availableStatuses(): array
    {
        return [
            'bozza' => 'Bozza',
            'in_lavorazione' => 'In lavorazione',
            'inviata' => 'Inviata al cliente',
            'completata' => 'Completata',
            'archiviata' => 'Archiviata',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function availableModelTypes(): array
    {
        return [
            'F24' => 'Modello F24',
            '730' => 'Modello 730',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function availableFrequencies(): array
    {
        return [
            'unica' => 'Unica soluzione',
            'mensile' => 'Mensile',
            'bimestrale' => 'Bimestrale',
            'trimestrale' => 'Trimestrale',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function availableRateStatuses(): array
    {
        return [
            'pending' => 'Da pagare',
            'paid' => 'Pagata',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchRateSchedule(int $consulenzaId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM consulenze_fiscali_rate WHERE consulenza_id = :id ORDER BY numero');
        $stmt->execute([':id' => $consulenzaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchDocuments(int $consulenzaId): array
    {
        $stmt = $this->pdo->prepare('SELECT d.*, u.username AS uploaded_by_username
            FROM consulenze_fiscali_documenti d
            LEFT JOIN users u ON u.id = d.uploaded_by
            WHERE d.consulenza_id = :id
            ORDER BY d.created_at DESC');
        $stmt->execute([':id' => $consulenzaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function regenerateSchedule(int $consulenzaId, float $total, int $rateCount, string $firstDue, string $frequency): void
    {
        $this->pdo->prepare('DELETE FROM consulenze_fiscali_rate WHERE consulenza_id = :id')->execute([':id' => $consulenzaId]);

        $rateCount = max(1, $rateCount);
        $totalCents = (int) round($total * 100);
        $base = intdiv($totalCents, $rateCount);
        $remainder = $totalCents % $rateCount;

        $dates = $this->generateScheduleDates($firstDue, $frequency, $rateCount);

        $insert = $this->pdo->prepare('INSERT INTO consulenze_fiscali_rate (
            consulenza_id, numero, importo, scadenza, stato
        ) VALUES (
            :consulenza_id, :numero, :importo, :scadenza, :stato
        )');

        for ($i = 0; $i < $rateCount; $i++) {
            $amountCents = $base;
            if ($i === $rateCount - 1) {
                $amountCents += $remainder;
            }

            $insert->execute([
                ':consulenza_id' => $consulenzaId,
                ':numero' => $i + 1,
                ':importo' => $amountCents / 100,
                ':scadenza' => $dates[$i] ?? $firstDue,
                ':stato' => 'pending',
            ]);
        }
    }

    /**
     * @return array<int,string>
     */
    private function generateScheduleDates(string $firstDue, string $frequency, int $rateCount): array
    {
        $dates = [];
        $current = DateTimeImmutable::createFromFormat('Y-m-d', $firstDue);
        if ($current === false) {
            $current = new DateTimeImmutable();
        }

        $intervalMap = [
            'mensile' => 'P1M',
            'bimestrale' => 'P2M',
            'trimestrale' => 'P3M',
        ];

        $interval = $intervalMap[$frequency] ?? null;

        for ($i = 0; $i < $rateCount; $i++) {
            $dates[] = $current->format('Y-m-d');
            if ($interval !== null) {
                $current = $current->add(new DateInterval($interval));
            }
        }

        return $dates;
    }

    private function generateCodice(): string
    {
        do {
            $candidate = sprintf('CFIS-%s-%04d', date('Ymd'), random_int(0, 9999));
            $stmt = $this->pdo->prepare('SELECT 1 FROM consulenze_fiscali WHERE codice = :codice LIMIT 1');
            $stmt->execute([':codice' => $candidate]);
            $exists = $stmt->fetchColumn() !== false;
        } while ($exists);

        return $candidate;
    }

    private function absoluteFromRelative(string $relativePath): ?string
    {
        $relativePath = ltrim($relativePath, '/\\');
        if ($relativePath === '') {
            return null;
        }

        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }
}
