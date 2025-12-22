<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore', 'Collaboratore');

// Service per gestire le credenziali Iliad
class IliadCredentialsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listCredentials(int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare('
            SELECT id, fibra_id, fibra_password, mobile_id, mobile_password, include_fibra, include_mobile, created_at
            FROM iliad_credentials
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countCredentials(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM iliad_credentials');
        return (int) $stmt->fetchColumn();
    }

    public function createCredential(array $data, int $userId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO iliad_credentials (fibra_id, fibra_password, mobile_id, mobile_password, include_fibra, include_mobile, created_by)
            VALUES (:fibra_id, :fibra_password, :mobile_id, :mobile_password, :include_fibra, :include_mobile, :created_by)
        ');
        $stmt->execute([
            ':fibra_id' => $data['fibra_id'] ?? null,
            ':fibra_password' => $data['fibra_password'],
            ':mobile_id' => $data['mobile_id'] ?? null,
            ':mobile_password' => $data['mobile_password'],
            ':include_fibra' => isset($data['include_fibra']) ? 1 : 0,
            ':include_mobile' => isset($data['include_mobile']) ? 1 : 0,
            ':created_by' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getCredential(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM iliad_credentials WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteCredential(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM iliad_credentials WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}

$iliadService = new IliadCredentialsService($pdo);