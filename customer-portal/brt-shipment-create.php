<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use Throwable;

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/brt_service.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pageTitle = 'Nuova spedizione BRT';

$brtModuleReady = true;
$brtModuleError = null;
$pickupService = null;

try {
    $pickupService = new PickupBrtService();
} catch (Throwable $exception) {
    $brtModuleReady = false;
    $brtModuleError = $exception->getMessage();
}

$allowedDestinationCountries = ['IT' => 'Italia'];
$defaultCountry = 'IT';
$defaultNetwork = '';
$defaultServiceType = '';
$defaultPricingCondition = '';
$defaultPudoId = '';
$labelRequiredDefault = true;
$nextNumericReference = null;

if ($brtModuleReady) {
    try {
        $config = new BrtConfig();
        $allowedDestinationCountries = $config->getAllowedDestinationCountries();
        $defaultCountryCandidate = strtoupper($config->getDefaultCountryIsoAlpha2() ?? 'IT');
        if (isset($allowedDestinationCountries[$defaultCountryCandidate])) {
            $defaultCountry = $defaultCountryCandidate;
        } else {
            $defaultCountry = array_key_first($allowedDestinationCountries) ?? 'IT';
        }
        $defaultNetwork = strtoupper($config->getDefaultNetwork() ?? '');
        $defaultServiceType = strtoupper($config->getDefaultServiceType() ?? '');
        $defaultPricingCondition = strtoupper((string) ($config->getPricingConditionCode($defaultNetwork !== '' ? $defaultNetwork : null) ?? ''));
        $defaultPudoId = trim((string) ($config->getDefaultPudoId() ?? ''));
        $labelRequiredDefault = $config->isLabelRequiredByDefault();
        $senderCode = $config->getSenderCustomerCode();
        $nextNumericReference = brt_next_numeric_reference($senderCode);
    } catch (Throwable $configException) {
        $brtModuleReady = false;
        $brtModuleError = $brtModuleError ?: $configException->getMessage();
    }
}

$allowedDestinationCountries = $allowedDestinationCountries !== [] ? $allowedDestinationCountries : ['IT' => 'Italia'];

$portalBrtPricing = [
    'currency' => 'EUR',
    'currency_symbol' => '€',
    'tiers' => [],
    'has_pricing' => false,
    'unlimited_hint' => 'Quando il peso o il volume sono lasciati vuoti nello scaglione, vengono considerati senza limite.',
];

if ($brtModuleReady && $pickupService instanceof PickupBrtService) {
    $portalBrtPricing = $pickupService->getPortalPricing();
}

$paymentCancelled = isset($_GET['payment_cancel']) && (int) $_GET['payment_cancel'] === 1;

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="portal-main d-flex flex-column flex-grow-1">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-plus text-primary"></i>
                    Nuova spedizione BRT
                </h1>
                <p class="text-muted-soft mb-0">Compila il form completo per generare una spedizione come nel portale operatori BRT.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
                <a class="btn topbar-btn" href="brt-shipments.php">
                    <i class="fa-solid fa-truck-fast"></i>
                    <span class="topbar-btn-label">Torna alle spedizioni</span>
                </a>
            </div>
        </div>

        <?php if (!$brtModuleReady): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                <?= htmlspecialchars($brtModuleError ?? 'Modulo BRT non disponibile', ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php else: ?>
            <?php if ($paymentCancelled): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2"></i>
                    Pagamento Stripe annullato: la spedizione non è stata creata. Puoi riprovare compilando nuovamente il form.
                </div>
            <?php endif; ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <?php if ($portalBrtPricing['has_pricing']): ?>
                        <section class="mb-4" id="portalBrtPricingCard">
                            <div class="border border-light-subtle rounded-4 p-4 bg-body-tertiary">
                                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                                    <div>
                                        <h2 class="h5 mb-1">Listino spedizioni BRT</h2>
                                        <p class="text-muted-soft small mb-2">Consulta gli scaglioni applicati automaticamente al portale clienti.</p>
                                        <p class="text-muted small mb-0"><i class="fa-solid fa-circle-info me-1"></i><?php echo htmlspecialchars($portalBrtPricing['unlimited_hint'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div class="text-lg-end">
                                        <div class="fw-semibold text-muted small">Stima con valori inseriti</div>
                                        <div class="fs-4 fw-bold" id="portalBrtPricingEstimate">—</div>
                                        <div class="small" id="portalBrtPricingEstimateHint">Indica peso e dimensioni per ottenere una stima.</div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="row g-3" id="portalBrtPricingTiers">
                                        <?php foreach ($portalBrtPricing['tiers'] as $index => $tier): ?>
                                            <div class="col-12 col-md-6 col-xxl-4">
                                                <div class="portal-brt-pricing-tier border border-light-subtle rounded-3 h-100 p-3" data-tier-index="<?php echo (int) $index; ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                                        <div>
                                                            <div class="fw-semibold mb-1"><?php echo htmlspecialchars($tier['display']['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                            <div class="text-muted small mb-1"><?php echo htmlspecialchars($tier['display']['criteria'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                        </div>
                                                        <div class="text-end">
                                                            <div class="fw-semibold"><?php echo htmlspecialchars($tier['display']['price'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <?php $portalBrtPricingJson = json_encode($portalBrtPricing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                        <?php if ($portalBrtPricingJson !== false): ?>
                            <script>
                            window.portalConfig = window.portalConfig || {};
                            window.portalConfig.brtPricing = <?php echo $portalBrtPricingJson; ?>;
                            </script>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-warning d-flex align-items-start gap-3" role="alert">
                            <i class="fa-solid fa-circle-exclamation fs-4 pt-1"></i>
                            <div>
                                <strong>Listino non configurato.</strong>
                                <div class="small">Per mostrare il prezzo stimato aggiorna gli scaglioni nelle impostazioni amministrative.</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form id="brtShipmentForm" novalidate
                          data-redirect="brt-shipments.php"
                          data-default-country="<?= htmlspecialchars($defaultCountry, ENT_QUOTES, 'UTF-8') ?>"
                          data-default-network="<?= htmlspecialchars($defaultNetwork, ENT_QUOTES, 'UTF-8') ?>"
                          data-default-service="<?= htmlspecialchars($defaultServiceType, ENT_QUOTES, 'UTF-8') ?>"
                          data-default-pricing-code="<?= htmlspecialchars($defaultPricingCondition, ENT_QUOTES, 'UTF-8') ?>"
                          data-default-label-required="<?= $labelRequiredDefault ? '1' : '0' ?>"
                          data-default-pudo="<?= htmlspecialchars($defaultPudoId, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="service_type" id="brtServiceType" value="<?= htmlspecialchars($defaultServiceType, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="delivery_type" id="brtDeliveryType" value="">
                        <input type="hidden" name="network" id="brtNetwork" value="<?= htmlspecialchars($defaultNetwork, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="pudo_id" id="brtPudoId" value="<?= htmlspecialchars($defaultPudoId, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="pudo_description" id="brtPudoDescription" value="">
                        <input type="hidden" name="pricing_condition_code" id="brtPricingCondition" value="<?= htmlspecialchars($defaultPricingCondition, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="row g-4">
                            <div class="col-12">
                                <h2 class="h5 mb-0">Dettagli spedizione</h2>
                                <p class="text-muted-soft small mb-0">Dimensioni, colli e servizi aggiuntivi determinano volume e metadati della spedizione.</p>
                            </div>

                            <?php if ($nextNumericReference !== null): ?>
                                <div class="col-md-4">
                                    <label class="form-label" for="brtNumericReference">Riferimento numerico previsto</label>
                                    <input class="form-control" id="brtNumericReference" type="text" value="<?= htmlspecialchars((string) $nextNumericReference, ENT_QUOTES, 'UTF-8') ?>" readonly>
                                    <div class="form-text">Assegnato automaticamente da BRT al momento della creazione.</div>
                                </div>
                            <?php endif; ?>

                            <div class="col-md-4">
                                <label class="form-label" for="brtAlphanumericReference">Riferimento alfanumerico</label>
                                <input class="form-control" id="brtAlphanumericReference" name="alphanumeric_reference" type="text" maxlength="80" placeholder="Es. ORD-2025-0001">
                                <div class="form-text">Opzionale, massimo 80 caratteri.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="brtParcels">Colli *</label>
                                <input class="form-control" id="brtParcels" name="parcels" type="number" min="1" value="1" required>
                                <div class="invalid-feedback">Indica il numero di colli.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="brtWeight">Peso (kg) *</label>
                                <input class="form-control" id="brtWeight" name="weight" type="number" min="0.1" step="0.1" value="1.0" required>
                                <div class="invalid-feedback">Indica il peso totale in chilogrammi.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="brtLength">Lunghezza (cm) *</label>
                                <input class="form-control" id="brtLength" name="length_cm" type="number" min="1" step="0.1" required>
                                <div class="invalid-feedback">Inserisci la lunghezza in centimetri.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="brtDepth">Profondità (cm) *</label>
                                <input class="form-control" id="brtDepth" name="depth_cm" type="number" min="1" step="0.1" required>
                                <div class="invalid-feedback">Inserisci la profondità in centimetri.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="brtHeight">Altezza (cm) *</label>
                                <input class="form-control" id="brtHeight" name="height_cm" type="number" min="1" step="0.1" required>
                                <div class="invalid-feedback">Inserisci l'altezza in centimetri.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="brtVolume">Volume totale (m³)</label>
                                <input class="form-control" id="brtVolume" name="volume" type="text" readonly>
                                <div class="form-text">Calcolo automatico in base a colli e dimensioni.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="brtVolumetricWeight">Peso volumetrico (Kg)</label>
                                <input class="form-control" id="brtVolumetricWeight" type="text" readonly>
                                <div class="form-text">Calcolato con coefficiente BRT (4000) per confrontarlo con il peso reale.</div>
                            </div>

                            <div class="col-12">
                                <h2 class="h5 mb-0 mt-2">Destinatario</h2>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="brtRecipient">Destinatario *</label>
                                <input class="form-control" id="brtRecipient" name="recipient_name" type="text" required>
                                <div class="invalid-feedback">Inserisci la ragione sociale o il nominativo.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="brtContact">Referente</label>
                                <input class="form-control" id="brtContact" name="contact_name" type="text" placeholder="Persona di contatto">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="brtAddress">Indirizzo *</label>
                                <input class="form-control" id="brtAddress" name="address" type="text" required>
                                <div class="invalid-feedback">Inserisci l'indirizzo del destinatario.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="brtZip">CAP *</label>
                                <input class="form-control" id="brtZip" name="zip" type="text" required pattern="[0-9A-Za-z]{4,10}">
                                <div class="invalid-feedback">Inserisci un CAP valido.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="brtCity">Città *</label>
                                <input class="form-control" id="brtCity" name="city" type="text" required list="brtCityOptions" autocomplete="off">
                                <div class="invalid-feedback">Inserisci la città.</div>
                                <div class="form-text">Compila il CAP per ottenere suggerimenti di città e provincia.</div>
                                <datalist id="brtCityOptions"></datalist>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="brtProvince">Provincia</label>
                                <input class="form-control" id="brtProvince" name="province" type="text" maxlength="5" placeholder="Es. MI">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="brtCountry">Paese *</label>
                                <select class="form-select" id="brtCountry" name="country" required>
                                    <?php foreach ($allowedDestinationCountries as $countryCode => $countryName): ?>
                                        <option value="<?= htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') ?>"<?= $countryCode === $defaultCountry ? ' selected' : '' ?>><?= htmlspecialchars($countryCode . ' · ' . $countryName, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Seleziona un paese supportato.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="brtEmail">Email destinatario</label>
                                <input class="form-control" id="brtEmail" name="email" type="email" placeholder="esempio@azienda.it">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="brtPhone">Telefono</label>
                                <input class="form-control" id="brtPhone" name="phone" type="text" placeholder="Telefono fisso">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="brtMobile">Cellulare</label>
                                <input class="form-control" id="brtMobile" name="mobile" type="text" placeholder="+39 333 1234567">
                            </div>

                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body d-flex flex-column flex-lg-row align-items-start align-items-lg-center gap-3 justify-content-between">
                                        <div class="flex-grow-1">
                                            <h3 class="h6 mb-1">Punto di ritiro BRT</h3>
                                            <p class="text-muted-soft small mb-2">Seleziona un PUDO per consegnare il pacco presso un Fermopoint BRT. Lascia vuoto per spedire all'indirizzo del destinatario.</p>
                                            <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-0 d-none" data-pudo-selection-alert>
                                                <i class="fa-solid fa-map-location-dot pt-1"></i>
                                                <div>
                                                    <div class="fw-semibold mb-0">Punto selezionato</div>
                                                    <div class="small" data-pudo-selection-label></div>
                                                </div>
                                            </div>
                                            <div class="text-muted small" data-pudo-selection-empty>
                                                Nessun punto di ritiro selezionato: la consegna avverrà all'indirizzo inserito sopra.
                                            </div>
                                        </div>
                                        <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 flex-shrink-0">
                                            <button class="btn btn-outline-secondary" type="button" data-action="brt-pudo-clear" disabled>
                                                <i class="fa-solid fa-rotate-left me-1"></i>
                                                Rimuovi punto
                                            </button>
                                            <button class="btn btn-primary" type="button" data-action="brt-pudo-open">
                                                <i class="fa-solid fa-map-location-dot me-1"></i>
                                                Scegli punto
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <h2 class="h5 mb-0 mt-2">Servizi aggiuntivi</h2>
                            </div>
                            <div class="col-lg-6">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body">
                                        <h3 class="h6">Assicurazione</h3>
                                        <p class="text-muted-soft small">Compila l'importo assicurato se previsto dal contratto.</p>
                                        <div class="row g-2 align-items-end">
                                            <div class="col-7">
                                                <label class="form-label" for="brtInsuranceAmount">Importo assicurato</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="fa-solid fa-shield"></i></span>
                                                    <input class="form-control" id="brtInsuranceAmount" name="insurance_amount" type="text" inputmode="decimal" placeholder="Es. 1500,00">
                                                </div>
                                            </div>
                                            <div class="col-5">
                                                <label class="form-label" for="brtInsuranceCurrency">Valuta</label>
                                                <select class="form-select" id="brtInsuranceCurrency" name="insurance_currency">
                                                    <option value="EUR" selected>EUR</option>
                                                    <option value="CHF">CHF</option>
                                                    <option value="USD">USD</option>
                                                    <option value="GBP">GBP</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card border-0 shadow-sm h-100" data-cod-section>
                                    <div class="card-body">
                                        <h3 class="h6">Contrassegno</h3>
                                        <p class="text-muted-soft small">Inserisci l'importo se è previsto il pagamento alla consegna.</p>
                                        <div class="row g-2 align-items-end">
                                            <div class="col-7">
                                                <label class="form-label" for="brtCodAmount">Importo contrassegno</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="fa-solid fa-money-bill"></i></span>
                                                    <input class="form-control" id="brtCodAmount" name="cod_amount" type="text" inputmode="decimal" placeholder="Es. 250,00">
                                                </div>
                                            </div>
                                            <div class="col-5">
                                                <label class="form-label" for="brtCodCurrency">Valuta</label>
                                                <select class="form-select" id="brtCodCurrency" name="cod_currency">
                                                    <option value="EUR" selected>EUR</option>
                                                    <option value="CHF">CHF</option>
                                                    <option value="USD">USD</option>
                                                    <option value="GBP">GBP</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label" for="brtCodPaymentType">Tipo pagamento</label>
                                                <input class="form-control" id="brtCodPaymentType" name="cod_payment_type" type="text" maxlength="2" placeholder="Es. AS">
                                                <div class="form-text">Codice a 1-2 caratteri fornito da BRT.</div>
                                            </div>
                                            <div class="col-6 d-flex align-items-end">
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" id="brtCodMandatory" name="cod_mandatory" type="checkbox" value="1">
                                                    <label class="form-check-label" for="brtCodMandatory">Contrassegno obbligatorio</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="brtNotes">Note per il corriere</label>
                                <textarea class="form-control" id="brtNotes" name="notes" rows="3" placeholder="Istruzioni di consegna, riferimenti interni..."></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" id="brtLabelRequired" name="label_required" type="checkbox" value="1"<?= $labelRequiredDefault ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="brtLabelRequired">Genera automaticamente l'etichetta PDF al termine della creazione</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4">
                            <a class="btn btn-outline-secondary" href="brt-shipments.php">
                                <i class="fa-solid fa-chevron-left me-2"></i>
                                Annulla e torna alla lista
                            </a>
                            <button class="btn btn-primary" type="submit">
                                <span class="me-2"><i class="fa-solid fa-truck-fast"></i></span>
                                Crea spedizione
                            </button>
                        </div>
                    </form>

                    <div class="modal fade" id="brtPudoModal" tabindex="-1" aria-labelledby="brtPudoModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-xl modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h1 class="modal-title fs-5" id="brtPudoModalLabel">Seleziona punto di ritiro BRT</h1>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-4" data-pudo-modal-root>
                                        <div class="col-12 col-lg-4 d-flex flex-column gap-3">
                                            <div class="card border-0 shadow-sm">
                                                <div class="card-body">
                                                    <form id="brtPudoSearchForm" class="d-flex flex-column gap-3">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <h2 class="h6 mb-0">Ricerca</h2>
                                                            <button type="button" class="btn btn-link btn-sm text-decoration-none px-0" data-action="brt-pudo-sync">
                                                                <i class="fa-solid fa-arrows-rotate me-1"></i>
                                                                Usa destinatario
                                                            </button>
                                                        </div>
                                                        <div>
                                                            <label class="form-label" for="brtPudoSearchZip">CAP *</label>
                                                            <input class="form-control" id="brtPudoSearchZip" type="text" required>
                                                        </div>
                                                        <div>
                                                            <label class="form-label" for="brtPudoSearchCity">Città *</label>
                                                            <input class="form-control" id="brtPudoSearchCity" type="text" required>
                                                        </div>
                                                        <div>
                                                            <label class="form-label" for="brtPudoSearchProvince">Provincia</label>
                                                            <input class="form-control" id="brtPudoSearchProvince" type="text" maxlength="5">
                                                        </div>
                                                        <div>
                                                            <label class="form-label" for="brtPudoSearchCountry">Paese</label>
                                                            <select class="form-select" id="brtPudoSearchCountry">
                                                                <?php foreach ($allowedDestinationCountries as $countryCode => $countryName): ?>
                                                                    <option value="<?= htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') ?>"<?= $countryCode === $defaultCountry ? ' selected' : '' ?>><?= htmlspecialchars($countryCode . ' · ' . $countryName, ENT_QUOTES, 'UTF-8') ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="d-grid">
                                                            <button class="btn btn-primary" type="submit" id="brtPudoSearchButton">
                                                                <i class="fa-solid fa-magnifying-glass me-1"></i>
                                                                Cerca PUDO
                                                            </button>
                                                        </div>
                                                        <div class="small text-muted" data-pudo-status>Inserisci CAP e città per iniziare la ricerca.</div>
                                                    </form>
                                                </div>
                                            </div>
                                            <div class="card border-0 shadow-sm flex-grow-1">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h2 class="h6 mb-0">Risultati</h2>
                                                        <span class="badge text-bg-primary d-none" data-pudo-count></span>
                                                    </div>
                                                    <div class="portal-pudo-results list-group" data-pudo-results>
                                                        <div class="text-muted small" data-pudo-placeholder>Nessuna ricerca eseguita.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-lg-8">
                                            <div class="card border-0 shadow-sm h-100">
                                                <div class="card-body p-0">
                                                    <div class="portal-pudo-map" id="brtPudoMap" role="presentation" aria-label="Mappa punti di ritiro"></div>
                                                </div>
                                                <div class="card-footer bg-transparent text-muted small">
                                                    Seleziona un punto nell'elenco o sulla mappa per impostare il PUDO.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        <i class="fa-solid fa-xmark me-1"></i>
                                        Chiudi
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
