<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pickup_service.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pickupService = new PickupService();
$customerId = (int) $customer['id'];

$stats = $pickupService->getCustomerStats($customerId);
$recentPackages = $pickupService->getCustomerPackages($customerId, ['limit' => 5]);
$recentNotifications = $pickupService->getCustomerNotifications($customerId, ['limit' => 5, 'unread_only' => false]);
$unreadNotifications = $pickupService->getCustomerNotifications($customerId, ['limit' => 5, 'unread_only' => true]);
$pendingReports = $pickupService->getCustomerReports($customerId, ['status' => 'reported']);

$pageTitle = 'Dashboard';
?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main d-flex flex-column flex-grow-1">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <?php
        $pendingCount = (int) ($stats['pending_packages'] ?? 0);
        $readyCount = (int) ($stats['ready_packages'] ?? 0);
        $monthlyCount = (int) ($stats['monthly_delivered'] ?? 0);
        $totalPackages = (int) ($stats['total_packages'] ?? 0);
        $reportsCount = count($pendingReports);
        $unreadCount = count($unreadNotifications);

        if ($readyCount > 0) {
            $heroSubtitle = sprintf('Hai %d pacchi pronti per il ritiro: passa in sede quando preferisci.', $readyCount);
        } elseif ($pendingCount > 0) {
            $heroSubtitle = sprintf('Ci sono %d segnalazioni in attesa di aggiornamenti dal team Coresuite.', $pendingCount);
        } elseif ($totalPackages > 0) {
            $heroSubtitle = "Tieni d'occhio la tua area per scoprire quando i pacchi saranno pronti.";
        } else {
            $heroSubtitle = "Inizia segnalando il prossimo pacco: ti aggiorneremo in tempo reale sullo stato.";
        }

        $statusIconMap = [
            'reported' => 'fa-clipboard-list',
            'confirmed' => 'fa-circle-check',
            'arrived' => 'fa-box-open',
            'cancelled' => 'fa-ban',
            'in_arrivo' => 'fa-truck-loading',
            'consegnato' => 'fa-boxes-stacked',
            'ritirato' => 'fa-hand-holding-box',
            'in_giacenza' => 'fa-warehouse',
            'in_giacenza_scaduto' => 'fa-triangle-exclamation'
        ];
        ?>

        <div class="portal-dashboard">
            <section class="card ag-card dashboard-card portal-hero text-white">
                <div class="card-body">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-7">
                            <span class="dashboard-chip">Aggiornamento operativo</span>
                            <h1 class="dashboard-title">Bentornato <?= htmlspecialchars($customer['name'] ?? $customer['email'] ?? 'Cliente') ?> 👋</h1>
                            <p class="dashboard-subtitle"><?= htmlspecialchars($heroSubtitle) ?></p>
                            <div class="dashboard-cta">
                                <button type="button" class="btn btn-light dashboard-cta-btn" data-bs-toggle="modal" data-bs-target="#reportPackageModal">
                                    <i class="fa-solid fa-plus"></i>
                                    Segnala pacco
                                </button>
                                <a class="btn btn-outline-light dashboard-cta-btn" href="packages.php">
                                    <i class="fa-solid fa-boxes-stacked"></i>
                                    Vedi tutti i pacchi
                                </a>
                                <a class="btn btn-outline-light dashboard-cta-btn" href="notifications.php">
                                    <i class="fa-solid fa-bell"></i>
                                    Notifiche
                                    <?php if ($unreadCount > 0): ?>
                                        <span class="badge bg-danger-subtle text-danger-emphasis ms-2"><?= $unreadCount ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="dashboard-stat-grid">
                                <div class="dashboard-stat-card">
                                    <span class="dashboard-stat-label">Pronti al ritiro</span>
                                    <span class="dashboard-stat-value"><?= number_format($readyCount, 0, ',', '.') ?></span>
                                    <span class="dashboard-stat-hint">Aggiornato <?= date('d/m') ?></span>
                                </div>
                                <div class="dashboard-stat-card">
                                    <span class="dashboard-stat-label">Segnalazioni aperte</span>
                                    <span class="dashboard-stat-value"><?= number_format($pendingCount, 0, ',', '.') ?></span>
                                    <span class="dashboard-stat-hint">Monitorate dal team</span>
                                </div>
                                <div class="dashboard-stat-card">
                                    <span class="dashboard-stat-label">Ritirati nel mese</span>
                                    <span class="dashboard-stat-value"><?= number_format($monthlyCount, 0, ',', '.') ?></span>
                                    <span class="dashboard-stat-hint">Ultimi 30 giorni</span>
                                </div>
                                <div class="dashboard-stat-card">
                                    <span class="dashboard-stat-label">Totale pacchi</span>
                                    <span class="dashboard-stat-value"><?= number_format($totalPackages, 0, ',', '.') ?></span>
                                    <span class="dashboard-stat-hint">Dal primo accesso</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card ag-card dashboard-card portal-actions">
                <div class="card-header border-0 pb-0">
                    <div class="dashboard-panel-header">
                        <div>
                            <h2 class="dashboard-panel-title">Azioni rapide</h2>
                            <p class="dashboard-panel-subtitle mb-0">Accedi subito alle attività più frequenti</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-4">
                        <a class="dashboard-action" href="report.php">
                            <span class="dashboard-action-icon" data-variant="primary"><i class="fa-solid fa-clipboard"></i></span>
                            <div class="dashboard-action-content">
                                <span class="dashboard-action-title">Nuova segnalazione</span>
                                <span class="dashboard-action-text">Registra un nuovo pacco per iniziare il monitoraggio.</span>
                            </div>
                            <i class="fa-solid fa-chevron-right dashboard-action-chevron" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <a class="dashboard-action" href="packages.php">
                            <span class="dashboard-action-icon" data-variant="success"><i class="fa-solid fa-box"></i></span>
                            <div class="dashboard-action-content">
                                <span class="dashboard-action-title">Situazione pacchi</span>
                                <span class="dashboard-action-text">Consulta lo stato dettagliato e scarica i documenti.</span>
                            </div>
                            <i class="fa-solid fa-chevron-right dashboard-action-chevron" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <a class="dashboard-action" href="notifications.php">
                            <span class="dashboard-action-icon" data-variant="warning"><i class="fa-solid fa-bell"></i></span>
                            <div class="dashboard-action-content">
                                <span class="dashboard-action-title d-flex align-items-center gap-2">Centro notifiche
                                    <?php if ($unreadCount > 0): ?>
                                        <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis"><?= $unreadCount ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="dashboard-action-text">Rivedi gli avvisi recenti e segna come gestiti quelli completati.</span>
                            </div>
                            <i class="fa-solid fa-chevron-right dashboard-action-chevron" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
                </div>
            </section>

            <section class="card ag-card dashboard-card portal-metrics">
                <div class="card-header border-0 pb-0">
                    <div class="dashboard-panel-header">
                        <div>
                            <h2 class="dashboard-panel-title">Metriche operative</h2>
                            <p class="dashboard-panel-subtitle mb-0">Stato aggiornato dei tuoi pacchi</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-xl-3">
                        <article class="dashboard-metric h-100">
                            <div class="dashboard-metric-icon bg-primary-subtle text-primary"><i class="fa-solid fa-box-open"></i></div>
                            <div class="dashboard-metric-body">
                                <span class="dashboard-metric-label">In attesa</span>
                                <span class="dashboard-metric-value"><?= number_format($pendingCount, 0, ',', '.') ?></span>
                                <p class="dashboard-metric-text">Pacchi segnalati ma non ancora arrivati al punto Pickup.</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <article class="dashboard-metric h-100">
                            <div class="dashboard-metric-icon bg-success-subtle text-success"><i class="fa-solid fa-truck-ramp-box"></i></div>
                            <div class="dashboard-metric-body">
                                <span class="dashboard-metric-label">Pronti</span>
                                <span class="dashboard-metric-value"><?= number_format($readyCount, 0, ',', '.') ?></span>
                                <p class="dashboard-metric-text">Ti avviseremo appena disponibili per il ritiro.</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <article class="dashboard-metric h-100">
                            <div class="dashboard-metric-icon bg-info-subtle text-info"><i class="fa-solid fa-calendar-check"></i></div>
                            <div class="dashboard-metric-body">
                                <span class="dashboard-metric-label">Ultimi 30 giorni</span>
                                <span class="dashboard-metric-value"><?= number_format($monthlyCount, 0, ',', '.') ?></span>
                                <p class="dashboard-metric-text">Pacchi ritirati recentemente dal tuo team operativo.</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <article class="dashboard-metric h-100">
                            <div class="dashboard-metric-icon bg-warning-subtle text-warning"><i class="fa-solid fa-circle-exclamation"></i></div>
                            <div class="dashboard-metric-body">
                                <span class="dashboard-metric-label">Follow-up</span>
                                <span class="dashboard-metric-value"><?= number_format($reportsCount, 0, ',', '.') ?></span>
                                <p class="dashboard-metric-text">Segnalazioni con attività aperta da monitorare.</p>
                            </div>
                        </article>
                    </div>
                </div>
                </div>
            </section>

            <div class="row g-4 dashboard-main">
                <div class="col-xxl-8">
                    <section class="card ag-card dashboard-panel dashboard-card">
                        <div class="card-header border-0 pb-0">
                            <div class="dashboard-panel-header">
                                <div>
                                    <h2 class="dashboard-panel-title">Ultimi pacchi</h2>
                                    <p class="dashboard-panel-subtitle">Monitoriamo gli aggiornamenti in tempo reale</p>
                                </div>
                                <a class="btn btn-sm btn-outline-primary" href="packages.php">Vai all'elenco</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentPackages)): ?>
                                <div class="dashboard-empty-state">
                                    <span class="dashboard-empty-icon"><i class="fa-solid fa-box"></i></span>
                                    <h3 class="dashboard-empty-title">Nessun pacco registrato</h3>
                                    <p class="dashboard-empty-text">Segnala il primo pacco per iniziare a ricevere notifiche e aggiornamenti sul ritiro.</p>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reportPackageModal">
                                        <i class="fa-solid fa-plus me-2"></i>Segnala ora
                                    </button>
                                </div>
                            <?php else: ?>
                                <ul class="dashboard-feed list-unstyled mb-0">
                                    <?php foreach ($recentPackages as $package): ?>
                                        <?php
                                        $reportStatus = trim((string) ($package['status'] ?? 'reported')) ?: 'reported';
                                        $pickupStatus = trim((string) ($package['pickup_status'] ?? ''));
                                        $status = $pickupStatus !== '' ? $pickupStatus : $reportStatus;
                                        $statusIconKey = isset($statusIconMap[$status]) ? $status : $reportStatus;
                                        $statusIcon = $statusIconMap[$statusIconKey] ?? 'fa-box';
                                        $timestamp = $package['pickup_updated_at'] ?? ($package['updated_at'] ?? $package['created_at']);
                                        $timestamp = $timestamp ?: $package['created_at'];
                                        ?>
                                        <li class="dashboard-feed-item">
                                            <span class="dashboard-feed-icon" data-status="<?= htmlspecialchars($status !== '' ? $status : $reportStatus) ?>">
                                                <i class="fa-solid <?= $statusIcon ?>" aria-hidden="true"></i>
                                            </span>
                                            <div class="dashboard-feed-main">
                                                <div class="dashboard-feed-header">
                                                    <span class="dashboard-feed-title"><?= htmlspecialchars($package['tracking_code']) ?></span>
                                                    <div class="dashboard-feed-status"><?= $pickupService->getStatusBadge($status) ?></div>
                                                </div>
                                                <div class="dashboard-feed-meta">
                                                    <span><i class="fa-solid fa-user"></i><?= htmlspecialchars($package['recipient_name'] ?: 'Destinatario non indicato') ?></span>
                                                    <span><i class="fa-solid fa-truck"></i><?= htmlspecialchars($package['courier_name'] ?? 'Corriere N/D') ?></span>
                                                    <span><i class="fa-regular fa-clock"></i><?= $timestamp ? date('d/m H:i', strtotime($timestamp)) : '-' ?></span>
                                                </div>
                                            </div>
                                            <div class="dashboard-feed-actions">
                                                <a class="btn btn-sm btn-outline-primary" href="packages.php?view=<?= (int) $package['id'] ?>">Dettagli</a>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="card ag-card dashboard-panel dashboard-card">
                        <div class="card-header border-0 pb-0">
                            <div class="dashboard-panel-header">
                                <div>
                                    <h2 class="dashboard-panel-title">Segnalazioni aperte</h2>
                                    <p class="dashboard-panel-subtitle">Pacchi che richiedono ancora un intervento</p>
                                </div>
                                <span class="badge bg-primary-subtle text-primary-emphasis"><?= $reportsCount ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($reportsCount === 0): ?>
                                <div class="dashboard-empty-state dashboard-empty-state-compact">
                                    <span class="dashboard-empty-icon"><i class="fa-solid fa-circle-check"></i></span>
                                    <p class="dashboard-empty-text">Tutte le segnalazioni risultano gestite. Ti avviseremo se dovessero presentarsi anomalie.</p>
                                </div>
                            <?php else: ?>
                                <ul class="dashboard-feed list-unstyled mb-0 dashboard-feed-compact">
                                    <?php foreach ($pendingReports as $report): ?>
                                        <li class="dashboard-feed-item">
                                            <span class="dashboard-feed-icon" data-status="warning">
                                                <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
                                            </span>
                                            <div class="dashboard-feed-main">
                                                <div class="dashboard-feed-header">
                                                    <span class="dashboard-feed-title"><?= htmlspecialchars($report['tracking_code']) ?></span>
                                                    <div class="dashboard-feed-status"><span class="badge bg-warning-subtle text-warning-emphasis">In attesa</span></div>
                                                </div>
                                                <div class="dashboard-feed-meta">
                                                    <span><i class="fa-regular fa-calendar"></i><?= date('d/m/Y', strtotime($report['created_at'])) ?></span>
                                                    <?php if (!empty($report['notes'])): ?>
                                                        <span><i class="fa-regular fa-note-sticky"></i><?= htmlspecialchars($report['notes']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <div class="col-xxl-4">
                    <section class="card ag-card dashboard-panel dashboard-card">
                        <div class="card-header border-0 pb-0">
                            <div class="dashboard-panel-header">
                                <div>
                                    <h2 class="dashboard-panel-title">Notifiche recenti</h2>
                                    <p class="dashboard-panel-subtitle">Aggiornamenti e promemoria dal sistema</p>
                                </div>
                                <?php if ($unreadCount > 0): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis"><?= $unreadCount ?> nuove</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentNotifications)): ?>
                                <div class="dashboard-empty-state dashboard-empty-state-compact">
                                    <span class="dashboard-empty-icon"><i class="fa-regular fa-bell"></i></span>
                                    <p class="dashboard-empty-text">Zero notifiche pendenti. Puoi sempre rivedere la cronologia completa dalla sezione dedicata.</p>
                                </div>
                            <?php else: ?>
                                <ul class="dashboard-feed list-unstyled mb-0 dashboard-feed-compact">
                                    <?php foreach ($recentNotifications as $notification): ?>
                                        <li class="dashboard-feed-item">
                                            <span class="dashboard-feed-icon" data-status="notification">
                                                <i class="fa-solid fa-<?= $pickupService->getNotificationIcon($notification['type']) ?>" aria-hidden="true"></i>
                                            </span>
                                            <div class="dashboard-feed-main">
                                                <div class="dashboard-feed-header">
                                                    <span class="dashboard-feed-title"><?= htmlspecialchars($notification['title']) ?></span>
                                                    <span class="dashboard-feed-time"><?= date('d/m H:i', strtotime($notification['created_at'])) ?></span>
                                                </div>
                                                <p class="dashboard-feed-text"><?= htmlspecialchars($notification['message']) ?></p>
                                            </div>
                                            <div class="dashboard-feed-actions">
                                                <a class="btn btn-sm btn-outline-secondary" href="notifications.php#<?= (int) $notification['id'] ?>">Apri</a>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="text-center mt-3">
                                    <a class="btn btn-sm btn-outline-primary" href="notifications.php">Vai al centro notifiche</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="card ag-card dashboard-panel dashboard-card">
                        <div class="card-header border-0 pb-0">
                            <h2 class="dashboard-panel-title mb-0">Supporto operativo</h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Hai bisogno di assistenza rapida o vuoi segnalare un problema urgente?</p>
                            <ul class="dashboard-support-list list-unstyled mb-0">
                                <li class="dashboard-support-item">
                                    <span class="dashboard-support-icon"><i class="fa-solid fa-headset"></i></span>
                                    <div>
                                        <strong>Helpdesk Pickup</strong>
                                        <a href="mailto:assistenza@coresuite.it">assistenza@coresuite.it</a>
                                        <small>Risposta entro 4 ore lavorative</small>
                                    </div>
                                </li>
                                <li class="dashboard-support-item">
                                    <span class="dashboard-support-icon"><i class="fa-solid fa-phone"></i></span>
                                    <div>
                                        <strong>Assistenza telefonica</strong>
                                        <a href="tel:+390810584542">+39 081 058 4542</a>
                                        <small>Lun-Ven · 09:00-18:00</small>
                                    </div>
                                </li>
                                <li class="dashboard-support-item">
                                    <span class="dashboard-support-icon"><i class="fa-solid fa-circle-info"></i></span>
                                    <div>
                                        <strong>Documentazione rapida</strong>
                                        <a href="help.php">Guide e FAQ</a>
                                        <small>Procedure passo-passo per il tuo team</small>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="reportPackageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-plus me-2"></i>Segnala nuovo pacco</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <form id="reportPackageForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="tracking_code">Codice tracking *</label>
                            <input class="form-control" id="tracking_code" name="tracking_code" placeholder="es. 1Z999AA1234567890" required maxlength="<?= portal_config('max_tracking_length') ?>">
                            <small class="text-muted">Almeno <?= portal_config('min_tracking_length') ?> caratteri.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="courier_name">Corriere</label>
                            <select class="form-select" id="courier_name" name="courier_name">
                                <option value="">Seleziona corriere</option>
                                <option value="BRT">BRT</option>
                                <option value="GLS">GLS</option>
                                <option value="DHL">DHL</option>
                                <option value="UPS">UPS</option>
                                <option value="FedEx">FedEx</option>
                                <option value="Poste Italiane">Poste Italiane</option>
                                <option value="TNT">TNT</option>
                                <option value="SDA">SDA</option>
                                <option value="Altro">Altro</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="recipient_name">Destinatario</label>
                            <input class="form-control" id="recipient_name" name="recipient_name" value="<?= htmlspecialchars($customer['name'] ?? '') ?>" placeholder="Nome della persona che ritira">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="expected_delivery_date">Data prevista</label>
                            <input class="form-control" type="date" id="expected_delivery_date" name="expected_delivery_date" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="notes">Note</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Informazioni utili sul pacco"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Annulla</button>
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane me-2"></i>Invia segnalazione</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const reportForm = document.getElementById('reportPackageForm');
    if (!reportForm) {
        return;
    }

    reportForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(reportForm);

        fetch('api/report-package.php', {
            method: 'POST',
            body: formData
        })
            .then((response) => response.json())
            .then((data) => {
                if (!data.success) {
                    window.PickupPortal?.showAlert?.(data.message || 'Errore durante la segnalazione', 'danger');
                    return;
                }

                const modalElement = document.getElementById('reportPackageModal');
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                modalInstance?.hide();

                reportForm.reset();
                window.PickupPortal?.showAlert?.('Segnalazione registrata correttamente. Aggiorniamo i dati...', 'success');

                // Ricarichiamo i dati per mostrare il nuovo pacco nella lista.
                setTimeout(() => window.location.reload(), 1200);
            })
            .catch(() => {
                window.PickupPortal?.showAlert?.('Impossibile completare la richiesta in questo momento.', 'danger');
            });
    });
});
</script>
