<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';

require_role('Admin', 'Operatore', 'Manager', 'Patronato');

$isPatronatoUser = current_user_can('Patronato');
$requestedTitleChoiceInput = trim((string) ($_GET['title_choice'] ?? ($_POST['title_choice'] ?? '')));
$isPatronatoContext = $isPatronatoUser || strcasecmp($requestedTitleChoiceInput, 'Patronato') === 0;

$pageTitle = $isPatronatoContext ? 'Nuovo appuntamento patronato' : 'Nuovo appuntamento';
$pageHeading = $pageTitle;
$pageSubtitle = $isPatronatoContext
    ? 'Pianifica un appuntamento per lo sportello patronato, collega la pratica e invia promemoria mirati.'
    : 'Pianifica un nuovo appuntamento, gestisci responsabile e promemoria per il cliente.';
$backUrl = $isPatronatoContext ? '../caf-patronato/index.php' : 'index.php';
$backLabel = $isPatronatoContext ? 'Torna alle pratiche patronato' : 'Ritorna agli appuntamenti';
$successRedirect = $isPatronatoContext ? '../caf-patronato/index.php?created=1' : 'index.php?created=1';

$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll() : [];
$titleOptions = ['Apertura SPID', 'Registrazione PEC', 'Richiesta Firma Digitale/CNS'];
$serviceTypes = get_appointment_types($pdo);
if (!$serviceTypes) {
    $serviceTypes = \App\Services\SettingsService::defaultAppointmentTypes();
}
if ($isPatronatoContext) {
    $titleOptions = ['Patronato'];
}
if ($isPatronatoContext) {
    $serviceTypes = ['Patronato'];
}
$statusConfig = get_appointment_status_config($pdo);
$statuses = $statusConfig['available'];
if (!$statuses) {
    $statuses = ['Programmato'];
}
$activeStatuses = $statusConfig['active'] ?: $statuses;
$confirmationStatus = $statusConfig['confirmation'] !== '' ? $statusConfig['confirmation'] : ($statuses[0] ?? '');
$responsabileStmt = $pdo->query("SELECT username, nome, cognome FROM users WHERE ruolo IN ('Admin', 'Manager', 'Operatore') ORDER BY cognome, nome, username");
$responsabileOptions = [];
if ($responsabileStmt) {
    $responsabileRows = $responsabileStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($responsabileRows as $row) {
        $fullName = trim(((string) ($row['nome'] ?? '')) . ' ' . ((string) ($row['cognome'] ?? '')));
        $label = $fullName !== '' ? $fullName : (string) ($row['username'] ?? '');
        if ($label !== '' && !in_array($label, $responsabileOptions, true)) {
            $responsabileOptions[] = $label;
        }
    }
}
if (!$responsabileOptions) {
    $responsabileOptions = ['Carmine', 'Valentina'];
}

$defaultLocation = (string) (env('APPOINTMENT_DEFAULT_LOCATION', 'Via Plinio il Vecchio 72 Castellammare di Stabia') ?: 'Via Plinio il Vecchio 72 Castellammare di Stabia');

$data = [
    'cliente_id' => '',
    'titolo' => $titleOptions[0],
    'tipo_servizio' => $serviceTypes[0] ?? '',
    'responsabile' => '',
    'luogo' => $defaultLocation,
    'luogo_modalita' => 'agenzia',
    'data_inizio' => date('Y-m-d\\TH:i'),
    'data_fine' => '',
    'stato' => $statuses[0] ?? 'Programmato',
    'note' => '',
];
$titleChoice = $titleOptions[0];
$customTitle = '';
$errors = [];
$csrfToken = csrf_token();

$prefilledClientId = isset($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : 0;
if ($prefilledClientId > 0) {
    $data['cliente_id'] = (string) $prefilledClientId;
}

if (!$isPatronatoContext) {
    $presetTitleChoice = trim((string) ($_GET['title_choice'] ?? ''));
    if ($presetTitleChoice !== '' && in_array($presetTitleChoice, $titleOptions, true)) {
        $titleChoice = $presetTitleChoice;
        $data['titolo'] = $presetTitleChoice;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    foreach ($data as $field => $_) {
    $data[$field] = trim($_POST[$field] ?? '');
    }

    $data['luogo_modalita'] = $data['luogo_modalita'] === 'domicilio' ? 'domicilio' : 'agenzia';
    if ($data['luogo_modalita'] === 'domicilio') {
        if ($data['luogo'] === '') {
            $errors[] = 'Inserisci l\'indirizzo del domicilio del cliente.';
        }
    } else {
        $data['luogo'] = $defaultLocation;
    }

    if ($data['responsabile'] !== '' && !in_array($data['responsabile'], $responsabileOptions, true)) {
        $data['responsabile'] = trim($data['responsabile']);
    }

    if ($isPatronatoContext) {
        $titleChoice = 'Patronato';
        $data['titolo'] = 'Patronato';
        $customTitle = '';
    } else {
        $titleChoice = trim($_POST['title_choice'] ?? $titleOptions[0]);
        if (!in_array($titleChoice, $titleOptions, true) && $titleChoice !== '__custom') {
            $titleChoice = $titleOptions[0];
        }
        if ($titleChoice === '__custom') {
            $customTitle = trim($_POST['custom_title'] ?? '');
            $data['titolo'] = $customTitle;
            if ($data['titolo'] === '') {
                $errors[] = 'Inserisci un titolo personalizzato.';
            }
        } else {
            $data['titolo'] = $titleChoice;
        }
    }

    if ((int) $data['cliente_id'] <= 0) {
        $errors[] = 'Seleziona un cliente valido.';
    }
    if ($data['titolo'] === '' && $titleChoice !== '__custom') {
        $errors[] = 'Seleziona un titolo per l\'appuntamento.';
    }
    if ($isPatronatoContext) {
        $data['tipo_servizio'] = $serviceTypes[0] ?? 'Patronato';
    } elseif (!in_array($data['tipo_servizio'], $serviceTypes, true)) {
        $errors[] = 'Tipo di servizio non valido.';
    }
    if (!in_array($data['stato'], $statuses, true)) {
        $errors[] = 'Stato selezionato non valido.';
    }

    $start = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $data['data_inizio']) ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $data['data_inizio']);
    if (!$start) {
        $errors[] = 'Data e ora di inizio non valide.';
    }

    $end = null;
    if ($data['data_fine'] !== '') {
        $end = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $data['data_fine']) ?: DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $data['data_fine']);
        if (!$end) {
            $errors[] = 'Data e ora di fine non valide.';
        } elseif ($start && $end < $start) {
            $errors[] = 'La data di fine non può precedere l\'inizio.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO servizi_appuntamenti (cliente_id, titolo, tipo_servizio, responsabile, luogo, data_inizio, data_fine, stato, note) VALUES (:cliente_id, :titolo, :tipo_servizio, :responsabile, :luogo, :data_inizio, :data_fine, :stato, :note)');
        $stmt->execute([
            ':cliente_id' => (int) $data['cliente_id'],
            ':titolo' => $data['titolo'],
            ':tipo_servizio' => $data['tipo_servizio'],
            ':responsabile' => $data['responsabile'] !== '' ? $data['responsabile'] : null,
            ':luogo' => $data['luogo'] !== '' ? $data['luogo'] : null,
            ':data_inizio' => $start->format('Y-m-d H:i:s'),
            ':data_fine' => $end ? $end->format('Y-m-d H:i:s') : null,
            ':stato' => $data['stato'],
            ':note' => $data['note'] !== '' ? $data['note'] : null,
        ]);

        $appointmentId = (int) $pdo->lastInsertId();

        $calendarService = new \App\Services\GoogleCalendarService();
    if ($calendarService->isEnabled() && $confirmationStatus !== '' && strcasecmp($data['stato'], $confirmationStatus) === 0) {
            $appointmentStmt = $pdo->prepare('SELECT sa.*, c.email AS cliente_email, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale AS cliente_ragione_sociale FROM servizi_appuntamenti sa LEFT JOIN clienti c ON c.id = sa.cliente_id WHERE sa.id = :id LIMIT 1');
            $appointmentStmt->execute([':id' => $appointmentId]);
            $appointment = $appointmentStmt->fetch();

            if ($appointment) {
                try {
                    $syncResult = $calendarService->syncAppointment($appointment);
                    $updateCalendar = $pdo->prepare('UPDATE servizi_appuntamenti SET google_event_id = :event_id, google_event_synced_at = :synced_at, google_event_sync_error = NULL WHERE id = :id');
                    $updateCalendar->execute([
                        ':event_id' => $syncResult['eventId'],
                        ':synced_at' => $syncResult['syncedAt']->format('Y-m-d H:i:s'),
                        ':id' => $appointmentId,
                    ]);
                } catch (\Throwable $calendarException) {
                    $errorMessage = substr($calendarException->getMessage(), 0, 240);
                    $errorUpdate = $pdo->prepare('UPDATE servizi_appuntamenti SET google_event_sync_error = :error WHERE id = :id');
                    $errorUpdate->execute([
                        ':error' => $errorMessage,
                        ':id' => $appointmentId,
                    ]);
                    add_flash('warning', 'Appuntamento creato ma sincronizzazione con Google Calendar non riuscita: ' . $errorMessage);
                }
            }
        }

        $clientStmt = $pdo->prepare('SELECT nome, cognome, email FROM clienti WHERE id = :id LIMIT 1');
        $clientStmt->execute([':id' => (int) $data['cliente_id']]);
        $client = $clientStmt->fetch();

        if ($client && isset($client['email']) && filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
            $clientName = trim((string) (($client['nome'] ?? '') . ' ' . ($client['cognome'] ?? '')));
            if ($clientName === '') {
                $clientName = 'Cliente';
            }

            $startText = format_datetime_locale($start->format('Y-m-d H:i:s'));
            $endText = $end ? format_datetime_locale($end->format('Y-m-d H:i:s')) : '';

            $content = '<p>Gentile ' . sanitize_output($clientName) . ',</p>';
            $content .= '<p>abbiamo pianificato un nuovo appuntamento con i seguenti dettagli:</p>';
            $content .= '<ul style="list-style: none; padding: 0;">';
            $content .= '<li><strong>Titolo:</strong> ' . sanitize_output($data['titolo']) . '</li>';
            $content .= '<li><strong>Tipologia:</strong> ' . sanitize_output($data['tipo_servizio']) . '</li>';
            if ($data['responsabile'] !== '') {
                $content .= '<li><strong>Responsabile:</strong> ' . sanitize_output($data['responsabile']) . '</li>';
            }
            $content .= '<li><strong>Data inizio:</strong> ' . sanitize_output($startText) . '</li>';
            if ($endText !== '') {
                $content .= '<li><strong>Data fine:</strong> ' . sanitize_output($endText) . '</li>';
            }
            if ($data['luogo'] !== '') {
                $content .= '<li><strong>Luogo:</strong> ' . sanitize_output($data['luogo']) . '</li>';
            }
            $content .= '</ul>';

            if ($data['note'] !== '') {
                $content .= '<p><strong>Note:</strong><br>' . nl2br(sanitize_output($data['note'])) . '</p>';
            }

            $content .= '<p>Per ulteriori informazioni puoi rispondere direttamente a questa email.</p>';

            $mailSubject = 'Nuovo appuntamento: ' . $data['titolo'];
            $mailBody = render_mail_template($mailSubject, $content);
            $mailSent = send_system_mail($client['email'], $mailSubject, $mailBody);

            if (!$mailSent) {
                add_flash('warning', 'Appuntamento creato ma invio email al cliente non riuscito.');
            }
        } else {
            add_flash('warning', 'Appuntamento creato ma il cliente non dispone di un indirizzo email valido.');
        }

        header('Location: ' . $successRedirect);
        exit;
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1"><?php echo sanitize_output($pageHeading); ?></h1>
                <p class="text-muted mb-0"><?php echo sanitize_output($pageSubtitle); ?></p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-warning" href="<?php echo sanitize_output($backUrl); ?>"><i class="fa-solid fa-arrow-left me-2"></i><?php echo sanitize_output($backLabel); ?></a>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Dettagli appuntamento</h2>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning">
                        <?php echo implode('<br>', array_map('sanitize_output', $errors)); ?>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="row g-4">
                        <div class="col-xl-4 col-lg-6">
                            <label class="form-label" for="cliente_id">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleziona cliente…</option>
                                <?php foreach ($clients as $client): ?>
                                    <?php
                                        $company = trim((string) ($client['ragione_sociale'] ?? ''));
                                        $person = trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? ''));
                                        $label = $company !== '' && $person !== ''
                                            ? $company . ' - ' . $person
                                            : ($company !== '' ? $company : $person);
                                        if ($label === '') {
                                            $label = 'Cliente #' . (int) $client['id'];
                                        }
                                    ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo ((int) $data['cliente_id'] === (int) $client['id']) ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-4 col-lg-6">
                            <label class="form-label" for="title_choice">Titolo</label>
                            <select class="form-select" id="title_choice" name="title_choice">
                                <?php foreach ($titleOptions as $option): ?>
                                    <option value="<?php echo sanitize_output($option); ?>" <?php echo $titleChoice === $option ? 'selected' : ''; ?>><?php echo sanitize_output($option); ?></option>
                                <?php endforeach; ?>
                                <?php if (!$isPatronatoContext): ?>
                                    <option value="__custom" <?php echo $titleChoice === '__custom' ? 'selected' : ''; ?>>Titolo personalizzato…</option>
                                <?php endif; ?>
                            </select>
                            <?php if ($isPatronatoContext): ?>
                                <small class="text-muted">Il titolo è preimpostato per catalogare gli appuntamenti Patronato.</small>
                            <?php else: ?>
                                <small class="text-muted">Preferisci "Titolo personalizzato" per descrivere situazioni particolari.</small>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isPatronatoContext): ?>
                            <div class="col-xl-4 col-lg-12" id="customTitleGroup" <?php echo $titleChoice === '__custom' ? '' : 'hidden'; ?>>
                                <label class="form-label" for="custom_title">Titolo personalizzato</label>
                                <input class="form-control" id="custom_title" name="custom_title" value="<?php echo sanitize_output($customTitle); ?>" placeholder="Es. Rinnovo firma digitale">
                                <small class="text-muted">Obbligatorio solo se selezioni l'opzione personalizzata.</small>
                            </div>
                        <?php endif; ?>

                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="tipo_servizio">Tipologia</label>
                            <select class="form-select" id="tipo_servizio" name="tipo_servizio">
                                <?php foreach ($serviceTypes as $type): ?>
                                    <option value="<?php echo sanitize_output($type); ?>" <?php echo $data['tipo_servizio'] === $type ? 'selected' : ''; ?>><?php echo sanitize_output($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="responsabile">Responsabile</label>
                            <select class="form-select" id="responsabile" name="responsabile">
                                <option value="">Nessun responsabile assegnato</option>
                                <?php foreach ($responsabileOptions as $option): ?>
                                    <option value="<?php echo sanitize_output($option); ?>" <?php echo $data['responsabile'] === $option ? 'selected' : ''; ?>><?php echo sanitize_output($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">In mancanza di assegnazione resterà visibile come "N/D".</small>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="stato">Stato appuntamento</label>
                            <select class="form-select" id="stato" name="stato">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo sanitize_output($status); ?>" <?php echo $data['stato'] === $status ? 'selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="data_inizio">Data e ora di inizio</label>
                            <input class="form-control" id="data_inizio" type="datetime-local" name="data_inizio" value="<?php echo sanitize_output($data['data_inizio']); ?>" required>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label" for="data_fine">Data e ora di fine</label>
                            <input class="form-control" id="data_fine" type="datetime-local" name="data_fine" value="<?php echo sanitize_output($data['data_fine']); ?>" placeholder="Facoltativo">
                            <small class="text-muted">Indica la durata prevista quando disponibile.</small>
                        </div>
                        <div class="col-lg-4 col-md-12">
                            <label class="form-label" for="luogo_modalita">Luogo</label>
                            <select class="form-select" id="luogo_modalita" name="luogo_modalita">
                                <option value="agenzia" <?php echo $data['luogo_modalita'] === 'agenzia' ? 'selected' : ''; ?>>Presso agenzia</option>
                                <option value="domicilio" <?php echo $data['luogo_modalita'] === 'domicilio' ? 'selected' : ''; ?>>A domicilio del cliente</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="luogo">Indirizzo appuntamento</label>
                            <input class="form-control" id="luogo" name="luogo" list="addressSuggestions" value="<?php echo sanitize_output($data['luogo']); ?>" <?php echo $data['luogo_modalita'] === 'agenzia' ? 'readonly' : ''; ?> data-default-location="<?php echo sanitize_output($defaultLocation); ?>" autocomplete="street-address" placeholder="<?php echo $data['luogo_modalita'] === 'domicilio' ? 'Es. Via Roma 10, Castellammare di Stabia' : 'Indirizzo sede agenzia'; ?>">
                            <datalist id="addressSuggestions"></datalist>
                            <small class="text-muted">Questo indirizzo verrà riportato nei promemoria email e sugli eventi sincronizzati.</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="note">Note interne</label>
                            <textarea class="form-control" id="note" name="note" rows="4" placeholder="Dettagli operativi, materiali da preparare o richieste particolari del cliente."><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                    </div>
                    <div class="stack-sm justify-content-end mt-4">
                        <a class="btn btn-outline-warning" href="<?php echo sanitize_output($backUrl); ?>"><i class="fa-solid fa-arrow-rotate-left me-2"></i>Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Registra appuntamento</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var titleSelect = document.getElementById('title_choice');
                var customGroup = document.getElementById('customTitleGroup');
                var customInput = document.getElementById('custom_title');
                if (titleSelect && customGroup) {
                    var toggleCustom = function () {
                        if (titleSelect.value === '__custom') {
                            customGroup.removeAttribute('hidden');
                            if (customInput) {
                                customInput.required = true;
                            }
                        } else {
                            customGroup.setAttribute('hidden', 'hidden');
                            if (customInput) {
                                customInput.required = false;
                            }
                        }
                    };
                    titleSelect.addEventListener('change', toggleCustom);
                    toggleCustom();
                }

                var locationMode = document.getElementById('luogo_modalita');
                var locationInput = document.getElementById('luogo');
                if (locationMode && locationInput) {
                    var defaultLocation = locationInput.getAttribute('data-default-location') || '';
                    var setReadOnly = function (shouldBeReadOnly) {
                        if (shouldBeReadOnly) {
                            locationInput.setAttribute('readonly', 'readonly');
                            locationInput.readOnly = true;
                        } else {
                            locationInput.removeAttribute('readonly');
                            locationInput.readOnly = false;
                        }
                    };
                    var toggleLocation = function () {
                        var isHome = locationMode.value === 'domicilio';
                        if (isHome) {
                            setReadOnly(false);
                            if (locationInput.value.trim() === defaultLocation.trim()) {
                                locationInput.value = '';
                            }
                            locationInput.placeholder = 'Es. Via Roma 10, Castellammare di Stabia';
                        } else {
                            locationInput.value = defaultLocation;
                            setReadOnly(true);
                            locationInput.placeholder = '';
                        }
                    };
                    locationMode.addEventListener('change', toggleLocation);
                    toggleLocation();
                }

                var addressList = document.getElementById('addressSuggestions');
                var pendingController = null;
                var debounceTimer = null;
                var cityContext = 'Castellammare di Stabia';
                var viewbox = {
                    left: 14.420,
                    top: 40.760,
                    right: 14.560,
                    bottom: 40.630
                };
                if (locationInput && addressList) {
                    var fetchSuggestions = function (query) {
                        if (pendingController) {
                            pendingController.abort();
                        }
                        var effectiveQuery = query ? query + ' ' + cityContext : cityContext;
                        pendingController = new AbortController();
                        var endpoint = new URL('https://nominatim.openstreetmap.org/search');
                        endpoint.searchParams.set('format', 'json');
                        endpoint.searchParams.set('addressdetails', '0');
                        endpoint.searchParams.set('limit', '10');
                        endpoint.searchParams.set('countrycodes', 'it');
                        endpoint.searchParams.set('accept-language', 'it');
                        endpoint.searchParams.set('bounded', '1');
                        endpoint.searchParams.set('viewbox', [viewbox.left, viewbox.top, viewbox.right, viewbox.bottom].join(','));
                        endpoint.searchParams.set('q', effectiveQuery);

                        fetch(endpoint.toString(), {
                            signal: pendingController.signal,
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('Network response was not ok');
                                }
                                return response.json();
                            })
                            .then(function (results) {
                                addressList.innerHTML = '';
                                if (!Array.isArray(results)) {
                                    return;
                                }
                                results.forEach(function (item) {
                                    var option = document.createElement('option');
                                    option.value = item.display_name;
                                    addressList.appendChild(option);
                                });
                            })
                            .catch(function () {
                                if (!pendingController || !pendingController.signal.aborted) {
                                    addressList.innerHTML = '';
                                }
                            });
                    };

                    locationInput.addEventListener('input', function () {
                        if (locationInput.readOnly) {
                            return;
                        }
                        clearTimeout(debounceTimer);
                        var value = locationInput.value.trim();
                        debounceTimer = setTimeout(function () {
                            fetchSuggestions(value);
                        }, 300);
                    });

                    locationInput.addEventListener('focus', function () {
                        if (locationInput.readOnly) {
                            return;
                        }
                        if (addressList.childElementCount === 0) {
                            fetchSuggestions(locationInput.value.trim());
                        }
                    });

                    locationInput.addEventListener('blur', function () {
                        clearTimeout(debounceTimer);
                    });
                }
            });
        </script>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
