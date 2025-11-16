<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

use App\Services\CAFPatronato\PracticesService;
use PDO;
use RuntimeException;
use Throwable;

require_role('Admin', 'Operatore', 'Manager', 'Patronato');

$currentRole = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';
$isPatronatoOperator = strcasecmp($currentRole, 'Patronato') === 0;

$pageTitle = 'Nuova pratica CAF & Patronato';
$csrfToken = csrf_token();

$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$typeOptions = caf_patronato_type_options();
if (!$typeOptions) {
    $typeOptions = ['CAF' => 'CAF'];
}
$defaultTypeKey = array_key_first($typeOptions) ?? 'CAF';

$statusOptions = caf_patronato_status_options();
$defaultStatusKey = array_key_first($statusOptions) ?? 'Da lavorare';
$priorityOptions = caf_patronato_priority_options();
$serviceOptions = caf_patronato_service_options($defaultTypeKey);
$serviceOptionMap = caf_patronato_service_config();
$serviceOptionMapForJs = [];
foreach ($serviceOptionMap as $mapType => $mapValues) {
    if (!is_string($mapType)) {
        continue;
    }
    $typeKey = strtoupper(trim($mapType));
    if ($typeKey === '') {
        continue;
    }
    if (!is_array($mapValues)) {
        continue;
    }
    $clean = [];
    foreach ($mapValues as $mapValue) {
        if (!is_array($mapValue)) {
            continue;
        }
        $name = trim((string) ($mapValue['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $hash = mb_strtolower($name, 'UTF-8');
        if (isset($clean[$hash])) {
            continue;
        }
        $price = $mapValue['price'] ?? null;
        if ($price !== null && !is_numeric($price)) {
            $price = null;
        }
        $clean[$hash] = [
            'name' => $name,
            'price' => $price !== null ? round((float) $price, 2) : null,
        ];
    }
    $serviceOptionMapForJs[$typeKey] = array_values($clean);
}

$data = [
    'tipo_pratica' => $defaultTypeKey,
    'servizio' => '',
    'nominativo' => '',
    'codice_fiscale' => '',
    'telefono' => '',
    'email' => '',
    'cliente_id' => '',
    'stato' => $defaultStatusKey,
    'priorita' => '0',
    'scadenza_at' => '',
    'note_interne' => '',
    'send_notification' => '1',
];

$errors = [];
$processedUploads = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    foreach ($data as $field => $_) {
        $value = $_POST[$field] ?? '';
        if (is_string($value)) {
            $data[$field] = trim($value);
        }
    }

    $selectedType = strtoupper($data['tipo_pratica']);
    if (!array_key_exists($selectedType, $typeOptions)) {
        $selectedType = $defaultTypeKey;
    }
    $data['tipo_pratica'] = $selectedType;
    $data['nominativo'] = trim($data['nominativo']);
    $data['codice_fiscale'] = strtoupper($data['codice_fiscale']);
    $data['telefono'] = trim($data['telefono']);
    $data['email'] = trim($data['email']);

    if ($data['nominativo'] === '') {
        $errors[] = 'Inserisci il nominativo del cliente o assistito.';
    }

    if ($data['codice_fiscale'] !== '' && !preg_match('/^[A-Z0-9]{11,16}$/', $data['codice_fiscale'])) {
        $errors[] = 'Il codice fiscale deve contenere 11 o 16 caratteri alfanumerici.';
    }

    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo email non valido.';
    }

    $clienteId = (int) $data['cliente_id'];
    if ($clienteId > 0) {
        $clienteStmt = $pdo->prepare('SELECT id FROM clienti WHERE id = :id LIMIT 1');
        $clienteStmt->execute([':id' => $clienteId]);
        if (!$clienteStmt->fetch()) {
            $errors[] = 'Cliente selezionato non valido.';
        }
    } else {
        $data['cliente_id'] = '';
    }

    if (!array_key_exists($data['stato'], $statusOptions)) {
        $errors[] = 'Stato pratica non valido.';
        $data['stato'] = $defaultStatusKey;
    }

    if (!array_key_exists((int) $data['priorita'], $priorityOptions)) {
        $errors[] = 'Priorità selezionata non valida.';
        $data['priorita'] = '0';
    }

    $scadenzaDate = null;
    if ($data['scadenza_at'] !== '') {
        $scadenzaDate = DateTimeImmutable::createFromFormat('Y-m-d', $data['scadenza_at']) ?: DateTimeImmutable::createFromFormat('d/m/Y', $data['scadenza_at']);
        if (!$scadenzaDate) {
            $errors[] = 'Inserisci una data di scadenza valida (formato YYYY-MM-DD).';
        }
    }

    $uploads = caf_patronato_normalize_uploads($_FILES['allegati'] ?? null);
    if ($uploads) {
        $allowedTypes = caf_patronato_allowed_mime_types();
        foreach ($uploads as $upload) {
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Errore durante il caricamento di uno degli allegati.';
                continue;
            }

            if ($upload['size'] <= 0 || $upload['size'] > CAF_PATRONATO_MAX_UPLOAD_SIZE) {
                $errors[] = 'Ogni allegato deve essere inferiore a ' . number_format(CAF_PATRONATO_MAX_UPLOAD_SIZE / 1_048_576, 0) . ' MB.';
                continue;
            }

            $mime = caf_patronato_detect_mime($upload['tmp_name']);
            if (!array_key_exists($mime, $allowedTypes)) {
                $errors[] = 'Formato allegato non supportato (' . htmlspecialchars($upload['name'], ENT_QUOTES, 'UTF-8') . '). Consentiti: PDF, JPG, PNG.';
                continue;
            }

            $processedUploads[] = [
                'name' => $upload['name'],
                'tmp_name' => $upload['tmp_name'],
                'size' => $upload['size'],
                'mime' => $mime,
            ];
        }
    }

    if (!$errors) {
        $assignedCode = null;
        $legacyAttachments = [];
        try {
            try {
                $temporaryCode = 'TMP-' . strtoupper(bin2hex(random_bytes(6)));
            } catch (Throwable) {
                $temporaryCode = 'TMP-' . strtoupper(str_replace('.', '', uniqid('', true)));
            }

            if (strlen($temporaryCode) > 40) {
                $temporaryCode = substr($temporaryCode, 0, 40);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO caf_patronato_pratiche (
                    pratica_code,
                    tipo_pratica,
                    servizio,
                    nominativo,
                    codice_fiscale,
                    telefono,
                    email,
                    cliente_id,
                    stato,
                    priorita,
                    scadenza_at,
                    note_interne,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                ) VALUES (
                    :pratica_code,
                    :tipo_pratica,
                    :servizio,
                    :nominativo,
                    :codice_fiscale,
                    :telefono,
                    :email,
                    :cliente_id,
                    :stato,
                    :priorita,
                    :scadenza_at,
                    :note_interne,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                )');

            $stmt->execute([
                ':pratica_code' => $temporaryCode,
                ':tipo_pratica' => $data['tipo_pratica'],
                ':servizio' => $data['servizio'] !== '' ? $data['servizio'] : null,
                ':nominativo' => $data['nominativo'],
                ':codice_fiscale' => $data['codice_fiscale'] !== '' ? $data['codice_fiscale'] : null,
                ':telefono' => $data['telefono'] !== '' ? $data['telefono'] : null,
                ':email' => $data['email'] !== '' ? $data['email'] : null,
                ':cliente_id' => $clienteId > 0 ? $clienteId : null,
                ':stato' => $data['stato'],
                ':priorita' => (int) $data['priorita'],
                ':scadenza_at' => $scadenzaDate ? $scadenzaDate->format('Y-m-d') : null,
                ':note_interne' => $data['note_interne'] !== '' ? $data['note_interne'] : null,
                ':created_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
                ':updated_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]);

            $praticaId = (int) $pdo->lastInsertId();

            if ($praticaId <= 0) {
                throw new RuntimeException('Impossibile determinare l\'ID della pratica.');
            }

            $assignedCode = caf_patronato_build_code($praticaId, $data['tipo_pratica'], date('Y-m-d H:i:s'));

            $codeStmt = $pdo->prepare('UPDATE caf_patronato_pratiche SET pratica_code = :code WHERE id = :id');
            $codeStmt->execute([
                ':code' => $assignedCode,
                ':id' => $praticaId,
            ]);

            if ($processedUploads) {
                $storageDir = public_path(CAF_PATRONATO_UPLOAD_DIR . '/' . $praticaId);
                if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                    throw new RuntimeException('Impossibile creare la cartella per gli allegati.');
                }

                caf_patronato_get_encryption_key();

                $attachmentStmt = $pdo->prepare('INSERT INTO caf_patronato_allegati (
                        pratica_id,
                        file_name,
                        file_path,
                        mime_type,
                        file_size,
                        created_by,
                        created_at
                    ) VALUES (
                        :pratica_id,
                        :file_name,
                        :file_path,
                        :mime_type,
                        :file_size,
                        :created_by,
                        NOW()
                    )');

                foreach ($processedUploads as $upload) {
                    $original = sanitize_filename($upload['name']);
                    $displayName = $original;
                    if ($isPatronatoOperator && $upload['mime'] === 'application/pdf') {
                        $standardName = caf_patronato_generate_standard_filename($data['servizio'], $data['nominativo']);
                        if ($standardName !== null) {
                            $displayName = $standardName;
                        }
                    }

                    $baseName = sprintf('%s_%s_%s', $praticaId, date('YmdHis'), bin2hex(random_bytes(4)) . '_' . $original);
                    $encryptedName = $baseName . CAF_PATRONATO_ENCRYPTION_SUFFIX;
                    $destination = $storageDir . DIRECTORY_SEPARATOR . $encryptedName;
                    caf_patronato_encrypt_uploaded_file($upload['tmp_name'], $destination);

                    $relativePath = CAF_PATRONATO_UPLOAD_DIR . '/' . $praticaId . '/' . $encryptedName;

                    $attachmentStmt->execute([
                        ':pratica_id' => $praticaId,
                        ':file_name' => $displayName,
                        ':file_path' => $relativePath,
                        ':mime_type' => $upload['mime'],
                        ':file_size' => $upload['size'],
                        ':created_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
                    ]);

                    $attachmentId = (int) $pdo->lastInsertId();
                    $legacyAttachments[] = [
                        'id' => $attachmentId,
                        'file_name' => $displayName,
                        'file_path' => $relativePath,
                        'mime_type' => $upload['mime'],
                        'file_size' => (int) $upload['size'],
                        'download_url' => caf_patronato_build_download_url('document', $attachmentId),
                    ];
                }
            }

            $legacyPayload = [
                'tipo_pratica' => $data['tipo_pratica'],
                'servizio' => $data['servizio'],
                'nominativo' => $data['nominativo'],
                'stato' => $data['stato'],
                'note_interne' => $data['note_interne'],
                'telefono' => $data['telefono'],
                'email' => $data['email'],
                'codice_fiscale' => $data['codice_fiscale'],
                'cliente_id' => $clienteId > 0 ? $clienteId : null,
                'scadenza' => $scadenzaDate ? $scadenzaDate->format('Y-m-d') : null,
            ];

            $creatorUserId = (int) ($_SESSION['user_id'] ?? 0);
            $legacyPracticeId = caf_patronato_sync_legacy_pratica(
                $pdo,
                $legacyPayload,
                $praticaId,
                $assignedCode,
                $legacyAttachments,
                $creatorUserId
            );

            $pdo->commit();

            $pratica = caf_patronato_fetch_pratica($pdo, $praticaId);
            if ($pratica && isset($pratica['pratica_code'])) {
                $assignedCode = (string) $pratica['pratica_code'];
            }
            caf_patronato_log_action($pdo, 'Pratica creata', 'Pratica #' . $praticaId . ' creata: ' . $data['nominativo']);

            $notificationSent = false;
            $customerMailSent = false;
            if ($data['send_notification'] === '1' && $pratica) {
                $notificationSent = caf_patronato_send_notification($pratica, false);
            }

            if (!empty($legacyPracticeId) && $data['email'] !== '') {
                try {
                    $service = new PracticesService($pdo, project_root_path());
                    $customerMailSent = $service->sendCustomerConfirmationMail((int) $legacyPracticeId, $creatorUserId, $data['email']);
                } catch (Throwable $exception) {
                    error_log('CAF/Patronato customer confirmation mail failed: ' . $exception->getMessage());
                }
            }

            $message = 'Pratica registrata correttamente.';
            if ($assignedCode) {
                $message .= ' Codice: ' . $assignedCode . '.';
            }
            if ($notificationSent) {
                $message .= ' Notifica email inviata al team.';
            }
            if (!empty($customerMailSent)) {
                $message .= ' Email inviata al cliente.';
            }

            add_flash('success', $message);
            header('Location: index.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('CAF/Patronato create failed: ' . $exception->getMessage());
            $errors[] = 'Si è verificato un errore durante il salvataggio della pratica. Riprova.';
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Nuova pratica CAF &amp; Patronato</h1>
                <p class="text-muted mb-0">Registra una nuova richiesta CAF o Patronato e allega la documentazione necessaria.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-warning" href="index.php">
                    <i class="fa-solid fa-arrow-left me-2"></i>Ritorna all'elenco
                </a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-warning">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize_output($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card ag-card">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-4" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="tipo_pratica">Tipologia</label>
                        <select class="form-select" id="tipo_pratica" name="tipo_pratica">
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $data['tipo_pratica'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="servizio">Servizio richiesto</label>
                        <div class="service-selector position-relative" data-service-selector>
                            <div class="input-group">
                                <input class="form-control" id="servizio" name="servizio" type="search" autocomplete="off" value="<?php echo sanitize_output($data['servizio']); ?>" placeholder="Es. ISEE, NASpI, Pensione" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-haspopup="listbox" aria-owns="cafPatronatoServiceList" aria-controls="cafPatronatoServiceList">
                                <button class="btn btn-outline-secondary" type="button" data-service-toggle aria-label="Mostra servizi disponibili">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="list-group position-absolute w-100 d-none shadow-sm border bg-white" id="cafPatronatoServiceList" data-service-list role="listbox" style="z-index: 1055; max-height: 240px; overflow-y: auto;"></div>
                        </div>
                        <div class="form-text">Elenco alimentato dalle impostazioni &ldquo;Servizi richiesti&rdquo;. Puoi indicare anche valori personalizzati.</div>
                        <div class="d-flex justify-content-between align-items-center small text-muted mt-1">
                            <span>Prezzo consigliato</span>
                            <span class="fw-semibold" data-service-price>—</span>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label" for="nominativo">Nominativo*</label>
                        <input class="form-control" id="nominativo" name="nominativo" value="<?php echo sanitize_output($data['nominativo']); ?>" required>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="codice_fiscale">Codice fiscale</label>
                        <input class="form-control" id="codice_fiscale" name="codice_fiscale" value="<?php echo sanitize_output($data['codice_fiscale']); ?>" maxlength="16" placeholder="RSSMRA80A01H501Z" autocomplete="off" data-client-lookup>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="telefono">Telefono</label>
                        <input class="form-control" id="telefono" name="telefono" value="<?php echo sanitize_output($data['telefono']); ?>" placeholder="Es. 081 1234567">
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="<?php echo sanitize_output($data['email']); ?>" placeholder="esempio@mail.com">
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="cliente_id">Cliente collegato</label>
                        <select class="form-select" id="cliente_id" name="cliente_id">
                            <option value="">Nessun cliente</option>
                            <?php foreach ($clients as $client): ?>
                                <?php
                                    $company = trim((string) ($client['ragione_sociale'] ?? ''));
                                    $person = trim(((string) ($client['cognome'] ?? '')) . ' ' . ((string) ($client['nome'] ?? '')));
                                    $label = $company !== '' && $person !== '' ? $company . ' - ' . $person : ($company !== '' ? $company : $person);
                                    if ($label === '') {
                                        $label = 'Cliente #' . (int) $client['id'];
                                    }
                                ?>
                                <option value="<?php echo (int) $client['id']; ?>" <?php echo (int) $data['cliente_id'] === (int) $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize_output($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="stato">Stato iniziale</label>
                        <select class="form-select" id="stato" name="stato">
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $data['stato'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="priorita">Priorità</label>
                        <select class="form-select" id="priorita" name="priorita">
                            <?php foreach ($priorityOptions as $value => $label): ?>
                                <option value="<?php echo (int) $value; ?>" <?php echo (int) $data['priorita'] === (int) $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" for="scadenza_at">Scadenza</label>
                        <input class="form-control" id="scadenza_at" type="date" name="scadenza_at" value="<?php echo sanitize_output($data['scadenza_at']); ?>">
                        <small class="text-muted">Lascia vuoto se non necessaria.</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="note_interne">Note interne</label>
                        <textarea class="form-control" id="note_interne" name="note_interne" rows="4" placeholder="Dettagli utili alla lavorazione, documenti da richiedere, promemoria."><?php echo sanitize_output($data['note_interne']); ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="allegati">Allegati</label>
                        <input class="form-control" id="allegati" name="allegati[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png">
                        <small class="text-muted">Formati ammessi: PDF, JPG, PNG. Dimensione massima 12 MB per file.</small>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="send_notification" name="send_notification" value="1" <?php echo $data['send_notification'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="send_notification">Invia notifica email al team CAF/Patronato</label>
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-warning" href="index.php">
                            <i class="fa-solid fa-arrow-rotate-left me-2"></i>Annulla
                        </a>
                        <button class="btn btn-warning text-dark" type="submit">
                            <i class="fa-solid fa-floppy-disk me-2"></i>Registra pratica
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="cafClientLookupModal" tabindex="-1" aria-labelledby="cafClientLookupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="cafClientLookupModalLabel">Cliente già registrato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Abbiamo trovato un cliente con il codice fiscale <span class="fw-semibold" data-client-cf></span>.</p>
                <div class="bg-light rounded p-3 mb-3">
                    <div class="fw-semibold" data-client-name></div>
                    <div class="small text-muted" data-client-email></div>
                    <div class="small text-muted" data-client-phone></div>
                </div>
                <p class="mb-0">Vuoi collegarlo automaticamente a questa pratica?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Chiudi</button>
                <button type="button" class="btn btn-warning text-dark" id="cafClientLookupConfirm">Collega cliente</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const serviceMap = <?php echo json_encode($serviceOptionMapForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const initialServices = <?php echo json_encode(array_values($serviceOptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const typeSelect = document.getElementById('tipo_pratica');
    const input = document.getElementById('servizio');
    const selector = document.querySelector('[data-service-selector]');
    const list = selector ? selector.querySelector('[data-service-list]') : null;
    const toggle = selector ? selector.querySelector('[data-service-toggle]') : null;
    const priceTarget = document.querySelector('[data-service-price]');
    const euroFormatter = (typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function')
        ? new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' })
        : null;

    if (!typeSelect || !input || !selector || !list) {
        return;
    }

    let currentOptions = [];
    let visibleOptions = [];
    let activeIndex = -1;

    const buildServiceNames = function (entries) {
        if (!Array.isArray(entries)) {
            return [];
        }
        const names = [];
        entries.forEach(function (entry) {
            let label = '';
            if (typeof entry === 'string') {
                label = entry;
            } else if (entry && typeof entry === 'object' && typeof entry.name === 'string') {
                label = entry.name;
            }
            const trimmed = label.trim();
            if (trimmed !== '') {
                names.push(trimmed);
            }
        });
        return names;
    };

    const uniqueNormalize = function (values) {
        const unique = [];
        const seen = new Set();
        values.forEach(function (value) {
            if (typeof value !== 'string') {
                return;
            }
            const trimmed = value.trim();
            if (trimmed === '') {
                return;
            }
            const key = trimmed.toUpperCase();
            if (seen.has(key)) {
                return;
            }
            seen.add(key);
            unique.push(trimmed);
        });
        return unique;
    };

    const normalizeServiceName = function (value) {
        if (typeof value !== 'string') {
            return '';
        }
        return value.trim().toLowerCase();
    };

    const resolveServicePrice = function (typeKey, serviceName) {
        const normalizedType = (typeKey || '').toUpperCase();
        const normalizedName = normalizeServiceName(serviceName || '');
        if (!normalizedType || !normalizedName) {
            return null;
        }

        const entries = Array.isArray(serviceMap[normalizedType]) ? serviceMap[normalizedType] : [];
        const hasOwn = Object.prototype.hasOwnProperty;

        for (let index = 0; index < entries.length; index += 1) {
            const entry = entries[index];
            let label = '';
            if (typeof entry === 'string') {
                label = entry;
            } else if (entry && typeof entry === 'object' && typeof entry.name === 'string') {
                label = entry.name;
            }

            if (normalizeServiceName(label) !== normalizedName) {
                continue;
            }

            if (!entry || typeof entry !== 'object' || !hasOwn.call(entry, 'price')) {
                return null;
            }

            const priceValue = entry.price;
            if (priceValue === null || priceValue === '') {
                return null;
            }

            const numericPrice = typeof priceValue === 'number' ? priceValue : parseFloat(String(priceValue));
            if (!isFinite(numericPrice) || numericPrice < 0) {
                return null;
            }

            return numericPrice;
        }

        return null;
    };

    const renderPriceHint = function (price) {
        if (!priceTarget) {
            return;
        }
        if (price === null) {
            priceTarget.textContent = '—';
            priceTarget.classList.add('text-muted');
            return;
        }
        const formatted = euroFormatter ? euroFormatter.format(price) : ('\u20AC ' + price.toFixed(2));
        priceTarget.textContent = formatted;
        priceTarget.classList.remove('text-muted');
    };

    const updatePriceHint = function () {
        if (!priceTarget) {
            return;
        }
        const resolvedPrice = resolveServicePrice(typeSelect.value, input.value);
        renderPriceHint(resolvedPrice);
    };

    const ensureCurrentOptions = function () {
        const typeKey = (typeSelect.value || '').toUpperCase();
        const options = Array.isArray(serviceMap[typeKey]) && serviceMap[typeKey].length > 0
            ? buildServiceNames(serviceMap[typeKey])
            : initialServices;
        currentOptions = uniqueNormalize(options);
    };

    const closeList = function () {
        list.classList.add('d-none');
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    };

    const openList = function () {
        if (visibleOptions.length === 0) {
            closeList();
            return;
        }
        list.classList.remove('d-none');
        input.setAttribute('aria-expanded', 'true');
    };

    const setActiveIndex = function (index) {
        const items = list.querySelectorAll('[data-value]');
        if (!items.length) {
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');
            return;
        }

        if (index < 0) {
            index = items.length - 1;
        } else if (index >= items.length) {
            index = 0;
        }

        items.forEach(function (item, itemIndex) {
            if (itemIndex === index) {
                item.classList.add('active');
                input.setAttribute('aria-activedescendant', item.id);
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('active');
            }
        });

        activeIndex = index;
    };

    const renderList = function (options) {
        list.innerHTML = '';

        options.forEach(function (option, index) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action py-2';
            button.textContent = option;
            button.dataset.value = option;
            button.id = 'cafPatronatoServiceOption' + index;
            button.setAttribute('role', 'option');
            list.appendChild(button);
        });

        if (!options.length) {
            closeList();
        }
    };

    const filterList = function (term) {
        const lookup = term.trim().toLowerCase();
        visibleOptions = lookup === ''
            ? currentOptions.slice(0, 50)
            : currentOptions.filter(function (option) {
                return option.toLowerCase().includes(lookup);
            }).slice(0, 50);
        renderList(visibleOptions);
        if (visibleOptions.length) {
            setActiveIndex(0);
        } else {
            activeIndex = -1;
        }
    };

    const selectOption = function (value) {
        if (typeof value !== 'string') {
            return;
        }
        input.value = value;
        closeList();
        input.focus();
        updatePriceHint();
    };

    input.addEventListener('focus', function () {
        ensureCurrentOptions();
        filterList(input.value);
        if (visibleOptions.length) {
            openList();
        }
        updatePriceHint();
    });

    input.addEventListener('input', function () {
        ensureCurrentOptions();
        filterList(input.value);
        if (visibleOptions.length) {
            openList();
        } else {
            closeList();
        }
        updatePriceHint();
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown') {
            if (list.classList.contains('d-none')) {
                if (visibleOptions.length) {
                    openList();
                    setActiveIndex(0);
                }
            } else if (visibleOptions.length) {
                setActiveIndex(activeIndex + 1);
            }
            event.preventDefault();
        } else if (event.key === 'ArrowUp') {
            if (!list.classList.contains('d-none') && visibleOptions.length) {
                setActiveIndex(activeIndex - 1);
                event.preventDefault();
            }
        } else if (event.key === 'Enter') {
            if (!list.classList.contains('d-none') && activeIndex >= 0 && activeIndex < visibleOptions.length) {
                selectOption(visibleOptions[activeIndex]);
                event.preventDefault();
            }
        } else if (event.key === 'Escape') {
            if (!list.classList.contains('d-none')) {
                closeList();
                event.preventDefault();
            }
        }
    });

    if (toggle) {
        toggle.addEventListener('click', function () {
            ensureCurrentOptions();
            if (list.classList.contains('d-none')) {
                filterList('');
                if (visibleOptions.length) {
                    openList();
                }
            } else {
                closeList();
            }
            input.focus();
        });
    }

    list.addEventListener('mousedown', function (event) {
        const option = event.target.closest('[data-value]');
        if (!option) {
            return;
        }
        event.preventDefault();
        selectOption(option.dataset.value || '');
    });

    document.addEventListener('click', function (event) {
        if (!selector.contains(event.target)) {
            closeList();
        }
    });

    typeSelect.addEventListener('change', function () {
        ensureCurrentOptions();
        filterList(input.value);
        if (visibleOptions.length) {
            openList();
        } else {
            closeList();
        }
        updatePriceHint();
    });

    // initialize with current type
    ensureCurrentOptions();
    filterList(input.value);
    updatePriceHint();
})();


(function () {
    const initClientLookup = function () {
        const cfInput = document.getElementById('codice_fiscale');
        const clienteSelect = document.getElementById('cliente_id');
        const modalElement = document.getElementById('cafClientLookupModal');
        const confirmButton = document.getElementById('cafClientLookupConfirm');

        if (!cfInput || !clienteSelect || !modalElement || !confirmButton) {
            return;
        }

        if (typeof window.fetch !== 'function') {
            return;
        }

        const bootstrapNs = window.bootstrap || window.Bootstrap || (window.bootstrap5 ?? null);
        if (!bootstrapNs || typeof bootstrapNs.Modal !== 'function') {
            console.warn('Bootstrap Modal non disponibile: carica bootstrap.bundle.js per abilitare il collegamento cliente.');
            return;
        }

        const nameTarget = modalElement.querySelector('[data-client-name]');
        const cfTarget = modalElement.querySelector('[data-client-cf]');
        const emailTarget = modalElement.querySelector('[data-client-email]');
        const phoneTarget = modalElement.querySelector('[data-client-phone]');
        let modalInstance = null;
        let pendingClient = null;
        let lastLookupValue = '';
        let lastRequestId = 0;

        const sanitizeCf = function (value) {
            if (typeof value !== 'string') {
                return '';
            }
            return value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        };

        const debounce = function (callback, delay) {
            let timerId = null;
            return function () {
                if (timerId) {
                    window.clearTimeout(timerId);
                }
                const args = arguments;
                timerId = window.setTimeout(function () {
                    callback.apply(null, args);
                }, delay);
            };
        };

        const updateModalContent = function (client) {
            if (!client) {
                return;
            }
            if (cfTarget) {
                cfTarget.textContent = client.cf || '';
            }
            if (nameTarget) {
                nameTarget.textContent = client.display_name || client.nominativo_suggestion || 'Cliente trovato';
            }
            if (emailTarget) {
                emailTarget.textContent = client.email ? ('Email: ' + client.email) : 'Email non presente';
            }
            if (phoneTarget) {
                phoneTarget.textContent = client.telefono ? ('Telefono: ' + client.telefono) : 'Telefono non presente';
            }
        };

        const ensureClientOption = function (clientId, label) {
            const targetValue = String(clientId ?? '');
            if (targetValue === '') {
                return null;
            }
            let option = null;
            for (let index = 0; index < clienteSelect.options.length; index += 1) {
                if (clienteSelect.options[index].value === targetValue) {
                    option = clienteSelect.options[index];
                    break;
                }
            }
            if (!option) {
                option = document.createElement('option');
                option.value = targetValue;
                option.textContent = label || ('Cliente #' + targetValue);
                clienteSelect.appendChild(option);
            }
            return option;
        };

        const applyClientSelection = function (client) {
            if (!client) {
                return;
            }
            const targetId = client.id != null ? String(client.id) : '';
            if (targetId) {
                const option = ensureClientOption(targetId, client.display_name || client.nominativo_suggestion);
                if (option) {
                    option.selected = true;
                    clienteSelect.value = targetId;
                    clienteSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            const nominativoField = document.getElementById('nominativo');
            if (nominativoField && (!nominativoField.value || nominativoField.value.trim() === '')) {
                const suggestion = client.nominativo_suggestion || client.display_name || '';
                if (suggestion) {
                    nominativoField.value = suggestion;
                }
            }

            const emailField = document.getElementById('email');
            if (emailField && (!emailField.value || emailField.value.trim() === '') && client.email) {
                emailField.value = client.email;
            }

            const phoneField = document.getElementById('telefono');
            if (phoneField && (!phoneField.value || phoneField.value.trim() === '') && client.telefono) {
                phoneField.value = client.telefono;
            }

            if (client.cf) {
                cfInput.value = client.cf.toUpperCase();
            }
        };

        const showModal = function (client) {
            pendingClient = client;
            updateModalContent(client);
            if (!modalInstance) {
                modalInstance = typeof bootstrapNs.Modal.getOrCreateInstance === 'function'
                    ? bootstrapNs.Modal.getOrCreateInstance(modalElement, { backdrop: 'static' })
                    : new bootstrapNs.Modal(modalElement, { backdrop: 'static' });
            }
            modalInstance.show();
        };

        confirmButton.addEventListener('click', function () {
            if (!pendingClient) {
                return;
            }
            applyClientSelection(pendingClient);
            if (modalInstance) {
                modalInstance.hide();
            }
        });

        modalElement.addEventListener('hidden.bs.modal', function () {
            pendingClient = null;
            lastLookupValue = '';
        });

        const performLookup = function (rawValue) {
            const normalized = sanitizeCf(rawValue);
            if (normalized.length < 11) {
                if (normalized === '') {
                    lastLookupValue = '';
                }
                return;
            }
            if (normalized === lastLookupValue) {
                return;
            }
            lastLookupValue = normalized;
            const requestId = ++lastRequestId;

            fetch('lookup-client.php?cf=' + encodeURIComponent(normalized), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(function (payload) {
                if (requestId !== lastRequestId) {
                    return;
                }
                if (!payload || payload.found !== true || !payload.client) {
                    lastLookupValue = '';
                    return;
                }
                showModal(payload.client);
            }).catch(function (error) {
                console.warn('Ricerca cliente non disponibile', error);
            });
        };

        const debouncedLookup = debounce(function (value) {
            performLookup(value);
        }, 450);

        cfInput.addEventListener('input', function () {
            const current = cfInput.value;
            const uppercased = current.toUpperCase();
            if (current !== uppercased) {
                const selectionStart = cfInput.selectionStart;
                const selectionEnd = cfInput.selectionEnd;
                cfInput.value = uppercased;
                if (typeof selectionStart === 'number' && typeof selectionEnd === 'number') {
                    cfInput.setSelectionRange(selectionStart, selectionEnd);
                }
            }
            debouncedLookup(cfInput.value);
        });

        cfInput.addEventListener('blur', function () {
            performLookup(cfInput.value);
        });

        if (cfInput.value) {
            cfInput.value = cfInput.value.toUpperCase();
        }
    };

    if (document.readyState === 'complete') {
        initClientLookup();
    } else {
        window.addEventListener('load', initClientLookup, { once: true });
    }
})();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
