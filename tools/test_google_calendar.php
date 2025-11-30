<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db_connect.php';

use App\Services\GoogleCalendarService;

$service = new GoogleCalendarService();

if (!$service->isEnabled()) {
    echo "Google Calendar non risulta abilitato o configurato.\n";
    exit(1);
}

$reflection = new ReflectionClass(GoogleCalendarService::class);
$getAccessToken = $reflection->getMethod('getAccessToken');
$getAccessToken->setAccessible(true);
$makeRequest = $reflection->getMethod('makeRequest');
$makeRequest->setAccessible(true);

try {
    $token = $getAccessToken->invoke($service);
    echo "Token ottenuto (lunghezza " . strlen($token) . ")" . PHP_EOL;
    $response = $makeRequest->invoke($service, 'GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList');
    echo "HTTP Status: " . ($response['status'] ?? 'n/d') . PHP_EOL;
    echo "Body:\n";
    print_r($response['body']);

    $calendarId = env('GOOGLE_CALENDAR_CALENDAR_ID');
    if ($calendarId) {
        echo PHP_EOL . 'Tentativo di calendarList.insert per ' . $calendarId . PHP_EOL;
        $insertResponse = $makeRequest->invoke($service, 'POST', 'https://www.googleapis.com/calendar/v3/users/me/calendarList', ['id' => $calendarId]);
        echo 'HTTP Status: ' . ($insertResponse['status'] ?? 'n/d') . PHP_EOL;
        print_r($insertResponse['body']);
    }
} catch (Throwable $e) {
    echo 'Errore: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
