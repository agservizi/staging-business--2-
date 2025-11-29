<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Scanner QR + PIN';
$extraScripts = $extraScripts ?? [];


$extraScripts[] = asset('assets/vendor/jsqr/jsQR.js');
$extraScripts[] = asset('assets/js/mfa-qr-scanner.js');

$completeEndpoint = base_url('api/mfa/qr/devices/complete.php');
$challengeLookupEndpoint = base_url('api/mfa/qr/challenges/lookup.php');
$challengeDecisionEndpoint = base_url('api/mfa/qr/challenges/decision.php');
$devicesEndpoint = base_url('api/mfa/qr/devices/index.php');
$csrfToken = csrf_token();
$tokenExample = '00000000000000000000000000000000';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content flex-grow-1">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>
    <div class="content-wrapper p-4 p-lg-5" data-qr-page>
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('dashboard.php'); ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo base_url('modules/impostazioni/profile.php'); ?>">Profilo</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Scanner QR + PIN</li>
                </ol>
            </nav>
            <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 justify-content-between">
                <div>
                    <h1 class="h3 mb-1">Web app scanner QR</h1>
                    <p class="text-muted mb-0">Utilizza la fotocamera del dispositivo per attivare i dispositivi MFA QR + PIN.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/impostazioni/profile.php'); ?>">
                        <i class="fa-solid fa-arrow-left me-2"></i>Torna al profilo
                    </a>
                    <button type="button" class="btn btn-outline-warning" data-qr-install disabled>
                        <i class="fa-solid fa-download me-2"></i>Installa come app
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-4" data-qr-layout>
            <div class="col-12 col-xl-7">
                 <div class="card ag-card h-100" data-qr-scanner
                     data-complete-endpoint="<?php echo sanitize_output($completeEndpoint); ?>"
                     data-challenge-lookup="<?php echo sanitize_output($challengeLookupEndpoint); ?>"
                     data-challenge-decision="<?php echo sanitize_output($challengeDecisionEndpoint); ?>"
                     data-devices-endpoint="<?php echo sanitize_output($devicesEndpoint); ?>"
                     data-csrf="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="card-header bg-transparent border-0 d-flex flex-column flex-lg-row gap-2 align-items-lg-center">
                        <div>
                            <h5 class="card-title mb-1">Scanner live</h5>
                            <p class="text-muted mb-0 small">Concedi l'accesso alla fotocamera e inquadra il QR generato nel profilo.</p>
                        </div>
                        <div class="ms-lg-auto d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-warning btn-sm" data-qr-start>
                                <i class="fa-solid fa-play me-1"></i>Avvia scansione
                            </button>
                            <button type="button" class="btn btn-outline-light btn-sm" data-qr-stop disabled>
                                <i class="fa-solid fa-stop me-1"></i>Ferma
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-qr-reset disabled>
                                <i class="fa-solid fa-arrows-rotate me-1"></i>Nuova scansione
                            </button>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <div class="alert alert-warning small d-none" role="alert" data-qr-support></div>
                        <div class="border rounded-3 p-3 bg-body-secondary d-flex flex-column gap-3" data-qr-camera-panel>
                            <div class="d-flex flex-column flex-lg-row gap-2 align-items-lg-center">
                                <div class="flex-fill">
                                    <div class="small text-muted">Fotocamera</div>
                                    <select class="form-select" data-qr-camera-select disabled>
                                        <option value="">—</option>
                                    </select>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-qr-camera-refresh>
                                    <i class="fa-solid fa-rotate me-1"></i>Rileva camere
                                </button>
                            </div>
                            <div class="form-text" data-qr-camera-hint>Concedi l'accesso alla fotocamera per elencare i dispositivi disponibili.</div>
                        </div>
                        <div class="border rounded-3 bg-dark position-relative overflow-hidden" style="min-height:320px;" data-qr-stage>
                            <video class="w-100 h-100" style="object-fit:cover;" autoplay playsinline muted data-qr-video></video>
                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column justify-content-center align-items-center text-white bg-dark bg-opacity-75" data-qr-placeholder>
                                <i class="fa-solid fa-camera-retro fa-2x mb-3"></i>
                                <p class="mb-0 text-center">Concedi l'accesso alla fotocamera e premi "Avvia scansione".</p>
                            </div>
                        </div>
                        <div class="border rounded-3 p-3 bg-body-secondary">
                            <div class="small text-muted">Stato scanner</div>
                            <div class="fw-semibold" data-qr-status>In attesa di avvio.</div>
                        </div>
                        <div class="border rounded-3 p-3 bg-body-secondary d-none" data-qr-result>
                            <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2 mb-2">
                                <div>
                                    <div class="small text-muted">Token rilevato</div>
                                    <code class="text-break" data-qr-token><?php echo $tokenExample; ?></code>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm ms-lg-auto" data-qr-copy disabled>
                                    <i class="fa-solid fa-copy me-1"></i>Copia token
                                </button>
                            </div>
                            <div class="small" data-qr-message>—</div>
                            <div class="mt-3">
                                <div class="small text-muted">Payload letto</div>
                                <pre class="small mb-0 text-break" data-qr-raw>—</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-1">Esito attivazione</h5>
                        <p class="text-muted small mb-0">Lo stato viene aggiornato automaticamente quando il QR contiene un provisioning token valido.</p>
                    </div>
                    <div class="card-body d-flex flex-column gap-3" data-qr-activation>
                        <div class="alert alert-info" role="status" data-qr-activation-status>
                            <i class="fa-solid fa-circle-info me-2"></i>Nessuna scansione ancora rilevata.
                        </div>
                        <div class="border rounded-3 p-3 bg-body-tertiary" data-qr-device-panel>
                            <div class="small text-muted">Dispositivo collegato su questo browser</div>
                            <div class="fw-semibold" data-qr-device-name>Nessun dispositivo associato a questa web app</div>
                            <div class="text-muted small">UUID: <span data-qr-device-id>—</span></div>
                            <div class="form-text mt-2" data-qr-device-hint>Attiva o seleziona un dispositivo per poter approvare i login da questo browser.</div>
                            <div class="mt-3">
                                <label class="form-label" for="qr_device_select">Seleziona dispositivo attivo</label>
                                <select class="form-select" id="qr_device_select" data-qr-device-select disabled>
                                    <option value="">Caricamento dispositivi...</option>
                                </select>
                                <div class="form-text">Scegli quale dispositivo userai per approvare le richieste QR.</div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-3" data-qr-device-save disabled>
                                <i class="fa-solid fa-link me-1"></i>Associa a questo browser
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm mt-3 d-none" data-qr-device-clear>
                                <i class="fa-solid fa-trash-can me-1"></i>Dimentica questo dispositivo
                            </button>
                        </div>
                        <dl class="row small mb-0 d-none" data-qr-activation-details>
                            <dt class="col-sm-4 text-muted">Dispositivo</dt>
                            <dd class="col-sm-8" data-qr-device-label>—</dd>
                            <dt class="col-sm-4 text-muted">UUID</dt>
                            <dd class="col-sm-8 text-break" data-qr-device-uuid>—</dd>
                            <dt class="col-sm-4 text-muted">Utente</dt>
                            <dd class="col-sm-8" data-qr-user-display>—</dd>
                        </dl>
                        <div class="border-top pt-3">
                            <h6 class="fw-semibold mb-2">Inserimento manuale</h6>
                            <p class="text-muted small mb-3">Se la fotocamera non è disponibile, digita il token mostrato accanto al QR.</p>
                            <form class="d-flex flex-column gap-2" data-qr-manual-form>
                                <input type="hidden" name="action" value="manual">
                                <div>
                                    <label class="form-label" for="qr_manual_token">Token</label>
                                    <input class="form-control" id="qr_manual_token" name="token" type="text" inputmode="hexadecimal" minlength="32" maxlength="64" placeholder="es. f2c4..." required>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-warning flex-fill" data-qr-manual-submit>
                                        <i class="fa-solid fa-paper-plane me-1"></i>Attiva da token
                                    </button>
                                    <button type="button" class="btn btn-outline-light flex-fill" data-qr-manual-reset>
                                        Cancella
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card ag-card mt-4" data-qr-approval>
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-1">Approvazione richieste login</h5>
                        <p class="text-muted small mb-0">Inquadra il QR dinamico dalla schermata di login per approvare con il PIN del dispositivo salvato.</p>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <div class="alert alert-info" role="status" data-qr-approval-alert>
                            <i class="fa-solid fa-circle-info me-2"></i>Inquadra un QR dinamico per iniziare.
                        </div>
                        <dl class="row small mb-0 d-none" data-qr-challenge-details>
                            <dt class="col-sm-4 text-muted">Token</dt>
                            <dd class="col-sm-8 text-break" data-qr-challenge-token>—</dd>
                            <dt class="col-sm-4 text-muted">IP origine</dt>
                            <dd class="col-sm-8" data-qr-challenge-ip>—</dd>
                            <dt class="col-sm-4 text-muted">Browser</dt>
                            <dd class="col-sm-8" data-qr-challenge-agent>—</dd>
                            <dt class="col-sm-4 text-muted">Richiesta</dt>
                            <dd class="col-sm-8" data-qr-challenge-issued>—</dd>
                            <dt class="col-sm-4 text-muted">Scadenza</dt>
                            <dd class="col-sm-8" data-qr-challenge-expiry>—</dd>
                        </dl>
                        <form class="d-flex flex-column gap-2 d-none" data-qr-approval-form novalidate>
                            <div>
                                <label class="form-label" for="qr_pin_input">PIN dispositivo</label>
                                <input class="form-control" id="qr_pin_input" type="password" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" autocomplete="one-time-code" placeholder="PIN scelto sul dispositivo" data-qr-pin-input required>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-success flex-fill" data-qr-approve>
                                    <i class="fa-solid fa-check me-1"></i>Approva con PIN
                                </button>
                                <button type="button" class="btn btn-outline-danger flex-fill" data-qr-deny>
                                    <i class="fa-solid fa-ban me-1"></i>Nega richiesta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card mt-4">
            <div class="card-header bg-transparent border-0">
                <h5 class="card-title mb-1">Come funziona</h5>
                <p class="text-muted small mb-0">Sequenza consigliata per configurare un nuovo dispositivo QR + PIN.</p>
            </div>
            <div class="card-body">
                <ol class="mb-0 ps-3">
                    <li class="mb-2">Apri il <strong>profilo utente</strong> da un computer, vai alla sezione "Dispositivi MFA via QR" e premi "Abbina dispositivo".</li>
                    <li class="mb-2">Dal dispositivo mobile, apri questa pagina scanner, premi "Avvia scansione" e concedi l'accesso alla fotocamera.</li>
                    <li class="mb-2">Inquadra il QR che appare sul computer: il token viene riconosciuto e attivato automaticamente.</li>
                    <li class="mb-2">Se colleghi una fotocamera esterna o una webcam USB, usa il menu "Fotocamera" per selezionarla.</li>
                    <li>Il dispositivo passa allo stato "Attivo" e può approvare i login inserendo il PIN impostato.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
