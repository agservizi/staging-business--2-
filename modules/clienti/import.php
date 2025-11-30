<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore');
$pageTitle = 'Importa clienti';

$csrfToken = csrf_token();
$maxUploadBytes = 5 * 1024 * 1024; // 5 MB
$importSummary = null;
$formErrors = [];

function clienti_import_normalize_label(string $label): string
{
    $label = trim($label);
    $label = ltrim($label, "\xEF\xBB\xBF");
    if ($label === '') {
        return '';
    }

    $label = mb_strtolower($label, 'UTF-8');
    $label = strtr($label, [
        'à' => 'a',
        'è' => 'e',
        'é' => 'e',
        'ì' => 'i',
        'ò' => 'o',
        'ù' => 'u',
    ]);

    return preg_replace('/[^a-z0-9]+/u', '', $label) ?? '';
}

function clienti_import_map_label(string $normalized): ?string
{
    if ($normalized === '') {
        return null;
    }

    static $map = [
        'ragionesociale' => 'ragione_sociale',
        'azienda' => 'ragione_sociale',
        'societa' => 'ragione_sociale',
        'company' => 'ragione_sociale',
        'cliente' => 'ragione_sociale',
        'nome' => 'nome',
        'referentenome' => 'nome',
        'contatto' => 'nome',
    'firstname' => 'nome',
    'first' => 'nome',
        'cognome' => 'cognome',
        'referentecognome' => 'cognome',
        'surname' => 'cognome',
    'lastname' => 'cognome',
    'last' => 'cognome',
        'cf' => 'cf_piva',
        'codicefiscale' => 'cf_piva',
        'codicefiscalepartitaiva' => 'cf_piva',
        'partitaiva' => 'cf_piva',
        'piva' => 'cf_piva',
        'cfpiva' => 'cf_piva',
        'vatnumber' => 'cf_piva',
        'email' => 'email',
        'mail' => 'email',
        'pec' => 'email',
        'telefono' => 'telefono',
        'tel' => 'telefono',
        'cellulare' => 'telefono',
        'mobile' => 'telefono',
        'phone' => 'telefono',
        'indirizzo' => 'indirizzo',
        'address' => 'indirizzo',
        'via' => 'indirizzo',
        'note' => 'note',
        'annotazioni' => 'note',
        'noteinterne' => 'note',
        'cap' => '__cap',
        'zipcode' => '__cap',
        'zip' => '__cap',
        'citta' => '__city',
        'città' => '__city',
        'comune' => '__city',
        'localita' => '__city',
        'city' => '__city',
        'provincia' => '__province',
        'siglaprovincia' => '__province',
        'province' => '__province',
        'stato' => '__country',
        'paese' => '__country',
        'nazione' => '__country',
        'country' => '__country',
    ];

    return $map[$normalized] ?? null;
}

function clienti_import_limit_length(string $value, int $max): string
{
    if ($value === '') {
        return '';
    }

    return mb_strlen($value, 'UTF-8') > $max
        ? mb_substr($value, 0, $max, 'UTF-8')
        : $value;
}

function clienti_import_detect_delimiter(string $filePath): string
{
    $candidates = [',', ';', "\t", '|'];
    $line = '';
    $handle = @fopen($filePath, 'rb');
    if ($handle !== false) {
        $line = fgets($handle, 4096);
        fclose($handle);
    }

    $bestDelimiter = ';';
    $bestCount = 0;
    foreach ($candidates as $delimiter) {
        $count = substr_count((string) $line, $delimiter);
        if ($count > $bestCount) {
            $bestDelimiter = $delimiter;
            $bestCount = $count;
        }
    }

    return $bestCount === 0 ? ';' : $bestDelimiter;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $file = $_FILES['csv_file'] ?? null;
    if (!is_array($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $formErrors[] = 'Seleziona un file CSV da importare.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $formErrors[] = 'Errore durante il caricamento del file (codice ' . (int) $file['error'] . ').';
    } elseif ((int) $file['size'] === 0) {
        $formErrors[] = 'Il file selezionato è vuoto.';
    } elseif ((int) $file['size'] > $maxUploadBytes) {
        $formErrors[] = 'Il file supera la dimensione massima consentita di 5 MB.';
    } else {
        set_time_limit(0);

        $delimiter = clienti_import_detect_delimiter($file['tmp_name']);
        $csv = new SplFileObject($file['tmp_name'], 'rb');
        $csvFlags = SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY;
        if (defined('SplFileObject::DROP_NEW_LINE')) {
            $csvFlags |= constant('SplFileObject::DROP_NEW_LINE');
        }
        $csv->setFlags($csvFlags);
        $csv->setCsvControl($delimiter, "\"", "\\");

        $headerRow = null;
        while (!$csv->eof()) {
            $row = $csv->fgetcsv();
            if ($row === false) {
                break;
            }
            if ($row === [null]) {
                continue;
            }
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $headerRow = $row;
            break;
        }

        if ($headerRow === null) {
            $formErrors[] = 'Impossibile leggere l\'intestazione del file CSV.';
        } else {
            $headerRow[0] = ltrim((string) ($headerRow[0] ?? ''), "\xEF\xBB\xBF");

            $headerTargets = [];
            $headerSummary = [];
            $recognizedColumns = 0;

            foreach ($headerRow as $index => $label) {
                $normalized = clienti_import_normalize_label((string) $label);
                $mapped = clienti_import_map_label($normalized);
                if ($mapped !== null) {
                    $recognizedColumns++;
                    $headerSummary[] = [
                        'original' => (string) $label,
                        'mapped' => $mapped,
                    ];
                }
                $headerTargets[(int) $index] = $mapped;
            }

            $hasCompany = in_array('ragione_sociale', $headerTargets, true);
            $hasName = in_array('nome', $headerTargets, true);
            $hasSurname = in_array('cognome', $headerTargets, true);

            if ($recognizedColumns === 0) {
                $formErrors[] = 'Nessuna colonna dell\'intestazione è stata riconosciuta.';
            } elseif (!$hasCompany && (!$hasName || !$hasSurname)) {
                $formErrors[] = 'L\'intestazione deve contenere la colonna "Ragione sociale" oppure entrambe le colonne "Nome" e "Cognome".';
            } else {
                $processed = 0;
                $created = 0;
                $updated = 0;
                $unchanged = 0;
                $skipped = 0;
                $rowErrors = [];
                $activity = [];
                $lineNumber = 1; // include header

                $selectByEmail = $pdo->prepare('SELECT id, ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note FROM clienti WHERE email = :email LIMIT 1');
                $selectByCf = $pdo->prepare('SELECT id, ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note FROM clienti WHERE cf_piva = :cf_piva LIMIT 1');
                $insertStmt = $pdo->prepare('INSERT INTO clienti (ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note) VALUES (:ragione_sociale, :nome, :cognome, :cf_piva, :email, :telefono, :indirizzo, :note)');
                $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');

                try {
                    $pdo->beginTransaction();

                    while (!$csv->eof()) {
                        $row = $csv->fgetcsv();
                        if ($row === false) {
                            break;
                        }

                        $lineNumber++;

                        if ($row === [null]) {
                            continue;
                        }
                        if (count($row) === 1 && trim((string) $row[0]) === '') {
                            continue;
                        }

                        $rowData = [
                            'ragione_sociale' => '',
                            'nome' => '',
                            'cognome' => '',
                            'cf_piva' => '',
                            'email' => '',
                            'telefono' => '',
                            'indirizzo' => '',
                            'note' => '',
                        ];
                        $extras = [
                            'cap' => '',
                            'city' => '',
                            'province' => '',
                            'country' => '',
                        ];

                        $nonEmptyOriginal = false;
                        $hasRelevantData = false;

                        foreach ($headerTargets as $index => $target) {
                            $value = isset($row[$index]) ? trim((string) $row[$index]) : '';
                            if ($index === 0) {
                                $value = ltrim($value, "\xEF\xBB\xBF");
                            }
                            if ($value !== '') {
                                $nonEmptyOriginal = true;
                            }
                            if ($target === null || $value === '') {
                                continue;
                            }

                            $hasRelevantData = true;
                            switch ($target) {
                                case 'ragione_sociale':
                                    $rowData['ragione_sociale'] = clienti_import_limit_length($value, 160);
                                    break;
                                case 'nome':
                                    $rowData['nome'] = clienti_import_limit_length($value, 80);
                                    break;
                                case 'cognome':
                                    $rowData['cognome'] = clienti_import_limit_length($value, 80);
                                    break;
                                case 'cf_piva':
                                    $rowData['cf_piva'] = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');
                                    break;
                                case 'email':
                                    $rowData['email'] = clienti_import_limit_length($value, 160);
                                    break;
                                case 'telefono':
                                    $rowData['telefono'] = clienti_import_limit_length($value, 40);
                                    break;
                                case 'indirizzo':
                                    $rowData['indirizzo'] = clienti_import_limit_length($value, 255);
                                    break;
                                case 'note':
                                    $rowData['note'] = clienti_import_limit_length($value, 2000);
                                    break;
                                case '__cap':
                                    $extras['cap'] = clienti_import_limit_length($value, 16);
                                    break;
                                case '__city':
                                    $extras['city'] = clienti_import_limit_length($value, 80);
                                    break;
                                case '__province':
                                    $extras['province'] = clienti_import_limit_length(strtoupper($value), 40);
                                    break;
                                case '__country':
                                    $extras['country'] = clienti_import_limit_length($value, 80);
                                    break;
                            }
                        }

                        if (!$nonEmptyOriginal) {
                            continue;
                        }
                        if (!$hasRelevantData) {
                            $skipped++;
                            $rowErrors[] = 'Riga ' . $lineNumber . ': nessun campo riconosciuto.';
                            continue;
                        }

                        $addressParts = [];
                        if ($rowData['indirizzo'] !== '') {
                            $addressParts[] = $rowData['indirizzo'];
                        }
                        if ($extras['cap'] !== '') {
                            $addressParts[] = $extras['cap'];
                        }
                        if ($extras['city'] !== '') {
                            $addressParts[] = $extras['city'];
                        }
                        if ($extras['province'] !== '') {
                            $addressParts[] = $extras['province'];
                        }
                        if ($extras['country'] !== '') {
                            $addressParts[] = $extras['country'];
                        }
                        $rowData['indirizzo'] = clienti_import_limit_length(implode(', ', array_filter($addressParts, static fn($part) => $part !== '')), 255);

                        $rowValidationErrors = [];
                        if ($rowData['ragione_sociale'] === '' && ($rowData['nome'] === '' || $rowData['cognome'] === '')) {
                            $rowValidationErrors[] = 'mancano nome e cognome';
                        }
                        if ($rowData['email'] !== '' && !filter_var($rowData['email'], FILTER_VALIDATE_EMAIL)) {
                            $rowValidationErrors[] = 'email non valida';
                        }
                        if ($rowData['cf_piva'] !== '' && !preg_match('/^[A-Z0-9]{11,16}$/', $rowData['cf_piva'])) {
                            $rowValidationErrors[] = 'CF/P.IVA non valida';
                        }
                        if ($rowData['telefono'] !== '' && !preg_match('/^[0-9+()\\s-]{6,}$/', $rowData['telefono'])) {
                            $rowValidationErrors[] = 'telefono non valido';
                        }

                        if ($rowValidationErrors) {
                            $skipped++;
                            $rowErrors[] = 'Riga ' . $lineNumber . ': ' . implode('; ', $rowValidationErrors) . '.';
                            continue;
                        }

                        if ($rowData['ragione_sociale'] !== '' && $rowData['nome'] === '' && $rowData['cognome'] === '') {
                            $rowData['nome'] = 'N/D';
                            $rowData['cognome'] = 'N/D';
                        }

                        $processed++;
                        $existing = null;

                        if ($rowData['email'] !== '') {
                            $selectByEmail->execute([':email' => $rowData['email']]);
                            $existing = $selectByEmail->fetch();
                            $selectByEmail->closeCursor();
                        }
                        if ($existing === false || $existing === null) {
                            $existing = null;
                        }

                        if ($existing === null && $rowData['cf_piva'] !== '') {
                            $selectByCf->execute([':cf_piva' => $rowData['cf_piva']]);
                            $existing = $selectByCf->fetch();
                            $selectByCf->closeCursor();
                            if ($existing === false) {
                                $existing = null;
                            }
                        }

                        if ($existing !== null) {
                            $changes = [];
                            $params = [':id' => (int) $existing['id']];
                            foreach (['ragione_sociale', 'nome', 'cognome', 'cf_piva', 'email', 'telefono', 'indirizzo', 'note'] as $field) {
                                $value = $rowData[$field];
                                if ($value === '' || $value === null) {
                                    continue;
                                }
                                $current = (string) ($existing[$field] ?? '');
                                if ($value !== $current) {
                                    $changes[$field] = $value;
                                    $params[':' . $field] = $value;
                                }
                            }

                            if ($changes) {
                                $setParts = [];
                                foreach (array_keys($changes) as $field) {
                                    $setParts[] = $field . ' = :' . $field;
                                }
                                $setParts[] = 'updated_at = NOW()';
                                $updateSql = 'UPDATE clienti SET ' . implode(', ', $setParts) . ' WHERE id = :id';
                                $updateStmt = $pdo->prepare($updateSql);
                                $updateStmt->execute($params);

                                $updated++;
                                $labelSource = $rowData['ragione_sociale'] !== ''
                                    ? $rowData['ragione_sociale']
                                    : trim($rowData['cognome'] . ' ' . $rowData['nome']);
                                if ($labelSource === '') {
                                    $labelSource = 'Cliente';
                                }
                                $activity[] = 'Aggiornato #' . (int) $existing['id'] . ' - ' . $labelSource;
                                $logStmt->execute([
                                    ':user_id' => $_SESSION['user_id'],
                                    ':modulo' => 'Clienti',
                                    ':azione' => 'Import CSV - aggiornamento cliente',
                                    ':dettagli' => sprintf('%s (#%d) da riga %d', $labelSource, (int) $existing['id'], $lineNumber),
                                ]);
                            } else {
                                $unchanged++;
                            }

                            continue;
                        }

                        $insertStmt->execute([
                            ':ragione_sociale' => $rowData['ragione_sociale'],
                            ':nome' => $rowData['nome'] !== '' ? $rowData['nome'] : 'N/D',
                            ':cognome' => $rowData['cognome'] !== '' ? $rowData['cognome'] : 'N/D',
                            ':cf_piva' => $rowData['cf_piva'] !== '' ? $rowData['cf_piva'] : null,
                            ':email' => $rowData['email'] !== '' ? $rowData['email'] : null,
                            ':telefono' => $rowData['telefono'] !== '' ? $rowData['telefono'] : null,
                            ':indirizzo' => $rowData['indirizzo'] !== '' ? $rowData['indirizzo'] : null,
                            ':note' => $rowData['note'] !== '' ? $rowData['note'] : null,
                        ]);
                        $newId = (int) $pdo->lastInsertId();
                        $created++;

                        $labelSource = $rowData['ragione_sociale'] !== ''
                            ? $rowData['ragione_sociale']
                            : trim($rowData['cognome'] . ' ' . $rowData['nome']);
                        if ($labelSource === '') {
                            $labelSource = 'Cliente';
                        }
                        $activity[] = 'Creato #' . $newId . ' - ' . $labelSource;
                        $logStmt->execute([
                            ':user_id' => $_SESSION['user_id'],
                            ':modulo' => 'Clienti',
                            ':azione' => 'Import CSV - nuovo cliente',
                            ':dettagli' => sprintf('%s (#%d) da riga %d', $labelSource, $newId, $lineNumber),
                        ]);
                    }

                    $pdo->commit();

                    $displayedErrors = array_slice($rowErrors, 0, 20);
                    $importSummary = [
                        'file_name' => (string) $file['name'],
                        'processed' => $processed,
                        'created' => $created,
                        'updated' => $updated,
                        'unchanged' => $unchanged,
                        'skipped' => $skipped,
                        'errors' => $displayedErrors,
                        'errors_truncated' => count($displayedErrors) < count($rowErrors),
                        'activity' => array_slice($activity, 0, 10),
                        'header' => $headerSummary,
                    ];

                    if ($created > 0 || $updated > 0) {
                        add_flash('success', sprintf('Import completato: %d creati, %d aggiornati, %d invariati.', $created, $updated, $unchanged));
                    } else {
                        add_flash('info', 'Import completato: nessuna variazione rispetto ai dati esistenti.');
                    }
                    if ($skipped > 0) {
                        add_flash('warning', sprintf('%d righe non sono state importate. Consulta i dettagli qui sotto.', $skipped));
                    }
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Import clienti CSV fallito: ' . $exception->getMessage());
                    $formErrors[] = 'Errore imprevisto durante l\'importazione. Nessuna modifica è stata salvata.';
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Importa clienti</h1>
                <p class="text-muted mb-0">Carica un file CSV per creare o aggiornare automaticamente le anagrafiche clienti.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alla lista</a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Carica file CSV</h2>
                <?php if ($formErrors): ?>
                    <div class="alert alert-warning">
                        <?php foreach ($formErrors as $message): ?>
                            <div><?php echo sanitize_output($message); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="stack-lg" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div>
                        <label class="form-label" for="csv_file">File CSV</label>
                        <input class="form-control" id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
                        <small class="text-muted">Dimensione massima: 5 MB. È supportata automaticamente la separazione con virgola o punto e virgola.</small>
                    </div>
                    <div>
                        <p class="text-muted mb-2">Colonne riconosciute: <code>Ragione sociale</code>, <code>Nome</code>, <code>Cognome</code>, <code>CF/P.IVA</code>, <code>Email</code>, <code>Telefono</code>, <code>Indirizzo</code>, <code>CAP</code>, <code>Comune</code>, <code>Provincia</code>, <code>Stato</code>, <code>Note</code>. L'ordine delle colonne non è importante.</p>
                        <p class="text-muted mb-0">Le intestazioni vengono mappate in modo case-insensitive; eventuali colonne aggiuntive verranno ignorate.</p>
                    </div>
                    <div class="stack-sm justify-content-end">
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-file-import me-2"></i>Avvia import</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($importSummary): ?>
            <div class="card ag-card">
                <div class="card-header bg-transparent border-0">
                    <h2 class="h5 mb-0">Risultato import</h2>
                </div>
                <div class="card-body">
                    <p class="mb-4"><strong>File:</strong> <?php echo sanitize_output($importSummary['file_name']); ?></p>
                    <dl class="row g-3 mb-0">
                        <dt class="col-sm-5 col-md-4 col-lg-3">Righe elaborate</dt>
                        <dd class="col-sm-7 col-md-8 col-lg-3"><?php echo (int) $importSummary['processed']; ?></dd>
                        <dt class="col-sm-5 col-md-4 col-lg-3">Clienti creati</dt>
                        <dd class="col-sm-7 col-md-8 col-lg-3"><?php echo (int) $importSummary['created']; ?></dd>
                        <dt class="col-sm-5 col-md-4 col-lg-3">Clienti aggiornati</dt>
                        <dd class="col-sm-7 col-md-8 col-lg-3"><?php echo (int) $importSummary['updated']; ?></dd>
                        <dt class="col-sm-5 col-md-4 col-lg-3">Clienti invariati</dt>
                        <dd class="col-sm-7 col-md-8 col-lg-3"><?php echo (int) $importSummary['unchanged']; ?></dd>
                        <dt class="col-sm-5 col-md-4 col-lg-3">Righe ignorate</dt>
                        <dd class="col-sm-7 col-md-8 col-lg-3"><?php echo (int) $importSummary['skipped']; ?></dd>
                    </dl>

                    <?php if (!empty($importSummary['header'])): ?>
                        <hr class="my-4">
                        <h3 class="h6 mb-3">Colonne riconosciute</h3>
                        <ul class="mb-0">
                            <?php foreach ($importSummary['header'] as $column): ?>
                                <li><code><?php echo sanitize_output($column['original']); ?></code> → <?php echo sanitize_output($column['mapped']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($importSummary['activity'])): ?>
                        <hr class="my-4">
                        <h3 class="h6 mb-3">Operazioni recenti</h3>
                        <ul class="mb-0">
                            <?php foreach ($importSummary['activity'] as $message): ?>
                                <li><?php echo sanitize_output($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($importSummary['errors'])): ?>
                        <hr class="my-4">
                        <div class="alert alert-warning mb-0">
                            <p class="fw-semibold mb-2">Righe non importate</p>
                            <ul class="mb-0 small">
                                <?php foreach ($importSummary['errors'] as $message): ?>
                                    <li><?php echo sanitize_output($message); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (!empty($importSummary['errors_truncated'])): ?>
                                <p class="small text-muted mb-0 mt-2">Sono state omesse ulteriori righe con errori per brevità.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
