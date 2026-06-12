<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'items' => [],
    'unread' => 0,
    'message' => 'Le notifiche centralizzate del gestionale sono disattivate. Usa i promemoria in dashboard o il portale cliente per le notifiche pickup.',
]);
