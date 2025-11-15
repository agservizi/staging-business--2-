<?php
require_once __DIR__ . '/../includes/db_connect.php';

$stmt = $pdo->query('SELECT id, titolo, stato, google_event_id, google_event_synced_at, google_event_sync_error FROM servizi_appuntamenti ORDER BY id DESC LIMIT 10');
$rows = $stmt->fetchAll();

foreach ($rows as $row) {
    echo 'ID: ' . $row['id'] . PHP_EOL;
    echo 'Titolo: ' . $row['titolo'] . PHP_EOL;
    echo 'Stato: ' . $row['stato'] . PHP_EOL;
    echo 'Google Event ID: ' . ($row['google_event_id'] ?? '[null]') . PHP_EOL;
    echo 'Synced At: ' . ($row['google_event_synced_at'] ?? '[null]') . PHP_EOL;
    echo 'Sync Error: ' . ($row['google_event_sync_error'] ?? '[null]') . PHP_EOL;
    echo str_repeat('-', 40) . PHP_EOL;
}
