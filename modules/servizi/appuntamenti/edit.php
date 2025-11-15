<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Modifica appuntamento';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM servizi_appuntamenti WHERE id = :id');
$stmt->execute([':id' => $id]);
$record = $stmt->fetch();
if (!$record) {
    header('Location: index.php?notfound=1');
    exit;
}

$originalStart = $record['data_inizio'] ?? null;
$originalStatus = (string) ($record['stato'] ?? '');
$originalReminderSentAt = $record['reminder_sent_at'] ?? null;

$clients = $pdo->query('SELECT id, nome, cognome FROM clienti ORDER BY cognome, nome')->fetchAll();
$serviceTypes = get_appointment_types($pdo);
if (!$serviceTypes) {
    $serviceTypes = \App\Services\SettingsService::defaultAppointmentTypes();
}
$statusConfig = get_appointment_status_config($pdo);
$statuses = $statusConfig['available'];
if (!$statuses) {
    $statuses = ['Programmato'];
}
$activeStatuses = $statusConfig['active'] ?: $statuses;
$confirmationStatus = trim((string) ($statusConfig['confirmation'] !== '' ? $statusConfig['confirmation'] : ($statuses[0] ?? '')));
$responsabiliStmt = $pdo->query("SELECT username, nome, cognome FROM users WHERE ruolo IN ('Admin', 'Manager', 'Operatore') ORDER BY cognome, nome, username");
$responsabili = [];
if ($responsabiliStmt) {
    $responsabileRows = $responsabiliStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($responsabileRows as $row) {
        $fullName = trim(((string) ($row['nome'] ?? '')) . ' ' . ((string) ($row['cognome'] ?? '')));
        $label = $fullName !== '' ? $fullName : (string) ($row['username'] ?? '');
        if ($label !== '' && !in_array($label, $responsabili, true)) {
            $responsabili[] = $label;
        }
    }
}
if (!$responsabili) {
    $responsabili = ['Carmine', 'Valentina'];
}

$defaultLocation = (string) (env('APPOINTMENT_DEFAULT_LOCATION', 'Via Plinio il Vecchio 72 Castellammare di Stabia') ?: 'Via Plinio il Vecchio 72 Castellammare di Stabia');

$calendarService = new \App\Services\GoogleCalendarService();

$toDateTimeLocal = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return '';
    }
    return $date->format('Y-m-d\\TH:i');
};

$currentLocation = trim((string) ($record['luogo'] ?? ''));
$locationMode = 'agenzia';
if ($currentLocation !== '' && strcasecmp($currentLocation, $defaultLocation) !== 0) {
    $locationMode = 'domicilio';
}
if ($locationMode === 'agenzia') {
    $currentLocation = $defaultLocation;
}

$data = [
    'cliente_id' => (string) ($record['cliente_id'] ?? ''),
    'titolo' => (string) ($record['titolo'] ?? ''),
    'tipo_servizio' => (string) ($record['tipo_servizio'] ?? ($serviceTypes[0] ?? '')),
    'responsabile' => (string) ($record['responsabile'] ?? ''),
    'luogo' => $currentLocation,
    'luogo_modalita' => $locationMode,
    'data_inizio' => $toDateTimeLocal($record['data_inizio'] ?? null),
    'data_fine' => $toDateTimeLocal($record['data_fine'] ?? null),
    'stato' => (string) ($record['stato'] ?? ($statuses[0] ?? '')),
    'note' => (string) ($record['note'] ?? ''),
];
$errors = [];
$csrfToken = csrf_token();

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

    if ((int) $data['cliente_id'] <= 0) {
        $errors[] = 'Seleziona un cliente valido.';
    }
    if ($data['titolo'] === '') {
        $errors[] = 'Inserisci un titolo per l\'appuntamento.';
    }
    if (!in_array($data['tipo_servizio'], $serviceTypes, true)) {
        $errors[] = 'Tipologia selezionata non valida.';
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
        $newStartSql = $start->format('Y-m-d H:i:s');
        $newEndSql = $end ? $end->format('Y-m-d H:i:s') : null;
        $newResponsabile = $data['responsabile'] !== '' ? $data['responsabile'] : null;
        $newLuogo = $data['luogo'] !== '' ? $data['luogo'] : null;
        $newNote = $data['note'] !== '' ? $data['note'] : null;

        $calendarSyncRequired = false;
        $calendarUnsyncRequired = false;
        if ($calendarService->isEnabled() && $confirmationStatus !== '') {
            $wasConfirmed = strcasecmp(trim($originalStatus), $confirmationStatus) === 0;
            $isConfirmed = strcasecmp(trim($data['stato']), $confirmationStatus) === 0;

            if ($wasConfirmed && !$isConfirmed && !empty($record['google_event_id'])) {
                $calendarUnsyncRequired = true;
            }

            if ($isConfirmed) {
                if (!$wasConfirmed || empty($record['google_event_id'])) {
                    $calendarSyncRequired = true;
                } else {
                    $fieldsToCompare = [
                        'titolo' => $data['titolo'],
                        'tipo_servizio' => $data['tipo_servizio'],
                        'responsabile' => $newResponsabile,
                        'luogo' => $newLuogo,
                        'data_inizio' => $newStartSql,
                        'data_fine' => $newEndSql,
                        'note' => $newNote,
                    ];

                    foreach ($fieldsToCompare as $field => $newValue) {
                        $originalValue = $record[$field] ?? null;
                        if ($originalValue !== $newValue) {
                            $calendarSyncRequired = true;
                            break;
                        }
                    }
                }
            }
        }

        $update = $pdo->prepare('UPDATE servizi_appuntamenti SET cliente_id = :cliente_id, titolo = :titolo, tipo_servizio = :tipo_servizio, responsabile = :responsabile, luogo = :luogo, data_inizio = :data_inizio, data_fine = :data_fine, stato = :stato, note = :note WHERE id = :id');
        $update->execute([
            ':cliente_id' => (int) $data['cliente_id'],
            ':titolo' => $data['titolo'],
            ':tipo_servizio' => $data['tipo_servizio'],
            ':responsabile' => $newResponsabile,
            ':luogo' => $newLuogo,
            ':data_inizio' => $newStartSql,
            ':data_fine' => $newEndSql,
            ':stato' => $data['stato'],
            ':note' => $newNote,
            ':id' => $id,
        ]);

        if ($originalReminderSentAt !== null) {
            $shouldResetReminder = false;

            if ($originalStart !== $newStartSql) {
                $shouldResetReminder = true;
            }

            $now = new DateTimeImmutable('now');
            $wasActive = in_array($originalStatus, $activeStatuses, true);
            $isActive = in_array($data['stato'], $activeStatuses, true);

            if (!$wasActive && $isActive) {
                $shouldResetReminder = true;
            }

            if ($shouldResetReminder && $start > $now) {
                $resetStmt = $pdo->prepare('UPDATE servizi_appuntamenti SET reminder_sent_at = NULL WHERE id = :id');
                $resetStmt->execute([':id' => $id]);
            }
        }

        if ($calendarSyncRequired) {
            $appointmentStmt = $pdo->prepare('SELECT sa.*, c.email AS cliente_email, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale AS cliente_ragione_sociale FROM servizi_appuntamenti sa LEFT JOIN clienti c ON c.id = sa.cliente_id WHERE sa.id = :id LIMIT 1');
            $appointmentStmt->execute([':id' => $id]);
            $appointment = $appointmentStmt->fetch();

            if ($appointment) {
                try {
                    $syncResult = $calendarService->syncAppointment($appointment);
                    $updateCalendar = $pdo->prepare('UPDATE servizi_appuntamenti SET google_event_id = :event_id, google_event_synced_at = :synced_at, google_event_sync_error = NULL WHERE id = :id');
                    $updateCalendar->execute([
                        ':event_id' => $syncResult['eventId'],
                        ':synced_at' => $syncResult['syncedAt']->format('Y-m-d H:i:s'),
                        ':id' => $id,
                    ]);
                } catch (\Throwable $calendarException) {
                    $errorMessage = substr($calendarException->getMessage(), 0, 240);
                    $errorUpdate = $pdo->prepare('UPDATE servizi_appuntamenti SET google_event_sync_error = :error WHERE id = :id');
                    $errorUpdate->execute([
                        ':error' => $errorMessage,
                        ':id' => $id,
                    ]);
                    add_flash('warning', 'Appuntamento aggiornato ma sincronizzazione con Google Calendar non riuscita: ' . $errorMessage);
                }
            }
        } elseif ($calendarUnsyncRequired) {
            try {
                $calendarService->removeAppointmentEvent($record);
                $clearCalendar = $pdo->prepare('UPDATE servizi_appuntamenti SET google_event_id = NULL, google_event_synced_at = NULL, google_event_sync_error = NULL WHERE id = :id');
                $clearCalendar->execute([':id' => $id]);
            } catch (\Throwable $calendarException) {
                add_flash('warning', 'Appuntamento aggiornato ma rimozione evento da Google Calendar non riuscita: ' . $calendarException->getMessage());
            }
        }

        header('Location: view.php?id=' . $id . '&updated=1');
        exit;
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="mb-4">
            <a class="btn btn-outline-warning" href="view.php?id=<?php echo $id; ?>"><i class="fa-solid fa-arrow-left"></i> Dettaglio appuntamento</a>
        </div>
        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h1 class="h4 mb-0">Modifica appuntamento</h1>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning"><?php echo implode('<br>', array_map('sanitize_output', $errors)); ?></div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="cliente_id">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id">
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo ((int) $data['cliente_id'] === (int) $client['id']) ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($client['cognome'] . ' ' . $client['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="titolo">Titolo</label>
                            <input class="form-control" id="titolo" name="titolo" value="<?php echo sanitize_output($data['titolo']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="tipo_servizio">Tipologia</label>
                            <select class="form-select" id="tipo_servizio" name="tipo_servizio">
                                <?php foreach ($serviceTypes as $type): ?>
                                    <option value="<?php echo sanitize_output($type); ?>" <?php echo $data['tipo_servizio'] === $type ? 'selected' : ''; ?>><?php echo sanitize_output($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="responsabile">Responsabile</label>
                            <input class="form-control" id="responsabile" name="responsabile" list="responsabileOptions" value="<?php echo sanitize_output($data['responsabile']); ?>">
                            <?php if ($responsabili): ?>
                                <datalist id="responsabileOptions">
                                    <?php foreach ($responsabili as $responsabile): ?>
                                        <option value="<?php echo sanitize_output($responsabile); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="luogo_modalita">Luogo</label>
                            <select class="form-select" id="luogo_modalita" name="luogo_modalita">
                                <option value="agenzia" <?php echo $data['luogo_modalita'] === 'agenzia' ? 'selected' : ''; ?>>Presso agenzia</option>
                                <option value="domicilio" <?php echo $data['luogo_modalita'] === 'domicilio' ? 'selected' : ''; ?>>A domicilio del cliente</option>
                            </select>
                            <small class="text-muted">Aggiorna la modalità dell'appuntamento se il cliente richiede servizio a domicilio.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="luogo">Indirizzo appuntamento</label>
                            <input class="form-control" id="luogo" name="luogo" value="<?php echo sanitize_output($data['luogo']); ?>" <?php echo $data['luogo_modalita'] === 'agenzia' ? 'readonly' : ''; ?> data-default-location="<?php echo sanitize_output($defaultLocation); ?>" autocomplete="street-address" placeholder="<?php echo $data['luogo_modalita'] === 'domicilio' ? 'Es. Via Roma 10, Castellammare di Stabia' : ''; ?>">
                            <small class="text-muted">Questo indirizzo verrà riportato nelle email e nella sincronizzazione con Google Calendar.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="data_inizio">Inizio</label>
                            <input class="form-control" id="data_inizio" type="datetime-local" name="data_inizio" value="<?php echo sanitize_output($data['data_inizio']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="data_fine">Fine</label>
                            <input class="form-control" id="data_fine" type="datetime-local" name="data_fine" value="<?php echo sanitize_output($data['data_fine']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="stato">Stato</label>
                            <select class="form-select" id="stato" name="stato">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo sanitize_output($status); ?>" <?php echo $data['stato'] === $status ? 'selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="note">Note interne</label>
                            <textarea class="form-control" id="note" name="note" rows="4"><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a class="btn btn-secondary" href="view.php?id=<?php echo $id; ?>">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit">Salva modifiche</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var locationMode = document.getElementById('luogo_modalita');
        var locationInput = document.getElementById('luogo');
        if (locationMode && locationInput) {
            var defaultLocation = locationInput.getAttribute('data-default-location') || '';
            var toggleLocation = function () {
                var isHome = locationMode.value === 'domicilio';
                if (isHome) {
                    locationInput.removeAttribute('readonly');
                    if (locationInput.value.trim() === defaultLocation.trim()) {
                        locationInput.value = '';
                    }
                    locationInput.placeholder = 'Es. Via Roma 10, Castellammare di Stabia';
                } else {
                    locationInput.value = defaultLocation;
                    locationInput.setAttribute('readonly', 'readonly');
                    locationInput.placeholder = '';
                }
            };
            locationMode.addEventListener('change', toggleLocation);
            toggleLocation();
        }
    });
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
