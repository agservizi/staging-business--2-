<?php
declare(strict_types=1);

namespace App\Services\EmailMarketing;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

use function render_mail_template;

/**
 * Handle campaign recipient preparation and delivery through the configured mailer.
 */
final class CampaignDispatcher
{
    private const WRAP_TEMPLATE_PATTERN = '/<html[\s>]/i';

    private PDO $pdo;

    /**
     * @var callable(string $to, string $subject, string $html, array $options=0):bool
     */
    private $mailer;

    private string $unsubscribeBaseUrl;

    public function __construct(PDO $pdo, callable $mailer, string $unsubscribeBaseUrl)
    {
        $this->pdo = $pdo;
        $this->mailer = $mailer;
        $this->unsubscribeBaseUrl = rtrim($unsubscribeBaseUrl, '/');
    }

    /**
     * Dispatch the campaign, returning delivery statistics.
     *
     * @return array{total:int,sent:int,failed:int,skipped:int,dry_run:bool}
     */
    public function dispatch(int $campaignId, bool $dryRun = false): array
    {
        $campaign = $this->fetchCampaign($campaignId);
        if ($campaign === null) {
            throw new RuntimeException('Campagna non trovata.');
        }

        if (!$dryRun && !in_array($campaign['status'], ['draft', 'scheduled', 'failed'], true)) {
            throw new RuntimeException('La campagna non è in uno stato inviabile.');
        }

        $template = $this->loadTemplate((int) ($campaign['template_id'] ?? 0));
        $recipients = $this->buildRecipientPool($campaign);

        $summary = [
            'total' => count($recipients),
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => $dryRun,
        ];

        if ($recipients === []) {
            return $summary;
        }

        if ($dryRun) {
            foreach ($recipients as $recipient) {
                if ($recipient['sendable'] === false) {
                    $summary['skipped']++;
                } else {
                    $summary['sent']++;
                }
            }
            return $summary;
        }

        $this->transitionStatus($campaignId, 'sending');
        $synced = $this->syncRecipients($campaignId, $recipients);

        if ($synced === []) {
            $this->transitionStatus($campaignId, 'failed', ['last_error' => 'Nessun destinatario valido da inviare.']);
            return $summary;
        }

        $mailer = $this->mailer;
        $sendStartedAt = new DateTimeImmutable('now');

        foreach ($synced as $recipientRow) {
            if ($recipientRow['status'] === 'skipped') {
                $summary['skipped']++;
                continue;
            }

            $personalizedSubject = $this->personalizeSubject($campaign['subject'], $recipientRow);
            $html = $this->buildHtmlBody($campaign, $template, $recipientRow);

            $metaOptions = [
                'channel' => 'marketing',
                'metadata' => [
                    'campaign_id' => $campaignId,
                    'recipient_id' => (int) ($recipientRow['id'] ?? 0),
                ],
            ];

            try {
                $sent = $mailer($recipientRow['email'], $personalizedSubject, $html, $metaOptions);
            } catch (Throwable $exception) {
                $sent = false;
                $this->markRecipientAsFailed((int) $recipientRow['id'], $exception->getMessage());
            }

            if ($sent) {
                $summary['sent']++;
                $this->markRecipientAsSent((int) $recipientRow['id'], $sendStartedAt);
            } else {
                if ($sent !== true) {
                    $this->markRecipientAsFailed((int) $recipientRow['id'], 'Mailer ha restituito false.');
                }
                $summary['failed']++;
            }
        }

        $status = $summary['sent'] > 0 && $summary['failed'] === 0 ? 'sent' : ($summary['sent'] > 0 ? 'sent' : 'failed');
        try {
            $metricsJson = json_encode([
                'total' => $summary['total'],
                'sent' => $summary['sent'],
                'failed' => $summary['failed'],
                'skipped' => $summary['skipped'],
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $metricsJson = null;
        }

        $extra = [
            'sent_at' => $summary['sent'] > 0 ? $sendStartedAt->format('Y-m-d H:i:s') : null,
            'metrics_summary' => $metricsJson,
        ];

        if ($summary['failed'] > 0) {
            $extra['last_error'] = sprintf('%d destinatari non inviati.', $summary['failed']);
        } else {
            $extra['last_error'] = null;
        }

        $this->transitionStatus($campaignId, $status, $extra);

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCampaign(int $campaignId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_campaigns WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTemplate(int $templateId): ?array
    {
        if ($templateId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<int, array{email:string,first_name:string|null,last_name:string|null,subscriber_id:int|null,sendable:bool,skip_reason:string|null}>
     */
    private function buildRecipientPool(array $campaign): array
    {
        $filters = $this->decodeFilters($campaign['audience_filters'] ?? null);
        $recipients = [];

        switch ($campaign['audience_type']) {
            case 'manual':
                foreach ($filters['manual_emails'] ?? [] as $manual) {
                    $email = strtolower(trim((string) ($manual['email'] ?? '')));
                    if ($email === '' || !$this->isValidEmail($email)) {
                        continue;
                    }
                    $recipients[$email] = $this->wrapRecipient($email, $manual['first_name'] ?? null, $manual['last_name'] ?? null);
                }
                break;

            case 'list':
                $listIds = array_map('intval', $filters['list_ids'] ?? []);
                if ($listIds) {
                    $placeholders = implode(', ', array_fill(0, count($listIds), '?'));
                    $sql = 'SELECT ls.subscriber_id, s.email, s.first_name, s.last_name, s.status
                        FROM email_list_subscribers ls
                        INNER JOIN email_subscribers s ON s.id = ls.subscriber_id
                        WHERE ls.list_id IN (' . $placeholders . ')';
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($listIds);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $email = strtolower(trim((string) ($row['email'] ?? '')));
                        if ($email === '' || !$this->isValidEmail($email)) {
                            continue;
                        }
                        $recipients[$email] = $this->wrapRecipient(
                            $email,
                            $row['first_name'] ?? null,
                            $row['last_name'] ?? null,
                            (int) $row['subscriber_id'],
                            (string) ($row['status'] ?? 'active')
                        );
                    }
                }
                break;

            case 'all_clients':
            default:
                $stmt = $this->pdo->query('SELECT id, email, nome, cognome FROM clienti WHERE email IS NOT NULL AND email <> ""');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '' || !$this->isValidEmail($email)) {
                        continue;
                    }
                    $recipients[$email] = $this->wrapRecipient(
                        $email,
                        $row['nome'] ?? null,
                        $row['cognome'] ?? null
                    );
                }
                break;
        }

        return array_values($recipients);
    }

    /**
     * @param array<string, mixed>|null $filters
     * @return array{list_ids?:array<int,int>,manual_emails?:array<int,array<string,string>>}
     */
    private function decodeFilters($filters): array
    {
        if ($filters === null || $filters === '') {
            return [];
        }

        if (is_array($filters)) {
            return $filters;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $filters, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Impossibile leggere i filtri destinatari: ' . $exception->getMessage(), 0, $exception);
        }

        return $decoded;
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @return array{email:string,first_name:string|null,last_name:string|null,subscriber_id:int|null,sendable:bool,skip_reason:string|null}
     */
    private function wrapRecipient(string $email, ?string $first, ?string $last, ?int $subscriberId = null, string $status = 'active'): array
    {
        $subscriber = $subscriberId !== null ? $this->ensureSubscriberById($subscriberId, $email, $first, $last, $status) : $this->ensureSubscriberByEmail($email, $first, $last);
        $sendable = in_array($subscriber['status'], ['active'], true);

        return [
            'email' => $subscriber['email'],
            'first_name' => $subscriber['first_name'],
            'last_name' => $subscriber['last_name'],
            'subscriber_id' => $subscriber['id'],
            'sendable' => $sendable,
            'skip_reason' => $sendable ? null : 'Iscritto non attivo (stato: ' . $subscriber['status'] . ')',
        ];
    }

    /**
     * @return array{id:int,email:string,first_name:string|null,last_name:string|null,status:string}
     */
    private function ensureSubscriberById(int $subscriberId, string $email, ?string $first, ?string $last, string $status): array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, first_name, last_name, status FROM email_subscribers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $subscriberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'id' => (int) $row['id'],
                'email' => strtolower((string) $row['email']),
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'status' => (string) ($row['status'] ?? 'active'),
            ];
        }

        return $this->ensureSubscriberByEmail($email, $first, $last, $status);
    }

    /**
     * @return array{id:int,email:string,first_name:string|null,last_name:string|null,status:string}
     */
    private function ensureSubscriberByEmail(string $email, ?string $first, ?string $last, string $status = 'active'): array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, first_name, last_name, status FROM email_subscribers WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'id' => (int) $row['id'],
                'email' => strtolower((string) $row['email']),
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'status' => (string) ($row['status'] ?? 'active'),
            ];
        }

        $persistedStatus = in_array($status, ['active', 'unsubscribed', 'bounced'], true) ? $status : 'active';

        $stmt = $this->pdo->prepare('INSERT INTO email_subscribers (email, first_name, last_name, status, source, created_at, updated_at)
            VALUES (:email, :first_name, :last_name, :status, :source, NOW(), NOW())');
        $stmt->execute([
            ':email' => $email,
            ':first_name' => $first,
            ':last_name' => $last,
            ':status' => $persistedStatus,
            ':source' => 'imported',
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'email' => $email,
            'first_name' => $first,
            'last_name' => $last,
            'status' => $persistedStatus,
        ];
    }

    /**
     * @param array<int, array{email:string,first_name:string|null,last_name:string|null,subscriber_id:int|null,sendable:bool,skip_reason:string|null}> $recipientPool
     * @return array<int, array<string, mixed>>
     */
    private function syncRecipients(int $campaignId, array $recipientPool): array
    {
        $insert = $this->pdo->prepare('INSERT INTO email_campaign_recipients (campaign_id, subscriber_id, email, first_name, last_name, status, last_error, unsubscribe_token, created_at)
            VALUES (:campaign_id, :subscriber_id, :email, :first_name, :last_name, :status, :last_error, :unsubscribe_token, NOW())
            ON DUPLICATE KEY UPDATE
                subscriber_id = VALUES(subscriber_id),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                status = VALUES(status),
                last_error = VALUES(last_error),
                unsubscribe_token = CASE WHEN VALUES(unsubscribe_token) IS NOT NULL THEN VALUES(unsubscribe_token) ELSE unsubscribe_token END,
                updated_at = NOW()');

        $syncedIds = [];
        foreach ($recipientPool as $recipient) {
            $status = $recipient['sendable'] ? 'pending' : 'skipped';
            $token = $recipient['sendable'] ? $this->generateToken() : null;
            $insert->execute([
                ':campaign_id' => $campaignId,
                ':subscriber_id' => $recipient['subscriber_id'],
                ':email' => $recipient['email'],
                ':first_name' => $recipient['first_name'],
                ':last_name' => $recipient['last_name'],
                ':status' => $status,
                ':last_error' => $recipient['skip_reason'],
                ':unsubscribe_token' => $token,
            ]);

            $syncedIds[] = $this->pdo->lastInsertId() !== '0' ? (int) $this->pdo->lastInsertId() : $this->lookupRecipientId($campaignId, $recipient['email']);
        }

        if ($syncedIds === []) {
            return [];
        }

        $placeholder = implode(', ', array_fill(0, count($syncedIds), '?'));
        $sql = 'SELECT * FROM email_campaign_recipients WHERE id IN (' . $placeholder . ') ORDER BY email';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($syncedIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function lookupRecipientId(int $campaignId, string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM email_campaign_recipients WHERE campaign_id = :campaign_id AND email = :email LIMIT 1');
        $stmt->execute([
            ':campaign_id' => $campaignId,
            ':email' => $email,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function transitionStatus(int $campaignId, string $status, array $extra = []): void
    {
        $columns = ['status = :status'];
        $params = [
            ':status' => $status,
            ':id' => $campaignId,
        ];

        foreach ($extra as $column => $value) {
            if ($value === null) {
                $columns[] = $column . ' = NULL';
                continue;
            }
            $placeholder = ':' . $column;
            $columns[] = $column . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $sql = 'UPDATE email_campaigns SET ' . implode(', ', $columns) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function personalizeSubject(string $subject, array $recipient): string
    {
        $fullName = trim(sprintf('%s %s', (string) ($recipient['first_name'] ?? ''), (string) ($recipient['last_name'] ?? '')));
        $replacements = [
            '{{email}}' => $recipient['email'] ?? '',
            '{{first_name}}' => $recipient['first_name'] ?? '',
            '{{last_name}}' => $recipient['last_name'] ?? '',
            '{{full_name}}' => $fullName,
        ];

        return strtr($subject, $replacements);
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed>|null $template
     * @param array<string, mixed> $recipient
     */
    private function buildHtmlBody(array $campaign, ?array $template, array $recipient): string
    {
        $content = (string) ($campaign['content_html'] ?? '');
        if ($content === '' && $template !== null) {
            $content = (string) ($template['html'] ?? '');
        }

        $unsubscribeUrl = $this->buildUnsubscribeUrl((string) ($recipient['unsubscribe_token'] ?? ''));
        $hasUnsubscribePlaceholder = strpos($content, '{{unsubscribe_url}}') !== false;

        $replacements = [
            '{{email}}' => $recipient['email'] ?? '',
            '{{first_name}}' => $recipient['first_name'] ?? '',
            '{{last_name}}' => $recipient['last_name'] ?? '',
            '{{full_name}}' => trim(sprintf('%s %s', $recipient['first_name'] ?? '', $recipient['last_name'] ?? '')),
            '{{unsubscribe_url}}' => $unsubscribeUrl,
        ];

        $personalized = strtr($content, $replacements);
        if (!preg_match(self::WRAP_TEMPLATE_PATTERN, $personalized)) {
            $personalized = $personalized . '<p style="margin-top:24px; font-size:12px; color:#6c7d93">Se non vuoi pi&ugrave; ricevere queste comunicazioni <a href="' . $unsubscribeUrl . '">clicca qui per disiscriverti</a>.</p>';
            $personalized = render_mail_template((string) ($campaign['name'] ?? 'Campagna'), $personalized);
        } else {
            if (!$hasUnsubscribePlaceholder) {
                $personalized .= '<p style="margin:24px 0; font-size:12px; color:#6c7d93">Se non vuoi pi&ugrave; ricevere queste comunicazioni <a href="' . $unsubscribeUrl . '">clicca qui</a>.</p>';
            }
        }

        return $personalized;
    }

    private function buildUnsubscribeUrl(string $token): string
    {
        if ($token === '') {
            return $this->unsubscribeBaseUrl . '/email-unsubscribe.php';
        }
        return $this->unsubscribeBaseUrl . '/email-unsubscribe.php?token=' . urlencode($token);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function markRecipientAsSent(int $recipientId, DateTimeImmutable $sentAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE email_campaign_recipients SET status = \'sent\', sent_at = :sent_at, last_error = NULL WHERE id = :id');
        $stmt->execute([
            ':sent_at' => $sentAt->format('Y-m-d H:i:s'),
            ':id' => $recipientId,
        ]);
    }

    private function markRecipientAsFailed(int $recipientId, string $message): void
    {
        $stmt = $this->pdo->prepare('UPDATE email_campaign_recipients SET status = \'failed\', last_error = :error WHERE id = :id');
        $stmt->execute([
            ':error' => mb_strimwidth($message, 0, 240, '...', 'UTF-8'),
            ':id' => $recipientId,
        ]);
    }
}
