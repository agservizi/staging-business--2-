<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../includes/env.php';

load_env(__DIR__ . '/../.env');

use App\Services\ServiziWeb\UfficioPostaleClient;
use Throwable;

$client = new UfficioPostaleClient(null, null, ['verify_ssl' => false]);
try {
	$result = $client->getPricing('telegrammi');
	var_export($result);
} catch (Throwable $exception) {
	fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
	exit(1);
}
