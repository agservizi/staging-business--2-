<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

$z = filter_input(INPUT_GET, 'z', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 22]]);
$x = filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT);
$y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT);

if ($z === false || $x === false || $y === false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid tile coordinates']);
    exit;
}

$maxIndex = 1 << $z;
if ($x < 0 || $x >= $maxIndex || $y < 0 || $y >= $maxIndex) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tile coordinates out of range']);
    exit;
}

$cacheDir = public_path(sprintf('cache/leaflet/%d/%d', $z, $x));
$cacheFile = $cacheDir . '/' . $y . '.png';
$cacheTtl = 60 * 60 * 24 * 7; // 7 giorni

if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile) < $cacheTtl)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
    exit;
}

$remoteUrl = sprintf('https://tile.openstreetmap.org/%d/%d/%d.png', $z, $x, $y);

$body = null;
$httpCode = 200;
$contentType = 'image/png';
$userAgent = env('LEAFLET_TILE_USER_AGENT', 'PickupPortal/1.0 (+https://coresuitebusiness.it)');

if (function_exists('curl_init')) {
    $ch = curl_init($remoteUrl);
    if ($ch === false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Cannot initialise tile download']);
        exit;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/png,image/*;q=0.8,*/*;q=0.5'
        ],
    ]);

    $body = curl_exec($ch);
    $errorNo = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/png';
    curl_close($ch);

    if ($body === false || $errorNo !== 0) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unable to download tile']);
        exit;
    }
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$userAgent}\r\nAccept: image/avif,image/webp,image/png,image/*;q=0.8,*/*;q=0.5\r\n",
            'timeout' => 10,
        ],
    ]);

    $body = @file_get_contents($remoteUrl, false, $context);

    if ($body === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unable to download tile']);
        exit;
    }

    if (isset($http_response_header[0]) && preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0], $matches)) {
        $httpCode = (int) $matches[1];
    }

    foreach ($http_response_header as $headerLine) {
        if (stripos($headerLine, 'content-type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('content-type:')));
            break;
        }
    }
}

if ($httpCode !== 200) {
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tile server returned an error']);
    exit;
}

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

if (is_dir($cacheDir) && is_writable($cacheDir)) {
    @file_put_contents($cacheFile, $body);
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($body));

echo $body;

