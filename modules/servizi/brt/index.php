<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtShipmentService;
use App\Services\Brt\BrtTrackingService;
use Throwable;

define('CORESUITE_BRT_BOOTSTRAP', true);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'BRT Spedizioni';

$csrfToken = csrf_token();

try {
    ensure_brt_tables();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('Database BRT non configurato: ' . $exception->getMessage());
}

$config = new BrtConfig();
$autoConfirmEnabled = $config->shouldAutoConfirm();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate_manifest') {
        $selected = $_POST['shipment_ids'] ?? [];
        if (!is_array($selected)) {
            $selected = [$selected];
        }

        $selectedIds = array_values(array_unique(array_filter(
            array_map(static fn ($value) => (int) $value, $selected),
            static fn ($value) => $value > 0
        )));

        if ($selectedIds === []) {
            add_flash('warning', 'Seleziona almeno una spedizione da inserire nel bordero.');
            header('Location: index.php');
            exit;
        }

        try {
            $shipmentService = new BrtShipmentService($config);
        } catch (Throwable $exception) {
            add_flash('warning', 'Configurazione BRT non valida: ' . $exception->getMessage());
            header('Location: index.php');
            exit;
        }

        $eligibleIds = [];
        $errors = [];

        foreach ($selectedIds as $selectedId) {
            $shipment = brt_get_shipment($selectedId);
            if ($shipment === null) {
                $errors[] = sprintf('Spedizione #%d non trovata.', $selectedId);
                continue;
            }

            if (!empty($shipment['manifest_id'])) {
                $errors[] = sprintf('Spedizione #%d gia assegnata a un bordero.', $selectedId);
                continue;
            }

            if (!empty($shipment['deleted_at']) || ($shipment['status'] ?? '') === 'cancelled') {
                $errors[] = sprintf('Spedizione #%d annullata, impossibile includerla.', $selectedId);
                continue;
            }

            $status = (string) ($shipment['status'] ?? '');

            if ($status !== 'confirmed') {
                $payload = [
                    'senderCustomerCode' => $shipment['sender_customer_code'],
                    'numericSenderReference' => (int) $shipment['numeric_sender_reference'],
                    'alphanumericSenderReference' => (string) $shipment['alphanumeric_sender_reference'],
                ];

                try {
                    $response = $shipmentService->confirmShipment($payload);
                    brt_mark_shipment_confirmed($selectedId, $response);
                    if (isset($response['labels']['label'][0]) && is_array($response['labels']['label'][0])) {
                        brt_attach_label($selectedId, $response['labels']['label'][0]);
                    }
                } catch (BrtException $exception) {
                    $cleanMessage = brt_normalize_remote_warning($exception->getMessage());
                    $errors[] = sprintf('Conferma spedizione #%d non riuscita: %s', $selectedId, $cleanMessage);
                    continue;
                } catch (Throwable $exception) {
                    $errors[] = sprintf('Conferma spedizione #%d non riuscita: %s', $selectedId, $exception->getMessage());
                    continue;
                }
            }

            $eligibleIds[] = $selectedId;
        }

        if ($eligibleIds === []) {
            if ($errors !== []) {
                add_flash('warning', implode(' ', $errors));
            } else {
                add_flash('warning', 'Nessuna spedizione disponibile per il bordero.');
            }
            header('Location: index.php');
            exit;
        }

        try {
            $manifest = brt_generate_manifest_for_shipments($eligibleIds, $config);
            add_flash(
                'success',
                sprintf(
                    'Generato bordero %s con %d spedizioni.',
                    (string) ($manifest['reference'] ?? ''),
                    (int) ($manifest['shipments_count'] ?? 0)
                )
            );
            brt_log_event('info', 'Bordero locale generato manualmente', [
                'reference' => $manifest['reference'] ?? null,
                'shipments_count' => $manifest['shipments_count'] ?? null,
                'user' => current_user_display_name(),
            ]);
        } catch (BrtException $exception) {
            $errors[] = brt_normalize_remote_warning($exception->getMessage());
            brt_log_event('warning', 'Bordero non generato: eccezione BRT', [
                'error' => $exception->getMessage(),
                'selected_ids' => $eligibleIds,
                'user' => current_user_display_name(),
            ]);
        } catch (Throwable $exception) {
            $errors[] = 'Bordero non generato: ' . $exception->getMessage();
            brt_log_event('error', 'Bordero non generato: errore inatteso', [
                'error' => $exception->getMessage(),
                'selected_ids' => $eligibleIds,
                'user' => current_user_display_name(),
            ]);
        }

        if ($errors !== []) {
            add_flash('warning', implode(' ', $errors));
        }

        header('Location: index.php');
        exit;
    }

    try {
        $shipmentService = new BrtShipmentService($config);
        $trackingService = new BrtTrackingService($config);
    } catch (Throwable $exception) {
        add_flash('warning', 'Configurazione BRT non valida: ' . $exception->getMessage());
        header('Location: index.php');
        exit;
    }

    if (in_array($action, ['cancel', 'refresh_tracking', 'refresh_details', 'reprint_label', 'delete_local'], true)) {
        $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
        if ($shipmentId <= 0) {
            add_flash('warning', 'Seleziona una spedizione valida.');
            header('Location: index.php');
            exit;
        }

        $shipment = brt_get_shipment($shipmentId);
        if ($shipment === null) {
            add_flash('warning', 'Spedizione BRT non trovata.');
            header('Location: index.php');
            exit;
        }

        $payload = [
            'senderCustomerCode' => $shipment['sender_customer_code'],
            'numericSenderReference' => (int) $shipment['numeric_sender_reference'],
            'alphanumericSenderReference' => $shipment['alphanumeric_sender_reference'],
        ];

        try {
            if ($action === 'cancel') {
                $response = $shipmentService->deleteShipment($payload);
                brt_mark_shipment_deleted($shipmentId, $response);
                add_flash('success', 'Spedizione annullata correttamente.');
                brt_log_event('info', 'Spedizione annullata', [
                    'shipment_id' => $shipmentId,
                    'parcel_id' => $shipment['parcel_id'] ?? null,
                    'user' => current_user_display_name(),
                ]);
            } elseif ($action === 'refresh_tracking') {
                $trackingId = (string) ($_POST['tracking_by_parcel_id'] ?? $shipment['tracking_by_parcel_id'] ?? '');
                if ($trackingId === '') {
                    $trackingId = (string) ($_POST['parcel_id'] ?? $shipment['parcel_id'] ?? '');
                }

                if ($trackingId === '') {
                    add_flash('warning', 'Impossibile aggiornare il tracking: trackingByParcelID mancante.');
                } else {
                    $tracking = $trackingService->trackingByParcelId($trackingId);
                    brt_update_tracking($shipmentId, $tracking);
                    add_flash('success', 'Tracking aggiornato con successo.');
                    brt_log_event('info', 'Tracking aggiornato', [
                        'shipment_id' => $shipmentId,
                        'tracking_id' => $trackingId,
                        'user' => current_user_display_name(),
                    ]);
                }
            } elseif ($action === 'refresh_details') {
                if ((string) ($shipment['status'] ?? '') !== 'confirmed') {
                    add_flash('warning', 'L\'aggiornamento dei dettagli è disponibile solo per spedizioni confermate.');
                } else {
                    $response = $shipmentService->confirmShipment($payload, ['forceLabel' => true]);
                    brt_mark_shipment_confirmed($shipmentId, $response, ['preserve_confirmed_at' => true]);

                    $oldLabelPath = $shipment['label_path'] ?? null;
                    $newLabelPath = null;
                    if (isset($response['labels']['label'][0]) && is_array($response['labels']['label'][0])) {
                        $newLabelPath = brt_attach_label($shipmentId, $response['labels']['label'][0]);
                    }

                    if ($oldLabelPath && $newLabelPath !== null && $oldLabelPath !== $newLabelPath) {
                        brt_delete_label_file($oldLabelPath);
                    }

                    add_flash('success', 'Dettagli spedizione aggiornati dal webservice.');
                    brt_log_event('info', 'Dettagli spedizione aggiornati', [
                        'shipment_id' => $shipmentId,
                        'parcel_id' => $shipment['parcel_id'] ?? null,
                        'user' => current_user_display_name(),
                    ]);
                }
            } elseif ($action === 'reprint_label') {
                if ((string) ($shipment['status'] ?? '') !== 'confirmed') {
                    add_flash('warning', 'La ristampa è disponibile solo per spedizioni confermate.');
                } else {
                    $response = $shipmentService->reprintShipmentLabel($payload, [
                        'labelParameters' => [
                            'outputType' => $config->getLabelOutputType(),
                            'offsetX' => $config->getLabelOffsetX(),
                            'offsetY' => $config->getLabelOffsetY(),
                            'isBorderRequired' => $config->isLabelBorderEnabled() ? 1 : null,
                            'isLogoRequired' => $config->isLabelLogoEnabled() ? 1 : null,
                            'isBarcodeControlRowRequired' => $config->isLabelBarcodeRowEnabled() ? 1 : null,
                        ],
                    ]);

                    brt_mark_shipment_confirmed($shipmentId, $response, ['preserve_confirmed_at' => true]);

                    $oldLabelPath = $shipment['label_path'] ?? null;
                    $newLabelPath = null;
                    if (isset($response['labels']['label'][0]) && is_array($response['labels']['label'][0])) {
                        $newLabelPath = brt_attach_label($shipmentId, $response['labels']['label'][0]);
                    }

                    if ($oldLabelPath && $newLabelPath !== null && $oldLabelPath !== $newLabelPath) {
                        brt_delete_label_file($oldLabelPath);
                    }

                    add_flash('success', 'Etichetta BRT rigenerata con successo.');
                    brt_log_event('info', 'Etichetta rigenerata', [
                        'shipment_id' => $shipmentId,
                        'parcel_id' => $shipment['parcel_id'] ?? null,
                        'user' => current_user_display_name(),
                    ]);
                }
            } else {
                if (!empty($shipment['manifest_id'])) {
                    add_flash('warning', 'Impossibile eliminare una spedizione già presente in un borderò.');
                } else {
                    $remoteDeleteError = null;
                    $remoteDeleted = !empty($shipment['deleted_at']);

                    if (!$remoteDeleted) {
                        try {
                            $shipmentService->deleteShipment($payload);
                            $remoteDeleted = true;
                            brt_log_event('info', 'Spedizione cancellata su BRT durante eliminazione definitiva', [
                                'shipment_id' => $shipmentId,
                                'parcel_id' => $shipment['parcel_id'] ?? null,
                                'user' => current_user_display_name(),
                            ]);
                        } catch (BrtException $exception) {
                            $remoteDeleteError = $exception->getMessage();
                        } catch (Throwable $exception) {
                            $remoteDeleteError = $exception->getMessage();
                        }
                    }

                    brt_remove_shipment($shipmentId);

                    if ($remoteDeleteError !== null) {
                        $cleanMessage = brt_normalize_remote_warning($remoteDeleteError);
                        add_flash('warning', 'Spedizione rimossa localmente. Errore BRT: ' . $cleanMessage);
                        brt_log_event('warning', 'Cancellazione remota spedizione fallita', [
                            'shipment_id' => $shipmentId,
                            'message' => $cleanMessage,
                            'user' => current_user_display_name(),
                        ]);
                    } else {
                        add_flash('success', 'Spedizione eliminata definitivamente.');
                        brt_log_event('info', 'Spedizione eliminata definitivamente', [
                            'shipment_id' => $shipmentId,
                            'user' => current_user_display_name(),
                        ]);
                    }
                }
            }
        } catch (BrtException $exception) {
            $cleanMessage = brt_normalize_remote_warning($exception->getMessage());
            add_flash('warning', 'Operazione BRT non riuscita: ' . $cleanMessage);
            brt_log_event('warning', 'Operazione BRT non riuscita', [
                'shipment_id' => $shipmentId,
                'action' => $action,
                'error' => $exception->getMessage(),
                'user' => current_user_display_name(),
            ]);
        } catch (Throwable $exception) {
            add_flash('warning', 'Operazione BRT non riuscita: ' . $exception->getMessage());
            brt_log_event('error', 'Operazione BRT non riuscita: errore inatteso', [
                'shipment_id' => $shipmentId,
                'action' => $action,
                'error' => $exception->getMessage(),
                'user' => current_user_display_name(),
            ]);
        }
    } else {
        add_flash('warning', 'Azione non supportata.');
    }

    header('Location: index.php');
    exit;
}

$statusOptions = [
    '' => 'Tutte',
    'created' => 'Create',
    'confirmed' => 'Confermate',
    'warning' => 'Con avvisi',
    'cancelled' => 'Annullate',
];

$filters = [];
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && isset($statusOptions[$statusFilter])) {
    $filters['status'] = $statusFilter;
}

$searchFilter = trim((string) ($_GET['search'] ?? ''));
if ($searchFilter !== '') {
    $filters['search'] = $searchFilter;
}

$shipments = brt_get_shipments($filters);
$recentOrmRequests = brt_get_recent_orm_requests();
$recentManifests = brt_get_recent_manifests();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">BRT Spedizioni</h1>
                <p class="text-muted mb-0">Gestione spedizioni, etichette e tracking tramite webservice BRT.</p>
            </div>
            <div class="toolbar-actions d-flex align-items-center gap-2">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuova spedizione</a>
                <a class="btn btn-outline-secondary" href="orm.php"><i class="fa-solid fa-truck-ramp-box me-2"></i>Ordine ritiro (ORM)</a>
                <a class="btn btn-outline-secondary" href="log.php"><i class="fa-solid fa-clipboard-list me-2"></i>Log attività</a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-md-4">
                        <label class="form-label" for="status">Stato spedizione</label>
                        <select class="form-select" id="status" name="status">
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>"<?php echo $statusFilter === $value ? ' selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="search">Ricerca</label>
                        <input class="form-control" type="search" id="search" name="search" value="<?php echo sanitize_output($searchFilter); ?>" placeholder="ParcelID, destinatario o riferimento mittente">
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Cerca</button>
                        <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-rotate-left me-2"></i>Reimposta</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($autoConfirmEnabled): ?>
            <div class="alert alert-info d-flex align-items-center" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i>
                Conferma automatica attiva: le spedizioni create saranno confermate in automatico.
            </div>
        <?php endif; ?>

        <div class="card ag-card mb-5">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="card-title h5 mb-0">Ultime spedizioni</h2>
                <span class="text-muted small">Mostrate al massimo 200 spedizioni</span>
            </div>
            <div class="card-body p-0">
                <?php if (!$shipments): ?>
                    <div class="p-4 text-center text-muted">Nessuna spedizione registrata.</div>
                <?php else: ?>
                    <form id="manifest-form" method="post" class="border-bottom p-3 mb-0">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                        <input type="hidden" name="action" value="generate_manifest">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span class="text-muted small">Seleziona le spedizioni da includere nel bordero: quelle ancora da confermare verranno confermate automaticamente prima della generazione.</span>
                            <button class="btn btn-primary btn-sm" type="submit">
                                <i class="fa-solid fa-file-circle-plus me-2"></i>Genera borderò
                            </button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" class="text-center">Sel.</th>
                                    <th scope="col">#</th>
                                    <th scope="col">Riferimenti</th>
                                    <th scope="col">Destinatario</th>
                                    <th scope="col">Stato</th>
                                    <th scope="col">Tracking</th>
                                    <th scope="col">Etichetta</th>
                                    <th scope="col" class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shipments as $shipment): ?>
                                    <?php
                                        $canSelectForManifest = in_array($shipment['status'] ?? '', ['created', 'confirmed', 'warning'], true)
                                            && empty($shipment['manifest_id'])
                                            && empty($shipment['deleted_at']);

                                        $requestPayload = brt_decode_request_payload($shipment['request_payload'] ?? null);
                                        $requestMeta = is_array($requestPayload['meta']) ? $requestPayload['meta'] : [];
                                        $requestSource = trim((string) ($requestMeta['source'] ?? ''));
                                        $requestCustomerId = $requestMeta['customer_id'] ?? null;
                                        if (is_array($requestCustomerId)) {
                                            $requestCustomerId = implode(', ', array_map('strval', $requestCustomerId));
                                        }
                                    ?>
                                    <tr>
                                        <td class="text-center">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="shipment_ids[]"
                                                value="<?php echo (int) $shipment['id']; ?>"
                                                form="manifest-form"<?php echo $canSelectForManifest ? '' : ' disabled'; ?><?php echo $canSelectForManifest ? '' : ' title="La spedizione deve essere confermata e non già inclusa in un borderò."'; ?>
                                            >
                                        </td>
                                        <td class="fw-semibold">#<?php echo (int) $shipment['id']; ?></td>
                                        <td>
                                            <div><span class="text-muted">Mittente:</span> <?php echo sanitize_output($shipment['sender_customer_code']); ?></div>
                                            <div><span class="text-muted">Ref. numerico:</span> <?php echo sanitize_output((string) $shipment['numeric_sender_reference']); ?></div>
                                            <?php if (!empty($shipment['alphanumeric_sender_reference'])): ?>
                                                <div><span class="text-muted">Ref. alfanumerico:</span> <?php echo sanitize_output($shipment['alphanumeric_sender_reference']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($requestSource !== '' || ($requestCustomerId !== null && $requestCustomerId !== '')): ?>
                                                <div class="mt-1">
                                                    <?php if ($requestSource !== ''): ?>
                                                        <span class="badge bg-info text-dark me-1">Origine: <?php echo sanitize_output($requestSource); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($requestCustomerId !== null && $requestCustomerId !== ''): ?>
                                                        <span class="badge bg-secondary">Cliente ID: <?php echo sanitize_output((string) $requestCustomerId); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($shipment['manifest_reference'])): ?>
                                                <div>
                                                    <span class="text-muted">Borderò:</span>
                                                    <?php if (!empty($shipment['manifest_pdf_path'])): ?>
                                                        <a href="<?php echo asset($shipment['manifest_pdf_path']); ?>" target="_blank" rel="noopener"><?php echo sanitize_output($shipment['manifest_reference']); ?></a>
                                                    <?php else: ?>
                                                        <?php echo sanitize_output($shipment['manifest_reference']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($shipment['manifest_official_number'])): ?>
                                                <div>
                                                    <span class="text-muted">Borderò WS:</span>
                                                    <?php if (!empty($shipment['manifest_official_url'])): ?>
                                                        <a href="<?php echo sanitize_output($shipment['manifest_official_url']); ?>" target="_blank" rel="noopener"><?php echo sanitize_output($shipment['manifest_official_number']); ?></a>
                                                    <?php elseif (!empty($shipment['manifest_official_pdf_path'])): ?>
                                                        <a href="<?php echo asset($shipment['manifest_official_pdf_path']); ?>" target="_blank" rel="noopener"><?php echo sanitize_output($shipment['manifest_official_number']); ?></a>
                                                    <?php else: ?>
                                                        <?php echo sanitize_output($shipment['manifest_official_number']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="small text-muted">Creazione: <?php echo sanitize_output(format_datetime_locale($shipment['created_at'] ?? '')); ?></div>
                                        </td>
                                        <td>
                                            <strong><?php echo sanitize_output($shipment['consignee_name']); ?></strong><br>
                                            <span class="text-muted"><?php echo sanitize_output($shipment['consignee_city']); ?></span><br>
                                            <?php if (!empty($shipment['consignee_phone'])): ?>
                                                <span class="badge bg-secondary">Tel: <?php echo sanitize_output($shipment['consignee_phone']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                                $status = (string) ($shipment['status'] ?? '');
                                                $statusBadge = [
                                                    'created' => 'bg-warning text-white',
                                                    'confirmed' => 'bg-success',
                                                    'warning' => 'bg-danger',
                                                    'cancelled' => 'bg-secondary',
                                                ][$status] ?? 'bg-secondary';
                                                $statusLabel = brt_translate_status($status) ?: $status;
                                                $executionMessage = brt_translate_execution_message($shipment['execution_message'] ?? null);
                                            ?>
                                            <span class="badge <?php echo $statusBadge; ?>"><?php echo sanitize_output($statusLabel); ?></span>
                                            <?php if ($executionMessage !== ''): ?>
                                                <div class="small text-muted mt-1"><?php echo sanitize_output($executionMessage); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($shipment['confirmed_at'])): ?>
                                                <div class="small text-muted">Confermata: <?php echo sanitize_output(format_datetime_locale($shipment['confirmed_at'])); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($shipment['deleted_at'])): ?>
                                                <div class="small text-muted">Annullata: <?php echo sanitize_output(format_datetime_locale($shipment['deleted_at'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($shipment['tracking_by_parcel_id'])): ?>
                                                <div><span class="text-muted">trackingByParcelID:</span> <?php echo sanitize_output($shipment['tracking_by_parcel_id']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($shipment['parcel_id'])): ?>
                                                <div><span class="text-muted">ParcelID:</span> <?php echo sanitize_output($shipment['parcel_id']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($shipment['last_tracking_at'])): ?>
                                                <div class="small text-muted">Aggiornato: <?php echo sanitize_output(format_datetime_locale($shipment['last_tracking_at'])); ?></div>
                                            <?php else: ?>
                                                <div class="small text-muted">Tracking non aggiornato</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($shipment['label_path'])): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo asset($shipment['label_path']); ?>" target="_blank" rel="noopener">
                                                    <i class="fa-solid fa-file-pdf me-1"></i>PDF
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                                <a class="btn btn-icon btn-outline-secondary btn-sm" href="view.php?id=<?php echo (int) $shipment['id']; ?>" title="Dettagli spedizione">
                                                    <i class="fa-solid fa-eye fa-sm fa-fw"></i>
                                                </a>
                                                <?php if ((string) ($shipment['status'] ?? '') !== 'cancelled' && empty($shipment['deleted_at'])): ?>
                                                    <a class="btn btn-icon btn-outline-warning btn-sm" href="orm.php?from_shipment=<?php echo (int) $shipment['id']; ?>" title="Precompila ordine di ritiro (ORM)">
                                                        <i class="fa-solid fa-truck-ramp-box fa-sm fa-fw"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (in_array($shipment['status'], ['created', 'warning'], true) && empty($shipment['manifest_id']) && empty($shipment['deleted_at'])): ?>
                                                    <a class="btn btn-icon btn-outline-primary btn-sm" href="edit.php?id=<?php echo (int) $shipment['id']; ?>" title="Modifica spedizione">
                                                        <i class="fa-solid fa-pen fa-sm fa-fw"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ((string) ($shipment['status'] ?? '') === 'confirmed' && empty($shipment['deleted_at'])): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="refresh_details">
                                                        <input type="hidden" name="shipment_id" value="<?php echo (int) $shipment['id']; ?>">
                                                        <button class="btn btn-icon btn-outline-info btn-sm" type="submit" title="Aggiorna dettagli da BRT">
                                                            <i class="fa-solid fa-arrows-rotate fa-sm fa-fw"></i>
                                                        </button>
                                                    </form>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="reprint_label">
                                                        <input type="hidden" name="shipment_id" value="<?php echo (int) $shipment['id']; ?>">
                                                        <button class="btn btn-icon btn-outline-secondary btn-sm" type="submit" title="Rigenera etichetta PDF">
                                                            <i class="fa-solid fa-print fa-sm fa-fw"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($shipment['status'] !== 'cancelled' && empty($shipment['deleted_at'])): ?>
                                                    <form
                                                        method="post"
                                                        class="d-inline"
                                                        data-confirm="Confermi l'annullamento della spedizione?"
                                                        data-confirm-title="Annulla spedizione"
                                                        data-confirm-confirm-label="Sì, annulla"
                                                        data-confirm-class="btn btn-danger"
                                                    >
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="cancel">
                                                        <input type="hidden" name="shipment_id" value="<?php echo (int) $shipment['id']; ?>">
                                                        <button class="btn btn-icon btn-outline-danger btn-sm" type="submit" title="Annulla spedizione">
                                                            <i class="fa-solid fa-xmark fa-sm fa-fw"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (!empty($shipment['tracking_by_parcel_id']) || !empty($shipment['parcel_id'])): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="refresh_tracking">
                                                        <input type="hidden" name="shipment_id" value="<?php echo (int) $shipment['id']; ?>">
                                                        <button class="btn btn-icon btn-outline-info btn-sm" type="submit" title="Aggiorna tracking">
                                                            <i class="fa-solid fa-location-crosshairs fa-sm fa-fw"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($shipment['status'] !== 'confirmed' && empty($shipment['manifest_id'])): ?>
                                                    <form
                                                        method="post"
                                                        class="d-inline"
                                                        data-confirm="Eliminare definitivamente la spedizione selezionata? Questa operazione non può essere annullata."
                                                        data-confirm-title="Elimina spedizione"
                                                        data-confirm-confirm-label="Elimina"
                                                        data-confirm-class="btn btn-danger"
                                                    >
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="delete_local">
                                                        <input type="hidden" name="shipment_id" value="<?php echo (int) $shipment['id']; ?>">
                                                        <button class="btn btn-icon btn-outline-danger btn-sm" type="submit" title="Elimina definitivamente">
                                                            <i class="fa-solid fa-trash fa-sm fa-fw"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-5">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="card-title h5 mb-0">Borderò generati</h2>
            </div>
            <div class="card-body p-0">
                <?php if (!$recentManifests): ?>
                    <div class="p-4 text-center text-muted">Nessun borderò generato finora.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Borderò</th>
                                    <th scope="col">Generato</th>
                                    <th scope="col">Spedizioni</th>
                                    <th scope="col">Colli</th>
                                    <th scope="col">Peso totale (Kg)</th>
                                    <th scope="col" class="text-end">Documenti</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentManifests as $manifest): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize_output($manifest['reference']); ?></div>
                                            <?php if (!empty($manifest['official_number'])): ?>
                                                <div class="small text-muted">WS: <?php echo sanitize_output($manifest['official_number']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output(format_datetime_locale($manifest['generated_at'] ?? '')); ?></td>
                                        <td><?php echo (int) $manifest['shipments_count']; ?></td>
                                        <td><?php echo (int) $manifest['total_parcels']; ?></td>
                                        <td><?php echo sanitize_output(number_format((float) $manifest['total_weight_kg'], 2, ',', '.')); ?></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <?php if (!empty($manifest['pdf_path'])): ?>
                                                    <a class="btn btn-outline-secondary" href="<?php echo asset($manifest['pdf_path']); ?>" target="_blank" rel="noopener">
                                                        <i class="fa-solid fa-file-pdf me-1"></i>Locale
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($manifest['official_url'])): ?>
                                                    <a class="btn btn-outline-primary" href="<?php echo sanitize_output($manifest['official_url']); ?>" target="_blank" rel="noopener">
                                                        <i class="fa-solid fa-link me-1"></i>WS
                                                    </a>
                                                <?php elseif (!empty($manifest['official_pdf_path'])): ?>
                                                    <a class="btn btn-outline-primary" href="<?php echo asset($manifest['official_pdf_path']); ?>" target="_blank" rel="noopener">
                                                        <i class="fa-solid fa-file-arrow-down me-1"></i>WS
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-5">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="card-title h5 mb-0">Ordini di ritiro (ORM) recenti</h2>
                <a class="btn btn-outline-secondary btn-sm" href="orm.php"><i class="fa-solid fa-truck-ramp-box me-2"></i>Gestisci ORM</a>
            </div>
            <div class="card-body p-0">
                <?php if (!$recentOrmRequests): ?>
                    <div class="p-4 text-center text-muted">Nessun ordine di ritiro registrato.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Prenotazione</th>
                                    <th scope="col">Data ritiro</th>
                                    <th scope="col">Parcelle</th>
                                    <th scope="col">Peso (Kg)</th>
                                    <th scope="col">Stato</th>
                                    <th scope="col">Creato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrmRequests as $orm): ?>
                                    <tr>
                                        <td>#<?php echo (int) $orm['id']; ?></td>
                                        <td><?php echo $orm['reservation_number'] ? sanitize_output($orm['reservation_number']) : '<span class="text-muted">—</span>'; ?></td>
                                        <td><?php echo $orm['collection_date'] ? sanitize_output(format_date_locale($orm['collection_date'])) : '<span class="text-muted">—</span>'; ?></td>
                                        <td><?php echo $orm['parcels'] !== null ? (int) $orm['parcels'] : '<span class="text-muted">—</span>'; ?></td>
                                        <td><?php echo $orm['weight_kg'] !== null ? sanitize_output(number_format((float) $orm['weight_kg'], 2, ',', '.')) : '<span class="text-muted">—</span>'; ?></td>
                                        <td><span class="badge bg-secondary text-uppercase"><?php echo sanitize_output($orm['status']); ?></span></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($orm['created_at'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
</div>
