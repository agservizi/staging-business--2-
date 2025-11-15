<?php
declare(strict_types=1);

use App\Services\ServiziWeb\TelegrammiService;
use App\Services\ServiziWeb\UfficioPostaleClient;
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

$tokenValue = env('UFFICIO_POSTALE_TOKEN') ?? env('UFFICIO_POSTALE_SANDBOX_TOKEN') ?? '';
if (trim((string) $tokenValue) === '') {
    add_flash('warning', 'Configura prima il token Ufficio Postale per inviare telegrammi.');
    header('Location: create.php');
    exit;
}

$clienteId = isset($_POST['cliente_id']) && $_POST['cliente_id'] !== '' ? (int) $_POST['cliente_id'] : null;
if ($clienteId !== null && $clienteId <= 0) {
    $clienteId = null;
}

$prodotto = trim((string) ($_POST['prodotto'] ?? ''));
if ($prodotto === '') {
    add_flash('danger', 'Specificare il prodotto da utilizzare.');
    header('Location: create.php');
    exit;
}

$documentoRaw = trim((string) ($_POST['documento'] ?? ''));

$mittente = null;
if (isset($_POST['mittente']) && is_array($_POST['mittente'])) {
    $mittenteInput = $_POST['mittente'];
    $mittenteNome = trim((string) ($mittenteInput['nome'] ?? ''));
    $mittenteEmail = trim((string) ($mittenteInput['email'] ?? ''));
    $mittenteTelefono = trim((string) ($mittenteInput['telefono'] ?? ''));
    $indirizzoInput = $mittenteInput['indirizzo'] ?? [];
    if (!is_array($indirizzoInput)) {
        $indirizzoInput = [];
    }

    $mittenteVia = trim((string) ($indirizzoInput['via'] ?? ''));
    $mittenteComplemento = trim((string) ($indirizzoInput['complemento'] ?? ''));
    $mittenteCap = trim((string) ($indirizzoInput['cap'] ?? ''));
    $mittenteCitta = trim((string) ($indirizzoInput['citta'] ?? ''));
    $mittenteProvincia = mb_strtoupper(trim((string) ($indirizzoInput['provincia'] ?? '')), 'UTF-8');

    if ($mittenteNome === '' || $mittenteVia === '' || $mittenteCap === '' || $mittenteCitta === '' || $mittenteProvincia === '') {
        add_flash('danger', 'Compila tutti i campi obbligatori del mittente.');
        header('Location: create.php');
        exit;
    }

    $mittenteIndirizzo = [
        'via' => $mittenteVia,
        'cap' => $mittenteCap,
        'citta' => $mittenteCitta,
        'provincia' => $mittenteProvincia,
    ];

    if ($mittenteComplemento !== '') {
        $mittenteIndirizzo['complemento'] = $mittenteComplemento;
    }

    $mittente = [
        'nome' => $mittenteNome,
        'indirizzo' => $mittenteIndirizzo,
    ];

    if ($mittenteEmail !== '') {
        $mittente['email'] = $mittenteEmail;
    }
    if ($mittenteTelefono !== '') {
        $mittente['telefono'] = $mittenteTelefono;
    }
} else {
    $mittenteRaw = trim((string) ($_POST['mittente_json'] ?? ''));
    if ($mittenteRaw === '') {
        add_flash('danger', 'Fornisci i dati del mittente.');
        header('Location: create.php');
        exit;
    }

    $decodedMittente = json_decode($mittenteRaw, true);
    if (!is_array($decodedMittente)) {
        add_flash('danger', 'Il campo "Mittente" deve contenere un JSON valido.');
        header('Location: create.php');
        exit;
    }
    $mittente = $decodedMittente;
}

$destinatari = [];
if (isset($_POST['destinatari']) && is_array($_POST['destinatari'])) {
    $rawDestinatari = $_POST['destinatari'];
    foreach ($rawDestinatari as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $nome = trim((string) ($item['nome'] ?? ''));
        $email = trim((string) ($item['email'] ?? ''));
        $telefono = trim((string) ($item['telefono'] ?? ''));
        $indirizzoItem = $item['indirizzo'] ?? [];
        if (!is_array($indirizzoItem)) {
            $indirizzoItem = [];
        }

        $via = trim((string) ($indirizzoItem['via'] ?? ''));
        $complemento = trim((string) ($indirizzoItem['complemento'] ?? ''));
        $cap = trim((string) ($indirizzoItem['cap'] ?? ''));
        $citta = trim((string) ($indirizzoItem['citta'] ?? ''));
    $provincia = mb_strtoupper(trim((string) ($indirizzoItem['provincia'] ?? '')), 'UTF-8');

        $isEmpty = ($nome === '' && $via === '' && $cap === '' && $citta === '' && $provincia === '' && $email === '' && $telefono === '' && $complemento === '');
        if ($isEmpty) {
            continue;
        }

        if ($nome === '' || $via === '' || $cap === '' || $citta === '' || $provincia === '') {
            add_flash('danger', 'Completa i dati del destinatario #' . ((int) $index + 1) . '.');
            header('Location: create.php');
            exit;
        }

        $destinatarioIndirizzo = [
            'via' => $via,
            'cap' => $cap,
            'citta' => $citta,
            'provincia' => $provincia,
        ];

        if ($complemento !== '') {
            $destinatarioIndirizzo['complemento'] = $complemento;
        }

        $destinatario = [
            'nome' => $nome,
            'indirizzo' => $destinatarioIndirizzo,
        ];

        if ($email !== '') {
            $destinatario['email'] = $email;
        }

        if ($telefono !== '') {
            $destinatario['telefono'] = $telefono;
        }

        $destinatari[] = $destinatario;
    }

    if (!$destinatari) {
        add_flash('danger', 'Inserisci almeno un destinatario completo.');
        header('Location: create.php');
        exit;
    }
} else {
    $destinatariRaw = trim((string) ($_POST['destinatari_json'] ?? ''));
    if ($destinatariRaw === '') {
        add_flash('danger', 'Fornisci almeno un destinatario.');
        header('Location: create.php');
        exit;
    }

    $decodedDestinatari = json_decode($destinatariRaw, true);
    if (!is_array($decodedDestinatari)) {
        add_flash('danger', 'Il campo "Destinatari" deve contenere un JSON valido.');
        header('Location: create.php');
        exit;
    }
    $destinatari = $decodedDestinatari;
}

$opzioniRaw = trim((string) ($_POST['opzioni_json'] ?? ''));
$callbackRaw = trim((string) ($_POST['callback_json'] ?? ''));
$extraRaw = trim((string) ($_POST['extra_json'] ?? ''));

$opzioni = null;
if ($opzioniRaw !== '') {
    $opzioni = json_decode($opzioniRaw, true);
    if ($opzioni === null || !is_array($opzioni)) {
        add_flash('danger', 'Il campo "Opzioni" deve contenere un JSON valido.');
        header('Location: create.php');
        exit;
    }
}

$callback = null;
if ($callbackRaw !== '') {
    $callback = json_decode($callbackRaw, true);
    if ($callback === null || !is_array($callback)) {
        add_flash('danger', 'Il campo "Callback" deve contenere un JSON valido.');
        header('Location: create.php');
        exit;
    }
}

$extra = null;
if ($extraRaw !== '') {
    $extra = json_decode($extraRaw, true);
    if ($extra === null || !is_array($extra)) {
        add_flash('danger', 'Il payload aggiuntivo deve contenere un JSON valido.');
        header('Location: create.php');
        exit;
    }
}

$documentoLines = preg_split('/\R{2,}/', str_replace(["\r\n", "\r"], "\n", $documentoRaw)) ?: [];
$documentoSegments = array_values(array_filter(array_map('trim', $documentoLines), static fn ($line) => $line !== ''));
if (!$documentoSegments) {
    add_flash('danger', 'Inserire il testo da inviare nel telegramma.');
    header('Location: create.php');
    exit;
}

$payload = [
    'prodotto' => $prodotto,
    'mittente' => $mittente,
    'destinatari' => $destinatari,
    'documento' => count($documentoSegments) === 1 ? $documentoSegments[0] : $documentoSegments,
];

if ($opzioni !== null) {
    $payload['opzioni'] = $opzioni;
}
if ($callback !== null) {
    $payload['callback'] = $callback;
}
if ($extra !== null) {
    $payload = array_merge($payload, $extra);
}

$riferimento = trim((string) ($_POST['riferimento'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($userId !== null && $userId <= 0) {
    $userId = null;
}

try {
    $client = new UfficioPostaleClient();
    $service = new TelegrammiService($pdo);

    $result = $client->createTelegram($payload);

    if ($note !== '') {
        if (isset($result['data']) && is_array($result['data'])) {
            if (isset($result['data']['id'])) {
                $result['data']['note'] = $note;
            } elseif ($result['data'] !== [] && array_keys($result['data']) === range(0, count($result['data']) - 1)) {
                foreach ($result['data'] as $index => $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $result['data'][$index]['note'] = $note;
                        break;
                    }
                }
            }
        } elseif (isset($result['id'])) {
            $result['note'] = $note;
        }
    }

    $records = $service->persistFromApi($result, $clienteId, $userId, $riferimento === '' ? null : $riferimento);
    $record = $records[0] ?? null;

    if ($record === null) {
        add_flash('warning', 'Telegramma inviato ma non è stato possibile salvare il dettaglio localmente.');
        header('Location: index.php');
        exit;
    }

    $telegrammaId = (string) ($record['telegramma_id'] ?? '');
    add_flash('success', 'Telegramma inviato correttamente. ID: ' . $telegrammaId);
    header('Location: view.php?id=' . urlencode($telegrammaId));
    exit;
} catch (Throwable $exception) {
    error_log('Telegrammi store error: ' . $exception->getMessage());
    add_flash('danger', 'Impossibile inviare il telegramma: ' . $exception->getMessage());
    header('Location: create.php');
    exit;
}
