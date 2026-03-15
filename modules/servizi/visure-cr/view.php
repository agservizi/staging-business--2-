<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$richiestaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($richiestaId <= 0) {
    add_flash('warning', 'Richiesta non valida.');
    header('Location: ' . visure_cr_module_url('index'));
    exit;
}

$richiesta = visure_cr_get_richiesta($pdo, $richiestaId);
if (!$richiesta) {
    add_flash('warning', 'Richiesta non trovata.');
    header('Location: ' . visure_cr_module_url('index'));
    exit;
}

$attachments = visure_cr_get_attachments($pdo, $richiestaId);
$stati = ['Bozza', 'Inviata', 'In lavorazione', 'Completata', 'Rifiutata'];
$tipi = ['persona_fisica' => 'Persona fisica', 'persona_giuridica' => 'Persona giuridica'];
$puoAggiornare = current_user_can('Admin', 'Operatore', 'Manager');
$csrfToken = csrf_token();
$categorieLabels = [
    'documento_identita' => 'Documento di identità',
    'tessera_sanitaria' => 'Tessera sanitaria',
    'delega_firmata' => 'Delega firmata',
    'visura_camerale' => 'Visura camerale',
    'firma' => 'Firma',
    'richiedente_documento' => 'Documento richiedente',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puoAggiornare) {
    $nuovoStato = $_POST['stato'] ?? $richiesta['stato'];
    $note = trim((string) ($_POST['note'] ?? ''));

    if (!in_array($nuovoStato, $stati, true)) {
        add_flash('warning', 'Stato non valido.');
        header('Location: ' . visure_cr_module_url('view', ['id' => $richiestaId]));
        exit;
    }

    $stmt = $pdo->prepare('UPDATE servizi_visure_cr_pratiche SET stato = :stato, note = :note, updated_by = :updated_by, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':stato' => $nuovoStato,
        ':note' => $note !== '' ? $note : null,
        ':updated_by' => (int) ($_SESSION['user_id'] ?? 0),
        ':id' => $richiestaId,
    ]);

    add_flash('success', 'Stato aggiornato.');
    header('Location: ' . visure_cr_module_url('view', ['id' => $richiestaId]));
    exit;
}

$pageTitle = 'Richiesta Visura CR';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Richiesta #<?php echo (int) $richiesta['id']; ?></h1>
                <p class="text-muted mb-0">Dettaglio richiesta Visura CR.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="<?php echo sanitize_output(visure_cr_module_url('index')); ?>"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="text-muted text-uppercase small">Tipologia</div>
                                <div class="fs-5 fw-semibold"><?php echo sanitize_output($tipi[$richiesta['tipo_visura']] ?? $richiesta['tipo_visura']); ?></div>
                            </div>
                            <span class="badge bg-secondary fs-6"><?php echo sanitize_output($richiesta['stato']); ?></span>
                        </div>

                        <?php if ($richiesta['tipo_visura'] === 'persona_fisica'): ?>
                            <div class="row g-2">
                                <div class="col-md-6"><strong>Nome:</strong> <?php echo sanitize_output($richiesta['nome'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Cognome:</strong> <?php echo sanitize_output($richiesta['cognome'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Codice fiscale:</strong> <?php echo sanitize_output($richiesta['codice_fiscale'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Data nascita:</strong> <?php echo sanitize_output(format_date_locale($richiesta['data_nascita'] ?? null)); ?></div>
                                <div class="col-md-6"><strong>Luogo nascita:</strong> <?php echo sanitize_output($richiesta['luogo_nascita'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Provincia:</strong> <?php echo sanitize_output($richiesta['provincia_nascita'] ?? ''); ?></div>
                                <div class="col-md-12"><strong>Residenza:</strong> <?php echo sanitize_output($richiesta['residenza'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Email:</strong> <?php echo sanitize_output($richiesta['email'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Telefono:</strong> <?php echo sanitize_output($richiesta['telefono'] ?? ''); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="row g-2">
                                <div class="col-md-6"><strong>Ragione sociale:</strong> <?php echo sanitize_output($richiesta['ragione_sociale'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Partita IVA:</strong> <?php echo sanitize_output($richiesta['partita_iva'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Codice fiscale:</strong> <?php echo sanitize_output($richiesta['codice_fiscale_giuridico'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Forma giuridica:</strong> <?php echo sanitize_output($richiesta['forma_giuridica'] ?? ''); ?></div>
                                <div class="col-md-12"><strong>Sede legale:</strong> <?php echo sanitize_output($richiesta['sede_legale'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Email aziendale:</strong> <?php echo sanitize_output($richiesta['email_aziendale'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Telefono aziendale:</strong> <?php echo sanitize_output($richiesta['telefono_aziendale'] ?? ''); ?></div>
                            </div>
                        <?php endif; ?>

                        <hr class="my-4">
                        <div class="fw-semibold mb-2">Richiedente</div>
                        <?php if ((int) $richiesta['richiedente_stesso'] === 1): ?>
                            <p class="text-muted mb-0">Il richiedente coincide con il soggetto della visura.</p>
                        <?php else: ?>
                            <div class="row g-2">
                                <div class="col-md-6"><strong>Nome:</strong> <?php echo sanitize_output($richiesta['richiedente_nome'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Cognome:</strong> <?php echo sanitize_output($richiesta['richiedente_cognome'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Codice fiscale:</strong> <?php echo sanitize_output($richiesta['richiedente_codice_fiscale'] ?? ''); ?></div>
                                <div class="col-md-6"><strong>Qualifica:</strong> <?php echo sanitize_output($richiesta['richiedente_qualifica'] ?? ''); ?></div>
                            </div>
                        <?php endif; ?>

                        <hr class="my-4">
                        <div class="fw-semibold mb-2">Consensi</div>
                        <ul class="list-unstyled mb-0">
                            <li>Privacy: <strong><?php echo $richiesta['consenso_privacy'] ? 'Sì' : 'No'; ?></strong></li>
                            <li>Autorizzazione richiesta: <strong><?php echo $richiesta['consenso_richiesta'] ? 'Sì' : 'No'; ?></strong></li>
                            <li>Veridicità dati: <strong><?php echo $richiesta['consenso_veridicita'] ? 'Sì' : 'No'; ?></strong></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Aggiorna stato</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($puoAggiornare): ?>
                            <form method="post">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <div class="mb-3">
                                    <label class="form-label" for="stato">Stato</label>
                                    <select class="form-select" id="stato" name="stato">
                                        <?php foreach ($stati as $stato): ?>
                                            <option value="<?php echo sanitize_output($stato); ?>" <?php echo $richiesta['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="note">Motivazione / Note</label>
                                    <textarea class="form-control" id="note" name="note" rows="3"><?php echo sanitize_output($richiesta['note'] ?? ''); ?></textarea>
                                </div>
                                <button class="btn btn-warning text-dark" type="submit">Aggiorna</button>
                            </form>
                        <?php else: ?>
                            <p class="text-muted mb-0">Non hai permessi per aggiornare la richiesta.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Allegati</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($attachments): ?>
                            <div class="list-group">
                                <?php foreach ($attachments as $attachment): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold"><?php echo sanitize_output($categorieLabels[$attachment['categoria']] ?? $attachment['categoria']); ?></div>
                                            <small class="text-muted"><?php echo sanitize_output($attachment['file_name']); ?></small>
                                        </div>
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output(visure_cr_module_url('download', ['id' => (int) $attachment['id']])); ?>"><i class="fa-solid fa-download"></i></a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun allegato disponibile.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
