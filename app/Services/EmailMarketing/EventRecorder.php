<?php
declare(strict_types=1);

namespace App\Services\EmailMarketing;

use DateTimeImmutable;
use Exception;
use JsonException;
use PDO;
use Throwable;

final class EventRecorder
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Apply a campaign event, updating recipient metrics and aggregations.
     *
     * @param array<string, mixed> $context
     */
    public function apply(int $campaignId, int $recipientId, string $event, array $context = [], ?string $occurredAt = null): void
    {
        $event = $this->normalizeEventType($event);
        $timestamp = $this->resolveTimestamp($occurredAt);

        $this->pdo->beginTransaction();
        try {
            $recipient = $this->fetchRecipient($recipientId);
            if ($recipient === null || (int) ($recipient['campaign_id'] ?? 0) !== $campaignId) {
                $this->pdo->commit();
                return;
            }

            $this->updateRecipientState($recipient, $event, $timestamp, $context);

            if ($this->shouldStoreEvent($event)) {
                $this->storeEvent($campaignId, $recipientId, $event, $context, $timestamp);
            }

            $this->recalculateMetrics($campaignId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRecipient(int $recipientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_campaign_recipients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $recipientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $recipient
     * @param array<string, mixed> $context
     */
    private function updateRecipientState(array $recipient, string $event, DateTimeImmutable $timestamp, array $context): void
    {
        $recipientId = (int) $recipient['id'];
        $subscriberId = (int) ($recipient['subscriber_id'] ?? 0);
        $formattedTs = $timestamp->format('Y-m-d H:i:s');

        switch ($event) {
            case 'delivered':
                if (($recipient['status'] ?? '') !== 'sent') {
                    $stmt = $this->pdo->prepare("UPDATE email_campaign_recipients SET status = 'sent', sent_at = :sent_at, updated_at = NOW() WHERE id = :id");
                    $stmt->execute([
                        ':sent_at' => $formattedTs,
                        ':id' => $recipientId,
                    ]);
                }
                $this->touchSubscriberEngagement($subscriberId, $timestamp, false);
                break;

            case 'open':
                $stmt = $this->pdo->prepare('UPDATE email_campaign_recipients SET opens = opens + 1, last_open_at = :opened_at, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':opened_at' => $formattedTs,
                    ':id' => $recipientId,
                ]);
                $this->touchSubscriberEngagement($subscriberId, $timestamp, true);
                break;

            case 'click':
                $stmt = $this->pdo->prepare('UPDATE email_campaign_recipients SET clicks = clicks + 1, last_click_at = :clicked_at, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':clicked_at' => $formattedTs,
                    ':id' => $recipientId,
                ]);
                $this->touchSubscriberEngagement($subscriberId, $timestamp, true);
                break;

            case 'bounce':
                $reason = $this->extractReason($context, 'Email rimbalzata');
                $stmt = $this->pdo->prepare("UPDATE email_campaign_recipients SET status = 'failed', last_error = :reason, updated_at = NOW() WHERE id = :id");
                $stmt->execute([
                    ':reason' => $reason,
                    ':id' => $recipientId,
                ]);
                $this->markSubscriberStatus($subscriberId, 'bounced', $timestamp);
                break;

            case 'complaint':
                $this->consumeUnsubscribeToken($recipientId);
                $remark = $this->extractReason($context, 'Segnalazione spam');
                $this->markSubscriberStatus($subscriberId, 'unsubscribed', $timestamp);
                $this->updateRecipientRemark($recipientId, $remark);
                break;

            case 'unsubscribe':
                $this->consumeUnsubscribeToken($recipientId);
                $remark = $this->extractReason($context, 'Disiscrizione utente');
                $this->markSubscriberStatus($subscriberId, 'unsubscribed', $timestamp);
                $this->updateRecipientRemark($recipientId, $remark);
                break;

            default:
                break;
        }
    }

    private function consumeUnsubscribeToken(int $recipientId): void
    {
        $stmt = $this->pdo->prepare('UPDATE email_campaign_recipients SET unsubscribe_token = NULL, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $recipientId]);
    }

    private function updateRecipientRemark(int $recipientId, string $remark): void
    {
        $stmt = $this->pdo->prepare('UPDATE email_campaign_recipients SET last_error = :remark, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':remark' => $remark,
            ':id' => $recipientId,
        ]);
    }

    private function extractReason(array $context, string $fallback): string
    {
        foreach (['reason', 'diagnostic', 'error', 'message'] as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return mb_strimwidth($value, 0, 220, '...');
            }
        }

        return $fallback;
    }

    private function markSubscriberStatus(int $subscriberId, string $status, DateTimeImmutable $timestamp): void
    {
        if ($subscriberId <= 0) {
            return;
        }

        $ts = $timestamp->format('Y-m-d H:i:s');
        $columns = "status = :status, updated_at = :updated_at";
        if ($status === 'unsubscribed') {
            $columns .= ', unsubscribed_at = :updated_at';
        } elseif ($status === 'active') {
            $columns .= ', unsubscribed_at = NULL';
        }

        $stmt = $this->pdo->prepare('UPDATE email_subscribers SET ' . $columns . ' WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':updated_at' => $ts,
            ':id' => $subscriberId,
        ]);

        $listStmt = $this->pdo->prepare("UPDATE email_list_subscribers SET status = :status, unsubscribed_at = CASE WHEN :status = 'active' THEN NULL ELSE :updated_at END WHERE subscriber_id = :id");
        $listStmt->execute([
            ':status' => $status,
            ':updated_at' => $ts,
            ':id' => $subscriberId,
        ]);
    }

    private function touchSubscriberEngagement(int $subscriberId, DateTimeImmutable $timestamp, bool $refreshEngagement): void
    {
        if ($subscriberId <= 0) {
            return;
        }

        $ts = $timestamp->format('Y-m-d H:i:s');
        $sql = 'UPDATE email_subscribers SET updated_at = NOW()';
        $params = [':id' => $subscriberId];

        if ($refreshEngagement) {
            $sql .= ', last_engagement_at = :engaged_at';
            $params[':engaged_at'] = $ts;
        }

        $sql .= ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function storeEvent(int $campaignId, int $recipientId, string $event, array $context, DateTimeImmutable $timestamp): void
    {
        $metaPayload = $this->filterMeta($context);
        try {
            $metaJson = $metaPayload !== [] ? json_encode($metaPayload, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            $metaJson = null;
        }

        $stmt = $this->pdo->prepare('INSERT INTO email_campaign_events (campaign_id, recipient_id, event_type, meta, occurred_at)
            VALUES (:campaign_id, :recipient_id, :event_type, :meta, :occurred_at)');
        $stmt->execute([
            ':campaign_id' => $campaignId,
            ':recipient_id' => $recipientId,
            ':event_type' => $event,
            ':meta' => $metaJson,
            ':occurred_at' => $timestamp->format('Y-m-d H:i:s'),
        ]);
    }

    private function shouldStoreEvent(string $event): bool
    {
        return in_array($event, ['open', 'click', 'bounce', 'complaint', 'unsubscribe'], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar>
     */
    private function filterMeta(array $context): array
    {
        $allowedKeys = ['provider_type', 'ip', 'user_agent', 'reason', 'diagnostic', 'link', 'recipient'];
        $filtered = [];
        foreach ($allowedKeys as $key) {
            if (!isset($context[$key])) {
                continue;
            }
            $value = $context[$key];
            if (is_scalar($value) && (string) $value !== '') {
                $filtered[$key] = (string) $value;
            }
        }

        return $filtered;
    }

    private function recalculateMetrics(int $campaignId): void
    {
        $summaryStmt = $this->pdo->prepare('SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = "skipped" THEN 1 ELSE 0 END) AS skipped,
                SUM(opens) AS opens_total,
                SUM(CASE WHEN opens > 0 THEN 1 ELSE 0 END) AS unique_opens,
                SUM(clicks) AS clicks_total,
                SUM(CASE WHEN clicks > 0 THEN 1 ELSE 0 END) AS unique_clicks
            FROM email_campaign_recipients
            WHERE campaign_id = :campaign_id');
        $summaryStmt->execute([':campaign_id' => $campaignId]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $eventStmt = $this->pdo->prepare('SELECT event_type, COUNT(*) AS total FROM email_campaign_events WHERE campaign_id = :campaign_id GROUP BY event_type');
        $eventStmt->execute([':campaign_id' => $campaignId]);
        $eventCounts = [
            'open' => 0,
            'click' => 0,
            'bounce' => 0,
            'complaint' => 0,
            'unsubscribe' => 0,
        ];
        while ($row = $eventStmt->fetch(PDO::FETCH_ASSOC)) {
            $type = $row['event_type'] ?? '';
            if (isset($eventCounts[$type])) {
                $eventCounts[$type] = (int) $row['total'];
            }
        }

        $metrics = [
            'total' => (int) ($summary['total'] ?? 0),
            'pending' => (int) ($summary['pending'] ?? 0),
            'sent' => (int) ($summary['sent'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
            'skipped' => (int) ($summary['skipped'] ?? 0),
            'opens_total' => (int) ($summary['opens_total'] ?? 0),
            'opens_unique' => (int) ($summary['unique_opens'] ?? 0),
            'clicks_total' => (int) ($summary['clicks_total'] ?? 0),
            'clicks_unique' => (int) ($summary['unique_clicks'] ?? 0),
            'events' => $eventCounts,
        ];

        try {
            $json = json_encode($metrics, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $json = null;
        }

        $updateStmt = $this->pdo->prepare('UPDATE email_campaigns SET metrics_summary = :summary, updated_at = NOW() WHERE id = :id');
        $updateStmt->execute([
            ':summary' => $json,
            ':id' => $campaignId,
        ]);
    }

    private function normalizeEventType(string $event): string
    {
        $event = strtolower(trim($event));
        return match ($event) {
            'email.sent', 'sent' => 'delivered',
            'email.delivered', 'delivered' => 'delivered',
            'email.opened', 'opened' => 'open',
            'email.clicked', 'clicked' => 'click',
            'email.complained', 'complained', 'complaint' => 'complaint',
            'email.bounced', 'bounced', 'bounce' => 'bounce',
            'email.unsubscribed', 'unsubscribed', 'unsubscribe' => 'unsubscribe',
            default => $event,
        };
    }

    private function resolveTimestamp(?string $value): DateTimeImmutable
    {
        if ($value !== null && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception $exception) {
                // Fallback to now
            }
        }

        return new DateTimeImmutable('now');
    }
}
