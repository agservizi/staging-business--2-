<?php
declare(strict_types=1);

class IliadCredentialsService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createCredential(array $data, int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO iliad_credentials (
                fibra_id, fibra_password, mobile_id, mobile_password,
                include_fibra, include_mobile, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['fibra_id'],
            $data['fibra_password'],
            $data['mobile_id'],
            $data['mobile_password'],
            $data['include_fibra'] ? 1 : 0,
            $data['include_mobile'] ? 1 : 0,
            $userId
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getCredential(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM iliad_credentials WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function listCredentials(int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("
            SELECT ic.*, u.username as created_by_name
            FROM iliad_credentials ic
            LEFT JOIN users u ON ic.created_by = u.id
            ORDER BY ic.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$perPage, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countCredentials(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM iliad_credentials");
        return (int) $stmt->fetchColumn();
    }

    public function deleteCredential(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM iliad_credentials WHERE id = ?");
        return $stmt->execute([$id]);
    }
}