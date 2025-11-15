<?php
declare(strict_types=1);

use App\Services\EmailMarketing\CampaignDispatcher;
use DateTimeImmutable;
use Throwable;

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Questo script può essere eseguito solo da CLI." . PHP_EOL;
    exit(1);
}

$options = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $options);

$config = get_email_marketing_config($pdo);
$unsubscribeBaseUrl = (string) ($config['unsubscribe_base_url'] ?? base_url());

$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$stmt = $pdo->prepare("SELECT id FROM email_campaigns WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= :now ORDER BY scheduled_at ASC");
$stmt->execute([':now' => $now]);
$campaignIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

if ($campaignIds === []) {
    echo "Nessuna campagna da processare." . PHP_EOL;
    exit(0);
}

echo sprintf("Trovate %d campagne da inviare (%s)." . PHP_EOL, count($campaignIds), $dryRun ? 'dry-run' : 'invio effettivo');

$dispatcher = new CampaignDispatcher(
    $pdo,
    static function (string $to, string $subject, string $html, array $options = []): bool {
        return send_system_mail($to, $subject, $html, $options);
    },
    $unsubscribeBaseUrl
);

foreach ($campaignIds as $rawId) {
    $campaignId = (int) $rawId;
    try {
        $summary = $dispatcher->dispatch($campaignId, $dryRun);
        echo sprintf(
            "Campagna #%d: totali=%d, inviati=%d, errori=%d, esclusi=%d%s" . PHP_EOL,
            $campaignId,
            $summary['total'],
            $summary['sent'],
            $summary['failed'],
            $summary['skipped'],
            $dryRun ? ' (simulazione)' : ''
        );
    } catch (Throwable $exception) {
        error_log('Scheduled campaign dispatch failed: ' . $exception->getMessage());
        echo sprintf("Campagna #%d: errore %s" . PHP_EOL, $campaignId, $exception->getMessage());
    }
}
