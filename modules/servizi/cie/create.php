<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuova prenotazione CIE';

$csrfToken = csrf_token();
$clients = cie_fetch_clients($pdo);
$statuses = cie_status_map();

$data = [
    'cliente_id' => null,
    'cittadino_nome' => '',
    'cittadino_cognome' => '',
    'cittadino_cf' => '',
    'cittadino_email' => '',
    'cittadino_telefono' => '',
    'data_nascita' => '',
    'luogo_nascita' => '',
    'residenza_indirizzo' => '',
    'residenza_cap' => '',
    'residenza_citta' => '',
    'residenza_provincia' => '',
    'comune_richiesta' => '',
    'disponibilita_data' => '',
    'disponibilita_fascia' => '',
    'note' => '',
    'stato' => 'nuova',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['cliente_id'] = isset($_POST['cliente_id']) && (int) $_POST['cliente_id'] > 0 ? (int) $_POST['cliente_id'] : null;
    $data['cittadino_nome'] = trim((string) ($_POST['cittadino_nome'] ?? ''));
    $data['cittadino_cognome'] = trim((string) ($_POST['cittadino_cognome'] ?? ''));
    $data['cittadino_cf'] = strtoupper(trim((string) ($_POST['cittadino_cf'] ?? '')));
    $data['cittadino_email'] = trim((string) ($_POST['cittadino_email'] ?? ''));
    $data['cittadino_telefono'] = trim((string) ($_POST['cittadino_telefono'] ?? ''));
    $data['data_nascita'] = (string) ($_POST['data_nascita'] ?? '');
    $data['luogo_nascita'] = trim((string) ($_POST['luogo_nascita'] ?? ''));
    $data['residenza_indirizzo'] = trim((string) ($_POST['residenza_indirizzo'] ?? ''));
    $data['residenza_cap'] = trim((string) ($_POST['residenza_cap'] ?? ''));
    $data['residenza_citta'] = trim((string) ($_POST['residenza_citta'] ?? ''));
    $data['residenza_provincia'] = strtoupper(trim((string) ($_POST['residenza_provincia'] ?? '')));
    $data['comune_richiesta'] = trim((string) ($_POST['comune_richiesta'] ?? ''));
    $data['disponibilita_data'] = (string) ($_POST['disponibilita_data'] ?? '');
    $data['disponibilita_fascia'] = trim((string) ($_POST['disponibilita_fascia'] ?? ''));
    $data['note'] = trim((string) ($_POST['note'] ?? ''));
    $data['stato'] = (string) ($_POST['stato'] ?? 'nuova');

    if ($data['cittadino_nome'] === '') {
        $errors[] = 'Inserisci il nome del cittadino.';
    }

    if ($data['cittadino_cognome'] === '') {
        $errors[] = 'Inserisci il cognome del cittadino.';
    }

    if ($data['comune_richiesta'] === '') {
        $errors[] = 'Indica il comune dove effettuare la richiesta.';
    }

    if ($data['cittadino_cf'] !== '' && !preg_match('/^[A-Z0-9]{11,16}$/', $data['cittadino_cf'])) {
        $errors[] = 'Codice fiscale non valido. Inserisci 11-16 caratteri alfanumerici.';
    }

    if ($data['cittadino_email'] !== '' && !filter_var($data['cittadino_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo email non valido.';
    }

    if ($data['data_nascita'] !== '' && !DateTime::createFromFormat('Y-m-d', $data['data_nascita'])) {
        $errors[] = 'Data di nascita non valida.';
    }

    if ($data['disponibilita_data'] !== '' && !DateTime::createFromFormat('Y-m-d', $data['disponibilita_data'])) {
        $errors[] = 'Data di disponibilità non valida.';
    }

    if ($data['residenza_provincia'] !== '' && strlen($data['residenza_provincia']) > 5) {
        $errors[] = 'La sigla provincia può contenere al massimo 5 caratteri.';
    }

    if (!in_array($data['stato'], cie_allowed_statuses(), true)) {
        $data['stato'] = 'nuova';
    }

    if (!$errors) {
        try {
            $payload = $data;
            $payload['data_nascita'] = $payload['data_nascita'] !== '' ? $payload['data_nascita'] : null;
            $payload['disponibilita_data'] = $payload['disponibilita_data'] !== '' ? $payload['disponibilita_data'] : null;

            $newId = cie_create($pdo, $payload, $_FILES);
            add_flash('success', 'Prenotazione CIE creata correttamente.');
            header('Location: view.php?id=' . $newId);
            exit;
        } catch (Throwable $exception) {
            $errors[] = 'Impossibile creare la prenotazione: ' . $exception->getMessage();
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
                <h1 class="h3 mb-1">Nuova prenotazione CIE</h1>
                <p class="text-muted mb-0">Accompagna il cittadino nella raccolta dei dati e avvia la procedura sul portale ministeriale.</p>
            </div>
            <div>
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alla dashboard</a>
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
                                        <option value="<?php echo sanitize_output((string) $clientId); ?>" data-client='<?php echo sanitize_output(json_encode([
                                            'nome' => $client['nome'] ?? '',
                                            'cognome' => $client['cognome'] ?? '',
                                            'cf' => $client['cf_piva'] ?? '',
                                            'email' => $client['email'] ?? '',
                                            'telefono' => $client['telefono'] ?? '',
                                            'indirizzo' => $client['indirizzo'] ?? '',
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>' <?php echo $data['cliente_id'] === $clientId ? 'selected' : ''; ?>>
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
                                <input class="form-control" id="cittadino_nome" name="cittadino_nome" value="<?php echo sanitize_output($data['cittadino_nome']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_cognome">Cognome</label>
                                <input class="form-control" id="cittadino_cognome" name="cittadino_cognome" value="<?php echo sanitize_output($data['cittadino_cognome']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_cf">Codice fiscale</label>
                                <input class="form-control" id="cittadino_cf" name="cittadino_cf" value="<?php echo sanitize_output($data['cittadino_cf']); ?>" maxlength="16" placeholder="Facoltativo">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="data_nascita">Data di nascita</label>
                                <input class="form-control" type="date" id="data_nascita" name="data_nascita" value="<?php echo sanitize_output($data['data_nascita']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="luogo_nascita">Luogo di nascita</label>
                                <input class="form-control" id="luogo_nascita" name="luogo_nascita" value="<?php echo sanitize_output($data['luogo_nascita']); ?>" placeholder="Comune di nascita" data-istat-comune="true" data-istat-min-chars="3">
                                <small class="text-muted">Suggerimenti ISTAT disponibili digitando almeno 3 caratteri.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_email">Email</label>
                                <input class="form-control" type="email" id="cittadino_email" name="cittadino_email" value="<?php echo sanitize_output($data['cittadino_email']); ?>" placeholder="Facoltativa per notifiche">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="cittadino_telefono">Telefono</label>
                                <input class="form-control" id="cittadino_telefono" name="cittadino_telefono" value="<?php echo sanitize_output($data['cittadino_telefono']); ?>" placeholder="Facoltativo, utile per WhatsApp">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Residenza</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="residenza_indirizzo">Indirizzo</label>
                                <input class="form-control" id="residenza_indirizzo" name="residenza_indirizzo" value="<?php echo sanitize_output($data['residenza_indirizzo']); ?>" placeholder="Via/Piazza e civico">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="residenza_cap">CAP</label>
                                <input class="form-control" id="residenza_cap" name="residenza_cap" value="<?php echo sanitize_output($data['residenza_cap']); ?>" maxlength="10">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="residenza_citta">Comune</label>
                                <input class="form-control" id="residenza_citta" name="residenza_citta" value="<?php echo sanitize_output($data['residenza_citta']); ?>" data-istat-comune="true" data-istat-province-target="#residenza_provincia" data-istat-cap-target="#residenza_cap">
                                <small class="text-muted">Scegli il comune dal dropdown ISTAT per compilare automaticamente la provincia.</small>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="residenza_provincia">Provincia</label>
                                <input class="form-control" id="residenza_provincia" name="residenza_provincia" value="<?php echo sanitize_output($data['residenza_provincia']); ?>" maxlength="5" placeholder="ES. RM">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Richiesta e disponibilità</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="comune_richiesta">Comune di richiesta</label>
                                <input class="form-control" id="comune_richiesta" name="comune_richiesta" value="<?php echo sanitize_output($data['comune_richiesta']); ?>" required data-istat-comune="true">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="disponibilita_data">Data preferita</label>
                                <input class="form-control" type="date" id="disponibilita_data" name="disponibilita_data" value="<?php echo sanitize_output($data['disponibilita_data']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="disponibilita_fascia">Fascia oraria</label>
                                <input class="form-control" id="disponibilita_fascia" name="disponibilita_fascia" value="<?php echo sanitize_output($data['disponibilita_fascia']); ?>" placeholder="Es. Mattina/Pomeriggio">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h2 class="h5 mb-3">Documentazione</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="documento_identita">Documento di riconoscimento</label>
                                <input class="form-control" type="file" id="documento_identita" name="documento_identita" accept=".pdf,image/jpeg,image/png">
                                <small class="text-muted">PDF, JPG o PNG fino a 10 MB.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="foto_cittadino">Foto cittadino</label>
                                <input class="form-control" type="file" id="foto_cittadino" name="foto_cittadino" accept="image/jpeg,image/png">
                                <small class="text-muted">Foto tessera aggiornata, massimo 5 MB.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="ricevuta">Ricevuta/Conferma portale</label>
                                <input class="form-control" type="file" id="ricevuta" name="ricevuta" accept=".pdf">
                                <small class="text-muted">Carica la ricevuta generata dal portale, fino a 15 MB.</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="note">Note interne</label>
                        <textarea class="form-control" id="note" name="note" rows="4" placeholder="Annota dettagli utili per l'operatore."><?php echo sanitize_output($data['note']); ?></textarea>
                    </div>

                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-light" href="index.php">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva prenotazione</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
        <?php
        $istatDatasetUrl = asset('customer-portal/assets/data/comuni.json');
        ?>
        <script>
        window.CIEIstatLookupConfig = {
            datasetUrl: '<?php echo sanitize_output($istatDatasetUrl); ?>',
            fallbackUrl: 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json',
            maxResults: 12,
            minChars: 2
        };
        </script>
        <script src="<?php echo asset('assets/js/cie-istat-lookup.js'); ?>"></script>
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
