<?php
declare(strict_types=1);

use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Manager', 'Operatore');

$pageTitle = 'Nuova consulenza fiscale rapida';
$service = consulenza_fiscale_service($pdo);
$statusOptions = consulenza_fiscale_status_options();
$modelOptions = consulenza_fiscale_model_options();
$frequencyOptions = consulenza_fiscale_frequency_options();

$clientsStmt = $pdo->query('SELECT id, ragione_sociale, nome, cognome FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$defaultDate = date('Y-m-d');
$data = [
    'cliente_id' => '',
    'intestatario_nome' => '',
    'codice_fiscale' => '',
    'tipo_modello' => 'F24',
    'anno_riferimento' => date('Y'),
    'periodo_riferimento' => '',
    'importo_totale' => '',
    'numero_rate' => 1,
    'frequenza_rate' => 'unica',
    'prima_scadenza' => $defaultDate,
    'stato' => 'bozza',
    'promemoria_scadenza' => '',
    'note' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $data['cliente_id'] = isset($_POST['cliente_id']) ? trim((string) $_POST['cliente_id']) : '';
    $data['intestatario_nome'] = trim((string) ($_POST['intestatario_nome'] ?? ''));
    $data['codice_fiscale'] = strtoupper(trim((string) ($_POST['codice_fiscale'] ?? '')));
    $data['tipo_modello'] = in_array($_POST['tipo_modello'] ?? '', array_keys($modelOptions), true) ? (string) $_POST['tipo_modello'] : 'F24';
    $data['anno_riferimento'] = trim((string) ($_POST['anno_riferimento'] ?? date('Y')));
    $data['periodo_riferimento'] = trim((string) ($_POST['periodo_riferimento'] ?? ''));
    $data['importo_totale'] = trim((string) ($_POST['importo_totale'] ?? ''));
    $data['numero_rate'] = (int) ($_POST['numero_rate'] ?? 1);
    $data['frequenza_rate'] = in_array($_POST['frequenza_rate'] ?? '', array_keys($frequencyOptions), true) ? (string) $_POST['frequenza_rate'] : 'unica';
    $data['prima_scadenza'] = isset($_POST['prima_scadenza']) ? (string) $_POST['prima_scadenza'] : $defaultDate;
    $data['stato'] = in_array($_POST['stato'] ?? '', array_keys($statusOptions), true) ? (string) $_POST['stato'] : 'bozza';
    $data['promemoria_scadenza'] = isset($_POST['promemoria_scadenza']) ? (string) $_POST['promemoria_scadenza'] : '';
    $data['note'] = trim((string) ($_POST['note'] ?? ''));

    $clienteId = $data['cliente_id'] !== '' && ctype_digit($data['cliente_id']) ? (int) $data['cliente_id'] : null;
    if ($data['cliente_id'] !== '' && $clienteId === null) {
        $errors['cliente_id'] = 'Cliente non valido.';
    }

    if ($data['intestatario_nome'] === '') {
        $errors['intestatario_nome'] = 'Inserisci il nominativo intestatario.';
    }

    if ($data['codice_fiscale'] === '' || !preg_match('/^[A-Z0-9]{11,16}$/', $data['codice_fiscale'])) {
        $errors['codice_fiscale'] = 'Inserisci un codice fiscale valido.';
    }

    $anno = (int) $data['anno_riferimento'];
    if ($anno < 2000 || $anno > (int) date('Y') + 1) {
        $errors['anno_riferimento'] = 'Anno di riferimento non valido.';
    }

    $importo = str_replace(['.', ','], ['', '.'], $data['importo_totale']);
    if ($importo === '' || !is_numeric($importo) || (float) $importo <= 0) {
        $errors['importo_totale'] = 'Indica un importo positivo.';
    } else {
        $importo = round((float) $importo, 2);
    }

    $data['numero_rate'] = max(1, min(12, $data['numero_rate']));
    if ($data['numero_rate'] === 1) {
        $data['frequenza_rate'] = 'unica';
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['prima_scadenza'])) {
        $errors['prima_scadenza'] = 'Inserisci una data di scadenza valida.';
    }

    if ($data['promemoria_scadenza'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['promemoria_scadenza'])) {
        $errors['promemoria_scadenza'] = 'Data promemoria non valida.';
    }

    if (!$errors) {
        $payload = [
            'cliente_id' => $clienteId,
            'intestatario_nome' => $data['intestatario_nome'],
            'codice_fiscale' => $data['codice_fiscale'],
            'tipo_modello' => $data['tipo_modello'],
            'anno_riferimento' => $anno,
            'periodo_riferimento' => $data['periodo_riferimento'] ?: null,
            'importo_totale' => $importo,
            'numero_rate' => $data['numero_rate'],
            'frequenza_rate' => $data['frequenza_rate'],
            'prima_scadenza' => $data['prima_scadenza'],
            'stato' => $data['stato'],
            'promemoria_scadenza' => $data['promemoria_scadenza'] ?: null,
            'note' => $data['note'] ?: null,
        ];

        try {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $consulenzaId = $service->create($payload, $userId);

            if (!empty($_FILES['documento_firmato']['name'])) {
                $service->addDocument($consulenzaId, $_FILES['documento_firmato'], $userId, true);
            }

            add_flash('success', 'Consulenza fiscale creata correttamente.');
            header('Location: view.php?id=' . $consulenzaId);
            exit;
        } catch (Throwable $exception) {
            $errors['general'] = 'Impossibile creare la consulenza. Riprova o verifica i log.';
            error_log('Consulenza Fiscale create error: ' . $exception->getMessage());
        }
    }
}

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Nuova consulenza fiscale</h1>
                <p class="text-muted mb-0">Compila i dati necessari a predisporre i modelli F24/730 e la rateizzazione.</p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Elenco consulenze</a>
            </div>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger" role="alert"><?php echo sanitize_output($errors['general']); ?></div>
        <?php endif; ?>

        <form class="card ag-card" method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Dati pratica</h2>
            </div>
            <div class="card-body row g-4">
                <div class="col-md-6">
                    <label class="form-label" for="cliente_id">Cliente collegato</label>
                    <select class="form-select" id="cliente_id" name="cliente_id">
                        <option value="">Nessun cliente</option>
                        <?php foreach ($clients as $client): ?>
                            <?php
                                $labelParts = array_filter([
                                    $client['ragione_sociale'] ?? null,
                                    trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')) ?: null,
                                ]);
                                $label = $labelParts ? implode(' - ', $labelParts) : ('Cliente #' . (int) $client['id']);
                            ?>
                            <option value="<?php echo (int) $client['id']; ?>" <?php echo (string) $client['id'] === $data['cliente_id'] ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($errors['cliente_id'])): ?><div class="invalid-feedback d-block"><?php echo sanitize_output($errors['cliente_id']); ?></div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="intestatario_nome">Intestatario pratica *</label>
                    <input class="form-control" id="intestatario_nome" name="intestatario_nome" value="<?php echo sanitize_output($data['intestatario_nome']); ?>" required>
                    <?php if (!empty($errors['intestatario_nome'])): ?><div class="invalid-feedback d-block"><?php echo sanitize_output($errors['intestatario_nome']); ?></div><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="codice_fiscale">Codice fiscale *</label>
                    <input class="form-control" id="codice_fiscale" name="codice_fiscale" value="<?php echo sanitize_output($data['codice_fiscale']); ?>" maxlength="16" autocomplete="off" required>
                    <?php if (!empty($errors['codice_fiscale'])): ?><div class="invalid-feedback d-block"><?php echo sanitize_output($errors['codice_fiscale']); ?></div><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tipo_modello">Modello *</label>
                    <select class="form-select" id="tipo_modello" name="tipo_modello" required>
                        <?php foreach ($modelOptions as $key => $label): ?>
                            <option value="<?php echo sanitize_output($key); ?>" <?php echo $data['tipo_modello'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="stato">Stato *</label>
                    <select class="form-select" id="stato" name="stato" required>
                        <?php foreach ($statusOptions as $key => $label): ?>
                            <option value="<?php echo sanitize_output($key); ?>" <?php echo $data['stato'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="anno_riferimento">Anno riferimento *</label>
                    <input class="form-control" id="anno_riferimento" name="anno_riferimento" type="number" min="2000" max="<?php echo (int) date('Y') + 1; ?>" value="<?php echo sanitize_output((string) $data['anno_riferimento']); ?>" required>
                    <?php if (!empty($errors['anno_riferimento'])): ?><div class="invalid-feedback d-block"><?php echo sanitize_output($errors['anno_riferimento']); ?></div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="periodo_riferimento">Periodo (mese/quadrimestre)</label>
                    <input class="form-control" id="periodo_riferimento" name="periodo_riferimento" value="<?php echo sanitize_output($data['periodo_riferimento']); ?>" placeholder="Es. 1° trimestre">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="importo_totale">Importo totale *</label>
                    <input class="form-control" id="importo_totale" name="importo_totale" value="<?php echo sanitize_output($data['importo_totale']); ?>" placeholder="Es. 1.200,00" required>
                    <?php if (!empty($errors['importo_totale'])): ?><div class="invalid-feedback d-block"><?php echo sanitize_output($errors['importo_totale']); ?></div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="numero_rate">Numero rate *</label>
                    <input class="form-control" id="numero_rate" name="numero_rate" type="number" min="1" max="12" value="<?php echo (int) $data['numero_rate']; ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="frequenza_rate">Frequenza *</label>
                    <select class="form-select" id="frequenza_rate" name="frequenza_rate">
                        <?php foreach ($frequencyOptions as $key => $label): ?>
                            <option value="<?php echo sanitize_output($key); ?>" <?php echo $data['frequenza_rate'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="prima_scadenza">Prima scadenza *</label>
                    <input class="form-control" id="prima_scadenza" name="prima_scadenza" type="date" value="<?php echo sanitize_output($data['prima_scadenza']); ?>" required>
                    <?php if (!empty($errors['prima_scadenza'])): ?><div class="invalid-feedback d-block"><?php echo sanitize_output($errors['prima_scadenza']); ?></div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="promemoria_scadenza">Promemoria personalizzato</label>
                    <input class="form-control" id="promemoria_scadenza" name="promemoria_scadenza" type="date" value="<?php echo sanitize_output($data['promemoria_scadenza']); ?>">
                    <?php if (!empty($errors['promemoria_scadenza'])): ?><div class="invalid-feedback d-block"><?php echo sanitize_output($errors['promemoria_scadenza']); ?></div><?php endif; ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="note">Note operative</label>
                    <textarea class="form-control" id="note" name="note" rows="4" placeholder="Es. Documenti raccolti, deleghe, esiti call."><?php echo sanitize_output($data['note']); ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label" for="documento_firmato">Documento firmato (PDF/JPG/PNG, max 15 MB)</label>
                    <input class="form-control" id="documento_firmato" name="documento_firmato" type="file" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 d-flex justify-content-end gap-2">
                <a class="btn btn-outline-secondary" href="index.php">Annulla</a>
                <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva consulenza</button>
            </div>
        </form>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
