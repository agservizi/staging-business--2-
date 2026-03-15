<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Nuova richiesta Visura CR';
$csrfToken = csrf_token();

$errors = [];
$success = false;

$data = [
    'tipo_visura' => 'persona_fisica',
    'nome' => '',
    'cognome' => '',
    'codice_fiscale' => '',
    'data_nascita' => '',
    'luogo_nascita' => '',
    'provincia_nascita' => '',
    'residenza' => '',
    'email' => '',
    'telefono' => '',
    'ragione_sociale' => '',
    'partita_iva' => '',
    'codice_fiscale_giuridico' => '',
    'forma_giuridica' => '',
    'sede_legale' => '',
    'email_aziendale' => '',
    'telefono_aziendale' => '',
    'richiedente_stesso' => true,
    'richiedente_nome' => '',
    'richiedente_cognome' => '',
    'richiedente_codice_fiscale' => '',
    'richiedente_qualifica' => '',
    'consenso_privacy' => false,
    'consenso_richiesta' => false,
    'consenso_veridicita' => false,
    'note' => '',
];

function field_value(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function bool_value(string $key): bool
{
    return isset($_POST[$key]) && in_array((string) $_POST[$key], ['1', 'on', 'true'], true);
}

function add_required(array &$errors, string $label, string $value): void
{
    if (trim($value) === '') {
        $errors[] = $label . ' è obbligatorio.';
    }
}

function is_valid_email(string $value): bool
{
    return $value === '' || (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
}

function has_upload(?array $files): bool
{
    if ($files === null || !isset($files['name'])) {
        return false;
    }
    if (is_array($files['name'])) {
        foreach ($files['name'] as $name) {
            if ($name !== '') {
                return true;
            }
        }
        return false;
    }
    return (string) $files['name'] !== '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saveDraft = isset($_POST['save_draft']) && $_POST['save_draft'] === '1';

    $data['tipo_visura'] = field_value('tipo_visura') ?: 'persona_fisica';
    $data['nome'] = field_value('nome');
    $data['cognome'] = field_value('cognome');
    $data['codice_fiscale'] = strtoupper(field_value('codice_fiscale'));
    $data['data_nascita'] = field_value('data_nascita');
    $data['luogo_nascita'] = field_value('luogo_nascita');
    $data['provincia_nascita'] = field_value('provincia_nascita');
    $data['residenza'] = field_value('residenza');
    $data['email'] = field_value('email');
    $data['telefono'] = field_value('telefono');

    $data['ragione_sociale'] = field_value('ragione_sociale');
    $data['partita_iva'] = field_value('partita_iva');
    $data['codice_fiscale_giuridico'] = strtoupper(field_value('codice_fiscale_giuridico'));
    $data['forma_giuridica'] = field_value('forma_giuridica');
    $data['sede_legale'] = field_value('sede_legale');
    $data['email_aziendale'] = field_value('email_aziendale');
    $data['telefono_aziendale'] = field_value('telefono_aziendale');

    $data['richiedente_stesso'] = bool_value('richiedente_stesso');
    $data['richiedente_nome'] = field_value('richiedente_nome');
    $data['richiedente_cognome'] = field_value('richiedente_cognome');
    $data['richiedente_codice_fiscale'] = strtoupper(field_value('richiedente_codice_fiscale'));
    $data['richiedente_qualifica'] = field_value('richiedente_qualifica');

    $data['consenso_privacy'] = bool_value('consenso_privacy');
    $data['consenso_richiesta'] = bool_value('consenso_richiesta');
    $data['consenso_veridicita'] = bool_value('consenso_veridicita');
    $data['note'] = field_value('note');

    if (!$saveDraft) {
        if (!in_array($data['tipo_visura'], ['persona_fisica', 'persona_giuridica'], true)) {
            $errors[] = 'Seleziona una tipologia valida.';
        }

        if ($data['tipo_visura'] === 'persona_fisica') {
            add_required($errors, 'Nome', $data['nome']);
            add_required($errors, 'Cognome', $data['cognome']);
            add_required($errors, 'Codice fiscale', $data['codice_fiscale']);
            add_required($errors, 'Data di nascita', $data['data_nascita']);
            add_required($errors, 'Luogo di nascita', $data['luogo_nascita']);
            add_required($errors, 'Email', $data['email']);
            add_required($errors, 'Telefono', $data['telefono']);
            if (!is_valid_email($data['email'])) {
                $errors[] = 'Email non valida.';
            }
        } else {
            add_required($errors, 'Ragione sociale', $data['ragione_sociale']);
            add_required($errors, 'Partita IVA', $data['partita_iva']);
            add_required($errors, 'Forma giuridica', $data['forma_giuridica']);
            add_required($errors, 'Sede legale', $data['sede_legale']);
            add_required($errors, 'Email aziendale', $data['email_aziendale']);
            add_required($errors, 'Telefono aziendale', $data['telefono_aziendale']);
            if (!is_valid_email($data['email_aziendale'])) {
                $errors[] = 'Email aziendale non valida.';
            }
        }

        if (!$data['richiedente_stesso']) {
            add_required($errors, 'Nome richiedente', $data['richiedente_nome']);
            add_required($errors, 'Cognome richiedente', $data['richiedente_cognome']);
            add_required($errors, 'Codice fiscale richiedente', $data['richiedente_codice_fiscale']);
            add_required($errors, 'Titolo/Qualifica', $data['richiedente_qualifica']);
        }

        if (!$data['consenso_privacy'] || !$data['consenso_richiesta'] || !$data['consenso_veridicita']) {
            $errors[] = 'Devi confermare tutti i consensi richiesti.';
        }

        if (!has_upload($_FILES['documento_identita'] ?? null)) {
            $errors[] = 'Documento di identità obbligatorio.';
        }
        if (!has_upload($_FILES['tessera_sanitaria'] ?? null)) {
            $errors[] = 'Tessera sanitaria obbligatoria.';
        }
        if (!has_upload($_FILES['delega_firmata'] ?? null)) {
            $errors[] = 'Delega firmata obbligatoria.';
        }
        if (!has_upload($_FILES['firma'] ?? null)) {
            $errors[] = 'Firma obbligatoria.';
        }
        if ($data['tipo_visura'] === 'persona_giuridica' && !has_upload($_FILES['visura_camerale'] ?? null)) {
            $errors[] = 'Visura camerale obbligatoria per persona giuridica.';
        }
        if (!$data['richiedente_stesso'] && !has_upload($_FILES['richiedente_documento'] ?? null)) {
            $errors[] = 'Documento richiedente obbligatorio.';
        }
    }

    if (!$errors) {
        $stato = $saveDraft ? 'Bozza' : 'Inviata';
        $aperturaDb = null;
        if ($data['data_nascita'] !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $data['data_nascita']) ?: DateTime::createFromFormat('d/m/Y', $data['data_nascita']);
            $aperturaDb = $date ? $date->format('Y-m-d') : null;
        }

        $stmt = $pdo->prepare('INSERT INTO servizi_visure_cr_pratiche (
            tipo_visura, stato, nome, cognome, codice_fiscale, data_nascita, luogo_nascita, provincia_nascita,
            residenza, email, telefono, ragione_sociale, partita_iva, codice_fiscale_giuridico, forma_giuridica,
            sede_legale, email_aziendale, telefono_aziendale, richiedente_stesso, richiedente_nome,
            richiedente_cognome, richiedente_codice_fiscale, richiedente_qualifica, consenso_privacy,
            consenso_richiesta, consenso_veridicita, note, created_by, updated_by, created_at, updated_at
        ) VALUES (
            :tipo_visura, :stato, :nome, :cognome, :codice_fiscale, :data_nascita, :luogo_nascita, :provincia_nascita,
            :residenza, :email, :telefono, :ragione_sociale, :partita_iva, :codice_fiscale_giuridico, :forma_giuridica,
            :sede_legale, :email_aziendale, :telefono_aziendale, :richiedente_stesso, :richiedente_nome,
            :richiedente_cognome, :richiedente_codice_fiscale, :richiedente_qualifica, :consenso_privacy,
            :consenso_richiesta, :consenso_veridicita, :note, :created_by, :updated_by, NOW(), NOW()
        )');

        $currentUser = (int) ($_SESSION['user_id'] ?? 0);
        $stmt->execute([
            ':tipo_visura' => $data['tipo_visura'],
            ':stato' => $stato,
            ':nome' => $data['nome'] ?: null,
            ':cognome' => $data['cognome'] ?: null,
            ':codice_fiscale' => $data['codice_fiscale'] ?: null,
            ':data_nascita' => $aperturaDb,
            ':luogo_nascita' => $data['luogo_nascita'] ?: null,
            ':provincia_nascita' => $data['provincia_nascita'] ?: null,
            ':residenza' => $data['residenza'] ?: null,
            ':email' => $data['email'] ?: null,
            ':telefono' => $data['telefono'] ?: null,
            ':ragione_sociale' => $data['ragione_sociale'] ?: null,
            ':partita_iva' => $data['partita_iva'] ?: null,
            ':codice_fiscale_giuridico' => $data['codice_fiscale_giuridico'] ?: null,
            ':forma_giuridica' => $data['forma_giuridica'] ?: null,
            ':sede_legale' => $data['sede_legale'] ?: null,
            ':email_aziendale' => $data['email_aziendale'] ?: null,
            ':telefono_aziendale' => $data['telefono_aziendale'] ?: null,
            ':richiedente_stesso' => $data['richiedente_stesso'] ? 1 : 0,
            ':richiedente_nome' => $data['richiedente_nome'] ?: null,
            ':richiedente_cognome' => $data['richiedente_cognome'] ?: null,
            ':richiedente_codice_fiscale' => $data['richiedente_codice_fiscale'] ?: null,
            ':richiedente_qualifica' => $data['richiedente_qualifica'] ?: null,
            ':consenso_privacy' => $data['consenso_privacy'] ? 1 : 0,
            ':consenso_richiesta' => $data['consenso_richiesta'] ? 1 : 0,
            ':consenso_veridicita' => $data['consenso_veridicita'] ? 1 : 0,
            ':note' => $data['note'] ?: null,
            ':created_by' => $currentUser,
            ':updated_by' => $currentUser,
        ]);

        $richiestaId = (int) $pdo->lastInsertId();
        $uploadErrors = [];
        visure_cr_handle_upload($pdo, $richiestaId, $_FILES['documento_identita'] ?? null, 'documento_identita', $uploadErrors);
        visure_cr_handle_upload($pdo, $richiestaId, $_FILES['tessera_sanitaria'] ?? null, 'tessera_sanitaria', $uploadErrors);
        visure_cr_handle_upload($pdo, $richiestaId, $_FILES['delega_firmata'] ?? null, 'delega_firmata', $uploadErrors);
        visure_cr_handle_upload($pdo, $richiestaId, $_FILES['firma'] ?? null, 'firma', $uploadErrors);
        visure_cr_handle_upload($pdo, $richiestaId, $_FILES['visura_camerale'] ?? null, 'visura_camerale', $uploadErrors);
        visure_cr_handle_upload($pdo, $richiestaId, $_FILES['richiedente_documento'] ?? null, 'richiedente_documento', $uploadErrors);

        if ($uploadErrors) {
            $errors = array_merge($errors, $uploadErrors);
        }

        if (!$errors) {
            add_flash('success', $saveDraft ? 'Bozza salvata correttamente.' : 'Richiesta inviata correttamente.');
            header('Location: ' . visure_cr_module_url('view', ['id' => $richiestaId]));
            exit;
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
                <h1 class="h3 mb-1">Nuova richiesta Visura CR</h1>
                <p class="text-muted mb-0">Compila i dati per avviare la richiesta.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="<?php echo sanitize_output(visure_cr_module_url('index')); ?>"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
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
                <form method="post" enctype="multipart/form-data" id="visure-cr-form" novalidate>
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="save_draft" id="save_draft" value="0">

                    <div class="cr-steps mb-4">
                        <div class="cr-step-indicator" data-step-indicator="1">1. Tipologia</div>
                        <div class="cr-step-indicator" data-step-indicator="2">2. Dati anagrafici</div>
                        <div class="cr-step-indicator" data-step-indicator="3">3. Richiedente</div>
                        <div class="cr-step-indicator" data-step-indicator="4">4. Documenti</div>
                        <div class="cr-step-indicator" data-step-indicator="5">5. Consensi</div>
                        <div class="cr-step-indicator" data-step-indicator="6">6. Riepilogo</div>
                    </div>

                    <section class="cr-step" data-step="1">
                        <h2 class="h5 mb-3">Tipologia di visura</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check card card-body bg-transparent border-0">
                                    <input class="form-check-input" type="radio" name="tipo_visura" id="tipo_fisica" value="persona_fisica" <?php echo $data['tipo_visura'] === 'persona_fisica' ? 'checked' : ''; ?> required>
                                    <label class="form-check-label fw-semibold" for="tipo_fisica">Visura CR Persona Fisica</label>
                                    <p class="text-muted mb-0">Per persone fisiche con codice fiscale.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check card card-body bg-transparent border-0">
                                    <input class="form-check-input" type="radio" name="tipo_visura" id="tipo_giuridica" value="persona_giuridica" <?php echo $data['tipo_visura'] === 'persona_giuridica' ? 'checked' : ''; ?> required>
                                    <label class="form-check-label fw-semibold" for="tipo_giuridica">Visura CR Persona Giuridica</label>
                                    <p class="text-muted mb-0">Per aziende e società.</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="cr-step" data-step="2">
                        <h2 class="h5 mb-3">Dati anagrafici</h2>
                        <div class="row g-3 cr-section" data-section="persona_fisica">
                            <div class="col-md-6">
                                <label class="form-label" for="nome">Nome *</label>
                                <input class="form-control" id="nome" name="nome" value="<?php echo sanitize_output($data['nome']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="cognome">Cognome *</label>
                                <input class="form-control" id="cognome" name="cognome" value="<?php echo sanitize_output($data['cognome']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="codice_fiscale">Codice fiscale *</label>
                                <input class="form-control" id="codice_fiscale" name="codice_fiscale" value="<?php echo sanitize_output($data['codice_fiscale']); ?>" maxlength="16" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="data_nascita">Data di nascita *</label>
                                <input class="form-control" id="data_nascita" name="data_nascita" type="date" value="<?php echo sanitize_output($data['data_nascita']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="luogo_nascita">Luogo di nascita *</label>
                                <input class="form-control" id="luogo_nascita" name="luogo_nascita" value="<?php echo sanitize_output($data['luogo_nascita']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="provincia_nascita">Provincia</label>
                                <input class="form-control" id="provincia_nascita" name="provincia_nascita" value="<?php echo sanitize_output($data['provincia_nascita']); ?>" maxlength="2">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label" for="residenza">Residenza (indirizzo completo)</label>
                                <input class="form-control" id="residenza" name="residenza" value="<?php echo sanitize_output($data['residenza']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email *</label>
                                <input class="form-control" id="email" name="email" type="email" value="<?php echo sanitize_output($data['email']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="telefono">Telefono *</label>
                                <input class="form-control" id="telefono" name="telefono" value="<?php echo sanitize_output($data['telefono']); ?>" required>
                            </div>
                        </div>

                        <div class="row g-3 cr-section" data-section="persona_giuridica">
                            <div class="col-md-6">
                                <label class="form-label" for="ragione_sociale">Ragione sociale *</label>
                                <input class="form-control" id="ragione_sociale" name="ragione_sociale" value="<?php echo sanitize_output($data['ragione_sociale']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="partita_iva">Partita IVA *</label>
                                <input class="form-control" id="partita_iva" name="partita_iva" value="<?php echo sanitize_output($data['partita_iva']); ?>" maxlength="16" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="codice_fiscale_giuridico">Codice fiscale (se diverso)</label>
                                <input class="form-control" id="codice_fiscale_giuridico" name="codice_fiscale_giuridico" value="<?php echo sanitize_output($data['codice_fiscale_giuridico']); ?>" maxlength="16">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="forma_giuridica">Forma giuridica *</label>
                                <select class="form-select" id="forma_giuridica" name="forma_giuridica" required>
                                    <option value="">Seleziona</option>
                                    <?php
                                        $forme = ['SRL', 'SPA', 'SAS', 'SNC', 'Ditta individuale', 'Cooperativa', 'Altro'];
                                        foreach ($forme as $forma):
                                    ?>
                                        <option value="<?php echo sanitize_output($forma); ?>" <?php echo $data['forma_giuridica'] === $forma ? 'selected' : ''; ?>><?php echo sanitize_output($forma); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label" for="sede_legale">Sede legale *</label>
                                <input class="form-control" id="sede_legale" name="sede_legale" value="<?php echo sanitize_output($data['sede_legale']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email_aziendale">Email aziendale *</label>
                                <input class="form-control" id="email_aziendale" name="email_aziendale" type="email" value="<?php echo sanitize_output($data['email_aziendale']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="telefono_aziendale">Telefono aziendale *</label>
                                <input class="form-control" id="telefono_aziendale" name="telefono_aziendale" value="<?php echo sanitize_output($data['telefono_aziendale']); ?>" required>
                            </div>
                        </div>
                    </section>

                    <section class="cr-step" data-step="3">
                        <h2 class="h5 mb-3">Dati richiedente</h2>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="richiedente_stesso" name="richiedente_stesso" value="1" <?php echo $data['richiedente_stesso'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="richiedente_stesso">Il richiedente è il soggetto della visura</label>
                        </div>

                        <div class="row g-3 cr-section" data-section="richiedente_altro">
                            <div class="col-md-6">
                                <label class="form-label" for="richiedente_nome">Nome *</label>
                                <input class="form-control" id="richiedente_nome" name="richiedente_nome" value="<?php echo sanitize_output($data['richiedente_nome']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="richiedente_cognome">Cognome *</label>
                                <input class="form-control" id="richiedente_cognome" name="richiedente_cognome" value="<?php echo sanitize_output($data['richiedente_cognome']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="richiedente_codice_fiscale">Codice fiscale *</label>
                                <input class="form-control" id="richiedente_codice_fiscale" name="richiedente_codice_fiscale" value="<?php echo sanitize_output($data['richiedente_codice_fiscale']); ?>" maxlength="16">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="richiedente_qualifica">Titolo / Qualifica *</label>
                                <input class="form-control" id="richiedente_qualifica" name="richiedente_qualifica" value="<?php echo sanitize_output($data['richiedente_qualifica']); ?>">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label" for="richiedente_documento">Documento di identità richiedente</label>
                                <input class="form-control" type="file" id="richiedente_documento" name="richiedente_documento" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                        </div>
                    </section>

                    <section class="cr-step" data-step="4">
                        <h2 class="h5 mb-3">Documenti da allegare</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Documento di identità (fronte/retro) *</label>
                                <div class="cr-drop" data-drop>
                                    <input class="form-control" type="file" name="documento_identita[]" accept=".pdf,.jpg,.jpeg,.png" multiple>
                                    <small class="text-muted d-block mt-1">Formati: PDF, JPG, PNG · Max 12MB</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tessera sanitaria *</label>
                                <div class="cr-drop" data-drop>
                                    <input class="form-control" type="file" name="tessera_sanitaria" accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted d-block mt-1">Formati: PDF, JPG, PNG · Max 12MB</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Delega firmata *</label>
                                <div class="cr-drop" data-drop>
                                    <input class="form-control" type="file" name="delega_firmata" accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted d-block mt-1">PDF o immagine · Max 12MB</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Firma digitale o autografa *</label>
                                <div class="cr-drop" data-drop>
                                    <input class="form-control" type="file" name="firma" accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted d-block mt-1">PDF o immagine · Max 12MB</small>
                                </div>
                            </div>
                            <div class="col-md-6 cr-section" data-section="visura_camerale">
                                <label class="form-label">Visura camerale (solo persona giuridica)</label>
                                <div class="cr-drop" data-drop>
                                    <input class="form-control" type="file" name="visura_camerale" accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted d-block mt-1">PDF o immagine · Max 12MB</small>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="cr-step" data-step="5">
                        <h2 class="h5 mb-3">Consensi e privacy</h2>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="consenso_privacy" id="consenso_privacy" value="1" <?php echo $data['consenso_privacy'] ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="consenso_privacy">Consenso al trattamento dei dati personali (GDPR)</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="consenso_richiesta" id="consenso_richiesta" value="1" <?php echo $data['consenso_richiesta'] ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="consenso_richiesta">Autorizzazione alla richiesta della Visura CR</label>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="consenso_veridicita" id="consenso_veridicita" value="1" <?php echo $data['consenso_veridicita'] ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="consenso_veridicita">Dichiarazione di veridicità dei dati</label>
                        </div>
                        <div class="d-flex flex-wrap gap-3">
                            <a class="link-warning" href="privacy.php" target="_blank" rel="noopener">Informativa Privacy</a>
                            <a class="link-warning" href="termini.php" target="_blank" rel="noopener">Termini del servizio</a>
                        </div>
                    </section>

                    <section class="cr-step" data-step="6">
                        <h2 class="h5 mb-3">Riepilogo e invio</h2>
                        <div class="row g-3" id="cr-summary"></div>
                        <div class="mt-3">
                            <label class="form-label" for="note">Note interne</label>
                            <textarea class="form-control" id="note" name="note" rows="3"><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                    </section>

                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <button class="btn btn-outline-secondary" type="button" data-step-prev>Indietro</button>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-warning" type="button" id="saveDraftBtn">Salva bozza</button>
                            <button class="btn btn-warning" type="button" data-step-next>Avanti</button>
                            <button class="btn btn-warning text-dark" type="submit" data-step-submit>Invia richiesta</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<style>
.cr-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 8px;
}
.cr-step-indicator {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.85rem;
    text-align: center;
    color: #cbd5e1;
}
.cr-step-indicator.is-active {
    background: rgba(255, 193, 7, 0.15);
    color: #facc15;
    font-weight: 600;
}
.cr-step {
    display: none;
}
.cr-step.is-active {
    display: block;
}
.cr-drop input[type="file"] {
    background: rgba(255, 255, 255, 0.02);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('visure-cr-form');
    const steps = Array.from(document.querySelectorAll('.cr-step'));
    const indicators = Array.from(document.querySelectorAll('.cr-step-indicator'));
    const nextBtn = document.querySelector('[data-step-next]');
    const prevBtn = document.querySelector('[data-step-prev]');
    const submitBtn = document.querySelector('[data-step-submit]');
    const saveDraftBtn = document.getElementById('saveDraftBtn');
    const tipoInputs = document.querySelectorAll('input[name="tipo_visura"]');
    const richiedenteSwitch = document.getElementById('richiedente_stesso');
    const summary = document.getElementById('cr-summary');
    const saveDraftField = document.getElementById('save_draft');

    let currentStep = 1;

    const setStep = (step) => {
        currentStep = Math.min(Math.max(step, 1), steps.length);
        steps.forEach((panel) => {
            panel.classList.toggle('is-active', Number(panel.dataset.step) === currentStep);
        });
        indicators.forEach((indicator) => {
            indicator.classList.toggle('is-active', Number(indicator.dataset.stepIndicator) === currentStep);
        });
        if (prevBtn) {
            prevBtn.disabled = currentStep === 1;
        }
        if (nextBtn) {
            nextBtn.style.display = currentStep === steps.length ? 'none' : 'inline-flex';
        }
        if (submitBtn) {
            submitBtn.style.display = currentStep === steps.length ? 'inline-flex' : 'none';
        }
        if (currentStep === steps.length) {
            renderSummary();
        }
    };

    const setRequired = (container, required) => {
        container.querySelectorAll('input, select, textarea').forEach((field) => {
            if (!field.dataset.originalRequired) {
                field.dataset.originalRequired = field.hasAttribute('required') ? '1' : '0';
            }
            if (field.dataset.originalRequired !== '1') {
                return;
            }
            if (required) {
                field.setAttribute('required', 'required');
            } else {
                field.removeAttribute('required');
                field.classList.remove('is-invalid');
            }
        });
    };

    const toggleSections = () => {
        const tipo = document.querySelector('input[name="tipo_visura"]:checked')?.value || 'persona_fisica';
        document.querySelectorAll('[data-section="persona_fisica"]').forEach((el) => {
            const active = tipo === 'persona_fisica';
            el.style.display = active ? '' : 'none';
            setRequired(el, active);
        });
        document.querySelectorAll('[data-section="persona_giuridica"]').forEach((el) => {
            const active = tipo === 'persona_giuridica';
            el.style.display = active ? '' : 'none';
            setRequired(el, active);
        });
        document.querySelectorAll('[data-section="visura_camerale"]').forEach((el) => {
            el.style.display = tipo === 'persona_giuridica' ? '' : 'none';
        });
        const richiedenteAltro = !richiedenteSwitch?.checked;
        document.querySelectorAll('[data-section="richiedente_altro"]').forEach((el) => {
            el.style.display = richiedenteAltro ? '' : 'none';
        });
    };

    const validateStep = () => {
        const activeStep = steps.find((panel) => Number(panel.dataset.step) === currentStep);
        if (!activeStep) {
            return true;
        }
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

    const renderSummary = () => {
        if (!summary) return;
        const formData = new FormData(form);
        const tipo = formData.get('tipo_visura') === 'persona_giuridica' ? 'Persona giuridica' : 'Persona fisica';
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

        if (tipo === 'Persona fisica') {
            addBlock('Dati anagrafici', [
                `Nome: ${formData.get('nome') || '—'}`,
                `Cognome: ${formData.get('cognome') || '—'}`,
                `Codice fiscale: ${formData.get('codice_fiscale') || '—'}`,
                `Data di nascita: ${formData.get('data_nascita') || '—'}`,
                `Luogo di nascita: ${formData.get('luogo_nascita') || '—'}`,
                `Email: ${formData.get('email') || '—'}`,
                `Telefono: ${formData.get('telefono') || '—'}`,
            ]);
        } else {
            addBlock('Dati aziendali', [
                `Ragione sociale: ${formData.get('ragione_sociale') || '—'}`,
                `Partita IVA: ${formData.get('partita_iva') || '—'}`,
                `Forma giuridica: ${formData.get('forma_giuridica') || '—'}`,
                `Email: ${formData.get('email_aziendale') || '—'}`,
                `Telefono: ${formData.get('telefono_aziendale') || '—'}`,
            ]);
        }

        const richiedenteStesso = formData.get('richiedente_stesso') === '1';
        addBlock('Richiedente', richiedenteStesso ? ['Il richiedente coincide con il soggetto.'] : [
            `Nome: ${formData.get('richiedente_nome') || '—'}`,
            `Cognome: ${formData.get('richiedente_cognome') || '—'}`,
            `Codice fiscale: ${formData.get('richiedente_codice_fiscale') || '—'}`,
            `Qualifica: ${formData.get('richiedente_qualifica') || '—'}`,
        ]);

        addBlock('Consensi', [
            `Privacy: ${formData.get('consenso_privacy') ? 'Sì' : 'No'}`,
            `Richiesta: ${formData.get('consenso_richiesta') ? 'Sì' : 'No'}`,
            `Veridicità: ${formData.get('consenso_veridicita') ? 'Sì' : 'No'}`,
        ]);

        summary.innerHTML = blocks.join('');
    };

    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (validateStep()) {
                setStep(currentStep + 1);
            }
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', () => setStep(currentStep - 1));
    }

    if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', () => {
            if (saveDraftField) {
                saveDraftField.value = '1';
            }
            form.submit();
        });
    }

    tipoInputs.forEach((input) => input.addEventListener('change', toggleSections));
    if (richiedenteSwitch) {
        richiedenteSwitch.addEventListener('change', toggleSections);
    }

    form.addEventListener('input', (event) => {
        const target = event.target;
        if (!target || !target.hasAttribute('required')) return;
        if (target.type === 'checkbox') {
            target.classList.toggle('is-invalid', !target.checked);
        } else {
            target.classList.toggle('is-invalid', !target.value.trim());
        }
    });

    toggleSections();
    setStep(1);
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
