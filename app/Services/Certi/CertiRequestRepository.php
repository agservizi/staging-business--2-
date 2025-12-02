<?php
declare(strict_types=1);

namespace App\Services\Certi;

use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class CertiRequestRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): array
    {
        $sql = <<<SQL
INSERT INTO certi_requests (
    tipo_certificato,
    categoria,
    dati_intestatario,
    stato,
    urgenza,
    note_interne,
    file_certificato,
    assegnato_a,
    ticket_id,
    docuengine_request_id,
    visengine_request_id,
    catasto_request_id,
    completata_il,
    created_by,
    updated_by
) VALUES (
    :tipo_certificato,
    :categoria,
    :dati_intestatario,
    :stato,
    :urgenza,
    :note_interne,
    :file_certificato,
    :assegnato_a,
    :ticket_id,
    :docuengine_request_id,
    :visengine_request_id,
    :catasto_request_id,
    :completata_il,
    :created_by,
    :updated_by
)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $this->bindRequestData($stmt, $data);
        $stmt->execute();
        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id);
    }

    public function findById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certi_requests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Richiesta certificato non trovata.');
        }

        return $this->hydrateRequest($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,summary:array<string,mixed>}
     */
    public function listRequests(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['categoria'])) {
            $where[] = 'categoria = :categoria';
            $params[':categoria'] = $filters['categoria'];
        }

        if (!empty($filters['stato'])) {
            $where[] = 'stato = :stato';
            $params[':stato'] = $filters['stato'];
        }

        if (!empty($filters['urgency'])) {
            $where[] = 'urgenza = :urgenza';
            $params[':urgenza'] = $filters['urgency'];
        }

        if (!empty($filters['assigned_to'])) {
            $where[] = 'assegnato_a = :assigned_to';
            $params[':assigned_to'] = $filters['assigned_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(JSON_SEARCH(dati_intestatario, "one", :search) IS NOT NULL OR tipo_certificato LIKE :search_like)';
            $params[':search'] = $filters['search'];
            $params[':search_like'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 15)));
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare("SELECT * FROM certi_requests {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM certi_requests {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        return [
            'items' => array_map(fn(array $row): array => $this->hydrateRequest($row), $rows),
            'total' => $total,
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * @return array{status:array<string,int>,category:array<string,int>}
     */
    public function getSummary(): array
    {
        $statusCounts = [];
        $stmt = $this->pdo->query('SELECT stato, COUNT(*) AS total FROM certi_requests GROUP BY stato');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $statusCounts[$row['stato']] = (int) $row['total'];
            }
        }

        $categoryCounts = [];
        $stmt = $this->pdo->query('SELECT categoria, COUNT(*) AS total FROM certi_requests GROUP BY categoria');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $categoryCounts[$row['categoria']] = (int) $row['total'];
            }
        }

        return [
            'status' => $statusCounts,
            'category' => $categoryCounts,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): array
    {
        $fields = [];
        $params = [':id' => $id];
        $updatable = [
            'tipo_certificato',
            'categoria',
            'dati_intestatario',
            'stato',
            'urgenza',
            'note_interne',
            'file_certificato',
            'assegnato_a',
            'ticket_id',
            'docuengine_request_id',
            'visengine_request_id',
            'catasto_request_id',
            'completata_il',
            'updated_by',
        ];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = $field . ' = :' . $field;
                $params[':' . $field] = $data[$field];
            }
        }

        if (!$fields) {
            return $this->findById($id);
        }

        $sql = 'UPDATE certi_requests SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function updateStatus(int $id, string $stato, ?int $userId, ?DateTimeImmutable $completedAt = null): array
    {
        $params = [
            ':id' => $id,
            ':stato' => $stato,
            ':updated_by' => $userId,
            ':completata_il' => $completedAt?->format('Y-m-d H:i:s'),
        ];

        $sql = 'UPDATE certi_requests SET stato = :stato, updated_by = :updated_by, completata_il = :completata_il, updated_at = NOW() WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateRequest(array $row): array
    {
        $row['dati_intestatario'] = $this->decodeJson($row['dati_intestatario']);
        return $row;
    }

    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function bindRequestData(PDOStatement $stmt, array $data): void
    {
        $stmt->bindValue(':tipo_certificato', (string) ($data['tipo_certificato'] ?? ''));
        $stmt->bindValue(':categoria', (string) ($data['categoria'] ?? 'comunale'));
        $stmt->bindValue(':dati_intestatario', json_encode($data['dati_intestatario'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue(':stato', (string) ($data['stato'] ?? 'nuova'));
        $stmt->bindValue(':urgenza', (string) ($data['urgenza'] ?? 'standard'));
        $stmt->bindValue(':note_interne', $data['note_interne'] ?? null);
        $stmt->bindValue(':file_certificato', $data['file_certificato'] ?? null);
        $stmt->bindValue(':assegnato_a', $data['assegnato_a'] ?? null, $data['assegnato_a'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':ticket_id', $data['ticket_id'] ?? null, $data['ticket_id'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':docuengine_request_id', $data['docuengine_request_id'] ?? null);
        $stmt->bindValue(':visengine_request_id', $data['visengine_request_id'] ?? null);
        $stmt->bindValue(':catasto_request_id', $data['catasto_request_id'] ?? null);
        $stmt->bindValue(':completata_il', $data['completata_il'] ?? null);
        $stmt->bindValue(':created_by', $data['created_by'] ?? null, $data['created_by'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':updated_by', $data['updated_by'] ?? null, $data['updated_by'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    }
}
