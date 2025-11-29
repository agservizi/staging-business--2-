<?php
declare(strict_types=1);

use App\Services\Security\MfaQrService;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/db_connect.php';
require_once __DIR__ . '/../../../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
    exit;
}

require_valid_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sessione non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$label = trim((string) ($payload['label'] ?? $payload['device_label'] ?? ''));
$pin = preg_replace('/\s+/', '', (string) ($payload['pin'] ?? ''));
$pinConfirmation = preg_replace('/\s+/', '', (string) ($payload['pin_confirmation'] ?? $payload['pinConfirm'] ?? ''));

$errors = [];
if ($label === '' || mb_strlen($label) < 3 || mb_strlen($label) > 100) {
    $errors[] = 'Inserisci un nome descrittivo per il dispositivo (3-100 caratteri).';
}
if ($pin === '' || !preg_match('/^[0-9]{4,8}$/', $pin)) {
    $errors[] = 'Il PIN deve contenere tra 4 e 8 cifre.';
}
if ($pinConfirmation === '' || $pin !== $pinConfirmation) {
    $errors[] = 'La conferma PIN non coincide.';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => implode(' ', $errors)], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $service = new MfaQrService($pdo);
    $device = $service->createDevice($userId, $label, $pin);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
    exit;
} catch (Throwable $exception) {
    error_log('MFA device creation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Impossibile registrare il dispositivo in questo momento.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $qrPayload = json_encode([
        'type' => 'coresuite:mfa-device-provision',
        'token' => $device['provisioning_token'],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('MFA QR payload generation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Errore durante la generazione del QR.'], JSON_THROW_ON_ERROR);
    exit;
}

$qrSvgDataUri = null;
try {
    if (
        class_exists(ImageRenderer::class) &&
        class_exists(RendererStyle::class) &&
        class_exists(SvgImageBackEnd::class) &&
        class_exists(Writer::class)
    ) {
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());
        $writer = new Writer($renderer);
        $svgString = $writer->writeString($qrPayload);
        $qrSvgDataUri = 'data:image/svg+xml;base64,' . base64_encode($svgString);
    } else {
        throw new RuntimeException('QR renderer non disponibile.');
    }
} catch (Throwable $exception) {
    error_log('MFA QR svg render failed: ' . $exception->getMessage());
}

$response = [
    'ok' => true,
    'device' => [
        'device_uuid' => $device['device_uuid'],
        'label' => $device['device_label'],
        'status' => $device['status'],
        'created_at' => $device['created_at'],
    ],
    'provisioning' => [
        'token' => $device['provisioning_token'],
        'expires_at' => $device['provisioning_expires_at'],
        'qr_payload' => $qrPayload,
        'qr_svg' => $qrSvgDataUri,
    ],
];

echo json_encode($response, JSON_THROW_ON_ERROR);
