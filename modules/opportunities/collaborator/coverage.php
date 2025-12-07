<?php
declare(strict_types=1);

use App\Services\Coverage\CoverageProviderRegistry;

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

$csrfToken = csrf_token();
$coverageRegistry = new CoverageProviderRegistry();
$coverageProvidersMeta = $coverageRegistry->publicMetadata();
$coverageProvidersByCategory = [
    'consumer' => [],
    'business' => [],
];
foreach ($coverageProvidersMeta as $providerMeta) {
    $category = $providerMeta['category'] ?? 'consumer';
    if (!array_key_exists($category, $coverageProvidersByCategory)) {
        $coverageProvidersByCategory[$category] = [];
    }
    $coverageProvidersByCategory[$category][] = $providerMeta;
}

$consumerProviders = [
    [
        'label' => 'Fastweb',
        'description' => 'Verifica copertura ADSL/Fibra residenziale e FTTH con inserimento rapido dell\'indirizzo.',
        'cta' => 'Apri portale Fastweb',
        'href' => 'https://www.fastweb.it/AVT/',
        'badge' => 'Fibra + ADSL',
    ],
    [
        'label' => 'WINDTRE',
        'description' => 'Controlla copertura fibra e rete mobile/5G per clienti domestici.',
        'cta' => 'Apri pagina WINDTRE',
        'href' => 'https://www.windtre.it/copertura-fibra-mobile-5g',
        'badge' => 'Fibra + Mobile',
    ],
    [
        'label' => 'Enel Energia',
        'description' => 'Richiedi valutazione copertura fibra Enel con onboarding assistito.',
        'cta' => 'Apri Enel Energia',
        'href' => 'https://www.enel.it/it-it/verifica-copertura-fibra',
        'badge' => 'Fibra FTTH',
    ],
];

$businessProviders = [
    [
        'label' => 'Fastweb Business',
        'description' => 'Portale aziende Fastweb: offerte dedicate e strumenti per richieste commerciali.',
        'cta' => 'Vai alla pagina Fastweb Business',
        'href' => 'https://www.fastweb.it/adsl-aziende/fastweb-business/',
        'badge' => 'Imprese',
    ],
    [
        'label' => 'WINDTRE Business – Copertura',
        'description' => 'Copertura nazionale per Partita IVA e grandi clienti con dettaglio rete.',
        'cta' => 'Apri copertura WINDTRE Business',
        'href' => 'https://www.windtrebusiness.it/partita-iva-aziende/vantaggi-e-rete/copertura-nazionale',
        'badge' => 'Mappa nazionale',
    ],
    [
        'label' => 'WINDTRE Business – Offerte Fibra',
        'description' => 'Catalogo offerte fisse per aziende con funnel guidato.',
        'cta' => 'Apri offerte fibra WINDTRE Business',
        'href' => 'https://www.windtrebusiness.it/partita-iva-aziende/fisso-e-internet/offerte-fibra',
        'badge' => 'Fisso & Internet',
    ],
    [
        'label' => 'Enel Energia – Imprese',
        'description' => 'Sezione dedicata ai servizi Enel per aziende e professionisti.',
        'cta' => 'Apri Enel Energia Imprese',
        'href' => 'https://www.enel.it/it-it/imprese',
        'badge' => 'Servizi B2B',
    ],
];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Utility collaboratori</p>
                <h1 class="h4 mb-0">Verifica copertura rete</h1>
            </div>
        </div>

        <div class="alert alert-info border-start border-4 border-info-subtle mb-4" role="status">
            Usa i link ufficiali per avviare una verifica puntuale insieme al cliente. Ricorda di salvare eventuali conferme in CRM allegando screenshot o PDF.
        </div>

        <section class="mb-5">
            <div class="card shadow-sm border-primary-subtle">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                        <div>
                            <p class="text-uppercase small text-muted mb-1">Automazione Selenium</p>
                            <h2 class="h5 mb-0">Verifica headless immediata</h2>
                        </div>
                        <span class="badge bg-primary-subtle text-primary fw-semibold">Beta tecnica</span>
                    </div>
                    <p class="text-muted mb-4">
                        Compila i campi richiesti, scegli il gestore e lascia che il nodo Selenium apra il sito reale. Al termine riceverai lo stato dell'automazione, eventuali dati estratti e lo screenshot della sessione.
                    </p>
                    <form class="row g-3" data-coverage-form novalidate>
                        <div class="col-12 col-xl-4">
                            <label class="form-label" for="coverageProvider">Gestore</label>
                            <select class="form-select" id="coverageProvider" name="provider" required data-provider-select>
                                <option value="">Seleziona gestore…</option>
                                <?php
                                    $categoryLabels = [
                                        'consumer' => 'Consumer (domestico)',
                                        'business' => 'Business / P. IVA',
                                    ];
                                ?>
                                <?php foreach ($categoryLabels as $categoryKey => $categoryLabel): ?>
                                    <?php if (!empty($coverageProvidersByCategory[$categoryKey])): ?>
                                        <optgroup label="<?php echo sanitize_output($categoryLabel); ?>">
                                            <?php foreach ($coverageProvidersByCategory[$categoryKey] as $providerMeta): ?>
                                                <option value="<?php echo sanitize_output($providerMeta['key']); ?>">
                                                    <?php echo sanitize_output($providerMeta['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text d-flex justify-content-between align-items-center mt-1 gap-2">
                                <span data-provider-status class="text-muted">Automazione non selezionata.</span>
                                <span class="text-muted" data-provider-note></span>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-4" data-field-row="company">
                            <label class="form-label" for="coverageCompany">Ragione sociale</label>
                            <input class="form-control" type="text" id="coverageCompany" name="company" placeholder="Es. AG Servizi" data-field-input>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-4" data-field-row="address">
                            <label class="form-label" for="coverageAddress">Indirizzo</label>
                            <input class="form-control" type="text" id="coverageAddress" name="address" placeholder="Via, Piazza…" data-field-input>
                        </div>
                        <div class="col-6 col-xl-2" data-field-row="civic">
                            <label class="form-label" for="coverageCivic">Civico</label>
                            <input class="form-control" type="text" id="coverageCivic" name="civic" placeholder="12" data-field-input>
                        </div>
                        <div class="col-6 col-xl-2" data-field-row="cap">
                            <label class="form-label" for="coverageCap">CAP</label>
                            <input class="form-control" type="text" id="coverageCap" name="cap" placeholder="20100" data-field-input>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-3" data-field-row="city">
                            <label class="form-label" for="coverageCity">Comune</label>
                            <input class="form-control" type="text" id="coverageCity" name="city" placeholder="Milano" data-field-input>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-3" data-field-row="province">
                            <label class="form-label" for="coverageProvince">Provincia</label>
                            <input class="form-control" type="text" id="coverageProvince" name="province" placeholder="MI" data-field-input>
                        </div>
                        <div class="col-12 col-xl-6" data-field-row="notes">
                            <label class="form-label" for="coverageNotes">Note interne</label>
                            <textarea class="form-control" id="coverageNotes" name="notes" rows="2" placeholder="Es. cliente preferisce FTTH" data-field-input></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button class="btn btn-outline-secondary" type="button" data-coverage-reset>
                                <i class="fa-solid fa-rotate-left me-2"></i>Reset
                            </button>
                            <button class="btn btn-primary" type="submit" data-coverage-submit>
                                <span data-label>Avvia Selenium</span>
                                <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" data-spinner></span>
                            </button>
                        </div>
                    </form>
                    <div class="alert alert-warning mt-3 d-none" role="alert" data-coverage-warning></div>
                    <div class="card mt-4 d-none" data-coverage-result>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div>
                                    <p class="text-uppercase small text-muted mb-1" data-result-provider>Gestore</p>
                                    <h3 class="h5 mb-0" data-result-status>—</h3>
                                </div>
                                <span class="badge bg-secondary-subtle text-secondary fw-semibold" data-result-badge>In attesa</span>
                            </div>
                            <p class="text-muted mb-4" data-result-message>Avvia una verifica per ottenere il responso.</p>
                            <div class="row g-3" data-result-summary></div>
                            <div class="mt-4 d-none" data-result-screenshot-wrapper>
                                <p class="text-muted small mb-2">Anteprima Selenium</p>
                                <img class="img-fluid border rounded shadow-sm" data-result-screenshot alt="Screenshot sessione" loading="lazy">
                            </div>
                            <details class="mt-4" data-result-steps-wrapper>
                                <summary class="text-muted small">Log dettagliato</summary>
                                <pre class="bg-dark text-white rounded mt-2 p-3 small" data-result-steps>In attesa di esecuzione…</pre>
                            </details>
                        </div>
                    </div>
                    <div class="mt-4">
                        <p class="text-muted small mb-2">
                            Imposta i parametri <code>COVERAGE_SELENIUM_ENDPOINT</code> e <code>COVERAGE_SELENIUM_BROWSER</code> nel file di configurazione. Esempio rapido con Docker:
                        </p>
                        <code class="d-block bg-light rounded px-3 py-2 text-muted small">docker run -d --name selenium -p 4444:4444 selenium/standalone-chrome:latest</code>
                    </div>
                </div>
            </div>
        </section>

        <section class="mb-5">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <p class="text-uppercase small text-muted mb-1">Domestico</p>
                    <h2 class="h5 mb-0">Copertura Consumer</h2>
                </div>
                <span class="badge bg-primary-subtle text-primary fw-semibold">Fibra + Mobile</span>
            </div>
            <div class="row g-4">
                <?php foreach ($consumerProviders as $provider): ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong><?php echo sanitize_output($provider['label']); ?></strong>
                                    <span class="badge bg-light text-muted border"><?php echo sanitize_output($provider['badge']); ?></span>
                                </div>
                                <p class="text-muted mb-4 flex-grow-1"><?php echo sanitize_output($provider['description']); ?></p>
                                <a class="btn btn-outline-primary w-100" href="<?php echo sanitize_output($provider['href']); ?>" target="_blank" rel="noreferrer noopener">
                                    <i class="fa-solid fa-up-right-from-square me-2"></i><?php echo sanitize_output($provider['cta']); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mb-5">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <p class="text-uppercase small text-muted mb-1">Business / P. IVA</p>
                    <h2 class="h5 mb-0">Copertura Aziende</h2>
                </div>
                <span class="badge bg-success-subtle text-success fw-semibold">B2B</span>
            </div>
            <div class="row g-4">
                <?php foreach ($businessProviders as $provider): ?>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong><?php echo sanitize_output($provider['label']); ?></strong>
                                    <span class="badge bg-light text-muted border"><?php echo sanitize_output($provider['badge']); ?></span>
                                </div>
                                <p class="text-muted mb-4 flex-grow-1"><?php echo sanitize_output($provider['description']); ?></p>
                                <a class="btn btn-outline-success w-100" href="<?php echo sanitize_output($provider['href']); ?>" target="_blank" rel="noreferrer noopener">
                                    <i class="fa-solid fa-up-right-from-square me-2"></i><?php echo sanitize_output($provider['cta']); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section>
            <div class="card shadow-sm border-warning-subtle">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <p class="text-uppercase small text-muted mb-1">Automazione consigliata</p>
                            <h2 class="h6 mb-0">Metodo facile (headless API)</h2>
                        </div>
                        <span class="badge bg-warning-subtle text-warning fw-semibold">Sperimentale</span>
                    </div>
                    <p class="text-muted mb-3">
                        Il nuovo endpoint <code>api/coverage-check.php</code> usa Selenium per aprire i portali con un browser reale, compilare l'indirizzo e restituire un JSON pronto da allegare alle opportunity.
                    </p>
                    <ol class="mb-0 text-muted">
                        <li>Avvio di un container headless che visita il portale scelto.</li>
                        <li>Compilazione automatica dei campi indirizzo e invio della richiesta.</li>
                        <li>Lettura dei risultati (copertura, tecnologia, note commerciali) e normalizzazione in JSON.</li>
                        <li>Ritorno del payload al CRM per allegarla alla scheda opportunity.</li>
                    </ol>
                    <p class="text-muted small mt-3 mb-1">
                        Per gestire picchi o richieste batch puoi spostare il trigger dentro una coda (es. supervisord + worker PHP) e serializzare il payload della richiesta.
                    </p>
                    <p class="text-muted small mb-0">
                        Dopo il deploy remoto ricordati di eseguire <code>composer install --no-dev --optimize-autoloader</code> così da installare <code>php-webdriver/webdriver</code> e le librerie Symfony necessarie al nodo Selenium.
                    </p>
                </div>
            </div>
        </section>
    </main>
</div>
<?php
    $coverageProvidersJson = json_encode($coverageProvidersMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    if ($coverageProvidersJson === false) {
        $coverageProvidersJson = '[]';
    }
?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const providers = <?php echo $coverageProvidersJson; ?>;
    const providerMap = providers.reduce((acc, provider) => {
        if (provider && provider.key) {
            acc[provider.key] = provider;
        }
        return acc;
    }, {});

    const form = document.querySelector('[data-coverage-form]');
    if (!form) {
        return;
    }

    const providerSelect = form.querySelector('[data-provider-select]');
    const providerStatus = form.querySelector('[data-provider-status]');
    const providerNote = form.querySelector('[data-provider-note]');
    const fieldRows = form.querySelectorAll('[data-field-row]');
    const warningAlert = document.querySelector('[data-coverage-warning]');
    const resetButton = form.querySelector('[data-coverage-reset]');
    const submitButton = form.querySelector('[data-coverage-submit]');
    const submitLabel = submitButton ? submitButton.querySelector('[data-label]') : null;
    const submitSpinner = submitButton ? submitButton.querySelector('[data-spinner]') : null;
    const resultCard = document.querySelector('[data-coverage-result]');
    const resultProvider = resultCard ? resultCard.querySelector('[data-result-provider]') : null;
    const resultStatus = resultCard ? resultCard.querySelector('[data-result-status]') : null;
    const resultBadge = resultCard ? resultCard.querySelector('[data-result-badge]') : null;
    const resultMessage = resultCard ? resultCard.querySelector('[data-result-message]') : null;
    const resultSummary = resultCard ? resultCard.querySelector('[data-result-summary]') : null;
    const resultSteps = resultCard ? resultCard.querySelector('[data-result-steps]') : null;
    const resultStepsWrapper = resultCard ? resultCard.querySelector('[data-result-steps-wrapper]') : null;
    const screenshotWrapper = resultCard ? resultCard.querySelector('[data-result-screenshot-wrapper]') : null;
    const screenshotImg = resultCard ? resultCard.querySelector('[data-result-screenshot]') : null;

    const endpoint = '<?php echo sanitize_output(asset('api/coverage-check.php')); ?>';
    const csrfToken = '<?php echo sanitize_output($csrfToken); ?>';

    const statusLabels = {
        completed: 'Completata',
        partial: 'Parziale',
        failed: 'Errore',
        manual: 'Sessione manuale',
    };
    const statusBadges = {
        completed: 'badge bg-success-subtle text-success fw-semibold',
        partial: 'badge bg-warning-subtle text-warning fw-semibold',
        failed: 'badge bg-danger-subtle text-danger fw-semibold',
        manual: 'badge bg-secondary-subtle text-secondary fw-semibold',
    };
    const automationLabels = {
        todo: 'Automazione da definire',
        beta: 'Automazione beta',
        stable: 'Automazione stabile',
    };

    const escapeHtml = (value) => {
        if (typeof value !== 'string') {
            value = value === undefined || value === null ? '' : String(value);
        }
        return value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const getProviderMeta = () => {
        const key = providerSelect ? providerSelect.value : '';
        return providerMap[key] || null;
    };

    const updateFieldVisibility = () => {
        const provider = getProviderMeta();
        fieldRows.forEach((row) => {
            const fieldKey = row.getAttribute('data-field-row');
            const input = row.querySelector('[data-field-input]');
            if (!fieldKey || !input) {
                return;
            }
            if (!provider || !provider.fields || !provider.fields[fieldKey]) {
                row.classList.add('d-none');
                input.required = false;
                input.value = '';
            } else {
                row.classList.remove('d-none');
                input.required = !!provider.fields[fieldKey].required;
            }
        });
    };

    const updateProviderMeta = () => {
        const provider = getProviderMeta();
        if (!providerStatus) {
            return;
        }
        if (!provider) {
            providerStatus.textContent = 'Automazione non selezionata.';
            if (providerNote) {
                providerNote.textContent = '';
            }
            return;
        }
        const automation = provider.automation_status && automationLabels[provider.automation_status]
            ? automationLabels[provider.automation_status]
            : 'Automazione configurabile';
        providerStatus.textContent = automation;
        if (providerNote) {
            providerNote.textContent = provider.notes ? provider.notes : '';
        }
    };

    const showWarning = (message) => {
        if (!warningAlert) {
            return;
        }
        warningAlert.textContent = message;
        warningAlert.classList.remove('d-none');
    };

    const hideWarning = () => {
        if (warningAlert) {
            warningAlert.classList.add('d-none');
            warningAlert.textContent = '';
        }
    };

    const setLoading = (isLoading) => {
        if (submitButton) {
            submitButton.disabled = isLoading;
        }
        if (submitSpinner) {
            submitSpinner.classList.toggle('d-none', !isLoading);
        }
        if (submitLabel) {
            submitLabel.textContent = isLoading ? 'Verifica in corso…' : 'Avvia Selenium';
        }
    };

    const clearResult = () => {
        if (resultCard) {
            resultCard.classList.add('d-none');
        }
        if (resultSummary) {
            resultSummary.innerHTML = '';
        }
        if (screenshotWrapper) {
            screenshotWrapper.classList.add('d-none');
        }
        if (screenshotImg) {
            screenshotImg.removeAttribute('src');
        }
        if (resultSteps) {
            resultSteps.textContent = '';
        }
    };

    const renderSummary = (summary) => {
        if (!resultSummary) {
            return;
        }
        resultSummary.innerHTML = '';
        const entries = summary && typeof summary === 'object'
            ? Object.entries(summary)
            : [];
        if (!entries.length) {
            const placeholder = document.createElement('div');
            placeholder.className = 'col-12';
            placeholder.innerHTML = '<div class="border rounded p-3 bg-light text-muted">Nessun dato estratto automaticamente per questa ricetta.</div>';
            resultSummary.appendChild(placeholder);
            return;
        }
        entries.forEach(([key, value]) => {
            const col = document.createElement('div');
            col.className = 'col-12 col-lg-6';
            const humanKey = key.replace(/_/g, ' ');
            col.innerHTML = `
                <div class="border rounded p-3 h-100">
                    <p class="text-muted small mb-1">${escapeHtml(humanKey)}</p>
                    <strong>${escapeHtml(value || '—')}</strong>
                </div>`;
            resultSummary.appendChild(col);
        });
    };

    const renderSteps = (steps) => {
        if (!resultSteps) {
            return;
        }
        if (!Array.isArray(steps) || !steps.length) {
            resultSteps.textContent = 'Nessun log disponibile.';
            return;
        }
        resultSteps.textContent = JSON.stringify(steps, null, 2);
    };

    const renderResult = (data) => {
        if (!resultCard) {
            return;
        }
        hideWarning();
        const provider = data.provider || {};
        const status = data.status || 'manual';
        const statusLabel = statusLabels[status] || status;
        const badgeClass = statusBadges[status] || 'badge bg-secondary-subtle text-secondary fw-semibold';

        if (resultProvider) {
            resultProvider.textContent = provider.label || provider.key || 'Gestore selezionato';
        }
        if (resultStatus) {
            resultStatus.textContent = statusLabel;
        }
        if (resultBadge) {
            resultBadge.className = badgeClass;
            resultBadge.textContent = statusLabel;
        }
        if (resultMessage) {
            resultMessage.textContent = data.message || 'Automazione completata.';
        }

        renderSummary(data.summary || {});
        renderSteps(data.steps || []);

        if (screenshotWrapper && screenshotImg) {
            if (data.screenshot) {
                screenshotImg.src = 'data:image/png;base64,' + data.screenshot;
                screenshotWrapper.classList.remove('d-none');
            } else {
                screenshotWrapper.classList.add('d-none');
                screenshotImg.removeAttribute('src');
            }
        }

        if (resultStepsWrapper) {
            resultStepsWrapper.open = false;
        }

        resultCard.classList.remove('d-none');
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideWarning();
        clearResult();
        if (!providerSelect || !providerSelect.value) {
            showWarning('Seleziona un gestore prima di avviare la verifica.');
            return;
        }

        const provider = getProviderMeta();
        if (!provider) {
            showWarning('Gestore non riconosciuto.');
            return;
        }

        const formData = new FormData(form);
        const payload = {};
        formData.forEach((value, key) => {
            if (typeof value === 'string') {
                payload[key] = value.trim();
            }
        });

        setLoading(true);
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || body.error) {
                throw new Error(body.error || 'Automazione non disponibile.');
            }
            renderResult(body.data || {});
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Automazione non disponibile.';
            showWarning(message);
        } finally {
            setLoading(false);
        }
    });

    resetButton?.addEventListener('click', () => {
        form.reset();
        hideWarning();
        clearResult();
        updateFieldVisibility();
        updateProviderMeta();
    });

    providerSelect?.addEventListener('change', () => {
        updateFieldVisibility();
        updateProviderMeta();
        clearResult();
    });

    updateFieldVisibility();
    updateProviderMeta();
});
</script>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
