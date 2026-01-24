<?php
declare(strict_types=1);

use App\Services\SettingsService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Modifica pratica ACI';

$projectRoot = realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../../';
$settingsService = new SettingsService($pdo, $projectRoot);

$defaultTypes = [
    'Passaggio di proprietà',
    'Immatricolazione',
    'Radiazione per esportazione',
    'Perdita di possesso',
    'Reimmatricolazione',
    'Duplicato carta di circolazione',
    'Duplicato certificato di proprietà (CDP)',
    'Aggiornamento dati',
    'Altro',
];

$tipi = $settingsService->getAciTypes();
if (!$tipi) {
    $tipi = $defaultTypes;
}

$defaultStatus = ['Bozza', 'In lavorazione', 'Inviata ACI', 'Completata', 'Sospesa'];
$stati = $settingsService->getAciStatuses();
if (!$stati) {
    $stati = $defaultStatus;
}

$praticaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

    $pratica = aci_get_pratica($pdo, $praticaId);
    if (!$pratica) {
        add_flash('warning', 'Pratica non trovata.');
        header('Location: index.php');
        exit;
    }

    $aciPricingList = $settingsService->getAciPricing($tipi);
    $aciPricingMap = [];
    foreach ($aciPricingList as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $price = $item['price'] ?? null;
        if ($price !== null && $price !== '') {
            $aciPricingMap[$name] = (float) $price;
        }
    }
    $aciPricingJson = json_encode($aciPricingMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
    $clients = $clientsStmt ? $clientsStmt->fetchAll() : [];

    $operatorsStmt = $pdo->query("SELECT id, username, nome, cognome FROM users WHERE ruolo IN ('Admin','Manager','Operatore') ORDER BY nome, cognome, username");
    $operators = $operatorsStmt ? $operatorsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $errors = [];
    $data = [
        'cliente_id' => (string) ($pratica['cliente_id'] ?? ''),
        'tipo_pratica' => (string) ($pratica['tipo_pratica'] ?? ($tipi[0] ?? $defaultTypes[0])),
        'tipo_pratica_altro' => (string) ($pratica['tipo_pratica_altro'] ?? ''),
        'stato' => (string) ($pratica['stato'] ?? ($stati[0] ?? 'Bozza')),
        'protocollo' => (string) ($pratica['protocollo'] ?? ''),
        'persona_giuridica' => (bool) ($pratica['persona_giuridica'] ?? 0),
        'intestatario_nome' => (string) ($pratica['intestatario_nome'] ?? ''),
        'intestatario_cognome' => (string) ($pratica['intestatario_cognome'] ?? ''),
        'intestatario_codice_fiscale' => (string) ($pratica['intestatario_codice_fiscale'] ?? ''),
        'intestatario_data_nascita' => $pratica['intestatario_data_nascita'] ? (string) $pratica['intestatario_data_nascita'] : '',
        'intestatario_luogo_nascita' => (string) ($pratica['intestatario_luogo_nascita'] ?? ''),
        'intestatario_residenza' => (string) ($pratica['intestatario_residenza'] ?? ''),
        'intestatario_email' => (string) ($pratica['intestatario_email'] ?? ''),
        'intestatario_telefono' => (string) ($pratica['intestatario_telefono'] ?? ''),
        'intestatario_ragione_sociale' => (string) ($pratica['intestatario_ragione_sociale'] ?? ''),
        'intestatario_partita_iva' => (string) ($pratica['intestatario_partita_iva'] ?? ''),
        'intestatario_codice_fiscale_giuridico' => (string) ($pratica['intestatario_codice_fiscale_giuridico'] ?? ''),
        'intestatario_sede_legale' => (string) ($pratica['intestatario_sede_legale'] ?? ''),
        'targa' => (string) ($pratica['targa'] ?? ''),
        'telaio' => (string) ($pratica['telaio'] ?? ''),
        'veicolo_tipo' => (string) ($pratica['veicolo_tipo'] ?? 'Auto'),
        'veicolo_marca' => (string) ($pratica['veicolo_marca'] ?? ''),
        'veicolo_modello' => (string) ($pratica['veicolo_modello'] ?? ''),
        'veicolo_anno_immatricolazione' => (string) ($pratica['veicolo_anno_immatricolazione'] ?? ''),
        'veicolo_alimentazione' => (string) ($pratica['veicolo_alimentazione'] ?? ''),
        'veicolo_potenza_kw' => (string) ($pratica['veicolo_potenza_kw'] ?? ''),
        'veicolo_classe_ambientale' => (string) ($pratica['veicolo_classe_ambientale'] ?? ''),
        'venditore_nome' => (string) ($pratica['venditore_nome'] ?? ''),
        'venditore_codice_fiscale' => (string) ($pratica['venditore_codice_fiscale'] ?? ''),
        'venditore_indirizzo' => (string) ($pratica['venditore_indirizzo'] ?? ''),
        'acquirente_nome' => (string) ($pratica['acquirente_nome'] ?? ''),
        'acquirente_codice_fiscale' => (string) ($pratica['acquirente_codice_fiscale'] ?? ''),
        'acquirente_indirizzo' => (string) ($pratica['acquirente_indirizzo'] ?? ''),
        'venditore_acquirente_coincidono' => (bool) ($pratica['venditore_acquirente_coincidono'] ?? 0),
        'diritti_aci' => number_format((float) ($pratica['diritti_aci'] ?? 0), 2, '.', ''),
        'imposta_bollo' => number_format((float) ($pratica['imposta_bollo'] ?? 0), 2, '.', ''),
        'emolumenti' => number_format((float) ($pratica['emolumenti'] ?? 0), 2, '.', ''),
        'compenso_agenzia' => number_format((float) ($pratica['compenso_agenzia'] ?? 0), 2, '.', ''),
        'totale' => number_format((float) ($pratica['totale'] ?? ($pratica['costo'] ?? 0)), 2, '.', ''),
        'metodo_pagamento' => (string) ($pratica['metodo_pagamento'] ?? 'Contanti'),
        'consenso_privacy' => (bool) ($pratica['consenso_privacy'] ?? 0),
        'consenso_aci' => (bool) ($pratica['consenso_aci'] ?? 0),
        'consenso_veridicita' => (bool) ($pratica['consenso_veridicita'] ?? 0),
        'note' => (string) ($pratica['note'] ?? ''),
        'note_interne' => (string) ($pratica['note_interne'] ?? ''),
        'assigned_to' => (string) ($pratica['assigned_to'] ?? ''),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_valid_csrf();

        $data['cliente_id'] = trim((string) ($_POST['cliente_id'] ?? ''));
        $data['tipo_pratica'] = trim((string) ($_POST['tipo_pratica'] ?? ''));
        $data['tipo_pratica_altro'] = trim((string) ($_POST['tipo_pratica_altro'] ?? ''));
        $data['stato'] = trim((string) ($_POST['stato'] ?? ''));
        $data['protocollo'] = trim((string) ($_POST['protocollo'] ?? ''));
        $data['persona_giuridica'] = isset($_POST['persona_giuridica']);

        $data['intestatario_nome'] = trim((string) ($_POST['intestatario_nome'] ?? ''));
        $data['intestatario_cognome'] = trim((string) ($_POST['intestatario_cognome'] ?? ''));
        $data['intestatario_codice_fiscale'] = strtoupper(trim((string) ($_POST['intestatario_codice_fiscale'] ?? '')));
        $data['intestatario_data_nascita'] = trim((string) ($_POST['intestatario_data_nascita'] ?? ''));
        $data['intestatario_luogo_nascita'] = trim((string) ($_POST['intestatario_luogo_nascita'] ?? ''));
        $data['intestatario_residenza'] = trim((string) ($_POST['intestatario_residenza'] ?? ''));
        $data['intestatario_email'] = trim((string) ($_POST['intestatario_email'] ?? ''));
        $data['intestatario_telefono'] = trim((string) ($_POST['intestatario_telefono'] ?? ''));

        $data['intestatario_ragione_sociale'] = trim((string) ($_POST['intestatario_ragione_sociale'] ?? ''));
        $data['intestatario_partita_iva'] = trim((string) ($_POST['intestatario_partita_iva'] ?? ''));
        $data['intestatario_codice_fiscale_giuridico'] = strtoupper(trim((string) ($_POST['intestatario_codice_fiscale_giuridico'] ?? '')));
        $data['intestatario_sede_legale'] = trim((string) ($_POST['intestatario_sede_legale'] ?? ''));

        $data['targa'] = strtoupper(trim((string) ($_POST['targa'] ?? '')));
        $data['telaio'] = strtoupper(trim((string) ($_POST['telaio'] ?? '')));
        $data['veicolo_tipo'] = trim((string) ($_POST['veicolo_tipo'] ?? ''));
        $data['veicolo_marca'] = trim((string) ($_POST['veicolo_marca'] ?? ''));
        $data['veicolo_modello'] = trim((string) ($_POST['veicolo_modello'] ?? ''));
        $data['veicolo_anno_immatricolazione'] = trim((string) ($_POST['veicolo_anno_immatricolazione'] ?? ''));
        $data['veicolo_alimentazione'] = trim((string) ($_POST['veicolo_alimentazione'] ?? ''));
        $data['veicolo_potenza_kw'] = trim((string) ($_POST['veicolo_potenza_kw'] ?? ''));
        $data['veicolo_classe_ambientale'] = trim((string) ($_POST['veicolo_classe_ambientale'] ?? ''));

        $data['venditore_nome'] = trim((string) ($_POST['venditore_nome'] ?? ''));
        $data['venditore_codice_fiscale'] = trim((string) ($_POST['venditore_codice_fiscale'] ?? ''));
        $data['venditore_indirizzo'] = trim((string) ($_POST['venditore_indirizzo'] ?? ''));
        $data['acquirente_nome'] = trim((string) ($_POST['acquirente_nome'] ?? ''));
        $data['acquirente_codice_fiscale'] = trim((string) ($_POST['acquirente_codice_fiscale'] ?? ''));
        $data['acquirente_indirizzo'] = trim((string) ($_POST['acquirente_indirizzo'] ?? ''));
        $data['venditore_acquirente_coincidono'] = isset($_POST['venditore_acquirente_coincidono']);

        $data['diritti_aci'] = trim((string) ($_POST['diritti_aci'] ?? '0'));
        $data['imposta_bollo'] = trim((string) ($_POST['imposta_bollo'] ?? '0'));
        $data['emolumenti'] = trim((string) ($_POST['emolumenti'] ?? '0'));
        $data['compenso_agenzia'] = trim((string) ($_POST['compenso_agenzia'] ?? '0'));
        $data['totale'] = trim((string) ($_POST['totale'] ?? '0'));
        $data['metodo_pagamento'] = trim((string) ($_POST['metodo_pagamento'] ?? ''));

        $data['consenso_privacy'] = isset($_POST['consenso_privacy']);
        $data['consenso_aci'] = isset($_POST['consenso_aci']);
        $data['consenso_veridicita'] = isset($_POST['consenso_veridicita']);
        $data['note'] = trim((string) ($_POST['note'] ?? ''));
        $data['note_interne'] = trim((string) ($_POST['note_interne'] ?? ''));
        $data['assigned_to'] = trim((string) ($_POST['assigned_to'] ?? ''));

        if ($data['tipo_pratica'] === '' || !in_array($data['tipo_pratica'], $tipi, true)) {
            $errors[] = 'Seleziona il tipo di pratica.';
        }
        if ($data['tipo_pratica'] === 'Altro' && $data['tipo_pratica_altro'] === '') {
            $errors[] = 'Specifica la descrizione per il tipo Altro.';
        }
        if ($data['stato'] === '' || !in_array($data['stato'], $stati, true)) {
            $errors[] = 'Seleziona lo stato della pratica.';
        }

        if ($data['persona_giuridica']) {
            if ($data['intestatario_ragione_sociale'] === '') {
                $errors[] = 'Ragione sociale obbligatoria.';
            }
            if ($data['intestatario_partita_iva'] === '') {
                $errors[] = 'Partita IVA obbligatoria.';
            }
            if ($data['intestatario_sede_legale'] === '') {
                $errors[] = 'Sede legale obbligatoria.';
            }
        } else {
            if ($data['intestatario_nome'] === '') {
                $errors[] = 'Nome intestatario obbligatorio.';
            }
            if ($data['intestatario_cognome'] === '') {
                $errors[] = 'Cognome intestatario obbligatorio.';
            }
            if ($data['intestatario_codice_fiscale'] === '') {
                $errors[] = 'Codice fiscale intestatario obbligatorio.';
            }
        }

        if ($data['targa'] === '') {
            $errors[] = 'La targa è obbligatoria.';
        }

        if (!$data['consenso_privacy'] || !$data['consenso_aci'] || !$data['consenso_veridicita']) {
            $errors[] = 'Devi confermare tutti i consensi richiesti.';
        }

        $clienteId = null;
        if ($data['cliente_id'] !== '') {
            if (!ctype_digit($data['cliente_id'])) {
                $errors[] = 'Cliente non valido.';
            } else {
                $clienteId = (int) $data['cliente_id'];
            }
        }

        $assignedTo = null;
        if ($data['assigned_to'] !== '') {
            if (!ctype_digit($data['assigned_to'])) {
                $errors[] = 'Operatore non valido.';
            } else {
                $assignedTo = (int) $data['assigned_to'];
            }
        }

        if (!$errors) {
            $intestatario = $data['persona_giuridica']
                ? $data['intestatario_ragione_sociale']
                : trim($data['intestatario_nome'] . ' ' . $data['intestatario_cognome']);

            $stmt = $pdo->prepare('UPDATE servizi_aci_pratiche SET
                cliente_id = :cliente_id,
                tipo_pratica = :tipo_pratica,
                tipo_pratica_altro = :tipo_pratica_altro,
                stato = :stato,
                targa = :targa,
                telaio = :telaio,
                intestatario = :intestatario,
                protocollo = :protocollo,
                costo = :costo,
                note = :note,
                persona_giuridica = :persona_giuridica,
                intestatario_nome = :intestatario_nome,
                intestatario_cognome = :intestatario_cognome,
                intestatario_codice_fiscale = :intestatario_codice_fiscale,
                intestatario_data_nascita = :intestatario_data_nascita,
                intestatario_luogo_nascita = :intestatario_luogo_nascita,
                intestatario_residenza = :intestatario_residenza,
                intestatario_email = :intestatario_email,
                intestatario_telefono = :intestatario_telefono,
                intestatario_ragione_sociale = :intestatario_ragione_sociale,
                intestatario_partita_iva = :intestatario_partita_iva,
                intestatario_codice_fiscale_giuridico = :intestatario_codice_fiscale_giuridico,
                intestatario_sede_legale = :intestatario_sede_legale,
                veicolo_tipo = :veicolo_tipo,
                veicolo_marca = :veicolo_marca,
                veicolo_modello = :veicolo_modello,
                veicolo_anno_immatricolazione = :veicolo_anno_immatricolazione,
                veicolo_alimentazione = :veicolo_alimentazione,
                veicolo_potenza_kw = :veicolo_potenza_kw,
                veicolo_classe_ambientale = :veicolo_classe_ambientale,
                venditore_nome = :venditore_nome,
                venditore_codice_fiscale = :venditore_codice_fiscale,
                venditore_indirizzo = :venditore_indirizzo,
                acquirente_nome = :acquirente_nome,
                acquirente_codice_fiscale = :acquirente_codice_fiscale,
                acquirente_indirizzo = :acquirente_indirizzo,
                venditore_acquirente_coincidono = :venditore_acquirente_coincidono,
                diritti_aci = :diritti_aci,
                imposta_bollo = :imposta_bollo,
                emolumenti = :emolumenti,
                compenso_agenzia = :compenso_agenzia,
                totale = :totale,
                metodo_pagamento = :metodo_pagamento,
                consenso_privacy = :consenso_privacy,
                consenso_aci = :consenso_aci,
                consenso_veridicita = :consenso_veridicita,
                note_interne = :note_interne,
                assigned_to = :assigned_to,
                updated_at = NOW()
                WHERE id = :id
                LIMIT 1');

            $stmt->execute([
                ':cliente_id' => $clienteId,
                ':tipo_pratica' => $data['tipo_pratica'],
                ':tipo_pratica_altro' => $data['tipo_pratica_altro'] ?: null,
                ':stato' => $data['stato'],
                ':targa' => $data['targa'] ?: null,
                ':telaio' => $data['telaio'] ?: null,
                ':intestatario' => $intestatario ?: null,
                ':protocollo' => $data['protocollo'] ?: null,
                ':costo' => (float) str_replace(',', '.', $data['totale']),
                ':note' => $data['note'] ?: null,
                ':persona_giuridica' => $data['persona_giuridica'] ? 1 : 0,
                ':intestatario_nome' => $data['intestatario_nome'] ?: null,
                ':intestatario_cognome' => $data['intestatario_cognome'] ?: null,
                ':intestatario_codice_fiscale' => $data['intestatario_codice_fiscale'] ?: null,
                ':intestatario_data_nascita' => $data['intestatario_data_nascita'] ?: null,
                ':intestatario_luogo_nascita' => $data['intestatario_luogo_nascita'] ?: null,
                ':intestatario_residenza' => $data['intestatario_residenza'] ?: null,
                ':intestatario_email' => $data['intestatario_email'] ?: null,
                ':intestatario_telefono' => $data['intestatario_telefono'] ?: null,
                ':intestatario_ragione_sociale' => $data['intestatario_ragione_sociale'] ?: null,
                ':intestatario_partita_iva' => $data['intestatario_partita_iva'] ?: null,
                ':intestatario_codice_fiscale_giuridico' => $data['intestatario_codice_fiscale_giuridico'] ?: null,
                ':intestatario_sede_legale' => $data['intestatario_sede_legale'] ?: null,
                ':veicolo_tipo' => $data['veicolo_tipo'] ?: null,
                ':veicolo_marca' => $data['veicolo_marca'] ?: null,
                ':veicolo_modello' => $data['veicolo_modello'] ?: null,
                ':veicolo_anno_immatricolazione' => $data['veicolo_anno_immatricolazione'] !== '' ? (int) $data['veicolo_anno_immatricolazione'] : null,
                ':veicolo_alimentazione' => $data['veicolo_alimentazione'] ?: null,
                ':veicolo_potenza_kw' => $data['veicolo_potenza_kw'] !== '' ? (float) str_replace(',', '.', $data['veicolo_potenza_kw']) : null,
                ':veicolo_classe_ambientale' => $data['veicolo_classe_ambientale'] ?: null,
                ':venditore_nome' => $data['venditore_nome'] ?: null,
                ':venditore_codice_fiscale' => $data['venditore_codice_fiscale'] ?: null,
                ':venditore_indirizzo' => $data['venditore_indirizzo'] ?: null,
                ':acquirente_nome' => $data['acquirente_nome'] ?: null,
                ':acquirente_codice_fiscale' => $data['acquirente_codice_fiscale'] ?: null,
                ':acquirente_indirizzo' => $data['acquirente_indirizzo'] ?: null,
                ':venditore_acquirente_coincidono' => $data['venditore_acquirente_coincidono'] ? 1 : 0,
                ':diritti_aci' => (float) str_replace(',', '.', $data['diritti_aci']),
                ':imposta_bollo' => (float) str_replace(',', '.', $data['imposta_bollo']),
                ':emolumenti' => (float) str_replace(',', '.', $data['emolumenti']),
                ':compenso_agenzia' => (float) str_replace(',', '.', $data['compenso_agenzia']),
                ':totale' => (float) str_replace(',', '.', $data['totale']),
                ':metodo_pagamento' => $data['metodo_pagamento'] ?: null,
                ':consenso_privacy' => $data['consenso_privacy'] ? 1 : 0,
                ':consenso_aci' => $data['consenso_aci'] ? 1 : 0,
                ':consenso_veridicita' => $data['consenso_veridicita'] ? 1 : 0,
                ':note_interne' => $data['note_interne'] ?: null,
                ':assigned_to' => $assignedTo,
                ':id' => $praticaId,
            ]);

            $uploadErrors = [];
            aci_handle_upload_category($pdo, $praticaId, $_FILES['documento_identita'] ?? null, 'documento_identita', $uploadErrors);
            aci_handle_upload_category($pdo, $praticaId, $_FILES['tessera_sanitaria'] ?? null, 'tessera_sanitaria', $uploadErrors);
            aci_handle_upload_category($pdo, $praticaId, $_FILES['carta_circolazione'] ?? null, 'carta_circolazione', $uploadErrors);
            aci_handle_upload_category($pdo, $praticaId, $_FILES['certificato_proprieta'] ?? null, 'certificato_proprieta', $uploadErrors);
            aci_handle_upload_category($pdo, $praticaId, $_FILES['atto_vendita'] ?? null, 'atto_vendita', $uploadErrors);
            aci_handle_upload_category($pdo, $praticaId, $_FILES['delega'] ?? null, 'delega', $uploadErrors);
            aci_handle_upload_category($pdo, $praticaId, $_FILES['visura_pra'] ?? null, 'visura_pra', $uploadErrors);

            if ($uploadErrors) {
                $errors = array_merge($errors, $uploadErrors);
            }

            if (!$errors) {
                add_flash('success', 'Pratica aggiornata correttamente.');
                header('Location: view.php?id=' . $praticaId);
                exit;
            }
        }
    }

    $attachments = aci_get_attachments($pdo, $praticaId);
    $csrfToken = csrf_token();
    $categorieLabels = [
        'documento_identita' => 'Documento identità intestatario',
        'tessera_sanitaria' => 'Tessera sanitaria',
        'carta_circolazione' => 'Carta di circolazione',
        'certificato_proprieta' => 'Certificato di proprietà (CDP)',
        'atto_vendita' => 'Atto di vendita',
        'delega' => 'Delega firmata',
        'visura_pra' => 'Visura PRA',
        'generico' => 'Allegato',
    ];

    require_once __DIR__ . '/../../../includes/header.php';
    require_once __DIR__ . '/../../../includes/sidebar.php';
    ?>
    <div class="flex-grow-1 d-flex flex-column min-vh-100">
        <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
        <main class="content-wrapper">
            <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h1 class="h3 mb-1">Modifica pratica ACI</h1>
                    <p class="text-muted mb-0">Aggiorna la pratica con il wizard guidato.</p>
                </div>
                <div class="toolbar-actions d-flex gap-2">
                    <a class="btn btn-outline-warning" href="view.php?id=<?php echo (int) $praticaId; ?>"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo sanitize_output($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card ag-card">
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" id="aci-wizard" novalidate>
                        <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">

                        <div class="cr-steps mb-4">
                            <div class="cr-step-indicator" data-step-indicator="1">1. Tipo</div>
                            <div class="cr-step-indicator" data-step-indicator="2">2. Intestatario</div>
                            <div class="cr-step-indicator" data-step-indicator="3">3. Veicolo</div>
                            <div class="cr-step-indicator" data-step-indicator="4">4. Venditore/Acquirente</div>
                            <div class="cr-step-indicator" data-step-indicator="5">5. Documenti</div>
                            <div class="cr-step-indicator" data-step-indicator="6">6. Costi</div>
                            <div class="cr-step-indicator" data-step-indicator="7">7. Consensi</div>
                            <div class="cr-step-indicator" data-step-indicator="8">8. Riepilogo</div>
                        </div>

                        <section class="cr-step" data-step="1">
                            <h2 class="h5 mb-3">Tipo di pratica ACI</h2>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="tipo_pratica">Tipo pratica *</label>
                                    <select class="form-select" id="tipo_pratica" name="tipo_pratica" required>
                                        <?php foreach ($tipi as $tipo): ?>
                                            <option value="<?php echo sanitize_output($tipo); ?>" <?php echo $data['tipo_pratica'] === $tipo ? 'selected' : ''; ?>><?php echo sanitize_output($tipo); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6" data-section="tipo_altro">
                                    <label class="form-label" for="tipo_pratica_altro">Descrizione (Altro) *</label>
                                    <input class="form-control" id="tipo_pratica_altro" name="tipo_pratica_altro" value="<?php echo sanitize_output($data['tipo_pratica_altro']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="cliente_id">Cliente</label>
                                    <select class="form-select" id="cliente_id" name="cliente_id">
                                        <option value="">Seleziona cliente</option>
                                        <?php foreach ($clients as $client): ?>
                                            <?php
                                                $clientLabelParts = array_filter([
                                                    $client['ragione_sociale'] ?: null,
                                                    trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')) ?: null,
                                                ]);
                                                $clientLabel = $clientLabelParts ? implode(' - ', $clientLabelParts) : ('#' . $client['id']);
                                            ?>
                                            <option value="<?php echo (int) $client['id']; ?>" <?php echo (string) $data['cliente_id'] === (string) $client['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($clientLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="stato">Stato *</label>
                                    <select class="form-select" id="stato" name="stato" required>
                                        <?php foreach ($stati as $stato): ?>
                                            <option value="<?php echo sanitize_output($stato); ?>" <?php echo $data['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="protocollo">Protocollo pratica</label>
                                    <input class="form-control" id="protocollo" name="protocollo" value="<?php echo sanitize_output($data['protocollo']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="assigned_to">Operatore assegnato</label>
                                    <select class="form-select" id="assigned_to" name="assigned_to">
                                        <option value="">Non assegnato</option>
                                        <?php foreach ($operators as $operator): ?>
                                            <?php $label = trim(($operator['nome'] ?? '') . ' ' . ($operator['cognome'] ?? '')) ?: ($operator['username'] ?? ''); ?>
                                            <option value="<?php echo (int) $operator['id']; ?>" <?php echo (string) $data['assigned_to'] === (string) $operator['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="cr-step" data-step="2">
                            <h2 class="h5 mb-3">Dati intestatario veicolo</h2>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="persona_giuridica" name="persona_giuridica" value="1" <?php echo $data['persona_giuridica'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="persona_giuridica">Persona giuridica</label>
                            </div>

                            <div class="row g-3" data-section="persona_fisica">
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_nome">Nome *</label>
                                    <input class="form-control" id="intestatario_nome" name="intestatario_nome" value="<?php echo sanitize_output($data['intestatario_nome']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_cognome">Cognome *</label>
                                    <input class="form-control" id="intestatario_cognome" name="intestatario_cognome" value="<?php echo sanitize_output($data['intestatario_cognome']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_codice_fiscale">Codice fiscale *</label>
                                    <input class="form-control" id="intestatario_codice_fiscale" name="intestatario_codice_fiscale" value="<?php echo sanitize_output($data['intestatario_codice_fiscale']); ?>" maxlength="16" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_data_nascita">Data di nascita</label>
                                    <input class="form-control" id="intestatario_data_nascita" name="intestatario_data_nascita" type="date" value="<?php echo sanitize_output($data['intestatario_data_nascita']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_luogo_nascita">Luogo di nascita</label>
                                    <input class="form-control" id="intestatario_luogo_nascita" name="intestatario_luogo_nascita" value="<?php echo sanitize_output($data['intestatario_luogo_nascita']); ?>">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="intestatario_residenza">Residenza</label>
                                    <input class="form-control" id="intestatario_residenza" name="intestatario_residenza" value="<?php echo sanitize_output($data['intestatario_residenza']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_email">Email</label>
                                    <input class="form-control" id="intestatario_email" name="intestatario_email" type="email" value="<?php echo sanitize_output($data['intestatario_email']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_telefono">Telefono</label>
                                    <input class="form-control" id="intestatario_telefono" name="intestatario_telefono" value="<?php echo sanitize_output($data['intestatario_telefono']); ?>">
                                </div>
                            </div>

                            <div class="row g-3" data-section="persona_giuridica_fields">
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_ragione_sociale">Ragione sociale *</label>
                                    <input class="form-control" id="intestatario_ragione_sociale" name="intestatario_ragione_sociale" value="<?php echo sanitize_output($data['intestatario_ragione_sociale']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_partita_iva">Partita IVA *</label>
                                    <input class="form-control" id="intestatario_partita_iva" name="intestatario_partita_iva" value="<?php echo sanitize_output($data['intestatario_partita_iva']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="intestatario_codice_fiscale_giuridico">Codice fiscale</label>
                                    <input class="form-control" id="intestatario_codice_fiscale_giuridico" name="intestatario_codice_fiscale_giuridico" value="<?php echo sanitize_output($data['intestatario_codice_fiscale_giuridico']); ?>">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="intestatario_sede_legale">Sede legale *</label>
                                    <input class="form-control" id="intestatario_sede_legale" name="intestatario_sede_legale" value="<?php echo sanitize_output($data['intestatario_sede_legale']); ?>" required>
                                </div>
                            </div>
                        </section>

                        <section class="cr-step" data-step="3">
                            <h2 class="h5 mb-3">Dati veicolo</h2>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="targa">Targa *</label>
                                    <input class="form-control" id="targa" name="targa" value="<?php echo sanitize_output($data['targa']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="veicolo_tipo">Tipo veicolo</label>
                                    <select class="form-select" id="veicolo_tipo" name="veicolo_tipo">
                                        <?php foreach (['Auto','Moto','Autocarro','Altro'] as $tipoVeicolo): ?>
                                            <option value="<?php echo sanitize_output($tipoVeicolo); ?>" <?php echo $data['veicolo_tipo'] === $tipoVeicolo ? 'selected' : ''; ?>><?php echo sanitize_output($tipoVeicolo); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="telaio">Numero telaio</label>
                                    <input class="form-control" id="telaio" name="telaio" value="<?php echo sanitize_output($data['telaio']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="veicolo_marca">Marca</label>
                                    <select class="form-select" id="veicolo_marca" name="veicolo_marca" data-initial-value="<?php echo sanitize_output($data['veicolo_marca']); ?>">
                                        <option value="">Seleziona marca</option>
                                        <?php if ($data['veicolo_marca'] !== ''): ?>
                                            <option value="<?php echo sanitize_output($data['veicolo_marca']); ?>" selected><?php echo sanitize_output($data['veicolo_marca']); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="veicolo_modello">Modello</label>
                                    <select class="form-select" id="veicolo_modello" name="veicolo_modello" data-initial-value="<?php echo sanitize_output($data['veicolo_modello']); ?>" disabled>
                                        <option value="">Seleziona modello</option>
                                        <?php if ($data['veicolo_modello'] !== ''): ?>
                                            <option value="<?php echo sanitize_output($data['veicolo_modello']); ?>" selected><?php echo sanitize_output($data['veicolo_modello']); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="veicolo_anno_immatricolazione">Anno immatricolazione</label>
                                    <input class="form-control" id="veicolo_anno_immatricolazione" name="veicolo_anno_immatricolazione" value="<?php echo sanitize_output($data['veicolo_anno_immatricolazione']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="veicolo_alimentazione">Alimentazione</label>
                                    <input class="form-control" id="veicolo_alimentazione" name="veicolo_alimentazione" value="<?php echo sanitize_output($data['veicolo_alimentazione']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="veicolo_potenza_kw">Potenza (kW)</label>
                                    <input class="form-control" id="veicolo_potenza_kw" name="veicolo_potenza_kw" value="<?php echo sanitize_output($data['veicolo_potenza_kw']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="veicolo_classe_ambientale">Classe ambientale</label>
                                    <input class="form-control" id="veicolo_classe_ambientale" name="veicolo_classe_ambientale" value="<?php echo sanitize_output($data['veicolo_classe_ambientale']); ?>">
                                </div>
                            </div>
                        </section>

                        <section class="cr-step" data-step="4">
                            <h2 class="h5 mb-3">Dati venditore / acquirente</h2>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="venditore_acquirente_coincidono" name="venditore_acquirente_coincidono" value="1" <?php echo $data['venditore_acquirente_coincidono'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="venditore_acquirente_coincidono">Venditore e acquirente coincidono</label>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="venditore_nome">Venditore</label>
                                    <input class="form-control" id="venditore_nome" name="venditore_nome" value="<?php echo sanitize_output($data['venditore_nome']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="venditore_codice_fiscale">Codice fiscale / P.IVA</label>
                                    <input class="form-control" id="venditore_codice_fiscale" name="venditore_codice_fiscale" value="<?php echo sanitize_output($data['venditore_codice_fiscale']); ?>">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="venditore_indirizzo">Indirizzo venditore</label>
                                    <input class="form-control" id="venditore_indirizzo" name="venditore_indirizzo" value="<?php echo sanitize_output($data['venditore_indirizzo']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="acquirente_nome">Acquirente</label>
                                    <input class="form-control" id="acquirente_nome" name="acquirente_nome" value="<?php echo sanitize_output($data['acquirente_nome']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="acquirente_codice_fiscale">Codice fiscale / P.IVA</label>
                                    <input class="form-control" id="acquirente_codice_fiscale" name="acquirente_codice_fiscale" value="<?php echo sanitize_output($data['acquirente_codice_fiscale']); ?>">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="acquirente_indirizzo">Residenza / Sede legale</label>
                                    <input class="form-control" id="acquirente_indirizzo" name="acquirente_indirizzo" value="<?php echo sanitize_output($data['acquirente_indirizzo']); ?>">
                                </div>
                            </div>
                        </section>

                        <section class="cr-step" data-step="5">
                            <h2 class="h5 mb-3">Documenti da allegare</h2>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Documento identità intestatario</label>
                                    <input class="form-control" type="file" name="documento_identita" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tessera sanitaria</label>
                                    <input class="form-control" type="file" name="tessera_sanitaria" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Carta di circolazione</label>
                                    <input class="form-control" type="file" name="carta_circolazione" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Certificato di proprietà (CDP)</label>
                                    <input class="form-control" type="file" name="certificato_proprieta" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Atto di vendita firmato</label>
                                    <input class="form-control" type="file" name="atto_vendita" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Delega firmata</label>
                                    <input class="form-control" type="file" name="delega" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Visura PRA</label>
                                    <input class="form-control" type="file" name="visura_pra" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                                <div class="col-md-12">
                                    <small class="text-muted">Formati: PDF, JPG, PNG · Max 12MB</small>
                                </div>
                            </div>
                        </section>

                        <section class="cr-step" data-step="6">
                            <h2 class="h5 mb-3">Costi e pagamenti</h2>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label" for="diritti_aci">Diritti ACI</label>
                                    <input class="form-control cost-field" id="diritti_aci" name="diritti_aci" value="<?php echo sanitize_output($data['diritti_aci']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="imposta_bollo">Imposta di bollo</label>
                                    <input class="form-control cost-field" id="imposta_bollo" name="imposta_bollo" value="<?php echo sanitize_output($data['imposta_bollo']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="emolumenti">Emolumenti</label>
                                    <input class="form-control cost-field" id="emolumenti" name="emolumenti" value="<?php echo sanitize_output($data['emolumenti']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="compenso_agenzia">Compenso agenzia</label>
                                    <input class="form-control cost-field" id="compenso_agenzia" name="compenso_agenzia" value="<?php echo sanitize_output($data['compenso_agenzia']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="totale">Totale</label>
                                    <input class="form-control" id="totale" name="totale" value="<?php echo sanitize_output($data['totale']); ?>" readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="metodo_pagamento">Metodo pagamento</label>
                                    <select class="form-select" id="metodo_pagamento" name="metodo_pagamento">
                                        <?php foreach (['Contanti','POS','Bonifico','Altro'] as $metodo): ?>
                                            <option value="<?php echo sanitize_output($metodo); ?>" <?php echo $data['metodo_pagamento'] === $metodo ? 'selected' : ''; ?>><?php echo sanitize_output($metodo); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="cr-step" data-step="7">
                            <h2 class="h5 mb-3">Consensi e dichiarazioni</h2>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="consenso_privacy" id="consenso_privacy" value="1" <?php echo $data['consenso_privacy'] ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="consenso_privacy">Consenso trattamento dati (GDPR)</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="consenso_aci" id="consenso_aci" value="1" <?php echo $data['consenso_aci'] ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="consenso_aci">Autorizzazione gestione pratica ACI</label>
                            </div>
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="consenso_veridicita" id="consenso_veridicita" value="1" <?php echo $data['consenso_veridicita'] ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="consenso_veridicita">Dichiarazione veridicità dati</label>
                            </div>
                            <div class="d-flex flex-wrap gap-3">
                                <a class="link-warning" href="privacy.php" target="_blank" rel="noopener">Privacy Policy</a>
                                <a class="link-warning" href="termini.php" target="_blank" rel="noopener">Termini del servizio</a>
                            </div>
                        </section>

                        <section class="cr-step" data-step="8">
                            <h2 class="h5 mb-3">Riepilogo e invio</h2>
                            <div class="row g-3" id="aci-summary"></div>
                            <div class="mt-3">
                                <label class="form-label" for="note_interne">Note interne</label>
                                <textarea class="form-control" id="note_interne" name="note_interne" rows="3"><?php echo sanitize_output($data['note_interne']); ?></textarea>
                            </div>
                        </section>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <button class="btn btn-outline-secondary" type="button" data-step-prev>Indietro</button>
                            <div class="d-flex gap-2">
                                <button class="btn btn-warning" type="button" data-step-next>Avanti</button>
                                <button class="btn btn-warning text-dark" type="submit" data-step-submit>Salva modifiche</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card ag-card mt-4">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Allegati</h2>
                    <span class="badge ag-badge"><?php echo count($attachments); ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$attachments): ?>
                        <p class="text-muted mb-0">Nessun allegato disponibile.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($attachments as $attachment): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold"><?php echo sanitize_output($categorieLabels[$attachment['categoria'] ?? 'generico'] ?? 'Allegato'); ?></div>
                                        <small class="text-muted d-block"><?php echo sanitize_output($attachment['file_name'] ?? ''); ?></small>
                                    </div>
                                    <a class="btn btn-sm btn-outline-primary" href="download.php?id=<?php echo (int) $attachment['id']; ?>"><i class="fa-solid fa-download me-1"></i>Scarica</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <style>
    .cr-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
    .cr-step-indicator { background: rgba(255,255,255,0.05); border-radius: 8px; padding: 10px 12px; font-size: 0.85rem; text-align: center; color: #cbd5e1; }
    .cr-step-indicator.is-active { background: rgba(255,193,7,0.15); color: #facc15; font-weight: 600; }
    .cr-step { display: none; }
    .cr-step.is-active { display: block; }
    </style>

    <?php
    $vehicleDatasetUrl = asset('assets/data/vehicle-catalog.json');
    ?>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const steps = Array.from(document.querySelectorAll('.cr-step'));
        const indicators = Array.from(document.querySelectorAll('.cr-step-indicator'));
        const nextBtn = document.querySelector('[data-step-next]');
        const prevBtn = document.querySelector('[data-step-prev]');
        const submitBtn = document.querySelector('[data-step-submit]');
        const tipoSelect = document.getElementById('tipo_pratica');
        const tipoAltro = document.querySelector('[data-section="tipo_altro"]');
        const personaGiuridica = document.getElementById('persona_giuridica');
        const venditoreCoincide = document.getElementById('venditore_acquirente_coincidono');
        const summary = document.getElementById('aci-summary');
        const pricingMap = <?php echo $aciPricingJson !== false ? $aciPricingJson : '{}'; ?>;
        const vehicleDatasetUrl = '<?php echo sanitize_output($vehicleDatasetUrl); ?>';
        const marcaSelect = document.getElementById('veicolo_marca');
        const modelloSelect = document.getElementById('veicolo_modello');
        const alimentazioneField = document.getElementById('veicolo_alimentazione');
        const potenzaField = document.getElementById('veicolo_potenza_kw');
        const classeField = document.getElementById('veicolo_classe_ambientale');

        let currentStep = 1;

        const setStep = (step) => {
            currentStep = Math.min(Math.max(step, 1), steps.length);
            steps.forEach((panel) => panel.classList.toggle('is-active', Number(panel.dataset.step) === currentStep));
            indicators.forEach((indicator) => indicator.classList.toggle('is-active', Number(indicator.dataset.stepIndicator) === currentStep));
            if (prevBtn) prevBtn.disabled = currentStep === 1;
            if (nextBtn) nextBtn.style.display = currentStep === steps.length ? 'none' : 'inline-flex';
            if (submitBtn) submitBtn.style.display = currentStep === steps.length ? 'inline-flex' : 'none';
            if (currentStep === steps.length) renderSummary();
        };

        const setRequired = (container, required) => {
            container.querySelectorAll('input, select, textarea').forEach((field) => {
                if (!field.dataset.originalRequired) {
                    field.dataset.originalRequired = field.hasAttribute('required') ? '1' : '0';
                }
                if (field.dataset.originalRequired !== '1') return;
                if (required) {
                    field.setAttribute('required', 'required');
                } else {
                    field.removeAttribute('required');
                    field.classList.remove('is-invalid');
                }
            });
        };

        const toggleSections = () => {
            if (tipoAltro && tipoSelect) {
                const isAltro = tipoSelect.value === 'Altro';
                tipoAltro.style.display = isAltro ? '' : 'none';
                setRequired(tipoAltro, isAltro);
            }
            const isGiuridica = personaGiuridica && personaGiuridica.checked;
            document.querySelectorAll('[data-section="persona_fisica"]').forEach((el) => {
                el.style.display = isGiuridica ? 'none' : '';
                setRequired(el, !isGiuridica);
            });
            document.querySelectorAll('[data-section="persona_giuridica_fields"]').forEach((el) => {
                el.style.display = isGiuridica ? '' : 'none';
                setRequired(el, isGiuridica);
            });
        };

        const validateStep = () => {
            const activeStep = steps.find((panel) => Number(panel.dataset.step) === currentStep);
            if (!activeStep) return true;
            const requiredFields = Array.from(activeStep.querySelectorAll('[required]'));
            let valid = true;
            requiredFields.forEach((field) => {
                if (field.type === 'checkbox') {
                    field.classList.toggle('is-invalid', !field.checked);
                    if (!field.checked) valid = false;
                    return;
                }
                if (field.type === 'radio') {
                    const group = activeStep.querySelectorAll('input[name="' + field.name + '"]');
                    const checked = Array.from(group).some((input) => input.checked);
                    group.forEach((input) => input.classList.toggle('is-invalid', !checked));
                    if (!checked) valid = false;
                    return;
                }
                const hasValue = field.value && field.value.trim() !== '';
                field.classList.toggle('is-invalid', !hasValue);
                if (!hasValue) valid = false;
            });
            return valid;
        };

        const formatCurrency = (value) => {
            const number = Number(value || 0);
            if (Number.isNaN(number)) return '0.00';
            return number.toFixed(2);
        };

        const updateTotal = () => {
            const fields = document.querySelectorAll('.cost-field');
            let total = 0;
            fields.forEach((field) => {
                const value = parseFloat(String(field.value).replace(',', '.'));
                if (!Number.isNaN(value)) total += value;
            });
            const totalField = document.getElementById('totale');
            if (totalField) totalField.value = formatCurrency(total);
        };

        const renderSummary = () => {
            if (!summary) return;
            const formData = new FormData(document.getElementById('aci-wizard'));
            const blocks = [];
            const addBlock = (title, rows) => {
                blocks.push(`
                    <div class="col-md-6">
                        <div class="card ag-card h-100">
                            <div class="card-body">
                                <div class="fw-semibold mb-2">${title}</div>
                                ${rows.map(row => `<div class="text-muted small">${row}</div>`).join('')}
                            </div>
                        </div>
                    </div>
                `);
            };
            addBlock('Pratica', [
                `Tipo: ${formData.get('tipo_pratica') || '—'}`,
                `Stato: ${formData.get('stato') || '—'}`,
            ]);
            addBlock('Intestatario', [
                formData.get('persona_giuridica') ? `Ragione sociale: ${formData.get('intestatario_ragione_sociale') || '—'}` : `Nome: ${formData.get('intestatario_nome') || '—'}`,
                formData.get('persona_giuridica') ? `P.IVA: ${formData.get('intestatario_partita_iva') || '—'}` : `Cognome: ${formData.get('intestatario_cognome') || '—'}`,
            ]);
            addBlock('Veicolo', [
                `Targa: ${formData.get('targa') || '—'}`,
                `Tipo: ${formData.get('veicolo_tipo') || '—'}`,
                `Marca/Modello: ${(formData.get('veicolo_marca') || '') + ' ' + (formData.get('veicolo_modello') || '')}`.trim(),
            ]);
            addBlock('Costi', [
                `Totale: ${formData.get('totale') || '0.00'}`,
                `Metodo pagamento: ${formData.get('metodo_pagamento') || '—'}`,
            ]);
            summary.innerHTML = blocks.join('');
        };

        if (nextBtn) nextBtn.addEventListener('click', () => { if (validateStep()) setStep(currentStep + 1); });
        if (prevBtn) prevBtn.addEventListener('click', () => setStep(currentStep - 1));

        if (tipoSelect) tipoSelect.addEventListener('change', () => {
            if (pricingMap && pricingMap[tipoSelect.value] !== undefined) {
                const campo = document.getElementById('compenso_agenzia');
                if (campo) campo.value = formatCurrency(pricingMap[tipoSelect.value]);
                updateTotal();
            }
            toggleSections();
        });

        if (personaGiuridica) personaGiuridica.addEventListener('change', toggleSections);

        if (venditoreCoincide) {
            venditoreCoincide.addEventListener('change', () => {
                if (!venditoreCoincide.checked) return;
                const venditoreNome = document.getElementById('venditore_nome');
                const venditoreCf = document.getElementById('venditore_codice_fiscale');
                const venditoreIndirizzo = document.getElementById('venditore_indirizzo');
                const acqNome = document.getElementById('acquirente_nome');
                const acqCf = document.getElementById('acquirente_codice_fiscale');
                const acqIndirizzo = document.getElementById('acquirente_indirizzo');
                if (venditoreNome && acqNome) venditoreNome.value = acqNome.value;
                if (venditoreCf && acqCf) venditoreCf.value = acqCf.value;
                if (venditoreIndirizzo && acqIndirizzo) venditoreIndirizzo.value = acqIndirizzo.value;
            });
        }

        const markManualInput = (field) => {
            if (!field) return;
            field.addEventListener('input', () => {
                field.dataset.autofill = '0';
            });
        };

        [alimentazioneField, potenzaField, classeField].forEach(markManualInput);

        const normalizeLabel = (value) => String(value || '').trim();
        const sortByLabel = (a, b) => a.localeCompare(b, 'it', { sensitivity: 'base' });

        const getModelBaseName = (model) => normalizeLabel(model && (model.name || model.model) ? (model.name || model.model) : '');
        const getModelFuel = (model) => normalizeLabel(model && model.fuel ? model.fuel : '');
        const getModelLabel = (model) => {
            const base = getModelBaseName(model);
            if (!base) return '';
            const fuel = getModelFuel(model);
            if (!fuel) return base;
            const baseLower = base.toLowerCase();
            const fuelLower = fuel.toLowerCase();
            if (baseLower.includes(fuelLower)) return base;
            return `${base} - ${fuel}`;
        };

        const resolveSelectedModel = (models, selectedValue) => {
            const normalized = normalizeLabel(selectedValue);
            if (!normalized) return '';
            const exact = (models || []).find((model) => normalizeLabel(getModelLabel(model)) === normalized);
            if (exact) return getModelLabel(exact);
            const baseMatch = (models || []).find((model) => normalizeLabel(getModelBaseName(model)) === normalized);
            return baseMatch ? getModelLabel(baseMatch) : normalized;
        };

        const setOptions = (select, options, selected) => {
            if (!select) return;
            const placeholder = select.querySelector('option[value=""]');
            select.innerHTML = '';
            if (placeholder) {
                select.appendChild(placeholder);
            } else {
                const empty = document.createElement('option');
                empty.value = '';
                empty.textContent = select === marcaSelect ? 'Seleziona marca' : 'Seleziona modello';
                select.appendChild(empty);
            }
            options.forEach((optionValue) => {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionValue;
                if (selected && optionValue === selected) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        };

        const applyVehicleDetails = (details) => {
            if (!details) return;
            if (alimentazioneField && alimentazioneField.dataset.autofill !== '0') {
                alimentazioneField.value = details.fuel ? String(details.fuel) : '';
                alimentazioneField.dataset.autofill = '1';
            }
            if (potenzaField && potenzaField.dataset.autofill !== '0') {
                potenzaField.value = details.power_kw !== undefined && details.power_kw !== null ? String(details.power_kw) : '';
                potenzaField.dataset.autofill = '1';
            }
            if (classeField && classeField.dataset.autofill !== '0') {
                classeField.value = details.emission_class ? String(details.emission_class) : '';
                classeField.dataset.autofill = '1';
            }
        };

        const clearVehicleDetails = () => {
            [alimentazioneField, potenzaField, classeField].forEach((field) => {
                if (!field) return;
                if (field.dataset.autofill === '0') return;
                field.value = '';
                field.dataset.autofill = '1';
            });
        };

        const initVehicleSelectors = (catalog) => {
            if (!marcaSelect || !modelloSelect) return;
            const initialBrand = normalizeLabel(marcaSelect.dataset.initialValue || marcaSelect.value);
            const initialModel = normalizeLabel(modelloSelect.dataset.initialValue || modelloSelect.value);

            const brandMap = new Map();
            (catalog || []).forEach((entry) => {
                if (!entry || !entry.brand) return;
                const brand = normalizeLabel(entry.brand);
                if (!brand) return;
                const models = Array.isArray(entry.models) ? entry.models : [];
                brandMap.set(brand, models);
            });

            const brandList = Array.from(brandMap.keys()).sort(sortByLabel);
            if (initialBrand && !brandMap.has(initialBrand)) {
                brandList.unshift(initialBrand);
            }

            setOptions(marcaSelect, brandList, initialBrand || '');

            const populateModels = (brandValue, selectedModel) => {
                const models = brandMap.get(brandValue) || [];
                const modelNames = models
                    .map((model) => getModelLabel(model))
                    .filter(Boolean)
                    .sort(sortByLabel);
                const resolvedSelected = resolveSelectedModel(models, selectedModel);
                if (resolvedSelected && !modelNames.includes(resolvedSelected)) {
                    modelNames.unshift(resolvedSelected);
                }
                setOptions(modelloSelect, modelNames, resolvedSelected || '');
                modelloSelect.disabled = modelNames.length === 0;
            };

            const findDetails = (brandValue, modelValue) => {
                const models = brandMap.get(brandValue) || [];
                return models.find((model) => normalizeLabel(getModelLabel(model)) === modelValue)
                    || models.find((model) => normalizeLabel(getModelBaseName(model)) === modelValue)
                    || null;
            };

            populateModels(initialBrand, initialModel);

            if (initialBrand && initialModel) {
                applyVehicleDetails(findDetails(initialBrand, initialModel));
            }

            marcaSelect.addEventListener('change', () => {
                const brandValue = normalizeLabel(marcaSelect.value);
                populateModels(brandValue, '');
                clearVehicleDetails();
            });

            modelloSelect.addEventListener('change', () => {
                const brandValue = normalizeLabel(marcaSelect.value);
                const modelValue = normalizeLabel(modelloSelect.value);
                applyVehicleDetails(findDetails(brandValue, modelValue));
            });
        };

        if (vehicleDatasetUrl) {
            fetch(vehicleDatasetUrl)
                .then((response) => (response.ok ? response.json() : []))
                .then((data) => initVehicleSelectors(data))
                .catch(() => initVehicleSelectors([]));
        }

        document.querySelectorAll('.cost-field').forEach((field) => {
            field.addEventListener('input', updateTotal);
        });

        toggleSections();
        updateTotal();
        setStep(1);
    });
    </script>

    <?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
