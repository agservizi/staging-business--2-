<?php
http_response_code(410);
exit;
__halt_compiler();

use PDO;
use RuntimeException;
use Throwable;

final class DocumentService
{
    private const DEFAULT_STATUS = 'draft';
    private const ALLOWED_STATUSES = ['draft', 'review', 'published', 'archived'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listDocuments(int $limit = 20, ?string $search = null): array
    {
        $limit = max(1, min(100, $limit));
        $params = [];
        $where = '';

        if ($search !== null && trim($search) !== '') {
            $where = 'WHERE titolo LIKE :search OR categoria LIKE :search';
            $params[':search'] = '%' . trim($search) . '%';
        }

        $sql = 'SELECT id, uuid, titolo, categoria, stato, current_version, updated_at '
            . 'FROM office_documents '
            . $where
            . ' ORDER BY updated_at DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getDocument(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM office_documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            return null;
        }

        $document['tags'] = $document['tags'] ? json_decode((string) $document['tags'], true) : [];
        $document['revisions'] = $this->listRevisions($id, 8);

        return $document;
    }

    /**
     * @return array<string,mixed>
     */
    public function saveDocument(array $payload, int $userId): array
    {
        $title = trim((string) ($payload['title'] ?? 'Nuovo documento'));
        $category = trim((string) ($payload['category'] ?? 'Generico'));
        $status = $this->normalizeStatus((string) ($payload['status'] ?? self::DEFAULT_STATUS));
        $content = (string) ($payload['content'] ?? '');
        $notes = trim((string) ($payload['notes'] ?? '')) ?: null;
        $tagsJson = $this->normalizeTags($payload['tags'] ?? null);
        $clienteId = isset($payload['cliente_id']) ? (int) $payload['cliente_id'] : null;
        if ($clienteId !== null && $clienteId <= 0) {
            $clienteId = null;
        }
        $ownerId = isset($payload['owner_id']) ? (int) $payload['owner_id'] : $userId;
        if ($ownerId !== null && $ownerId <= 0) {
            $ownerId = $userId > 0 ? $userId : null;
        }
        $documentId = isset($payload['id']) ? (int) $payload['id'] : null;

        if ($title === '') {
            throw new RuntimeException('Il titolo del documento non può essere vuoto.');
        }

        if ($content === '') {
            throw new RuntimeException('Il contenuto del documento è obbligatorio.');
        }

        $this->pdo->beginTransaction();

        try {
            if ($documentId === null) {
                $documentId = $this->insertDocument($title, $category, $status, $ownerId, $clienteId, $tagsJson, $notes);
                $currentVersion = 0;
            } else {
                $currentVersion = $this->updateDocument($documentId, $title, $category, $status, $ownerId, $clienteId, $tagsJson, $notes);
            }

            $newVersion = $currentVersion + 1;
            $this->insertRevision($documentId, $newVersion, $title, $content, $tagsJson, $userId);
            $this->touchDocument($documentId, $newVersion);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $document = $this->getDocument($documentId);
        if ($document === null) {
            throw new RuntimeException('Documento non trovato dopo il salvataggio.');
        }

        return $document;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRevisions(int $documentId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, versione, titolo_snapshot, commento, created_by, created_at '
            . 'FROM office_document_revisions WHERE document_id = :id '
            . 'ORDER BY versione DESC LIMIT ' . $limit
        );
        $stmt->execute([':id' => $documentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getLatestRevision(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, versione, contenuto, created_at '
            . 'FROM office_document_revisions WHERE document_id = :id '
            . 'ORDER BY versione DESC LIMIT 1'
        );
        $stmt->execute([':id' => $documentId]);

        $revision = $stmt->fetch(PDO::FETCH_ASSOC);
        return $revision ?: null;
    }

    private function insertDocument(
        string $title,
        string $category,
        string $status,
        ?int $ownerId,
        ?int $clienteId,
        ?string $tags,
        ?string $notes
    ): int {
        $uuid = $this->generateUuid();
        $slug = $this->generateUniqueSlug($title);

        $stmt = $this->pdo->prepare(
            'INSERT INTO office_documents (uuid, titolo, slug, categoria, stato, owner_id, cliente_id, tags, notes) '
            . 'VALUES (:uuid, :titolo, :slug, :categoria, :stato, :owner, :cliente, :tags, :notes)'
        );

        $stmt->execute([
            ':uuid' => $uuid,
            ':titolo' => $title,
            ':slug' => $slug,
            ':categoria' => $category,
            ':stato' => $status,
            ':owner' => $ownerId,
            ':cliente' => $clienteId,
            ':tags' => $tags,
            ':notes' => $notes,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updateDocument(
        int $documentId,
        string $title,
        string $category,
        string $status,
        ?int $ownerId,
        ?int $clienteId,
        ?string $tags,
        ?string $notes
    ): int {
        $stmt = $this->pdo->prepare('SELECT current_version FROM office_documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $documentId]);
        $currentVersion = (int) ($stmt->fetchColumn() ?: 0);

        $update = $this->pdo->prepare(
            'UPDATE office_documents SET titolo = :titolo, categoria = :categoria, stato = :stato, '
            . 'owner_id = :owner, cliente_id = :cliente, tags = :tags, notes = :notes '
            . 'WHERE id = :id'
        );

        $update->execute([
            ':titolo' => $title,
            ':categoria' => $category,
            ':stato' => $status,
            ':owner' => $ownerId,
            ':cliente' => $clienteId,
            ':tags' => $tags,
            ':notes' => $notes,
            ':id' => $documentId,
        ]);

        return $currentVersion;
    }

    private function insertRevision(
        int $documentId,
        int $version,
        string $title,
        string $content,
        ?string $metadata,
        int $userId,
        ?string $comment = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO office_document_revisions (document_id, versione, titolo_snapshot, contenuto, metadata, commento, created_by) '
            . 'VALUES (:document_id, :versione, :titolo, :contenuto, :metadata, :commento, :created_by)'
        );

        $stmt->execute([
            ':document_id' => $documentId,
            ':versione' => $version,
            ':titolo' => $title,
            ':contenuto' => $content,
            ':metadata' => $metadata,
            ':commento' => $comment,
            ':created_by' => $userId,
        ]);
    }

    public function revertToRevision(int $documentId, int $revisionId, int $userId): array
    {
        $revision = $this->getRevision($documentId, $revisionId);
        if (!$revision) {
            throw new RuntimeException('Versione indicata non trovata.');
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT current_version FROM office_documents WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $documentId]);
            $currentVersion = (int) ($stmt->fetchColumn() ?: 0);
            $newVersion = $currentVersion + 1;

            $this->insertRevision(
                $documentId,
                $newVersion,
                (string) $revision['titolo_snapshot'],
                (string) $revision['contenuto'],
                $revision['metadata'] ?? null,
                $userId,
                sprintf('Ripristino versione %d', (int) $revision['versione'])
            );

            $this->touchDocument($documentId, $newVersion);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $document = $this->getDocument($documentId);
        if ($document === null) {
            throw new RuntimeException('Documento non trovato dopo il ripristino.');
        }

        return $document;
    }

    public function getRevision(int $documentId, int $revisionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM office_document_revisions WHERE document_id = :document AND id = :revision LIMIT 1'
        );
        $stmt->execute([
            ':document' => $documentId,
            ':revision' => $revisionId,
        ]);

        $revision = $stmt->fetch(PDO::FETCH_ASSOC);
        return $revision ?: null;
    }

    private function touchDocument(int $documentId, int $version): void
    {
        $stmt = $this->pdo->prepare('UPDATE office_documents SET current_version = :version, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':version' => $version,
            ':id' => $documentId,
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
            $base = 'documento-' . bin2hex(random_bytes(4));
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
        $stmt = $this->pdo->prepare('SELECT 1 FROM office_documents WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);

        return (bool) $stmt->fetchColumn();
    }
}
