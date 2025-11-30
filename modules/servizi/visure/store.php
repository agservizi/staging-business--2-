<?php

use App\Services\ServiziWeb\OpenApiCatastoClient;
use App\Services\ServiziWeb\VisureService;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}

require_valid_csrf();

$apiKeyAvailable = trim((string) (env('OPENAPI_CATASTO_API_KEY') ?? env('OPENAPI_SANDBOX_API_KEY') ?? '')) !== '';
$tokenAvailable = trim((string) (env('OPENAPI_CATASTO_TOKEN') ?? env('OPENAPI_CATASTO_SANDBOX_TOKEN') ?? '')) !== '';
if (!$apiKeyAvailable || !$tokenAvailable) {
    add_flash('warning', 'Configura le credenziali OpenAPI Catasto prima di inviare la richiesta.');
    header('Location: create.php');
    exit;
}

$requestType = strtolower(trim((string) ($_POST['request_type'] ?? 'immobile')));
if (!in_array($requestType, ['immobile', 'soggetto'], true)) {
    $requestType = 'immobile';
}

$codiceFiscale = strtoupper(trim((string) ($_POST['codice_fiscale'] ?? '')));
$richiedente = trim((string) ($_POST['richiedente'] ?? ''));
if ($richiedente === '' && $codiceFiscale !== '') {
    $richiedente = $codiceFiscale;
}

$input = [
    'request_type' => $requestType,
    'codice_fiscale' => $codiceFiscale,
    'richiedente' => $richiedente,
    'tipo_visura' => $_POST['tipo_visura'] ?? 'ordinaria',
    'callback_url' => $_POST['callback_url'] ?? '',
    'callback_method' => $_POST['callback_method'] ?? '',
    'callback_field' => $_POST['callback_field'] ?? '',
];

if ($requestType === 'soggetto') {
    $input['tipo_soggetto'] = $_POST['tipo_soggetto'] ?? 'persona_fisica';
    $input['provincia_soggetto'] = $_POST['provincia_soggetto'] ?? '';
    $input['comune_soggetto'] = $_POST['comune_soggetto'] ?? '';
    $input['tipo_catasto_soggetto'] = $_POST['tipo_catasto_soggetto'] ?? 'TF';
} else {
    $input['tipo_catasto'] = $_POST['tipo_catasto'] ?? '';
    $input['provincia'] = $_POST['provincia'] ?? '';
    $input['comune'] = $_POST['comune'] ?? '';
    $input['foglio'] = $_POST['foglio'] ?? '';
    $input['particella'] = $_POST['particella'] ?? '';
    $input['subalterno'] = $_POST['subalterno'] ?? '';
    $input['sezione'] = $_POST['sezione'] ?? '';
    $input['sezione_urbana'] = $_POST['sezione_urbana'] ?? '';
}

$callbackPayloadRaw = trim((string) ($_POST['callback_payload'] ?? ''));
if ($callbackPayloadRaw !== '') {
    $decoded = json_decode($callbackPayloadRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        add_flash('danger', 'Il payload callback deve essere un JSON valido.');
        header('Location: create.php');
        exit;
    }
    $input['callback_payload'] = $decoded;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    $service = new VisureService($pdo, dirname(__DIR__, 3));
    $client = new OpenApiCatastoClient();
    $visura = $service->createVisura($client, $input, $userId);

    $visuraId = (string) ($visura['id'] ?? '');
    if ($visuraId !== '') {
        $suffix = $requestType === 'soggetto' ? ' (soggetto)' : ' (immobile)';
        add_flash('success', 'Richiesta di visura inviata correttamente' . $suffix . '. ID pratica: ' . $visuraId);
        header('Location: view.php?id=' . urlencode($visuraId));
        exit;
    }

    add_flash('warning', 'Richiesta inviata ma l\'ID pratica non è stato restituito.');
    header('Location: index.php');
    exit;
} catch (Throwable $exception) {
    error_log('VisureService create request failed: ' . $exception->getMessage());
    add_flash('danger', 'Impossibile creare la richiesta di visura: ' . $exception->getMessage());
    header('Location: create.php');
    exit;
}
