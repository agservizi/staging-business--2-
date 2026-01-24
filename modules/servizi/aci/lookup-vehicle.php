<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
http_response_code(410);

echo json_encode([
    'success' => false,
    'message' => 'Funzionalità rimossa.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
