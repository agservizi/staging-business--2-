<?php
declare(strict_types=1);

namespace App\Services\Security;

use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class MfaQrService
{
    private const DEVICE_LIMIT = 5;
    private const DEFAULT_PROVISIONING_TTL = 900; // 15 minutes
    private const DEFAULT_CHALLENGE_TTL = 180; // 3 minutes
    private const PIN_ATTEMPT_LIMIT = 5;
    private const PIN_LOCK_SECONDS = 300;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDevices(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mfa_qr_devices WHERE user_id = :user_id ORDER BY created_at DESC');
        $stmt->execute([':user_id' => $userId]);
        $devices = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $devices[] = $this->mapDevice($row);
        }

        return $devices;
    }

    public function countActiveDevices(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM mfa_qr_devices WHERE user_id = :user_id AND status = 'active'");
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function hasActiveDevices(int $userId): bool
    {
        return $this->countActiveDevices($userId) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function createDevice(int $userId, string $label, string $pin, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_PROVISIONING_TTL;
        $this->expirePendingDevices($userId);

        if ($this->countNonRevokedDevices($userId) >= self::DEVICE_LIMIT) {
            throw new RuntimeException('Hai raggiunto il numero massimo di dispositivi registrati.');
        }

        $uuid = $this->generateUuid();
        $token = $this->generateToken();
        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
        $expiresAt = (new DateTimeImmutable('now'))
            ->add(new DateInterval('PT' . max(30, $ttl) . 'S'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('INSERT INTO mfa_qr_devices (
            user_id, device_uuid, device_label, provisioning_token, provisioning_expires_at, pin_hash, failed_pin_attempts, pin_locked_until, status, created_at, updated_at
        ) VALUES (
            :user_id, :device_uuid, :device_label, :provisioning_token, :provisioning_expires_at, :pin_hash, 0, NULL, "pending", NOW(), NOW()
        )');

        $stmt->execute([
            ':user_id' => $userId,
            ':device_uuid' => $uuid,
            ':device_label' => $label,
            ':provisioning_token' => $token,
            ':provisioning_expires_at' => $expiresAt,
            ':pin_hash' => $pinHash,
        ]);

        $deviceId = (int) $this->pdo->lastInsertId();

        return array_merge(
            $this->mapDevice($this->getDeviceRow($deviceId)),
            [
                'provisioning_token' => $token,
            ]
        );
    }

    public function revokeDevice(int $userId, string $deviceUuid): bool
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_qr_devices
            SET status = "revoked", revoked_at = NOW(), updated_at = NOW(), provisioning_token = NULL, provisioning_expires_at = NULL
            WHERE user_id = :user_id AND device_uuid = :device_uuid AND status <> "revoked"');

        $stmt->execute([
            ':user_id' => $userId,
            ':device_uuid' => $deviceUuid,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activateDeviceByToken(string $token): ?array
    {
        $device = $this->getDeviceByProvisioningToken($token);
        if ($device === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('UPDATE mfa_qr_devices
            SET status = "active", provisioning_token = NULL, provisioning_expires_at = NULL, updated_at = NOW()
            WHERE id = :id AND status = "pending"');
        $stmt->execute([':id' => $device['id']]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->mapDevice($this->getDeviceRow($device['id']));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDeviceByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mfa_qr_devices WHERE device_uuid = :uuid LIMIT 1');
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->mapDevice($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function createChallenge(int $userId, string $ip, string $userAgent, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_CHALLENGE_TTL;
        $this->expireChallenges($userId);

        $token = $this->generateToken();
        $expiresAt = (new DateTimeImmutable('now'))
            ->add(new DateInterval('PT' . max(60, $ttl) . 'S'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('INSERT INTO mfa_qr_challenges (
            user_id, challenge_token, status, ip_address, user_agent, expires_at, created_at, updated_at
        ) VALUES (
            :user_id, :challenge_token, "pending", :ip_address, :user_agent, :expires_at, NOW(), NOW()
        )');

        $stmt->execute([
            ':user_id' => $userId,
            ':challenge_token' => $token,
            ':ip_address' => $ip,
            ':user_agent' => $userAgent,
            ':expires_at' => $expiresAt,
        ]);

        $challengeId = (int) $this->pdo->lastInsertId();

        return array_merge(
            $this->mapChallenge($this->getChallengeRow($challengeId)),
            [
                'challenge_token' => $token,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getChallengeByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mfa_qr_challenges WHERE challenge_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->mapChallenge($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function approveChallenge(string $challengeToken, array $device): ?array
    {
        $challenge = $this->getChallengeByToken($challengeToken);
        if ($challenge === null) {
            return null;
        }

        if ((int) $challenge['user_id'] !== (int) $device['user_id']) {
            return null;
        }

        if (!$this->isChallengePending($challenge)) {
            return $challenge;
        }

        try {
            $this->pdo->beginTransaction();

            $update = $this->pdo->prepare('UPDATE mfa_qr_challenges
                SET status = "approved", device_id = :device_id, approved_at = NOW(), updated_at = NOW()
                WHERE id = :id AND status = "pending"');
            $update->execute([
                ':device_id' => $device['id'],
                ':id' => $challenge['id'],
            ]);

            if ($update->rowCount() === 0) {
                $this->pdo->rollBack();
                return $this->getChallengeByToken($challengeToken);
            }

            $deviceUpdate = $this->pdo->prepare('UPDATE mfa_qr_devices SET last_used_at = NOW(), updated_at = NOW() WHERE id = :id');
            $deviceUpdate->execute([':id' => $device['id']]);

            $this->resetPinFailures((int) $device['id']);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return $this->getChallengeByToken($challengeToken);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function denyChallenge(string $challengeToken, ?int $deviceId = null): ?array
    {
        $challenge = $this->getChallengeByToken($challengeToken);
        if ($challenge === null) {
            return null;
        }

        if (!$this->isChallengePending($challenge)) {
            return $challenge;
        }

        $stmt = $this->pdo->prepare('UPDATE mfa_qr_challenges
            SET status = "denied", denied_at = NOW(), device_id = COALESCE(:device_id, device_id), updated_at = NOW()
            WHERE id = :id AND status = "pending"');
        $stmt->execute([
            ':device_id' => $deviceId,
            ':id' => $challenge['id'],
        ]);

        return $this->getChallengeByToken($challengeToken);
    }

    public function verifyDevicePin(array $device, string $pin): bool
    {
        if ($this->isDeviceLocked($device)) {
            return false;
        }
        $hash = $device['pin_hash'] ?? '';
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify($pin, $hash);
    }

    public function isDeviceLocked(array $device): bool
    {
        if (empty($device['pin_locked_until'])) {
            return false;
        }

        try {
            $lockUntil = new DateTimeImmutable((string) $device['pin_locked_until']);
        } catch (Throwable) {
            return false;
        }

        return $lockUntil > new DateTimeImmutable('now');
    }

    public function registerFailedPinAttempt(int $deviceId): array
    {
        $lockUntil = (new DateTimeImmutable('now'))
            ->add(new DateInterval('PT' . self::PIN_LOCK_SECONDS . 'S'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('UPDATE mfa_qr_devices
            SET failed_pin_attempts = failed_pin_attempts + 1,
                pin_locked_until = CASE WHEN failed_pin_attempts + 1 >= :limit THEN :lock_until ELSE pin_locked_until END,
                updated_at = NOW()
            WHERE id = :id');
        $stmt->execute([
            ':limit' => self::PIN_ATTEMPT_LIMIT,
            ':lock_until' => $lockUntil,
            ':id' => $deviceId,
        ]);

        return $this->getDeviceRow($deviceId);
    }

    public function resetPinFailures(int $deviceId): array
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_qr_devices SET failed_pin_attempts = 0, pin_locked_until = NULL, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $deviceId]);
        return $this->getDeviceRow($deviceId);
    }

    public function getPinAttemptLimit(): int
    {
        return self::PIN_ATTEMPT_LIMIT;
    }

    public function getPinLockSeconds(): int
    {
        return self::PIN_LOCK_SECONDS;
    }

    public function expireChallenges(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_qr_challenges
            SET status = "expired", updated_at = NOW()
            WHERE user_id = :user_id AND status = "pending" AND expires_at < NOW()');
        $stmt->execute([':user_id' => $userId]);
    }

    public function expirePendingDevices(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_qr_devices
            SET status = "revoked", revoked_at = NOW(), provisioning_token = NULL, provisioning_expires_at = NULL, updated_at = NOW()
            WHERE user_id = :user_id AND status = "pending" AND provisioning_expires_at IS NOT NULL AND provisioning_expires_at < NOW()');
        $stmt->execute([':user_id' => $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDeviceByProvisioningToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mfa_qr_devices
            WHERE provisioning_token = :token
            AND status = "pending"
            AND (provisioning_expires_at IS NULL OR provisioning_expires_at >= NOW())
            LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->mapDevice($row);
    }

    private function countNonRevokedDevices(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM mfa_qr_devices WHERE user_id = :user_id AND status <> 'revoked'");
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function getDeviceRow(int $deviceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mfa_qr_devices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $deviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Dispositivo MFA non trovato.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function getChallengeRow(int $challengeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mfa_qr_challenges WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $challengeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Challenge MFA non trovata.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapDevice(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'device_uuid' => (string) ($row['device_uuid'] ?? ''),
            'device_label' => (string) ($row['device_label'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'provisioning_token' => $row['provisioning_token'] ?? null,
            'provisioning_expires_at' => $row['provisioning_expires_at'] ?? null,
            'last_used_at' => $row['last_used_at'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'pin_hash' => (string) ($row['pin_hash'] ?? ''),
            'failed_pin_attempts' => (int) ($row['failed_pin_attempts'] ?? 0),
            'pin_locked_until' => $row['pin_locked_until'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapChallenge(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'device_id' => isset($row['device_id']) ? (int) $row['device_id'] : null,
            'challenge_token' => (string) ($row['challenge_token'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'approved_at' => $row['approved_at'] ?? null,
            'denied_at' => $row['denied_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param array<string, mixed> $challenge
     */
    private function isChallengePending(array $challenge): bool
    {
        if (($challenge['status'] ?? '') !== 'pending') {
            return false;
        }

        $expiresAt = $challenge['expires_at'] ?? null;
        if ($expiresAt === null) {
            return false;
        }

        $expiry = new DateTimeImmutable($expiresAt);
        if ($expiry < new DateTimeImmutable('now')) {
            $this->expireChallenge((int) $challenge['id']);
            return false;
        }

        return true;
    }

    private function expireChallenge(int $challengeId): void
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_qr_challenges SET status = "expired", updated_at = NOW() WHERE id = :id AND status = "pending"');
        $stmt->execute([':id' => $challengeId]);
    }
}
