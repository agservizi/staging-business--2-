<?php
declare(strict_types=1);

namespace App\Services\Certi;

use PDO;

final class CertiLogService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function log(int $requestId, string $action, ?int $actorId, ?string $actorName, ?array $context = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO certi_logs (request_id, action, actor_id, actor_name, details, old_snapshot, new_snapshot) VALUES (:request_id, :action, :actor_id, :actor_name, :details, :old_snapshot, :new_snapshot)');
        $stmt->execute([
            ':request_id' => $requestId,
            ':action' => $action,
            ':actor_id' => $actorId,
            ':actor_name' => $actorName,
            ':details' => $context['details'] ?? null,
            ':old_snapshot' => isset($context['old']) ? json_encode($context['old'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':new_snapshot' => isset($context['new']) ? json_encode($context['new'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listByRequest(int $requestId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, action, actor_id, actor_name, details, old_snapshot, new_snapshot, created_at FROM certi_logs WHERE request_id = :request_id ORDER BY created_at DESC');
        $stmt->execute([':request_id' => $requestId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $row['old_snapshot'] = $row['old_snapshot'] ? json_decode((string) $row['old_snapshot'], true) : null;
            $row['new_snapshot'] = $row['new_snapshot'] ? json_decode((string) $row['new_snapshot'], true) : null;
            return $row;
        }, $rows);
    }
}
