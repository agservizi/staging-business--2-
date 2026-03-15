<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
express_module_require_access($pdo, $currentUserId);

$pageTitle = 'Notifiche Express';
$currentRole = (string) ($_SESSION['role'] ?? 'Operatore');

$notifications = express_module_notification_list($pdo, $currentUserId, $currentRole, 100);
$notificationSummary = [
    'total' => count($notifications),
    'unread' => 0,
    'warning' => 0,
    'success' => 0,
    'latest_label' => '',
];

foreach ($notifications as $notification) {
    if (empty($notification['isRead'])) {
        $notificationSummary['unread']++;
    }
    if (($notification['type'] ?? '') === 'warning') {
        $notificationSummary['warning']++;
    }
    if (($notification['type'] ?? '') === 'success') {
        $notificationSummary['success']++;
    }
}

if ($notifications !== []) {
    $notificationSummary['latest_label'] = (string) ($notifications[0]['createdAtLabel'] ?? '');
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('notifications'); ?>
        <style>
            .express-notifications-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-notifications-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-notifications-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.45rem 0.85rem;
                border-radius: 999px;
                background: rgba(58, 123, 213, 0.10);
                color: #2154d7;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-notifications-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-notifications-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-notifications-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-notifications-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-notifications-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-notifications-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-notifications-list {
                display: grid;
                gap: 1rem;
            }

            .express-notifications-item {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.1rem 1.15rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1rem;
                background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            }

            .express-notifications-icon {
                width: 2.5rem;
                height: 2.5rem;
                border-radius: 0.9rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: #eef4ff;
                color: #2154d7;
                flex-shrink: 0;
            }

            .express-notifications-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                margin-top: 0.75rem;
            }

            .express-notifications-meta span {
                display: inline-flex;
                align-items: center;
                padding: 0.35rem 0.6rem;
                border-radius: 999px;
                background: #f8fafc;
                color: #64748b;
                font-size: 0.74rem;
                font-weight: 600;
            }

            .express-notifications-empty {
                padding: 2rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .express-notifications-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-notifications-kpis {
                    grid-template-columns: 1fr;
                }

                .express-notifications-item {
                    flex-direction: column;
                }
            }
        </style>

        <div class="express-notifications-shell">
            <section class="card express-notifications-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-notifications-pill"><i class="fa-solid fa-bell"></i>Centro notifiche</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 15ch;">Timeline Express più chiara per eventi, alert e aggiornamenti.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Monitora in un unico punto le notifiche generate dal modulo Express, con lettura rapida di novità, avvisi e conferme operative.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-notifications-kpis">
                                <div class="express-notifications-kpi">
                                    <span class="express-notifications-kpi-label">Eventi</span>
                                    <span class="express-notifications-kpi-value"><?php echo (int) $notificationSummary['total']; ?></span>
                                    <span class="express-notifications-kpi-note">Timeline Express caricata</span>
                                </div>
                                <div class="express-notifications-kpi">
                                    <span class="express-notifications-kpi-label">Non lette</span>
                                    <span class="express-notifications-kpi-value"><?php echo (int) $notificationSummary['unread']; ?></span>
                                    <span class="express-notifications-kpi-note">Richiedono attenzione immediata</span>
                                </div>
                                <div class="express-notifications-kpi">
                                    <span class="express-notifications-kpi-label">Avvisi</span>
                                    <span class="express-notifications-kpi-value"><?php echo (int) $notificationSummary['warning']; ?></span>
                                    <span class="express-notifications-kpi-note">Notifiche di tipo warning</span>
                                </div>
                                <div class="express-notifications-kpi">
                                    <span class="express-notifications-kpi-label">Ultimo evento</span>
                                    <span class="express-notifications-kpi-value"><?php echo $notificationSummary['latest_label'] !== '' ? 'OK' : '—'; ?></span>
                                    <span class="express-notifications-kpi-note"><?php echo sanitize_output($notificationSummary['latest_label'] !== '' ? $notificationSummary['latest_label'] : 'Nessun evento disponibile'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card express-notifications-panel">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h5 class="card-title mb-1">Timeline notifiche modulo Express</h5>
                            <p class="text-muted small mb-0">Feed cronologico degli eventi rilevanti del modulo, con stato lettura e dettagli di contesto.</p>
                        </div>
                        <span class="badge rounded-pill text-bg-light px-3 py-2"><?php echo count($notifications); ?> eventi</span>
                    </div>
                    <?php if ($notifications === []): ?>
                        <div class="express-notifications-empty">Nessuna notifica Express disponibile per questo utente.</div>
                    <?php else: ?>
                        <div class="express-notifications-list">
                            <?php foreach ($notifications as $notification): ?>
                                <article class="express-notifications-item">
                                    <div class="d-flex gap-3">
                                        <span class="express-notifications-icon">
                                            <i class="fa-solid <?php echo sanitize_output((string) ($notification['icon'] ?? 'fa-bell')); ?>"></i>
                                        </span>
                                        <div>
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <div class="fw-semibold"><?php echo sanitize_output((string) $notification['title']); ?></div>
                                                <span class="badge <?php echo !empty($notification['isRead']) ? 'text-bg-secondary' : 'text-bg-warning'; ?>"><?php echo !empty($notification['isRead']) ? 'Letta' : 'Nuova'; ?></span>
                                            </div>
                                            <div class="text-muted small mb-2"><?php echo sanitize_output((string) $notification['createdAtLabel']); ?></div>
                                            <div><?php echo sanitize_output((string) $notification['message']); ?></div>
                                            <?php if (!empty($notification['metadata']) && is_array($notification['metadata'])): ?>
                                                <div class="express-notifications-meta">
                                                    <?php foreach ($notification['metadata'] as $metaKey => $metaValue): ?>
                                                        <span><?php echo sanitize_output((string) $metaKey . ': ' . (is_scalar($metaValue) ? (string) $metaValue : json_encode($metaValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="badge text-bg-light"><?php echo sanitize_output(ucfirst((string) ($notification['type'] ?? 'info'))); ?></span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
