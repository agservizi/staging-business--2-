<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../includes/env.php';

load_env(__DIR__ . '/../.env');
configure_timezone();

$daysRead = 90;
$daysUnread = 365;
$dryRun = in_array('--dry-run', $argv ?? [], true);

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--days-read=')) {
        $daysRead = (int) substr($arg, strlen('--days-read='));
    }
    if (str_starts_with($arg, '--days-unread=')) {
        $daysUnread = (int) substr($arg, strlen('--days-unread='));
    }
}

if ($daysRead < 7) {
    $daysRead = 7;
}
if ($daysUnread < $daysRead) {
    $daysUnread = max($daysRead, 30);
}

$database = [
    'host' => env('DB_HOST'),
    'port' => env('DB_PORT'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'persistent' => filter_var(env('DB_PERSISTENT', false), FILTER_VALIDATE_BOOL),
];

$pdo = \App\Infrastructure\Database\ConnectionFactory::make($database);

$cutoffRead = (new DateTimeImmutable())->modify('-' . $daysRead . ' days')->format('Y-m-d H:i:s');
$cutoffUnread = (new DateTimeImmutable())->modify('-' . $daysUnread . ' days')->format('Y-m-d H:i:s');

$sql = 'DELETE FROM notifications WHERE (is_read = 1 AND created_at < :cutoff_read) OR (is_read = 0 AND created_at < :cutoff_unread)';

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':cutoff_read', $cutoffRead);
$stmt->bindValue(':cutoff_unread', $cutoffUnread);

if ($dryRun) {
    $countSql = 'SELECT COUNT(*) FROM notifications WHERE (is_read = 1 AND created_at < :cutoff_read) OR (is_read = 0 AND created_at < :cutoff_unread)';
    $countStmt = $pdo->prepare($countSql);
    $countStmt->bindValue(':cutoff_read', $cutoffRead);
    $countStmt->bindValue(':cutoff_unread', $cutoffUnread);
    $countStmt->execute();
    $count = (int) $countStmt->fetchColumn();
    fwrite(STDOUT, "DRY RUN: {$count} notifiche da rimuovere.\n");
    exit(0);
}

$stmt->execute();
$deleted = $stmt->rowCount();

fwrite(STDOUT, "Pulizia completata: {$deleted} notifiche eliminate.\n");
