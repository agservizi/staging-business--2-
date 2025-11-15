<?php

declare(strict_types=1);

use DateTime;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$bookingId = (int) ($_GET['id'] ?? 0);
if ($bookingId <= 0) {
    add_flash('warning', 'Prenotazione CIE non valida.');
    header('Location: index.php');
    exit;
}

$booking = cie_fetch_booking($pdo, $bookingId);
if ($booking === null) {
    add_flash('warning', 'Prenotazione CIE non trovata.');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Modifica prenotazione CIE';
$bookingCode = cie_booking_code($booking);
$clients = cie_fetch_clients($pdo);
$statusMap = cie_status_map();

$normalizeDateForInput = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $value = (string) $value;
    $formats = ['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd/m/Y H:i'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
};

$normalizeTimeForInput = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $value = (string) $value;
    if (preg_match('/^\d{2}:\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
        return substr($value, 0, 5);
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('H:i', $timestamp) : '';
};

$formData = [
    'cliente_id' => isset($booking['cliente_id']) && (int) $booking['cliente_id'] > 0 ? (int) $booking['cliente_id'] : null,
    'cittadino_nome' => (string) ($booking['cittadino_nome'] ?? ''),
    'cittadino_cognome' => (string) ($booking['cittadino_cognome'] ?? ''),
    'cittadino_cf' => (string) ($booking['cittadino_cf'] ?? ''),
    'cittadino_email' => (string) ($booking['cittadino_email'] ?? ''),
    'cittadino_telefono' => (string) ($booking['cittadino_telefono'] ?? ''),
    'data_nascita' => $normalizeDateForInput($booking['data_nascita'] ?? null),
    'luogo_nascita' => (string) ($booking['luogo_nascita'] ?? ''),
    'residenza_indirizzo' => (string) ($booking['residenza_indirizzo'] ?? ''),
    'residenza_cap' => (string) ($booking['residenza_cap'] ?? ''),
    'residenza_citta' => (string) ($booking['residenza_citta'] ?? ''),
    'residenza_provincia' => (string) ($booking['residenza_provincia'] ?? ''),
    'comune_richiesta' => (string) ($booking['comune_richiesta'] ?? ''),
    'disponibilita_data' => $normalizeDateForInput($booking['disponibilita_data'] ?? null),
    'disponibilita_fascia' => (string) ($booking['disponibilita_fascia'] ?? ''),
    'appuntamento_data' => $normalizeDateForInput($booking['appuntamento_data'] ?? null),
    'appuntamento_orario' => $normalizeTimeForInput($booking['appuntamento_orario'] ?? null),
    'appuntamento_numero' => (string) ($booking['appuntamento_numero'] ?? ''),
    'stato' => (string) ($booking['stato'] ?? 'nuova'),
    'note' => (string) ($booking['note'] ?? ''),
    'esito' => (string) ($booking['esito'] ?? ''),
];

$errors = [];
$csrfToken = csrf_token();
$removeDocumento = false;
$removeFoto = false;
$removeRicevuta = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $formData['cliente_id'] = isset($_POST['cliente_id']) && (int) $_POST['cliente_id'] > 0 ? (int) $_POST['cliente_id'] : null;
    $formData['cittadino_nome'] = trim((string) ($_POST['cittadino_nome'] ?? ''));
    $formData['cittadino_cognome'] = trim((string) ($_POST['cittadino_cognome'] ?? ''));
    $formData['cittadino_cf'] = strtoupper(trim((string) ($_POST['cittadino_cf'] ?? '')));
    $formData['cittadino_email'] = trim((string) ($_POST['cittadino_email'] ?? ''));
    $formData['cittadino_telefono'] = trim((string) ($_POST['cittadino_telefono'] ?? ''));
    $formData['data_nascita'] = trim((string) ($_POST['data_nascita'] ?? ''));
    $formData['luogo_nascita'] = trim((string) ($_POST['luogo_nascita'] ?? ''));
    $formData['residenza_indirizzo'] = trim((string) ($_POST['residenza_indirizzo'] ?? ''));
    $formData['residenza_cap'] = trim((string) ($_POST['residenza_cap'] ?? ''));
    $formData['residenza_citta'] = trim((string) ($_POST['residenza_citta'] ?? ''));
    $formData['residenza_provincia'] = strtoupper(trim((string) ($_POST['residenza_provincia'] ?? '')));
    $formData['comune_richiesta'] = trim((string) ($_POST['comune_richiesta'] ?? ''));
    $formData['disponibilita_data'] = trim((string) ($_POST['disponibilita_data'] ?? ''));
    $formData['disponibilita_fascia'] = trim((string) ($_POST['disponibilita_fascia'] ?? ''));
    $formData['appuntamento_data'] = trim((string) ($_POST['appuntamento_data'] ?? ''));
    $formData['appuntamento_orario'] = trim((string) ($_POST['appuntamento_orario'] ?? ''));
    $formData['appuntamento_numero'] = trim((string) ($_POST['appuntamento_numero'] ?? ''));
    $formData['stato'] = (string) ($_POST['stato'] ?? ($formData['stato'] ?? 'nuova'));
    $formData['note'] = trim((string) ($_POST['note'] ?? ''));
    $formData['esito'] = trim((string) ($_POST['esito'] ?? ''));

    $removeDocumento = isset($_POST['remove_documento_identita']) && $_POST['remove_documento_identita'] === '1';
    $removeFoto = isset($_POST['remove_foto_cittadino']) && $_POST['remove_foto_cittadino'] === '1';
    $removeRicevuta = isset($_POST['remove_ricevuta']) && $_POST['remove_ricevuta'] === '1';

    if ($formData['cittadino_nome'] === '') {
        $errors[] = 'Inserisci il nome del cittadino.';
    }

    if ($formData['cittadino_cognome'] === '') {
        $errors[] = 'Inserisci il cognome del cittadino.';
    }

    if ($formData['comune_richiesta'] === '') {
        $errors[] = 'Indica il comune dove effettuare la richiesta.';
    }

    if ($formData['cittadino_cf'] !== '' && !preg_match('/^[A-Z0-9]{11,16}$/', $formData['cittadino_cf'])) {
        $errors[] = 'Codice fiscale non valido. Inserisci 11-16 caratteri alfanumerici.';
    }

    if ($formData['cittadino_email'] !== '' && !filter_var($formData['cittadino_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo email non valido.';
    }

    if ($formData['data_nascita'] !== '' && !DateTime::createFromFormat('Y-m-d', $formData['data_nascita'])) {
        $errors[] = 'Data di nascita non valida.';
    }

    if ($formData['disponibilita_data'] !== '' && !DateTime::createFromFormat('Y-m-d', $formData['disponibilita_data'])) {
        $errors[] = 'Data di disponibilità non valida.';
    }

    if ($formData['appuntamento_data'] !== '' && !DateTime::createFromFormat('Y-m-d', $formData['appuntamento_data'])) {
        $errors[] = 'Data appuntamento non valida.';
    }

    if ($formData['appuntamento_orario'] !== '' && !preg_match('/^\d{2}:\d{2}$/', $formData['appuntamento_orario'])) {
        $errors[] = 'Orario appuntamento non valido. Usa formato HH:MM.';
    }

    if ($formData['residenza_provincia'] !== '' && strlen($formData['residenza_provincia']) > 5) {
        $errors[] = 'La sigla provincia può contenere al massimo 5 caratteri.';
    }

    if (!array_key_exists($formData['stato'], $statusMap)) {
        $formData['stato'] = 'nuova';
    }

    if (!$errors) {
        $payload = [
            'cliente_id' => $formData['cliente_id'],
            'cittadino_nome' => $formData['cittadino_nome'],
            'cittadino_cognome' => $formData['cittadino_cognome'],
            'cittadino_cf' => $formData['cittadino_cf'] !== '' ? $formData['cittadino_cf'] : null,
            'cittadino_email' => $formData['cittadino_email'] !== '' ? $formData['cittadino_email'] : null,
            'cittadino_telefono' => $formData['cittadino_telefono'] !== '' ? $formData['cittadino_telefono'] : null,
            'data_nascita' => $formData['data_nascita'] !== '' ? $formData['data_nascita'] : null,
            'luogo_nascita' => $formData['luogo_nascita'] !== '' ? $formData['luogo_nascita'] : null,
            'residenza_indirizzo' => $formData['residenza_indirizzo'] !== '' ? $formData['residenza_indirizzo'] : null,
            'residenza_cap' => $formData['residenza_cap'] !== '' ? $formData['residenza_cap'] : null,
            'residenza_citta' => $formData['residenza_citta'] !== '' ? $formData['residenza_citta'] : null,
            'residenza_provincia' => $formData['residenza_provincia'] !== '' ? $formData['residenza_provincia'] : null,
            'comune_richiesta' => $formData['comune_richiesta'],
            'disponibilita_data' => $formData['disponibilita_data'] !== '' ? $formData['disponibilita_data'] : null,
            'disponibilita_fascia' => $formData['disponibilita_fascia'] !== '' ? $formData['disponibilita_fascia'] : null,
            'appuntamento_data' => $formData['appuntamento_data'] !== '' ? $formData['appuntamento_data'] : null,
            'appuntamento_orario' => $formData['appuntamento_orario'] !== '' ? $formData['appuntamento_orario'] : null,
            'appuntamento_numero' => $formData['appuntamento_numero'] !== '' ? $formData['appuntamento_numero'] : null,
            'stato' => $formData['stato'],
            'note' => $formData['note'] !== '' ? $formData['note'] : null,
            'esito' => $formData['esito'] !== '' ? $formData['esito'] : null,
        ];

        try {
            cie_update($pdo, $bookingId, $payload, $_FILES, [
                'remove_documento_identita' => $removeDocumento,
                'remove_foto_cittadino' => $removeFoto,
                'remove_ricevuta' => $removeRicevuta,
            ]);

            add_flash('success', 'Prenotazione CIE aggiornata correttamente.');
            header('Location: view.php?id=' . $bookingId);
            exit;
        } catch (Throwable $exception) {
            error_log('Errore aggiornamento prenotazione CIE #' . $bookingId . ': ' . $exception->getMessage());
            $errors[] = 'Impossibile aggiornare la prenotazione: ' . $exception->getMessage();
            $booking = cie_fetch_booking($pdo, $bookingId) ?? $booking;
        }
    }

    $formData['data_nascita'] = $normalizeDateForInput($formData['data_nascita']);
    $formData['disponibilita_data'] = $normalizeDateForInput($formData['disponibilita_data']);
    $formData['appuntamento_data'] = $normalizeDateForInput($formData['appuntamento_data']);
    $formData['appuntamento_orario'] = $normalizeTimeForInput($formData['appuntamento_orario']);
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Modifica prenotazione CIE</h1>
                <p class="text-muted mb-0">
                    Prenotazione #<?php echo (int) $booking['id']; ?> · Codice <strong><?php echo sanitize_output($bookingCode); ?></strong>
                </p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-light" href="view.php?id=<?php echo (int) $booking['id']; ?>"><i class="fa-solid fa-arrow-left me-2"></i>Dettaglio</a>
                <a class="btn btn-outline-warning" href="open_portal.php?id=<?php echo (int) $booking['id']; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square me-2"></i>Portale CIE</a>
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuova richiesta</a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-warning" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize_output($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card ag-card">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-4">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">

                    <div class="col-12">
                        <h2 class="h5 mb-3">Dati cliente</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="cliente_id">Cliente collegato (Servizi ANPR)</label>
                                <select class="form-select" id="cliente_id" name="cliente_id">
                                    <option value="">Seleziona cliente (facoltativo)</option>
                                    <?php foreach ($clients as $client): ?>
                                        <?php $clientId = (int) ($client['id'] ?? 0); ?>
                                        <option value="<?php echo $clientId; ?>" data-client='<?php echo sanitize_output(json_encode([
                                            'nome' => $client['nome'] ?? '',
                                            'cognome' => $client['cognome'] ?? '',
                                            'cf' => $client['cf_piva'] ?? '',
                                            'email' => $client['email'] ?? '',
                                            'telefono' => $client['telefono'] ?? '',
                                            'indirizzo' => $client['indirizzo'] ?? '',
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>' <?php echo ($formData['cliente_id'] ?? null) === $clientId ? 'selected' : ''; ?>>
                                            <?php echo sanitize_output(trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? ''))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Importa automaticamente nome, cognome e contatti se presenti.</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Dati del cittadino</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_nome">Nome</label>
                                <input class="form-control" id="cittadino_nome" name="cittadino_nome" value="<?php echo sanitize_output($formData['cittadino_nome']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_cognome">Cognome</label>
                                <input class="form-control" id="cittadino_cognome" name="cittadino_cognome" value="<?php echo sanitize_output($formData['cittadino_cognome']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_cf">Codice fiscale</label>
                                <input class="form-control" id="cittadino_cf" name="cittadino_cf" value="<?php echo sanitize_output($formData['cittadino_cf']); ?>" maxlength="16" placeholder="Facoltativo">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="data_nascita">Data di nascita</label>
                                <input class="form-control" type="date" id="data_nascita" name="data_nascita" value="<?php echo sanitize_output($formData['data_nascita']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="luogo_nascita">Luogo di nascita</label>
                                <input class="form-control" id="luogo_nascita" name="luogo_nascita" value="<?php echo sanitize_output($formData['luogo_nascita']); ?>" placeholder="Comune di nascita">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_email">Email</label>
                                <input class="form-control" type="email" id="cittadino_email" name="cittadino_email" value="<?php echo sanitize_output($formData['cittadino_email']); ?>" placeholder="Facoltativa per notifiche">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_telefono">Telefono</label>
                                <input class="form-control" id="cittadino_telefono" name="cittadino_telefono" value="<?php echo sanitize_output($formData['cittadino_telefono']); ?>" placeholder="Facoltativo, utile per WhatsApp">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Residenza</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="residenza_indirizzo">Indirizzo</label>
                                <input class="form-control" id="residenza_indirizzo" name="residenza_indirizzo" value="<?php echo sanitize_output($formData['residenza_indirizzo']); ?>" placeholder="Via/Piazza e civico">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="residenza_cap">CAP</label>
                                <input class="form-control" id="residenza_cap" name="residenza_cap" value="<?php echo sanitize_output($formData['residenza_cap']); ?>" maxlength="10">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="residenza_citta">Comune</label>
                                <input class="form-control" id="residenza_citta" name="residenza_citta" value="<?php echo sanitize_output($formData['residenza_citta']); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="residenza_provincia">Provincia</label>
                                <input class="form-control" id="residenza_provincia" name="residenza_provincia" value="<?php echo sanitize_output($formData['residenza_provincia']); ?>" maxlength="5" placeholder="ES. RM">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Richiesta e disponibilità</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="comune_richiesta">Comune di richiesta</label>
                                <input class="form-control" id="comune_richiesta" name="comune_richiesta" value="<?php echo sanitize_output($formData['comune_richiesta']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="disponibilita_data">Data preferita</label>
                                <input class="form-control" type="date" id="disponibilita_data" name="disponibilita_data" value="<?php echo sanitize_output($formData['disponibilita_data']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="disponibilita_fascia">Fascia oraria</label>
                                <input class="form-control" id="disponibilita_fascia" name="disponibilita_fascia" value="<?php echo sanitize_output($formData['disponibilita_fascia']); ?>" placeholder="Es. Mattina/Pomeriggio">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Appuntamento</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="appuntamento_data">Data appuntamento</label>
                                <input class="form-control" type="date" id="appuntamento_data" name="appuntamento_data" value="<?php echo sanitize_output($formData['appuntamento_data']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="appuntamento_orario">Orario</label>
                                <input class="form-control" type="time" id="appuntamento_orario" name="appuntamento_orario" value="<?php echo sanitize_output($formData['appuntamento_orario']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="appuntamento_numero">Numero prenotazione</label>
                                <input class="form-control" id="appuntamento_numero" name="appuntamento_numero" value="<?php echo sanitize_output($formData['appuntamento_numero']); ?>" placeholder="Codice appuntamento portale">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Stato e note</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="stato">Stato richiesta</label>
                                <select class="form-select" id="stato" name="stato">
                                    <?php foreach ($statusMap as $key => $config): ?>
                                        <option value="<?php echo sanitize_output($key); ?>" <?php echo $formData['stato'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($config['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="esito">Esito</label>
                                <textarea class="form-control" id="esito" name="esito" rows="2" placeholder="Esito finale o note da condividere."><?php echo sanitize_output($formData['esito']); ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="note">Note interne</label>
                                <textarea class="form-control" id="note" name="note" rows="2" placeholder="Annotazioni visibili solo agli operatori."><?php echo sanitize_output($formData['note']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Documentazione</h2>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="form-label" for="documento_identita">Documento di riconoscimento</label>
                                <input class="form-control" type="file" id="documento_identita" name="documento_identita" accept=".pdf,image/jpeg,image/png">
                                <small class="text-muted">PDF, JPG o PNG fino a 10 MB. Carica un nuovo file per sostituire quello esistente.</small>
                                <?php if (!empty($booking['documento_identita_path'])): ?>
                                    <div class="mt-2 p-2 border rounded bg-dark-subtle">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <a class="text-warning text-decoration-none" href="<?php echo sanitize_output(base_url((string) $booking['documento_identita_path'])); ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-paperclip me-2"></i><?php echo sanitize_output($booking['documento_identita_nome'] ?? 'documento_identita'); ?>
                                            </a>
                                            <a class="btn btn-sm btn-outline-warning" href="<?php echo sanitize_output(base_url((string) $booking['documento_identita_path'])); ?>" download>Scarica</a>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="remove_documento_identita" name="remove_documento_identita" value="1" <?php echo $removeDocumento ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="remove_documento_identita">Rimuovi documento esistente</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="foto_cittadino">Foto cittadino</label>
                                <input class="form-control" type="file" id="foto_cittadino" name="foto_cittadino" accept="image/jpeg,image/png">
                                <small class="text-muted">Foto tessera aggiornata, massimo 5 MB.</small>
                                <?php if (!empty($booking['foto_cittadino_path'])): ?>
                                    <div class="mt-2 p-2 border rounded bg-dark-subtle">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <a class="text-warning text-decoration-none" href="<?php echo sanitize_output(base_url((string) $booking['foto_cittadino_path'])); ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-paperclip me-2"></i><?php echo sanitize_output($booking['foto_cittadino_nome'] ?? 'foto_cittadino'); ?>
                                            </a>
                                            <a class="btn btn-sm btn-outline-warning" href="<?php echo sanitize_output(base_url((string) $booking['foto_cittadino_path'])); ?>" download>Scarica</a>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="remove_foto_cittadino" name="remove_foto_cittadino" value="1" <?php echo $removeFoto ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="remove_foto_cittadino">Rimuovi foto esistente</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="ricevuta">Ricevuta/Conferma portale</label>
                                <input class="form-control" type="file" id="ricevuta" name="ricevuta" accept=".pdf">
                                <small class="text-muted">Carica la ricevuta generata dal portale, fino a 15 MB.</small>
                                <?php if (!empty($booking['ricevuta_path'])): ?>
                                    <div class="mt-2 p-2 border rounded bg-dark-subtle">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <a class="text-warning text-decoration-none" href="<?php echo sanitize_output(base_url((string) $booking['ricevuta_path'])); ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-paperclip me-2"></i><?php echo sanitize_output($booking['ricevuta_nome'] ?? 'ricevuta'); ?>
                                            </a>
                                            <a class="btn btn-sm btn-outline-warning" href="<?php echo sanitize_output(base_url((string) $booking['ricevuta_path'])); ?>" download>Scarica</a>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="remove_ricevuta" name="remove_ricevuta" value="1" <?php echo $removeRicevuta ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="remove_ricevuta">Rimuovi ricevuta esistente</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-light" href="view.php?id=<?php echo (int) $booking['id']; ?>">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Aggiorna prenotazione</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<script>
(function () {
    const select = document.getElementById('cliente_id');
    if (!select) {
        return;
    }

    select.addEventListener('change', function () {
        const option = select.selectedOptions[0];
        if (!option) {
            return;
        }
        const dataAttr = option.getAttribute('data-client');
        if (!dataAttr) {
            return;
        }
        try {
            const payload = JSON.parse(dataAttr);
            if (payload.nome && !document.getElementById('cittadino_nome').value) {
                document.getElementById('cittadino_nome').value = payload.nome;
            }
            if (payload.cognome && !document.getElementById('cittadino_cognome').value) {
                document.getElementById('cittadino_cognome').value = payload.cognome;
            }
            if (payload.cf && !document.getElementById('cittadino_cf').value) {
                document.getElementById('cittadino_cf').value = payload.cf.toUpperCase();
            }
            if (payload.email && !document.getElementById('cittadino_email').value) {
                document.getElementById('cittadino_email').value = payload.email;
            }
            if (payload.telefono && !document.getElementById('cittadino_telefono').value) {
                document.getElementById('cittadino_telefono').value = payload.telefono;
            }
            if (payload.indirizzo && !document.getElementById('residenza_indirizzo').value) {
                document.getElementById('residenza_indirizzo').value = payload.indirizzo;
            }
        } catch (error) {
            console.warn('Impossibile importare i dati cliente', error);
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
