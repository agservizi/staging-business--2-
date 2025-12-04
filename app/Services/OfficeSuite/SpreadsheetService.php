<?php
declare(strict_types=1);

namespace App\Services\OfficeSuite;

use PDO;
use RuntimeException;
use Throwable;

final class SpreadsheetService
{
    private const DEFAULT_STATUS = 'draft';
    private const ALLOWED_STATUSES = ['draft', 'review', 'published', 'archived'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listSheets(int $limit = 20, ?string $search = null): array
    {
        $limit = max(1, min(100, $limit));
        $params = [];
        $where = '';

        if ($search !== null && trim($search) !== '') {
            $where = 'WHERE titolo LIKE :search OR categoria LIKE :search';
            $params[':search'] = '%' . trim($search) . '%';
        }

        $sql = 'SELECT id, uuid, titolo, categoria, stato, current_version, updated_at '
            . 'FROM office_spreadsheets '
            . $where
            . ' ORDER BY updated_at DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSheet(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM office_spreadsheets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $sheet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sheet) {
            return null;
        }

        $sheet['tags'] = $sheet['tags'] ? json_decode((string) $sheet['tags'], true) : [];
        $sheet['revisions'] = $this->listRevisions($id, 8);

        return $sheet;
    }

    /**
     * @return array<string,mixed>
     */
    public function saveSheet(array $payload, int $userId): array
    {
        $title = trim((string) ($payload['title'] ?? 'Nuovo foglio'));
        $category = trim((string) ($payload['category'] ?? 'Standard'));
        $status = $this->normalizeStatus((string) ($payload['status'] ?? self::DEFAULT_STATUS));
        $gridState = (string) ($payload['grid'] ?? '');
        $tagsJson = $this->normalizeTags($payload['tags'] ?? null);
        $metadataJson = $this->normalizeMetadata($payload['grid_meta'] ?? null);
        $sheetId = isset($payload['id']) ? (int) $payload['id'] : null;
        $ownerId = isset($payload['owner_id']) ? (int) $payload['owner_id'] : $userId;
        if ($ownerId !== null && $ownerId <= 0) {
            $ownerId = $userId > 0 ? $userId : null;
        }

        if ($title === '') {
            throw new RuntimeException('Il titolo del foglio non può essere vuoto.');
        }

        if ($gridState === '') {
            throw new RuntimeException('La matrice del foglio è obbligatoria.');
        }

        $this->pdo->beginTransaction();

        try {
            if ($sheetId === null) {
                $sheetId = $this->insertSheet($title, $category, $status, $ownerId, $tagsJson);
                $currentVersion = 0;
            } else {
                $currentVersion = $this->updateSheet($sheetId, $title, $category, $status, $ownerId, $tagsJson);
            }

            $newVersion = $currentVersion + 1;
            $this->insertRevision($sheetId, $newVersion, $title, $gridState, $metadataJson, $userId);
            $this->touchSheet($sheetId, $newVersion);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $sheet = $this->getSheet($sheetId);
        if ($sheet === null) {
            throw new RuntimeException('Foglio non trovato dopo il salvataggio.');
        }

        return $sheet;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRevisions(int $sheetId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, versione, titolo_snapshot, commento, created_by, created_at '
            . 'FROM office_spreadsheet_revisions WHERE spreadsheet_id = :id '
            . 'ORDER BY versione DESC LIMIT ' . $limit
        );
        $stmt->execute([':id' => $sheetId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getLatestRevision(int $sheetId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, versione, grid_state, metadata, created_at '
            . 'FROM office_spreadsheet_revisions WHERE spreadsheet_id = :id '
            . 'ORDER BY versione DESC LIMIT 1'
        );
        $stmt->execute([':id' => $sheetId]);

        $revision = $stmt->fetch(PDO::FETCH_ASSOC);
        return $revision ?: null;
    }

    private function insertSheet(
        string $title,
        string $category,
        string $status,
        ?int $ownerId,
        ?string $tags
    ): int {
        $uuid = $this->generateUuid();
        $slug = $this->generateUniqueSlug($title);

        $stmt = $this->pdo->prepare(
            'INSERT INTO office_spreadsheets (uuid, titolo, slug, categoria, stato, owner_id, tags) '
            . 'VALUES (:uuid, :titolo, :slug, :categoria, :stato, :owner, :tags)'
        );

        $stmt->execute([
            ':uuid' => $uuid,
            ':titolo' => $title,
            ':slug' => $slug,
            ':categoria' => $category,
            ':stato' => $status,
            ':owner' => $ownerId,
            ':tags' => $tags,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updateSheet(
        int $sheetId,
        string $title,
        string $category,
        string $status,
        ?int $ownerId,
        ?string $tags
    ): int {
        $stmt = $this->pdo->prepare('SELECT current_version FROM office_spreadsheets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sheetId]);
        $currentVersion = (int) ($stmt->fetchColumn() ?: 0);

        $update = $this->pdo->prepare(
            'UPDATE office_spreadsheets SET titolo = :titolo, categoria = :categoria, stato = :stato, '
            . 'owner_id = :owner, tags = :tags WHERE id = :id'
        );

        $update->execute([
            ':titolo' => $title,
            ':categoria' => $category,
            ':stato' => $status,
            ':owner' => $ownerId,
            ':tags' => $tags,
            ':id' => $sheetId,
        ]);

        return $currentVersion;
    }

    private function insertRevision(
        int $sheetId,
        int $version,
        string $title,
        string $grid,
        ?string $metadata,
        int $userId,
        ?string $comment = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO office_spreadsheet_revisions (spreadsheet_id, versione, titolo_snapshot, grid_state, metadata, commento, created_by) '
            . 'VALUES (:sheet_id, :versione, :titolo, :grid, :metadata, :commento, :created_by)'
        );

        $stmt->execute([
            ':sheet_id' => $sheetId,
            ':versione' => $version,
            ':titolo' => $title,
            ':grid' => $grid,
            ':metadata' => $metadata,
            ':commento' => $comment,
            ':created_by' => $userId,
        ]);
    }

    public function revertToRevision(int $sheetId, int $revisionId, int $userId): array
    {
        $revision = $this->getRevision($sheetId, $revisionId);
        if (!$revision) {
            throw new RuntimeException('Versione del foglio non trovata.');
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT current_version FROM office_spreadsheets WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $sheetId]);
            $currentVersion = (int) ($stmt->fetchColumn() ?: 0);
            $newVersion = $currentVersion + 1;

            $this->insertRevision(
                $sheetId,
                $newVersion,
                (string) $revision['titolo_snapshot'],
                (string) $revision['grid_state'],
                $revision['metadata'] ?? null,
                $userId,
                sprintf('Ripristino versione %d', (int) $revision['versione'])
            );

            $this->touchSheet($sheetId, $newVersion);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $sheet = $this->getSheet($sheetId);
        if ($sheet === null) {
            throw new RuntimeException('Foglio non trovato dopo il ripristino.');
        }

        return $sheet;
    }

    public function getRevision(int $sheetId, int $revisionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM office_spreadsheet_revisions WHERE spreadsheet_id = :sheet AND id = :revision LIMIT 1'
        );
        $stmt->execute([
            ':sheet' => $sheetId,
            ':revision' => $revisionId,
        ]);

        $revision = $stmt->fetch(PDO::FETCH_ASSOC);
        return $revision ?: null;
    }

    private function touchSheet(int $sheetId, int $version): void
    {
        $stmt = $this->pdo->prepare('UPDATE office_spreadsheets SET current_version = :version, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':version' => $version,
            ':id' => $sheetId,
        ]);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower($status);
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return self::DEFAULT_STATUS;
        }

        return $status;
    }

    private function normalizeTags(null|array|string $tags): ?string
    {
        if ($tags === null) {
            return null;
        }

        if (is_string($tags)) {
            $fragments = array_filter(array_map('trim', explode(',', $tags)), static fn($value) => $value !== '');
            if (!$fragments) {
                return null;
            }
            $tags = array_values(array_unique($fragments));
        }

        if (!$tags) {
            return null;
        }

        return json_encode(array_values($tags), JSON_UNESCAPED_UNICODE);
    }

    private function normalizeMetadata(null|string $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        $metadata = trim($metadata);
        if ($metadata === '') {
            return null;
        }

        json_decode($metadata, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Metadati del foglio non validi.');
        }

        return $metadata;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function generateUniqueSlug(string $title): string
    {
        $base = $this->slugify($title);
        if ($base === '') {
            $base = 'foglio-' . bin2hex(random_bytes(4));
        }

        $slug = $base;
        $counter = 1;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugify(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: '';
        return trim($value, '-');
    }

    private function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM office_spreadsheets WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);

        return (bool) $stmt->fetchColumn();
    }
}
