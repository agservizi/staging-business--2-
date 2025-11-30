<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtOrmService;

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
$pageTitle = 'Ordini ritiro BRT (ORM)';

$csrfToken = csrf_token();

try {
    ensure_brt_tables();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('Database BRT non configurato: ' . $exception->getMessage());
}

$config = new BrtConfig();

try {
    $defaultCustomer = $config->getSenderCustomerCode();
} catch (BrtException $exception) {
    add_flash('warning', $exception->getMessage());
    header('Location: index.php');
    exit;
}

$defaultCountry = $config->getDefaultCountryIsoAlpha2() ?? 'IT';

$data = [
    'collection_date' => date('Y-m-d'),
    'collection_time' => '10:00',
    'parcel_count' => '1',
    'weight_kg' => '',
    'good_description' => '',
    'payer_type' => 'Ordering',
    'service_code' => '',
    'customer_account_number' => $defaultCustomer,
    'requester_customer_number' => $defaultCustomer,
    'sender_customer_number' => '',
    'sender_company_name' => '',
    'sender_address' => '',
    'sender_zip' => '',
    'sender_city' => '',
    'sender_state' => '',
    'sender_country' => $defaultCountry,
    'sender_contact_person' => '',
    'sender_contact_phone' => '',
    'receiver_company_name' => '',
    'receiver_address' => '',
    'receiver_zip' => '',
    'receiver_city' => '',
    'receiver_state' => '',
    'receiver_country' => $defaultCountry,
    'alerts_email' => '',
    'alerts_sms' => '',
    'notes' => '',
    'parcel_lines' => '',
    'opening_hour_1_from' => '',
    'opening_hour_1_to' => '',
    'opening_hour_2_from' => '',
    'opening_hour_2_to' => '',
    'request_ref' => '',
    'source_shipment_id' => '',
];

$errors = [];

$allowedPayerTypes = ['Ordering', 'Sender', 'Consignee'];

$recentOrmRequests = [];

$prefillShipment = null;

$isEditing = false;
$editingRequestId = null;
$editingReservationNumber = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editId = (int) ($_GET['edit'] ?? 0);
    $duplicateId = (int) ($_GET['duplicate'] ?? 0);
    $loadId = $editId > 0 ? $editId : ($duplicateId > 0 ? $duplicateId : 0);

    if ($loadId > 0) {
        $existingRequest = brt_get_orm_request($loadId);
        if ($existingRequest) {
            $formPayloadJson = $existingRequest['form_payload'] ?? null;
            $formPayload = null;
            if (is_string($formPayloadJson) && $formPayloadJson !== '') {
                $decoded = json_decode($formPayloadJson, true);
                if (is_array($decoded)) {
                    $formPayload = $decoded;
                }
            }

            if ($formPayload === null) {
                $requestPayloadJson = $existingRequest['request_payload'] ?? null;
                if (is_string($requestPayloadJson) && $requestPayloadJson !== '') {
                    $decoded = json_decode($requestPayloadJson, true);
                    if (is_array($decoded)) {
                        $formPayload = $decoded;
                    }
                }
            }

            if (is_array($formPayload)) {
                foreach ($data as $field => $default) {
                    if (array_key_exists($field, $formPayload)) {
                        $value = $formPayload[$field];
                        $data[$field] = is_scalar($value) ? (string) $value : $default;
                    }
                }
            }

            if ($editId > 0) {
                $isEditing = true;
                $editingRequestId = (int) $existingRequest['id'];
                $editingReservationNumber = (string) ($existingRequest['reservation_number'] ?? '');
            }

            if ($data['source_shipment_id'] !== '') {
                $prefillShipment = brt_get_shipment((int) $data['source_shipment_id']);
            }
        } else {
            add_flash('warning', 'Richiesta ORM non trovata.');
        }
    } else {
        $sourceShipmentId = (int) ($_GET['from_shipment'] ?? 0);
        if ($sourceShipmentId > 0) {
            $shipment = brt_get_shipment($sourceShipmentId);
            if ($shipment) {
                $data = brt_prefill_orm_form_data_from_shipment($data, $shipment, $config);
                $prefillShipment = $shipment;
            } else {
                add_flash('warning', 'Spedizione BRT di origine non trovata.');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_orm' || $action === 'update_orm') {
        $isUpdateAction = $action === 'update_orm';
        $currentRequest = null;

        if ($isUpdateAction) {
            $isEditing = true;
            $editingRequestId = (int) ($_POST['request_id'] ?? 0);
            $editingReservationNumber = trim((string) ($_POST['reservation_number'] ?? ''));

            if ($editingRequestId <= 0 || $editingReservationNumber === '') {
                $errors[] = 'Seleziona una prenotazione ORM valida da aggiornare.';
            } else {
                $currentRequest = brt_get_orm_request($editingRequestId);
                if (!$currentRequest) {
                    $errors[] = 'La richiesta ORM da aggiornare non è più disponibile.';
                } elseif ((string) ($currentRequest['reservation_number'] ?? '') !== $editingReservationNumber) {
                    $errors[] = 'Il numero di prenotazione fornito non coincide con quello registrato.';
                }
            }
        }

        foreach ($data as $field => $default) {
            $data[$field] = trim((string) ($_POST[$field] ?? ''));
        }

        $data['receiver_country'] = strtoupper($data['receiver_country']);
        if ($data['receiver_country'] === 'IE') {
            if ($data['receiver_zip'] === '') {
                $data['receiver_zip'] = 'EIRE';
            } else {
                $data['receiver_zip'] = strtoupper($data['receiver_zip']);
            }
        }

        if ($data['request_ref'] !== '') {
            $data['request_ref'] = brt_truncate_string($data['request_ref'], 35);
        }

        if ($data['source_shipment_id'] !== '') {
            $sourceShipment = (int) $data['source_shipment_id'];
            $data['source_shipment_id'] = $sourceShipment > 0 ? (string) $sourceShipment : '';
            if ($sourceShipment > 0) {
                $prefillShipment = brt_get_shipment($sourceShipment) ?: null;
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['collection_date'])) {
            $errors[] = 'Inserisci una data di ritiro valida (formato YYYY-MM-DD).';
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $data['collection_time'])) {
            $errors[] = 'Inserisci un orario di ritiro valido (formato HH:MM).';
        }

        if (!ctype_digit($data['parcel_count']) || (int) $data['parcel_count'] <= 0) {
            $errors[] = 'Il numero di colli deve essere un intero positivo.';
        }

        $weightValue = $data['weight_kg'] === '' ? null : (float) str_replace(',', '.', $data['weight_kg']);
        if ($weightValue !== null && $weightValue < 0) {
            $errors[] = 'Il peso deve essere maggiore o uguale a zero.';
        }

        if ($data['good_description'] === '') {
            $errors[] = 'Specifica la natura della merce.';
        }

        if (!in_array($data['payer_type'], $allowedPayerTypes, true)) {
            $data['payer_type'] = 'Ordering';
        }

        if ($data['customer_account_number'] === '') {
            $errors[] = 'Inserisci il codice cliente BRT principale.';
        }

        if ($data['requester_customer_number'] === '') {
            $errors[] = 'Inserisci il codice cliente dell\'ordinante (RQ).';
        }

        $hasSenderCode = $data['sender_customer_number'] !== '';
        $hasSenderAddress = $data['sender_company_name'] !== '' && $data['sender_address'] !== '' && $data['sender_zip'] !== '' && $data['sender_city'] !== '' && $data['sender_state'] !== '';
        if (!$hasSenderCode && !$hasSenderAddress) {
            $errors[] = 'Specifica il codice cliente mittente oppure compila l\'indirizzo completo.';
        }

        if ($data['sender_contact_person'] === '' || $data['sender_contact_phone'] === '') {
            $errors[] = 'Inserisci referente e telefono per il mittente (obbligatori per BRT).';
        }

        if ($data['service_code'] === 'B20' && trim($data['parcel_lines']) === '') {
            $errors[] = 'Per il servizio Fresh B20 è necessario indicare i parcelID con data di scadenza.';
        }

        $parcelInfos = [];
        if ($data['parcel_lines'] !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $data['parcel_lines']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/[|;]/', $line);
                $parcelId = trim((string) ($parts[0] ?? ''));
                $expiration = trim((string) ($parts[1] ?? ''));
                if ($parcelId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration)) {
                    $errors[] = 'Formato parcelID non valido. Utilizzare "CODICE|YYYY-MM-DD" per ogni riga.';
                    break;
                }
                $parcelInfos[] = [
                    'id' => $parcelId,
                    'expirationDate' => $expiration,
                ];
            }
        }

        if (!$errors) {
            $order = [
                'requestInfos' => [
                    'parcelCount' => (int) $data['parcel_count'],
                    'collectionDate' => $data['collection_date'],
                ],
                'customerInfos' => [
                    'custAccNumber' => $data['customer_account_number'],
                ],
                'stakeholders' => [],
                'brtSpec' => [
                    'goodDescription' => $data['good_description'],
                    'payerType' => $data['payer_type'],
                    'collectionTime' => $data['collection_time'],
                ],
            ];

            if ($weightValue !== null) {
                $order['brtSpec']['weightKG'] = $weightValue;
            }
            if ($data['service_code'] !== '') {
                $order['brtSpec']['serviceCode'] = $data['service_code'];
            }
            if ($data['notes'] !== '') {
                $order['brtSpec']['notes'] = $data['notes'];
            }
            if ($data['request_ref'] !== '') {
                $order['brtSpec']['requestRef'] = $data['request_ref'];
            }

            $alerts = [];
            if ($data['alerts_email'] !== '') {
                $alerts[] = ['type' => 'CONFIRM', 'mail' => $data['alerts_email']];
            }
            if ($data['alerts_sms'] !== '') {
                $alerts[] = ['type' => 'CONFIRM', 'sms' => $data['alerts_sms']];
            }
            if ($alerts !== []) {
                $order['brtSpec']['alerts'] = $alerts;
            }

            $openingHours = [];
            if ($data['opening_hour_1_from'] !== '' && $data['opening_hour_1_to'] !== '') {
                $openingHours[] = ['from' => $data['opening_hour_1_from'], 'to' => $data['opening_hour_1_to']];
            }
            if ($data['opening_hour_2_from'] !== '' && $data['opening_hour_2_to'] !== '') {
                $openingHours[] = ['from' => $data['opening_hour_2_from'], 'to' => $data['opening_hour_2_to']];
            }
            if ($openingHours !== []) {
                $order['brtSpec']['openingHours'] = $openingHours;
            }

            if ($parcelInfos !== []) {
                $order['brtSpec']['parcelInfos'] = $parcelInfos;
            }

            $order['stakeholders'][] = [
                'type' => 'RQ',
                'customerInfos' => [
                    'custAccNumber' => $data['requester_customer_number'],
                ],
            ];

            $senderStakeholder = [
                'type' => 'SE',
                'contact' => [
                    'contactDetails' => [
                        'contactPerson' => $data['sender_contact_person'],
                        'phone' => $data['sender_contact_phone'],
                    ],
                ],
            ];
            if ($hasSenderCode) {
                $senderStakeholder['customerInfos'] = ['custAccNumber' => $data['sender_customer_number']];
            } else {
                $senderStakeholder['address'] = [
                    'compName' => $data['sender_company_name'],
                    'street' => $data['sender_address'],
                    'zipCode' => $data['sender_zip'],
                    'city' => $data['sender_city'],
                    'state' => $data['sender_state'],
                    'countryCode' => $data['sender_country'] ?: $defaultCountry,
                ];
            }
            $order['stakeholders'][] = $senderStakeholder;

            if ($data['receiver_company_name'] !== '') {
                if ($data['receiver_address'] === '' || $data['receiver_zip'] === '' || $data['receiver_city'] === '' || $data['receiver_state'] === '') {
                    $errors[] = 'Per inserire il destinatario (RE) compila indirizzo, CAP, città e provincia.';
                } else {
                    $order['stakeholders'][] = [
                        'type' => 'RE',
                        'address' => [
                            'compName' => $data['receiver_company_name'],
                            'street' => $data['receiver_address'],
                            'zipCode' => $data['receiver_zip'],
                            'city' => $data['receiver_city'],
                            'state' => $data['receiver_state'],
                            'countryCode' => $data['receiver_country'] ?: $defaultCountry,
                        ],
                    ];
                }
            }

            if (!$errors) {
                $ormService = new BrtOrmService($config);

                if ($isUpdateAction) {
                    try {
                        $response = $ormService->updateOrder($editingReservationNumber, $order);
                        $remotePayload = null;
                        $syncWarning = null;

                        try {
                            $remotePayload = $ormService->getOrder($editingReservationNumber);
                        } catch (Throwable $syncException) {
                            $syncWarning = $syncException->getMessage();
                        }

                        if ($editingRequestId !== null && $editingRequestId > 0) {
                            brt_mark_orm_updated($editingRequestId, [$order], $response, $data, $remotePayload);
                        }

                        $reservationLabel = $editingReservationNumber !== '' ? ' #' . $editingReservationNumber : '';
                        add_flash('success', 'Prenotazione ORM' . $reservationLabel . ' aggiornata correttamente.');
                        if ($syncWarning !== null) {
                            add_flash('warning', 'Aggiornamento completato ma impossibile sincronizzare lo stato remoto: ' . $syncWarning);
                        }

                        header('Location: orm.php');
                        exit;
                    } catch (BrtException $exception) {
                        if ($editingRequestId !== null && $editingRequestId > 0) {
                            brt_mark_orm_error($editingRequestId, $exception->getMessage());
                        }
                        $errors[] = $exception->getMessage();
                    } catch (Throwable $exception) {
                        $errors[] = 'Errore durante l\'aggiornamento dell\'ordine di ritiro: ' . $exception->getMessage();
                    }
                } else {
                    try {
                        $response = $ormService->createOrders([$order]);
                        $requestId = brt_store_orm_response([$order], $response, $data);

                        $reservation = $response[0]['ormReservationNumber'] ?? null;
                        if ($reservation) {
                            add_flash('success', 'Ordine di ritiro creato. Prenotazione #' . $reservation . '.');
                        } else {
                            add_flash('success', 'Ordine di ritiro creato correttamente.');
                        }

                        header('Location: orm.php');
                        exit;
                    } catch (BrtException $exception) {
                        $errors[] = $exception->getMessage();
                    } catch (Throwable $exception) {
                        $errors[] = 'Errore durante la creazione dell\'ordine di ritiro: ' . $exception->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'sync_orm') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $reservationNumber = trim((string) ($_POST['reservation_number'] ?? ''));

        if ($requestId <= 0 || $reservationNumber === '') {
            add_flash('warning', 'Seleziona una prenotazione ORM valida da sincronizzare.');
        } else {
            try {
                $ormService = new BrtOrmService($config);
                $remotePayload = $ormService->getOrder($reservationNumber);
                brt_mark_orm_synced($requestId, $remotePayload);
                add_flash('success', 'Prenotazione #' . $reservationNumber . ' sincronizzata con successo.');
            } catch (BrtException $exception) {
                add_flash('warning', 'Impossibile sincronizzare la prenotazione: ' . $exception->getMessage());
            } catch (Throwable $exception) {
                add_flash('warning', 'Errore durante la sincronizzazione: ' . $exception->getMessage());
            }
        }

        header('Location: orm.php');
        exit;
    } elseif ($action === 'cancel_orm') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $reservationNumber = trim((string) ($_POST['reservation_number'] ?? ''));
        $record = null;
        if ($requestId > 0) {
            $record = brt_get_orm_request($requestId);
            if ($record && $reservationNumber === '') {
                $reservationNumber = (string) ($record['reservation_number'] ?? '');
            }
        }
        if ($reservationNumber === '') {
            add_flash('warning', 'Numero di prenotazione non disponibile per la cancellazione.');
        } else {
            try {
                $ormService = new BrtOrmService($config);
                $result = $ormService->cancelOrder($reservationNumber);
                if ($requestId > 0 || $record) {
                    $targetId = $requestId > 0 ? $requestId : (int) ($record['id'] ?? 0);
                    if ($targetId > 0) {
                        brt_mark_orm_cancelled($targetId, $result, is_array($result) ? $result : null);
                    }
                }
                if ($result) {
                    add_flash('success', 'Prenotazione BRT #' . $reservationNumber . ' annullata correttamente.');
                } else {
                    add_flash('warning', 'La prenotazione BRT #' . $reservationNumber . ' non risulta annullata. Verificare sul portale BRT.');
                }
            } catch (BrtException $exception) {
                if ($requestId > 0) {
                    brt_mark_orm_error($requestId, $exception->getMessage());
                }
                add_flash('warning', 'Impossibile annullare la prenotazione: ' . $exception->getMessage());
            } catch (Throwable $exception) {
                if ($requestId > 0) {
                    brt_mark_orm_error($requestId, $exception->getMessage());
                }
                add_flash('warning', 'Errore durante la cancellazione: ' . $exception->getMessage());
            }
        }
        header('Location: orm.php');
        exit;
    }
}

$recentOrmRequests = brt_get_recent_orm_requests();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Ordini di ritiro BRT</h1>
                <p class="text-muted mb-0">Crea e gestisci le richieste ORM tramite API BRT Pickup.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alle spedizioni</a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning" role="alert">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo sanitize_output($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($isEditing): ?>
                    <div class="alert alert-info d-flex align-items-center" role="alert">
                        <i class="fa-solid fa-pen-to-square me-2"></i>
                        <div>
                            <?php
                            $editingLabel = $editingReservationNumber !== ''
                                ? '# ' . sanitize_output($editingReservationNumber)
                                : '# ' . (int) $editingRequestId;
                            ?>
                            Modifica della prenotazione ORM <?php echo $editingLabel; ?>.
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $isEditing ? 'update_orm' : 'create_orm'; ?>">
                    <input type="hidden" name="source_shipment_id" value="<?php echo sanitize_output($data['source_shipment_id']); ?>">
                    <input type="hidden" name="request_ref" value="<?php echo sanitize_output($data['request_ref']); ?>">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="request_id" value="<?php echo (int) $editingRequestId; ?>">
                        <input type="hidden" name="reservation_number" value="<?php echo sanitize_output($editingReservationNumber); ?>">
                    <?php endif; ?>
                    <?php if ($prefillShipment !== null): ?>
                        <div class="alert alert-info d-flex align-items-start" role="alert">
                            <i class="fa-solid fa-truck-ramp-box me-2 mt-1"></i>
                            <div>
                                <strong>Richiesta precompilata</strong> dalla spedizione
                                <a href="view.php?id=<?php echo (int) $prefillShipment['id']; ?>" class="alert-link">#<?php echo (int) $prefillShipment['id']; ?></a>
                                verso <?php echo sanitize_output($prefillShipment['consignee_name'] ?? ''); ?>.
                                <div class="small text-muted">
                                    Riferimento mittente: <?php echo sanitize_output((string) ($prefillShipment['numeric_sender_reference'] ?? 'N/D')); ?>
                                    <?php if ($data['request_ref'] !== ''): ?>
                                        · RequestRef: <?php echo sanitize_output($data['request_ref']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <h2 class="h5 mb-3">Dati generali</h2>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label" for="collection_date">Data ritiro</label>
                            <input class="form-control" type="date" id="collection_date" name="collection_date" value="<?php echo sanitize_output($data['collection_date']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="collection_time">Orario</label>
                            <input class="form-control" type="time" id="collection_time" name="collection_time" value="<?php echo sanitize_output($data['collection_time']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="parcel_count">Numero colli</label>
                            <input class="form-control" type="number" min="1" id="parcel_count" name="parcel_count" value="<?php echo sanitize_output($data['parcel_count']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="weight_kg">Peso totale (Kg)</label>
                            <input class="form-control" type="text" id="weight_kg" name="weight_kg" value="<?php echo sanitize_output($data['weight_kg']); ?>" placeholder="es. 22.3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="good_description">Natura merce</label>
                            <input class="form-control" type="text" id="good_description" name="good_description" value="<?php echo sanitize_output($data['good_description']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="payer_type">Pagante</label>
                            <select class="form-select" id="payer_type" name="payer_type">
                                <?php foreach ($allowedPayerTypes as $payer): ?>
                                    <option value="<?php echo sanitize_output($payer); ?>"<?php echo $data['payer_type'] === $payer ? ' selected' : ''; ?>><?php echo sanitize_output($payer); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_code">Service Code (opz.)</label>
                            <input class="form-control" type="text" id="service_code" name="service_code" value="<?php echo sanitize_output($data['service_code']); ?>" placeholder="es. B20">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="notes">Note (opzionali)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"><?php echo sanitize_output($data['notes']); ?></textarea>
                        </div>
                    </div>

                    <h2 class="h5 mb-3">Account & Stakeholder</h2>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label" for="customer_account_number">Codice cliente principale</label>
                            <input class="form-control" type="text" id="customer_account_number" name="customer_account_number" value="<?php echo sanitize_output($data['customer_account_number']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="requester_customer_number">Codice ordinante (RQ)</label>
                            <input class="form-control" type="text" id="requester_customer_number" name="requester_customer_number" value="<?php echo sanitize_output($data['requester_customer_number']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="sender_customer_number">Codice mittente (SE)</label>
                            <input class="form-control" type="text" id="sender_customer_number" name="sender_customer_number" value="<?php echo sanitize_output($data['sender_customer_number']); ?>" placeholder="Compilare se mittente censito">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <h3 class="h6 mb-2">Mittente (SE) - indirizzo alternativo</h3>
                            <p class="text-muted small">Compila i campi sottostanti solo se non è disponibile un codice cliente mittente.</p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sender_company_name">Ragione sociale</label>
                            <input class="form-control" type="text" id="sender_company_name" name="sender_company_name" value="<?php echo sanitize_output($data['sender_company_name']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sender_address">Indirizzo</label>
                            <input class="form-control" type="text" id="sender_address" name="sender_address" value="<?php echo sanitize_output($data['sender_address']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="sender_zip">CAP</label>
                            <input class="form-control" type="text" id="sender_zip" name="sender_zip" value="<?php echo sanitize_output($data['sender_zip']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="sender_city">Città</label>
                            <input class="form-control" type="text" id="sender_city" name="sender_city" value="<?php echo sanitize_output($data['sender_city']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="sender_state">Provincia</label>
                            <input class="form-control" type="text" id="sender_state" name="sender_state" value="<?php echo sanitize_output($data['sender_state']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="sender_country">Nazione (ISO-2)</label>
                            <input class="form-control" type="text" id="sender_country" name="sender_country" value="<?php echo sanitize_output($data['sender_country']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sender_contact_person">Referente</label>
                            <input class="form-control" type="text" id="sender_contact_person" name="sender_contact_person" value="<?php echo sanitize_output($data['sender_contact_person']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sender_contact_phone">Telefono</label>
                            <input class="form-control" type="text" id="sender_contact_phone" name="sender_contact_phone" value="<?php echo sanitize_output($data['sender_contact_phone']); ?>" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <h3 class="h6 mb-2">Destinatario (RE) opzionale</h3>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="receiver_company_name">Ragione sociale</label>
                            <input class="form-control" type="text" id="receiver_company_name" name="receiver_company_name" value="<?php echo sanitize_output($data['receiver_company_name']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="receiver_address">Indirizzo</label>
                            <input class="form-control" type="text" id="receiver_address" name="receiver_address" value="<?php echo sanitize_output($data['receiver_address']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="receiver_zip">CAP</label>
                            <input class="form-control" type="text" id="receiver_zip" name="receiver_zip" value="<?php echo sanitize_output($data['receiver_zip']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="receiver_city">Città</label>
                            <input class="form-control" type="text" id="receiver_city" name="receiver_city" value="<?php echo sanitize_output($data['receiver_city']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="receiver_state">Provincia</label>
                            <input class="form-control" type="text" id="receiver_state" name="receiver_state" value="<?php echo sanitize_output($data['receiver_state']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="receiver_country">Nazione (ISO-2)</label>
                            <input class="form-control" type="text" id="receiver_country" name="receiver_country" value="<?php echo sanitize_output($data['receiver_country']); ?>">
                        </div>
                    </div>

                    <h2 class="h5 mb-3">Avvisi e disponibilità</h2>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label" for="alerts_email">Email conferma (opz.)</label>
                            <input class="form-control" type="email" id="alerts_email" name="alerts_email" value="<?php echo sanitize_output($data['alerts_email']); ?>" placeholder="mail@mail.it">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="alerts_sms">SMS conferma (opz.)</label>
                            <input class="form-control" type="text" id="alerts_sms" name="alerts_sms" value="<?php echo sanitize_output($data['alerts_sms']); ?>" placeholder="es. 3331234567">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Orari apertura</label>
                            <div class="input-group">
                                <input class="form-control" type="time" name="opening_hour_1_from" value="<?php echo sanitize_output($data['opening_hour_1_from']); ?>" placeholder="Da">
                                <span class="input-group-text">-</span>
                                <input class="form-control" type="time" name="opening_hour_1_to" value="<?php echo sanitize_output($data['opening_hour_1_to']); ?>" placeholder="A">
                            </div>
                            <div class="input-group mt-2">
                                <input class="form-control" type="time" name="opening_hour_2_from" value="<?php echo sanitize_output($data['opening_hour_2_from']); ?>" placeholder="Da">
                                <span class="input-group-text">-</span>
                                <input class="form-control" type="time" name="opening_hour_2_to" value="<?php echo sanitize_output($data['opening_hour_2_to']); ?>" placeholder="A">
                            </div>
                        </div>
                    </div>

                    <h2 class="h5 mb-3">Dettaglio colli Fresh (opzionale)</h2>
                    <div class="mb-4">
                        <label class="form-label" for="parcel_lines">Elenco parcelID e scadenze</label>
                        <textarea class="form-control" id="parcel_lines" name="parcel_lines" rows="4" placeholder="XXXXXXXX1|2024-03-10&#10;XXXXXXXX2|2024-03-15"><?php echo sanitize_output($data['parcel_lines']); ?></textarea>
                        <div class="form-text">Compila una riga per collo con formato <code>PARCELID|YYYY-MM-DD</code>. Obbligatorio per servizio Fresh B20.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit">
                            <i class="fa-solid <?php echo $isEditing ? 'fa-pen-to-square' : 'fa-truck-ramp-box'; ?> me-2"></i>
                            <?php echo $isEditing ? 'Aggiorna prenotazione ORM' : 'Invia richiesta ORM'; ?>
                        </button>
                        <a class="btn btn-outline-secondary" href="<?php echo $isEditing ? 'orm.php' : 'index.php'; ?>">Annulla</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="card-title h5 mb-0">Storico richieste</h2>
                <span class="text-muted small">Ultime 50 richieste</span>
            </div>
            <div class="card-body p-0">
                <?php if (!$recentOrmRequests): ?>
                    <div class="p-4 text-center text-muted">Nessuna richiesta registrata.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Prenotazione</th>
                                    <th>Data ritiro</th>
                                    <th>Colli</th>
                                    <th>Peso (Kg)</th>
                                    <th>Stato</th>
                                    <th>Creato</th>
                                    <th class="text-end">Azioni</th>
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
                                        <?php
                                        $status = (string) ($orm['status'] ?? '');
                                        $statusLabel = $status !== '' ? strtoupper($status) : 'N/D';
                                        $statusKey = strtolower($status);
                                        $statusClass = 'bg-secondary';
                                        if ($statusKey === 'confirmed' || $statusKey === 'synced' || $statusKey === 'updated') {
                                            $statusClass = 'bg-success';
                                        } elseif ($statusKey === 'pending') {
                                            $statusClass = 'bg-warning text-dark';
                                        } elseif ($statusKey === 'error' || $statusKey === 'cancel_failed') {
                                            $statusClass = 'bg-danger';
                                        } elseif ($statusKey === 'cancelled') {
                                            $statusClass = 'bg-dark';
                                        }

                                        $remoteStatus = (string) ($orm['remote_status'] ?? '');
                                        $remoteKey = strtolower($remoteStatus);
                                        $remoteClass = 'bg-info';
                                        if ($remoteKey === 'error') {
                                            $remoteClass = 'bg-danger';
                                        } elseif ($remoteKey === 'pending') {
                                            $remoteClass = 'bg-warning text-dark';
                                        } elseif ($remoteKey === 'confirmed' || $remoteKey === 'synced' || $remoteKey === 'updated') {
                                            $remoteClass = 'bg-success';
                                        }
                                        ?>
                                        <td>
                                            <span class="badge <?php echo $statusClass; ?> text-uppercase"><?php echo sanitize_output($statusLabel); ?></span>
                                            <?php if ($remoteStatus !== '' && strcasecmp($remoteStatus, $status) !== 0): ?>
                                                <span class="badge <?php echo $remoteClass; ?> text-uppercase ms-1"><?php echo sanitize_output(strtoupper($remoteStatus)); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output(format_datetime_locale($orm['created_at'] ?? '')); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <?php if (!empty($orm['reservation_number']) && !in_array(strtolower((string) $orm['status']), ['cancelled', 'cancel_failed'], true)): ?>
                                                    <a class="btn btn-icon btn-soft-primary btn-sm" href="orm.php?edit=<?php echo (int) $orm['id']; ?>" title="Modifica prenotazione">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <a class="btn btn-icon btn-soft-secondary btn-sm" href="orm.php?duplicate=<?php echo (int) $orm['id']; ?>" title="Duplica richiesta">
                                                    <i class="fa-solid fa-clone"></i>
                                                </a>

                                                <?php if (!empty($orm['reservation_number'])): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="sync_orm">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) $orm['id']; ?>">
                                                        <input type="hidden" name="reservation_number" value="<?php echo sanitize_output($orm['reservation_number']); ?>">
                                                        <button class="btn btn-icon btn-soft-info btn-sm" type="submit" title="Sincronizza stato">
                                                            <i class="fa-solid fa-arrows-rotate"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($orm['status'] === 'confirmed' && !empty($orm['reservation_number'])): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Confermi la cancellazione della prenotazione BRT?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="cancel_orm">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) $orm['id']; ?>">
                                                        <input type="hidden" name="reservation_number" value="<?php echo sanitize_output($orm['reservation_number']); ?>">
                                                        <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Annulla prenotazione">
                                                            <i class="fa-solid fa-ban"></i>
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
    </main>
    <?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
</div>
