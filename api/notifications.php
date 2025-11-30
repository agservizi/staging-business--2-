<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);

echo json_encode([
    'error' => 'Le notifiche sono state disattivate.',
]);
