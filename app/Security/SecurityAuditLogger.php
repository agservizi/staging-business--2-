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
        $this->insertAuditRow($userId, $username, $success, $ip, $userAgent, $note);
    }

    /**
     * Generic security event logger (MFA, password change, recovery, etc.).
     * Stores the event in login_audit using the note field to track the action/context.
     */
    public function logSecurityEvent(
        ?int $userId,
        string $username,
        string $action,
        string $ip,
        string $userAgent,
        array $context = [],
        bool $success = true
    ): void {
        $noteParts = [$action];
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $noteParts[] = mb_substr($json, 0, 200);
            }
        }
        $note = implode(' | ', array_filter($noteParts));
        $this->insertAuditRow($userId, $username, $success, $ip, $userAgent, $note);
    }

    private function insertAuditRow(
        ?int $userId,
        string $username,
        bool $success,
        string $ip,
        string $userAgent,
        ?string $note
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
