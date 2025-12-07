<?php
$username = current_user_display_name();
$role = $_SESSION['role'] ?? '';

$canSeeDocumentActions = $role !== 'Cliente' && $role !== 'Patronato' && $role !== 'Collaboratore';
$recentDocuments = [];
$documentsTableAvailable = false;
$documentsLoadFailed = false;
$collaboratorNotifications = [];
$collaboratorNotificationCount = 0;
$collaboratorNotificationsError = false;

if ($role === 'Collaboratore') {
    $collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
    $lookbackDays = 30;
    $maxPerSource = 6;

    if ($collaboratorId > 0 && isset($pdo) && $pdo instanceof PDO) {
        try {
            $statusSql = 'SELECT o.id, o.code, o.status_code, COALESCE(s.label, o.status_code) AS status_label, o.last_status_change
                FROM opportunities o
                LEFT JOIN opportunity_statuses s ON s.code = o.status_code
                WHERE o.collaborator_id = :collaborator
                  AND o.last_status_change IS NOT NULL
                  AND o.last_status_change >= DATE_SUB(NOW(), INTERVAL ' . (int) $lookbackDays . ' DAY)
                ORDER BY o.last_status_change DESC
                LIMIT ' . (int) $maxPerSource;
            $statusStmt = $pdo->prepare($statusSql);
            $statusStmt->execute([':collaborator' => $collaboratorId]);
            while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
                $collaboratorNotifications[] = [
                    'type' => 'status',
                    'title' => sprintf('Opportunity %s', sanitize_output($row['code'] ?? '')), // short label first
                    'subtitle' => sprintf('Stato: %s', sanitize_output($row['status_label'] ?? ($row['status_code'] ?? ''))),
                    'timestamp' => $row['last_status_change'] ?? null,
                    'url' => asset('modules/opportunities/collaborator/view.php?id=' . (int) ($row['id'] ?? 0)),
                ];
            }
        } catch (Throwable $exception) {
            $collaboratorNotificationsError = true;
        }

        try {
                        $ticketSql = 'SELECT tm.id, tm.ticket_id, tm.author_name, tm.created_at, t.codice, t.subject
                                FROM ticket_messages tm
                                INNER JOIN tickets t ON tm.ticket_id = t.id
                                LEFT JOIN users u ON tm.author_id = u.id
                                WHERE t.created_by = :collaborator
                                    AND tm.is_internal = 0
                                    AND tm.visibility = \'customer\'
                                    AND (tm.author_id IS NULL OR tm.author_id <> :collaborator)
                                    AND (u.ruolo IS NULL OR u.ruolo <> \'Collaboratore\')
                                    AND tm.created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $lookbackDays . ' DAY)
                                ORDER BY tm.created_at DESC
                                LIMIT ' . (int) $maxPerSource;
            $ticketStmt = $pdo->prepare($ticketSql);
            $ticketStmt->execute([':collaborator' => $collaboratorId]);
            while ($row = $ticketStmt->fetch(PDO::FETCH_ASSOC)) {
                $collaboratorNotifications[] = [
                    'type' => 'ticket',
                    'title' => sprintf('Ticket #%s', sanitize_output($row['codice'] ?? $row['ticket_id'] ?? '')), // show code fallback
                    'subtitle' => sprintf('Risposta admin: %s', sanitize_output($row['subject'] ?? 'Aggiornamento ticket')),
                    'timestamp' => $row['created_at'] ?? null,
                    'url' => asset('modules/opportunities/collaborator/ticket-view.php?id=' . (int) ($row['ticket_id'] ?? 0)),
                ];
            }
        } catch (Throwable $exception) {
            $collaboratorNotificationsError = true;
        }

        if ($collaboratorNotifications) {
            usort($collaboratorNotifications, static function (array $a, array $b): int {
                $aTime = strtotime((string) ($a['timestamp'] ?? '')) ?: 0;
                $bTime = strtotime((string) ($b['timestamp'] ?? '')) ?: 0;
                return $bTime <=> $aTime;
            });
            $collaboratorNotificationCount = count($collaboratorNotifications);
            $collaboratorNotifications = array_slice($collaboratorNotifications, 0, 10);
        }
    }
}

if (!function_exists('topbar_documents_table_available')) {
    /**
     * Cache the presence of the office_documents table to avoid repeated metadata queries per request.
     */
    function topbar_documents_table_available(PDO $pdo): bool
    {
        static $tableExists;
        if ($tableExists !== null) {
            return $tableExists;
        }

        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute([':table' => 'office_documents']);
            $tableExists = (bool) $stmt->fetchColumn();
        } catch (Throwable $exception) {
            $tableExists = false;
        }

        return $tableExists;
    }
}

if ($canSeeDocumentActions && isset($pdo) && $pdo instanceof PDO) {
    $documentsTableAvailable = topbar_documents_table_available($pdo);

    if ($documentsTableAvailable) {
        try {
            $documentsStmt = $pdo->query('SELECT id, titolo, categoria, stato, updated_at FROM office_documents ORDER BY updated_at DESC LIMIT 5');
            $recentDocuments = $documentsStmt !== false ? $documentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $exception) {
            $recentDocuments = [];
            $documentsLoadFailed = true;
        }
    }
}
?>
<header class="topbar border-bottom sticky-top">
    <div class="container-fluid">
        <div class="topbar-toolbar">
            <div class="topbar-left">
                <button class="btn topbar-btn topbar-btn-icon d-lg-none" type="button" id="sidebarMobileToggle" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Apri menu laterale">
                    <i class="fa-solid fa-bars" aria-hidden="true"></i>
                </button>
                <button class="btn topbar-btn topbar-btn-icon d-none d-lg-inline-flex" type="button" id="sidebarToggle" aria-label="Riduci barra laterale" aria-expanded="true">
                    <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
                </button>
                <div class="topbar-brand" role="presentation">
                    <span class="topbar-brand-title">Coresuite Business</span>
                    <span class="topbar-brand-subtitle">CRM Aziendale</span>
                </div>
            </div>

            <div class="topbar-actions">
                <?php if ($role === 'Collaboratore'): ?>
                    <div class="dropdown me-1">
                        <button class="btn topbar-btn topbar-btn-icon position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifiche">
                            <i class="fa-solid fa-bell" aria-hidden="true"></i>
                            <?php if ($collaboratorNotificationCount > 0): ?>
                                <span class="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle" id="collab-notifications-count" aria-label="<?php echo (int) $collaboratorNotificationCount; ?> notifiche"><?php echo (int) $collaboratorNotificationCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end topbar-dropdown" style="min-width: 360px;">
                            <div class="dropdown-header">
                                <div class="fw-semibold">Notifiche</div>
                                <p class="text-muted small mb-0">Cambi di stato (admin) e risposte ticket (ultimi 30 giorni).</p>
                                <button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="collab-notifications-read">Segna come lette</button>
                            </div>
                            <?php if ($collaboratorNotifications): ?>
                                <?php foreach ($collaboratorNotifications as $notification): ?>
                                    <?php
                                        $isTicket = ($notification['type'] ?? '') === 'ticket';
                                        $iconClass = $isTicket ? 'fa-ticket' : 'fa-arrow-right-arrow-left';
                                        $iconTone = $isTicket ? 'text-info' : 'text-primary';
                                        $timestamp = $notification['timestamp'] ?? null;
                                        $dateLabel = $timestamp ? format_datetime_locale($timestamp) : '';
                                    ?>
                                    <a class="dropdown-item" href="<?php echo sanitize_output($notification['url']); ?>">
                                        <div class="d-flex align-items-start gap-3">
                                            <div class="<?php echo $iconTone; ?>" aria-hidden="true">
                                                <i class="fa-solid <?php echo $iconClass; ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-truncate"><?php echo sanitize_output($notification['title']); ?></div>
                                                <div class="small text-muted text-truncate">
                                                    <?php echo sanitize_output($notification['subtitle']); ?><?php echo $dateLabel !== '' ? ' · ' . sanitize_output($dateLabel) : ''; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php elseif ($collaboratorNotificationsError): ?>
                                <p class="dropdown-item-text text-danger small mb-0 px-3">Errore nel caricare le notifiche.</p>
                            <?php else: ?>
                                <p class="dropdown-item-text text-muted small mb-0 px-3">Nessuna notifica recente.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($canSeeDocumentActions): ?>
                    <div class="topbar-quick-actions d-none d-md-flex">
                        <a class="btn topbar-btn topbar-btn-action" href="<?php echo base_url('modules/servizi/entrate-uscite/create.php'); ?>" aria-label="Registra una nuova entrata o uscita" title="Registra una nuova entrata o uscita" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-trigger="hover focus" data-bs-title="Registra una nuova entrata o uscita">
                            <i class="fa-solid fa-coins topbar-btn-icon-lead" aria-hidden="true"></i>
                            <span class="topbar-btn-label d-none d-xxl-inline">Nuova entrata/uscita</span>
                        </a>
                        <a class="btn topbar-btn topbar-btn-action" href="<?php echo base_url('modules/servizi/brt/create.php'); ?>" aria-label="Crea una nuova spedizione BRT" title="Crea una nuova spedizione BRT" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-trigger="hover focus" data-bs-title="Crea una nuova spedizione BRT">
                            <i class="fa-solid fa-truck-fast topbar-btn-icon-lead" aria-hidden="true"></i>
                            <span class="topbar-btn-label d-none d-xxl-inline">Nuova spedizione BRT</span>
                        </a>
                        <a class="btn topbar-btn topbar-btn-action" href="<?php echo base_url('modules/servizi/appuntamenti/create.php'); ?>" aria-label="Pianifica un nuovo appuntamento" title="Pianifica un nuovo appuntamento" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-trigger="hover focus" data-bs-title="Pianifica un nuovo appuntamento">
                            <i class="fa-solid fa-calendar-plus topbar-btn-icon-lead" aria-hidden="true"></i>
                            <span class="topbar-btn-label d-none d-xxl-inline">Nuovo appuntamento</span>
                        </a>
                            <a class="btn topbar-btn topbar-btn-action" href="<?php echo base_url('modules/office-suite/documents/editor.php'); ?>" aria-label="Crea un nuovo documento" title="Crea un nuovo documento" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-trigger="hover focus" data-bs-title="Crea un nuovo documento">
                                <i class="fa-solid fa-file-pen topbar-btn-icon-lead" aria-hidden="true"></i>
                                <span class="topbar-btn-label d-none d-xxl-inline">Nuovo documento</span>
                            </a>
                    </div>
                    <div class="dropdown d-md-none">
                        <button class="btn topbar-btn topbar-btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Azioni rapide">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/servizi/entrate-uscite/create.php'); ?>"><i class="fa-solid fa-coins me-2"></i>Nuova entrata/uscita</a></li>
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/servizi/brt/create.php'); ?>"><i class="fa-solid fa-truck-fast me-2"></i>Nuova spedizione BRT</a></li>
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/servizi/appuntamenti/create.php'); ?>"><i class="fa-solid fa-calendar-plus me-2"></i>Nuovo appuntamento</a></li>
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/office-suite/documents/editor.php'); ?>"><i class="fa-solid fa-file-pen me-2"></i>Nuovo documento</a></li>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($canSeeDocumentActions): ?>
                    <div class="dropdown">
                        <button class="btn topbar-btn topbar-btn-action" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Documenti recenti">
                            <span class="d-inline-flex align-items-center justify-content-center" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-trigger="hover focus" data-bs-title="Documenti recenti">
                                <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                            </span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end topbar-dropdown topbar-documents-dropdown" style="min-width: 320px;">
                            <div class="dropdown-header">
                                <div class="fw-semibold">Documenti recenti</div>
                                <p class="text-muted small mb-0">Titolo, tipologia, ultima modifica</p>
                            </div>
                            <?php if ($documentsTableAvailable && $recentDocuments): ?>
                                <?php foreach ($recentDocuments as $document): ?>
                                    <?php
                                    $documentTitle = sanitize_output($document['titolo'] ?? 'Documento senza titolo');
                                    $documentType = sanitize_output($document['categoria'] ?? 'Generico');
                                    $documentUpdatedAt = format_datetime_locale($document['updated_at'] ?? null);
                                    $documentUrl = base_url('modules/office-suite/documents/editor.php?id=' . (int) ($document['id'] ?? 0));
                                    ?>
                                    <a class="dropdown-item" href="<?php echo $documentUrl; ?>">
                                        <div class="fw-semibold text-truncate"><?php echo $documentTitle; ?></div>
                                        <div class="d-flex justify-content-between text-muted small">
                                            <span class="text-truncate me-2">Tipo: <?php echo $documentType !== '' ? $documentType : 'N/D'; ?></span>
                                            <span><?php echo $documentUpdatedAt !== '' ? sanitize_output($documentUpdatedAt) : '—'; ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php elseif ($documentsTableAvailable && !$recentDocuments): ?>
                                <p class="dropdown-item-text text-muted small mb-0 px-3">Nessun documento recente disponibile.</p>
                            <?php elseif (!$documentsTableAvailable): ?>
                                <p class="dropdown-item-text text-muted small mb-0 px-3">Archivio Office Suite non configurato.</p>
                            <?php endif; ?>
                            <?php if ($documentsLoadFailed): ?>
                                <p class="dropdown-item-text text-danger small mb-0 px-3">Errore durante il caricamento dei documenti.</p>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <div class="px-3 pb-2 d-grid gap-2">
                                <a class="btn btn-sm btn-primary" href="<?php echo base_url('modules/office-suite/documents/editor.php'); ?>">
                                    <i class="fa-solid fa-file-circle-plus me-2"></i>Nuovo documento
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/office-suite/documents/index.php'); ?>">
                                    <i class="fa-solid fa-folder-tree me-2"></i>Archivio Office Suite
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="btn topbar-btn topbar-btn-user dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-user-circle topbar-btn-icon-lead" aria-hidden="true"></i>
                        <span class="topbar-btn-label"><?php echo sanitize_output($username); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end topbar-dropdown">
                        <li class="dropdown-header">
                            <span class="text-muted small">Ruolo</span>
                            <div class="fw-semibold text-capitalize"><?php echo sanitize_output($role); ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo base_url('modules/impostazioni/profile.php'); ?>"><i class="fa-solid fa-id-badge me-2"></i>Profilo</a></li>
                        <li><a class="dropdown-item" href="<?php echo base_url('logout.php'); ?>"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
(() => {
    const markReadButton = document.getElementById('collab-notifications-read');
    const badge = document.getElementById('collab-notifications-count');

    const hideBadge = () => {
        if (badge) {
            badge.classList.add('d-none');
            sessionStorage.setItem('collabNotificationsRead', '1');
        }
    };

    if (sessionStorage.getItem('collabNotificationsRead') === '1') {
        hideBadge();
    }

    if (markReadButton) {
        markReadButton.addEventListener('click', () => {
            hideBadge();
        });
    }
})();
</script>
