<?php
use ReflectionClass;
use Throwable;

require __DIR__ . '/../bootstrap/autoload.php';
require __DIR__ . '/../includes/env.php';

load_env(__DIR__ . '/../.env');
configure_timezone();

$service = new App\Services\GoogleCalendarService();

echo 'GOOGLE_CALENDAR_ENABLED=' . var_export(env('GOOGLE_CALENDAR_ENABLED'), true) . PHP_EOL;
echo 'GOOGLE_CALENDAR_CALENDAR_ID=' . var_export(env('GOOGLE_CALENDAR_CALENDAR_ID'), true) . PHP_EOL;
echo 'GOOGLE_CALENDAR_CREDENTIALS_PATH=' . var_export(env('GOOGLE_CALENDAR_CREDENTIALS_PATH'), true) . PHP_EOL;
echo 'GOOGLE_CALENDAR_CREDENTIALS_JSON length=' . strlen((string) env('GOOGLE_CALENDAR_CREDENTIALS_JSON')) . PHP_EOL;

var_dump($service->isEnabled());

$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('ensureCredentialsLoaded');
$method->setAccessible(true);

try {
	$credentials = $method->invoke($service);
	echo "Credenziali caricate correttamente\n";
	echo 'client_email=' . ($credentials['client_email'] ?? 'n/a') . PHP_EOL;
} catch (Throwable $e) {
	echo 'Errore credenziali: ' . $e->getMessage() . PHP_EOL;
}
