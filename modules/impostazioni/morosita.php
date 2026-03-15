<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
use App\Services\Opportunities\OpportunityService;

require_role('Admin', 'Manager');

$pageTitle = 'Verifica morosità';
$csrfToken = csrf_token();
$opportunityService = new OpportunityService($pdo);
$catalog = $opportunityService->getProviderCatalog();
$providerOptions = [];
$providerLabels = [];
foreach ($catalog as $category => $providers) {
    foreach ($providers as $provider) {
        $providerOptions[] = [
            'id' => (int) ($provider['id'] ?? 0),
            'name' => (string) ($provider['name'] ?? ''),
            'category' => (string) $category,
        ];
        $providerLabels[(int) ($provider['id'] ?? 0)] = strtoupper($category) . ' · ' . (string) ($provider['name'] ?? '');
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Morosità</p>
                <h1 class="h4 mb-0">Verifica e imposta stato</h1>
                <p class="text-muted mb-0">Esegui un controllo manuale su un cliente tramite codice fiscale o partita IVA e, se serve, imposta l'esito.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-secondary" href="<?php echo opportunities_module_url('index'); ?>">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna alle Opportunity
                </a>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form id="morosita-form" class="row g-3" onsubmit="return false;">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label text-uppercase small text-muted">Codice fiscale / P.IVA</label>
                        <input type="text" class="form-control" name="tax_code" id="tax_code" maxlength="32" placeholder="Inserisci CF o P.IVA" required>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label text-uppercase small text-muted">Note (opzionale)</label>
                        <input type="text" class="form-control" name="note" id="note" maxlength="180" placeholder="Nota per il log">
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label text-uppercase small text-muted">Gestore (opzionale)</label>
                        <select class="form-select" name="provider_id" id="provider_id">
                            <option value="">Tutti</option>
                            <?php foreach ($providerOptions as $provider): ?>
                                <option value="<?php echo (int) $provider['id']; ?>">
                                    <?php echo sanitize_output(strtoupper($provider['category']) . ' · ' . $provider['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Seleziona il gestore per salvare/forzare la morosità su quel provider.</div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label text-uppercase small text-muted">Imposta esito (opzionale)</label>
                        <select class="form-select" name="score" id="score">
                            <option value="">Calcolo automatico</option>
                            <option value="ok">Regolare</option>
                            <option value="attenzione">Attenzione</option>
                            <option value="bloccato">Bloccato</option>
                        </select>
                        <div class="form-text">Seleziona solo per forzare l'esito; altrimenti lascia vuoto.</div>
                    </div>
                    <div class="col-12">
                        <p class="text-uppercase small text-muted mb-1">Metriche manuali (opzionali)</p>
                        <div class="row g-3">
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small text-muted">Pendenze aperte</label>
                                <input type="number" min="0" step="1" class="form-control" id="pending_count" placeholder="0">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small text-muted">Pendenze scadute</label>
                                <input type="number" min="0" step="1" class="form-control" id="overdue_count" placeholder="0">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small text-muted">Importo scaduto (€)</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="overdue_amount" placeholder="0,00">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small text-muted">Ritardo max (giorni)</label>
                                <input type="number" min="0" step="1" class="form-control" id="max_overdue_days" placeholder="0">
                            </div>
                        </div>
                        <div class="form-text">Se lasci vuoto, i dati vengono calcolati automaticamente. Se compili, usiamo questi valori per il punteggio.</div>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary" id="submit-btn">
                            <i class="fa-solid fa-shield-halved me-2"></i>Verifica ora
                        </button>
                        <button type="button" class="btn btn-outline-success" id="save-btn">
                            <i class="fa-solid fa-floppy-disk me-2"></i>Salva stato
                        </button>
                        <button type="reset" class="btn btn-outline-secondary" id="reset-btn">Reset</button>
                    </div>
                </form>
                <div class="mt-3" id="morosita-result" style="display:none;">
                    <div class="alert" id="morosita-alert" role="alert"></div>
                    <dl class="row mb-0" id="morosita-metrics"></dl>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
(function() {
    const form = document.getElementById('morosita-form');
    const taxInput = document.getElementById('tax_code');
    const noteInput = document.getElementById('note');
    const scoreSelect = document.getElementById('score');
    const providerSelect = document.getElementById('provider_id');
    const providerLabels = <?php echo json_encode($providerLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const pendingCountInput = document.getElementById('pending_count');
    const pendingAmountInput = null; // not exposed, keep default 0
    const overdueCountInput = document.getElementById('overdue_count');
    const overdueAmountInput = document.getElementById('overdue_amount');
    const maxOverdueDaysInput = document.getElementById('max_overdue_days');
    const submitBtn = document.getElementById('submit-btn');
    const saveBtn = document.getElementById('save-btn');
    const resultBox = document.getElementById('morosita-result');
    const alertBox = document.getElementById('morosita-alert');
    const metricsBox = document.getElementById('morosita-metrics');
    const csrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function setLoading(isLoading, mode) {
        if (submitBtn) {
            submitBtn.disabled = isLoading;
            submitBtn.innerHTML = isLoading
                ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Verifica...'
                : '<i class="fa-solid fa-shield-halved me-2"></i>Verifica ora';
        }
        if (saveBtn) {
            saveBtn.disabled = isLoading;
            saveBtn.innerHTML = isLoading && mode === 'save'
                ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Salvataggio...'
                : '<i class="fa-solid fa-floppy-disk me-2"></i>Salva stato';
        }
    }

    function renderResult(ok, message, data) {
        resultBox.style.display = 'block';
        alertBox.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
        alertBox.textContent = message;
        metricsBox.innerHTML = '';
        if (ok && data && data.metrics) {
            const entries = [
            ['Score', data.score],
            ['Gestore', data.provider_id ? (providerLabels?.[data.provider_id] || `ID ${data.provider_id}`) : '—'],
                ['Pendenze aperte', data.metrics.pending_count ?? 0],
                ['Pendenze scadute', data.metrics.overdue_count ?? 0],
                ['Importo scaduto', (data.metrics.overdue_amount ?? 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })],
                ['Ritardo massimo (giorni)', data.metrics.max_overdue_days ?? 0],
                ['Fonte', data.fonte ?? ''],
                ['Nota', data.note ?? ''],
                ['Aggiornato', data.updated_at ?? '']
            ];
            entries.forEach(([label, value]) => {
                const dt = document.createElement('dt');
                dt.className = 'col-sm-4 text-muted small mb-1';
                dt.textContent = label;
                const dd = document.createElement('dd');
                dd.className = 'col-sm-8 mb-1';
                dd.textContent = value === undefined || value === null || value === '' ? '—' : value;
                metricsBox.appendChild(dt);
                metricsBox.appendChild(dd);
            });
        }
    }

    async function submitMorosita(mode = 'verify') {
        const tax = taxInput.value.trim();
        const note = noteInput.value.trim();
        const providerId = providerSelect ? Number(providerSelect.value || 0) : 0;
        if (!tax) {
            alert('Inserisci un codice fiscale o una P.IVA');
            return;
        }
        const forceAuto = mode === 'verify';
        const metrics = forceAuto ? null : {
            pending_count: pendingCountInput ? Number(pendingCountInput.value || 0) : 0,
            pending_amount: 0,
            overdue_count: overdueCountInput ? Number(overdueCountInput.value || 0) : 0,
            overdue_amount: overdueAmountInput ? Number(overdueAmountInput.value || 0) : 0,
            max_overdue_days: maxOverdueDaysInput ? Number(maxOverdueDaysInput.value || 0) : 0,
        };

        setLoading(true, mode);
        try {
            const score = forceAuto ? '' : (scoreSelect ? scoreSelect.value.trim() : '');
            const response = await fetch('<?php echo base_url('api/customers/morosita-check.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ tax_code: tax, note, score: score || null, metrics, provider_id: providerId || null })
            });
            const data = await response.json();
            if (!response.ok) {
                renderResult(false, data?.error || 'Errore durante la verifica.', null);
                return;
            }
            const labelMap = { ok: 'Regolare', attenzione: 'Attenzione', bloccato: 'Bloccato' };
            renderResult(true, 'Verifica completata: ' + (labelMap[data.score] || data.score), data);
        } catch (error) {
            console.error(error);
            renderResult(false, 'Impossibile completare la verifica.', null);
        } finally {
            setLoading(false, mode);
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        submitMorosita('verify');
    });

    saveBtn?.addEventListener('click', () => {
        submitMorosita('save');
    });

    document.getElementById('reset-btn').addEventListener('click', () => {
        resultBox.style.display = 'none';
        alertBox.textContent = '';
        metricsBox.innerHTML = '';
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
