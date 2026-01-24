<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Modifica pratica ACI';

$stati = [
    'Aperta',
    'Documenti richiesti',
    'In lavorazione',
    'Inviata',
    'Completata',
    'Annullata',
];

$tipi = [
    'Passaggio proprietà',
    'Radiazione',
    'Duplicato documenti',
    'Immatricolazione',
    'Reimmatricolazione',
    'Perdita possesso',
    'Visura PRA',
];

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

$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll() : [];

$errors = [];
$data = [
    'cliente_id' => $pratica['cliente_id'] ?? '',
    'tipo_pratica' => $pratica['tipo_pratica'] ?? $tipi[0],
    'stato' => $pratica['stato'] ?? 'Aperta',
    'targa' => $pratica['targa'] ?? '',
    'telaio' => $pratica['telaio'] ?? '',
    'intestatario' => $pratica['intestatario'] ?? '',
    'protocollo' => $pratica['protocollo'] ?? '',
    'data_apertura' => $pratica['data_apertura'] ? format_date_locale($pratica['data_apertura']) : '',
    'data_scadenza' => $pratica['data_scadenza'] ? format_date_locale($pratica['data_scadenza']) : '',
    'data_chiusura' => $pratica['data_chiusura'] ? format_date_locale($pratica['data_chiusura']) : '',
    'costo' => $pratica['costo'] ?? '0.00',
    'note' => $pratica['note'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $data['cliente_id'] = trim((string) ($_POST['cliente_id'] ?? ''));
    $data['tipo_pratica'] = trim((string) ($_POST['tipo_pratica'] ?? ''));
    $data['stato'] = trim((string) ($_POST['stato'] ?? ''));
    $data['targa'] = strtoupper(trim((string) ($_POST['targa'] ?? '')));
    $data['telaio'] = strtoupper(trim((string) ($_POST['telaio'] ?? '')));
    $data['intestatario'] = trim((string) ($_POST['intestatario'] ?? ''));
    $data['protocollo'] = trim((string) ($_POST['protocollo'] ?? ''));
    $data['data_apertura'] = trim((string) ($_POST['data_apertura'] ?? ''));
    $data['data_scadenza'] = trim((string) ($_POST['data_scadenza'] ?? ''));
    $data['data_chiusura'] = trim((string) ($_POST['data_chiusura'] ?? ''));
    $data['costo'] = trim((string) ($_POST['costo'] ?? '0'));
    $data['note'] = trim((string) ($_POST['note'] ?? ''));

    if ($data['tipo_pratica'] === '' || !in_array($data['tipo_pratica'], $tipi, true)) {
        $errors[] = 'Seleziona il tipo di pratica.';
    }

    if ($data['stato'] === '' || !in_array($data['stato'], $stati, true)) {
        $errors[] = 'Seleziona lo stato della pratica.';
    }

    $clienteId = null;
    if ($data['cliente_id'] !== '') {
        if (!ctype_digit((string) $data['cliente_id'])) {
            $errors[] = 'Cliente non valido.';
        } else {
            $clienteId = (int) $data['cliente_id'];
        }
    }

    $aperturaDb = null;
    if ($data['data_apertura'] !== '') {
        $aperturaDate = DateTimeImmutable::createFromFormat('d/m/Y', $data['data_apertura']);
        if (!$aperturaDate || $aperturaDate->format('d/m/Y') !== $data['data_apertura']) {
            $errors[] = 'La data di apertura non è valida (usa gg/mm/aaaa).';
        } else {
            $aperturaDb = $aperturaDate->format('Y-m-d');
        }
    }

    $scadenzaDb = null;
    if ($data['data_scadenza'] !== '') {
        $scadenzaDate = DateTimeImmutable::createFromFormat('d/m/Y', $data['data_scadenza']);
        if (!$scadenzaDate || $scadenzaDate->format('d/m/Y') !== $data['data_scadenza']) {
            $errors[] = 'La data di scadenza non è valida (usa gg/mm/aaaa).';
        } else {
            $scadenzaDb = $scadenzaDate->format('Y-m-d');
        }
    }

    $chiusuraDb = null;
    if ($data['data_chiusura'] !== '') {
        $chiusuraDate = DateTimeImmutable::createFromFormat('d/m/Y', $data['data_chiusura']);
        if (!$chiusuraDate || $chiusuraDate->format('d/m/Y') !== $data['data_chiusura']) {
            $errors[] = 'La data di chiusura non è valida (usa gg/mm/aaaa).';
        } else {
            $chiusuraDb = $chiusuraDate->format('Y-m-d');
        }
    }

    $costo = 0.0;
    if ($data['costo'] !== '') {
        $costo = (float) str_replace(',', '.', $data['costo']);
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE servizi_aci_pratiche SET
            cliente_id = :cliente_id,
            tipo_pratica = :tipo_pratica,
            stato = :stato,
            targa = :targa,
            telaio = :telaio,
            intestatario = :intestatario,
            protocollo = :protocollo,
            data_apertura = :data_apertura,
            data_scadenza = :data_scadenza,
            data_chiusura = :data_chiusura,
            costo = :costo,
            note = :note,
            updated_at = NOW()
            WHERE id = :id
            LIMIT 1');
        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':tipo_pratica' => $data['tipo_pratica'],
            ':stato' => $data['stato'],
            ':targa' => $data['targa'] ?: null,
            ':telaio' => $data['telaio'] ?: null,
            ':intestatario' => $data['intestatario'] ?: null,
            ':protocollo' => $data['protocollo'] ?: null,
            ':data_apertura' => $aperturaDb,
            ':data_scadenza' => $scadenzaDb,
            ':data_chiusura' => $chiusuraDb,
            ':costo' => $costo,
            ':note' => $data['note'] ?: null,
            ':id' => $praticaId,
        ]);

        aci_handle_uploads($pdo, $praticaId, $_FILES['allegati'] ?? null, $errors);

        if (!$errors) {
            add_flash('success', 'Pratica aggiornata correttamente.');
            header('Location: view.php?id=' . $praticaId);
            exit;
        }
    }
}

$attachments = aci_get_attachments($pdo, $praticaId);
$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Modifica pratica ACI</h1>
                <p class="text-muted mb-0">Aggiorna i dettagli della pratica.</p>
            </div>
            <div class="toolbar-actions">
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
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <div class="row g-3">
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
                            <label class="form-label" for="tipo_pratica">Tipo pratica *</label>
                            <select class="form-select" id="tipo_pratica" name="tipo_pratica" required>
                                <?php foreach ($tipi as $tipo): ?>
                                    <option value="<?php echo sanitize_output($tipo); ?>" <?php echo $data['tipo_pratica'] === $tipo ? 'selected' : ''; ?>><?php echo sanitize_output($tipo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="stato">Stato *</label>
                            <select class="form-select" id="stato" name="stato" required>
                                <?php foreach ($stati as $stato): ?>
                                    <option value="<?php echo sanitize_output($stato); ?>" <?php echo $data['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="targa">Targa</label>
                            <input class="form-control" id="targa" name="targa" value="<?php echo sanitize_output($data['targa']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="telaio">Telaio</label>
                            <input class="form-control" id="telaio" name="telaio" value="<?php echo sanitize_output($data['telaio']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="intestatario">Intestatario</label>
                            <input class="form-control" id="intestatario" name="intestatario" value="<?php echo sanitize_output($data['intestatario']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="protocollo">Protocollo pratica</label>
                            <input class="form-control" id="protocollo" name="protocollo" value="<?php echo sanitize_output($data['protocollo']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="data_apertura">Data apertura</label>
                            <input class="form-control" id="data_apertura" name="data_apertura" placeholder="gg/mm/aaaa" value="<?php echo sanitize_output($data['data_apertura']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="data_scadenza">Data scadenza</label>
                            <input class="form-control" id="data_scadenza" name="data_scadenza" placeholder="gg/mm/aaaa" value="<?php echo sanitize_output($data['data_scadenza']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="data_chiusura">Data chiusura</label>
                            <input class="form-control" id="data_chiusura" name="data_chiusura" placeholder="gg/mm/aaaa" value="<?php echo sanitize_output($data['data_chiusura']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="costo">Costo</label>
                            <input class="form-control" id="costo" name="costo" value="<?php echo sanitize_output((string) $data['costo']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="note">Note</label>
                            <textarea class="form-control" id="note" name="note" rows="4"><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="allegati">Aggiungi allegati</label>
                            <input class="form-control" id="allegati" name="allegati[]" type="file" multiple>
                            <div class="form-text">Puoi caricare più documenti (max 12MB ciascuno).</div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-secondary" href="view.php?id=<?php echo (int) $praticaId; ?>">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva modifiche</button>
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
                                    <div class="fw-semibold"><?php echo sanitize_output($attachment['file_name'] ?? 'Allegato'); ?></div>
                                    <small class="text-muted"><?php echo sanitize_output($attachment['mime_type'] ?? ''); ?> · <?php echo number_format((int) ($attachment['file_size'] ?? 0) / 1024, 1); ?> KB</small>
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
