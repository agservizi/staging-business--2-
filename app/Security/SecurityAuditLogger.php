<?php
declare(strict_types=1);

namespace App\Security;

use PDO;
use Throwable;

final class SecurityAuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function logLoginAttempt(
        ?int $userId,
        string $username,
        bool $success,
        string $ip,
        string $userAgent,
        ?string $note = null
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO login_audit (user_id, username, success, ip_address, user_agent, note, created_at)
                 VALUES (:user_id, :username, :success, :ip_address, :user_agent, :note, NOW())'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':username' => $username,
                ':success' => $success ? 1 : 0,
                ':ip_address' => $ip,
                ':user_agent' => $userAgent,
                ':note' => $note,
            ]);
        } catch (Throwable $exception) {
            error_log('Security audit log failed: ' . $exception->getMessage());
        }
    }
}
