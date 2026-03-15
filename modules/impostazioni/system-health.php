<?php
use App\Services\SystemHealth\SystemDiagnosticsService;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager');
require_capability('settings.manage');

if (!function_exists('settings_is_ajax_request')) {
    function settings_is_ajax_request(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

$csrfToken = csrf_token();
$projectRoot = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
$diagnostics = new SystemDiagnosticsService($pdo, $projectRoot);
$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

if ($action === 'list' && settings_is_ajax_request()) {
    header('Content-Type: application/json');
    echo json_encode(['checks' => $diagnostics->runAll()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'fix' && $_SERVER['REQUEST_METHOD'] === 'POST' && settings_is_ajax_request()) {
    header('Content-Type: application/json');
    try {
        require_valid_csrf();
        $checkId = trim((string) ($_POST['check_id'] ?? ''));
        if ($checkId === '') {
            throw new InvalidArgumentException('Seleziona un controllo da ripristinare.');
        }
        $result = $diagnostics->runFix($checkId);
        echo json_encode([
            'success' => $result['fix']['success'],
            'message' => $result['fix']['message'],
            'check' => $result['check'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$pageTitle = 'Diagnostica sistema';
$initialChecks = $diagnostics->runAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Diagnostica sistema</h1>
                <p class="text-muted mb-0">Esegui una scansione rapida per individuare errori e colli di bottiglia.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-secondary" href="<?php echo impostazioni_module_url('index'); ?>"><i class="fa-solid fa-angles-left me-2"></i>Torna alle impostazioni</a>
                <button class="btn btn-primary" type="button" data-health-refresh><i class="fa-solid fa-arrows-rotate me-2"></i>Riesegui diagnostica</button>
            </div>
        </div>

           <div class="card ag-card" id="systemHealthApp"
               data-initial-checks='<?php echo htmlspecialchars(json_encode($initialChecks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'
             data-csrf="<?php echo sanitize_output($csrfToken); ?>"
             data-list-url="<?php echo sanitize_output(impostazioni_module_url('system-health', ['action' => 'list'])); ?>"
             data-fix-url="<?php echo sanitize_output(impostazioni_module_url('system-health')); ?>">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                    <div data-role="health-summary" class="badge bg-secondary-subtle text-body-secondary px-3 py-2">Inizializzazione controllo…</div>
                    <div class="d-none align-items-center gap-2 text-muted" data-role="health-loading">
                        <span class="spinner-border spinner-border-sm"></span>
                        <span>Elaborazione in corso…</span>
                    </div>
                </div>
                <div class="alert alert-info d-none" data-role="health-empty">Nessun controllo disponibile.</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Verifica</th>
                                <th>Stato</th>
                                <th>Dettagli</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody data-role="health-table"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
<script>
(function () {
    const root = document.getElementById('systemHealthApp');
    if (!root) {
        return;
    }

    const state = {
        csrf: root.dataset.csrf || '',
        listUrl: root.dataset.listUrl || '<?php echo sanitize_output(impostazioni_module_url('system-health', ['action' => 'list'])); ?>',
        fixUrl: root.dataset.fixUrl || '<?php echo sanitize_output(impostazioni_module_url('system-health')); ?>',
        checks: [],
    };

    try {
        state.checks = JSON.parse(root.dataset.initialChecks || '[]');
    } catch (error) {
        state.checks = [];
    }

    const tableBody = root.querySelector('[data-role="health-table"]');
    const summary = root.querySelector('[data-role="health-summary"]');
    const emptyAlert = root.querySelector('[data-role="health-empty"]');
    const loadingBox = root.querySelector('[data-role="health-loading"]');
    const refreshButton = document.querySelector('[data-health-refresh]');

    function notify(message, variant) {
        if (window.CS?.showToast) {
            window.CS.showToast(message, variant || 'info');
            return;
        }
        window.alert(message);
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function statusMeta(status) {
        switch ((status || '').toLowerCase()) {
            case 'ok':
                return { label: 'OK', className: 'badge bg-success-subtle text-success-emphasis' };
            case 'warning':
                return { label: 'Warning', className: 'badge bg-warning-subtle text-warning-emphasis' };
            case 'ko':
                return { label: 'Errore', className: 'badge bg-danger-subtle text-danger-emphasis' };
            default:
                return { label: 'Sconosciuto', className: 'badge bg-secondary-subtle text-body-secondary' };
        }
    }

    function severityMeta(severity) {
        switch ((severity || '').toLowerCase()) {
            case 'critical':
                return { label: 'Critico', className: 'badge bg-danger-subtle text-danger-emphasis' };
            case 'warning':
                return { label: 'Attenzione', className: 'badge bg-warning-subtle text-warning-emphasis' };
            default:
                return { label: 'Info', className: 'badge bg-info-subtle text-info-emphasis' };
        }
    }

    function renderChecks() {
        if (!tableBody) {
            return;
        }
        if (!state.checks.length) {
            tableBody.innerHTML = '';
            emptyAlert?.classList.remove('d-none');
            return;
        }
        emptyAlert?.classList.add('d-none');
        const rows = state.checks.map((check) => {
            const status = statusMeta(check.status);
            const severity = severityMeta(check.severity);
            const actionLabel = check.can_fix ? 'Risolvi' : 'Manuale';
            const disableButton = !check.can_fix || check.status === 'ok';
            const tooltip = !check.can_fix ? 'Intervento manuale richiesto' : 'Esegui il ripristino suggerito';
            const escapedDetails = escapeHtml(check.details || '');
            const escapedDescription = escapeHtml(check.description || '');
            const escapedLabel = escapeHtml(check.label || '');
            const escapedId = escapeHtml(check.id || '');
            return `
                <tr data-check-id="${escapedId}">
                    <td>
                        <div class="fw-semibold mb-1">${escapedLabel}</div>
                        <div class="small text-muted">${escapedDescription}</div>
                    </td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <span class="${status.className}">${status.label}</span>
                            <span class="${severity.className} small fw-semibold">${severity.label}</span>
                        </div>
                    </td>
                    <td class="small text-muted">${escapedDetails || '—'}</td>
                    <td class="text-end">
                        <button class="btn btn-sm ${disableButton ? 'btn-outline-secondary' : 'btn-outline-primary'}" type="button"
                            data-action="fix-check" data-check-id="${escapedId}" ${disableButton ? 'disabled' : ''}
                                title="${tooltip}">
                            <i class="fa-solid ${check.can_fix ? 'fa-screwdriver-wrench' : 'fa-hand' } me-1"></i>${actionLabel}
                        </button>
                    </td>
                </tr>
            `;
        });
        tableBody.innerHTML = rows.join('');
        updateSummary();
    }

    function updateSummary() {
        if (!summary) {
            return;
        }
        const totals = state.checks.reduce((acc, check) => {
            const status = (check.status || 'unknown').toLowerCase();
            acc[status] = (acc[status] || 0) + 1;
            return acc;
        }, {});
        const parts = [
            `<span class="text-success">OK: ${totals.ok || 0}</span>`,
            `<span class="text-warning">Warning: ${totals.warning || 0}</span>`,
            `<span class="text-danger">Errori: ${totals.ko || 0}</span>`,
        ];
        summary.innerHTML = parts.join(' · ');
        summary.classList.remove('bg-secondary-subtle', 'text-body-secondary');
        summary.classList.add('bg-body-secondary');
    }

    function setLoading(isLoading) {
        if (isLoading) {
            loadingBox?.classList.remove('d-none');
            refreshButton?.setAttribute('disabled', 'disabled');
        } else {
            loadingBox?.classList.add('d-none');
            refreshButton?.removeAttribute('disabled');
        }
    }

    async function fetchChecks() {
        setLoading(true);
        try {
            const response = await fetch(state.listUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                throw new Error('Risposta non valida dal server.');
            }
            const payload = await response.json();
            state.checks = Array.isArray(payload.checks) ? payload.checks : [];
            renderChecks();
        } catch (error) {
            console.error('Diagnostica', error);
            notify('Errore durante la diagnostica: ' + error.message, 'error');
        } finally {
            setLoading(false);
        }
    }

    async function runFix(checkId, button) {
        if (!checkId) {
            return;
        }
        if (button) {
            button.disabled = true;
            button.classList.add('disabled');
        }
        try {
            const response = await fetch(state.fixUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({ action: 'fix', check_id: checkId, _token: state.csrf }),
            });
            if (!response.ok) {
                throw new Error('Richiesta di ripristino non riuscita.');
            }
            const payload = await response.json();
            if (!payload || payload.success === false) {
                throw new Error(payload?.message || 'Operazione non riuscita.');
            }
            if (payload.check) {
                const index = state.checks.findIndex((item) => item.id === payload.check.id);
                if (index !== -1) {
                    state.checks[index] = payload.check;
                } else {
                    state.checks.push(payload.check);
                }
                renderChecks();
            }
            const message = payload.message || 'Ripristino completato.';
            notify(message, payload.success ? 'success' : 'info');
        } catch (error) {
            console.error('Fix diagnostica', error);
            notify(error.message, 'error');
        } finally {
            if (button) {
                button.disabled = false;
                button.classList.remove('disabled');
            }
        }
    }

    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element
            ? event.target.closest('[data-action="fix-check"]')
            : null;
        if (target) {
            runFix(target.dataset.checkId, target);
        }
    });

    refreshButton?.addEventListener('click', () => {
        fetchChecks();
    });

    renderChecks();
})();
</script>
