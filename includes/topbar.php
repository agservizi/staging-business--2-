<?php
$username = current_user_display_name();
$role = $_SESSION['role'] ?? '';

$canSeeDocumentActions = $role !== 'Cliente' && $role !== 'Patronato' && $role !== 'Collaboratore';
$collaboratorNotifications = [];
$collaboratorNotificationCount = 0;
$collaboratorNotificationsError = false;
$collaboratorNotificationsLastRead = null;
$collaboratorNotificationsLastStatusSeenAt = null;
$collaboratorNotificationsLastTicketSeenId = 0;
$collaboratorNotificationsLatestStatusInBatch = null;
$collaboratorNotificationsLatestTicketIdInBatch = 0;
    $forceHideCookie = isset($_COOKIE['collab_notifications_hidden']) && $_COOKIE['collab_notifications_hidden'] === '1';

if ($role === 'Collaboratore') {
    $collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
    $lookbackDays = 30;
    $maxPerSource = 6;
    $lastReadKey = 'collab_notifications_last_read_' . $collaboratorId;
    $lastSeenKey = 'collab_notifications_seen_' . $collaboratorId;

    if ($collaboratorId > 0 && isset($pdo) && $pdo instanceof PDO) {
        try {
            // Configurazioni ha PK sulla chiave: una sola riga, quindi basta LIMIT 1
            $lastReadStmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :key LIMIT 1');
            $lastReadStmt->execute([':key' => $lastReadKey]);
            $lastReadValue = $lastReadStmt->fetchColumn();
            if ($lastReadValue !== false && $lastReadValue !== null && $lastReadValue !== '') {
                $collaboratorNotificationsLastRead = strtotime((string) $lastReadValue) ?: null;
            }
            if (isset($_SESSION['collab_notifications_last_read'])) {
                $sessionRead = strtotime((string) $_SESSION['collab_notifications_last_read']) ?: null;
                if ($sessionRead !== null && ($collaboratorNotificationsLastRead === null || $sessionRead > $collaboratorNotificationsLastRead)) {
                    $collaboratorNotificationsLastRead = $sessionRead;
                }
            }
        } catch (Throwable $exception) {
            $collaboratorNotificationsLastRead = null;
        }

        try {
            $lastSeenStmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :key LIMIT 1');
            $lastSeenStmt->execute([':key' => $lastSeenKey]);
            $lastSeenValue = $lastSeenStmt->fetchColumn();
            if ($lastSeenValue) {
                $decodedSeen = json_decode((string) $lastSeenValue, true);
                if (is_array($decodedSeen)) {
                    if (isset($decodedSeen['last_status_at'])) {
                        $statusTimestamp = strtotime((string) $decodedSeen['last_status_at']);
                        $collaboratorNotificationsLastStatusSeenAt = $statusTimestamp !== false ? $statusTimestamp : null;
                    } else {
                        $collaboratorNotificationsLastStatusSeenAt = null;
                    }
                    $collaboratorNotificationsLastTicketSeenId = isset($decodedSeen['last_ticket_message_id']) ? (int) $decodedSeen['last_ticket_message_id'] : 0;
                }
            }
            if (isset($_SESSION['collab_notifications_last_status_at'])) {
                $sessionStatus = strtotime((string) $_SESSION['collab_notifications_last_status_at']) ?: null;
                if ($sessionStatus !== null && ($collaboratorNotificationsLastStatusSeenAt === null || $sessionStatus > $collaboratorNotificationsLastStatusSeenAt)) {
                    $collaboratorNotificationsLastStatusSeenAt = $sessionStatus;
                }
            }
            if (isset($_SESSION['collab_notifications_last_ticket_message_id'])) {
                $sessionTicketId = (int) $_SESSION['collab_notifications_last_ticket_message_id'];
                if ($sessionTicketId > $collaboratorNotificationsLastTicketSeenId) {
                    $collaboratorNotificationsLastTicketSeenId = $sessionTicketId;
                }
            }

            // Cookie fallback in case DB/session sync lags
            if (isset($_COOKIE['collab_notifications_seen'])) {
                $cookieRaw = (string) $_COOKIE['collab_notifications_seen'];
                $cookieDecoded = json_decode(base64_decode($cookieRaw, true) ?: '', true);
                if (is_array($cookieDecoded)) {
                    if (isset($cookieDecoded['last_status_at'])) {
                        $cookieStatus = strtotime((string) $cookieDecoded['last_status_at']) ?: null;
                        if ($cookieStatus !== null && ($collaboratorNotificationsLastStatusSeenAt === null || $cookieStatus > $collaboratorNotificationsLastStatusSeenAt)) {
                            $collaboratorNotificationsLastStatusSeenAt = $cookieStatus;
                        }
                    }
                    if (isset($cookieDecoded['last_ticket_message_id'])) {
                        $cookieTicketId = (int) $cookieDecoded['last_ticket_message_id'];
                        if ($cookieTicketId > $collaboratorNotificationsLastTicketSeenId) {
                            $collaboratorNotificationsLastTicketSeenId = $cookieTicketId;
                        }
                    }
                    if (isset($cookieDecoded['last_read_at'])) {
                        $cookieRead = strtotime((string) $cookieDecoded['last_read_at']) ?: null;
                        if ($cookieRead !== null && ($collaboratorNotificationsLastRead === null || $cookieRead > $collaboratorNotificationsLastRead)) {
                            $collaboratorNotificationsLastRead = $cookieRead;
                        }
                    }
                }
            }
        } catch (Throwable $exception) {
            $collaboratorNotificationsLastStatusSeenAt = null;
            $collaboratorNotificationsLastTicketSeenId = 0;
        }

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
                $collaboratorNotificationsLatestStatusInBatch = $collaboratorNotificationsLatestStatusInBatch === null
                    ? ($row['last_status_change'] ?? null)
                    : max($collaboratorNotificationsLatestStatusInBatch, ($row['last_status_change'] ?? null));
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
                                $ticketId = (int) ($row['id'] ?? 0);
                                if ($ticketId > $collaboratorNotificationsLatestTicketIdInBatch) {
                                    $collaboratorNotificationsLatestTicketIdInBatch = $ticketId;
                                }
                $collaboratorNotifications[] = [
                    'type' => 'ticket',
                    'id' => $ticketId,
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
            $collaboratorNotifications = array_slice($collaboratorNotifications, 0, 10);
            $lastStatusCutoff = $collaboratorNotificationsLastStatusSeenAt ?? $collaboratorNotificationsLastRead ?? 0;
            $lastTicketCutoffTime = $collaboratorNotificationsLastRead;
            $latestStatusTs = $collaboratorNotificationsLatestStatusInBatch ? strtotime((string) $collaboratorNotificationsLatestStatusInBatch) : 0;

            $forceHide = isset($_SESSION['collab_notifications_force_hide']) && $_SESSION['collab_notifications_force_hide'] === true;
            if ($forceHideCookie) {
                $forceHide = true;
            }
            if ($forceHide || (($latestStatusTs <= $lastStatusCutoff) && ($collaboratorNotificationsLatestTicketIdInBatch <= $collaboratorNotificationsLastTicketSeenId))) {
                $collaboratorNotificationCount = 0;
                unset($_SESSION['collab_notifications_force_hide']);
            } else {
                $collaboratorNotificationCount = count(array_filter($collaboratorNotifications, static function (array $item) use ($collaboratorNotificationsLastTicketSeenId, $lastStatusCutoff, $lastTicketCutoffTime): bool {
                    $timestamp = strtotime((string) ($item['timestamp'] ?? '')) ?: 0;
                    if (($item['type'] ?? '') === 'ticket') {
                        $id = isset($item['id']) ? (int) $item['id'] : 0;
                        if ($id > $collaboratorNotificationsLastTicketSeenId) {
                            return true;
                        }
                        return $lastTicketCutoffTime !== null ? $timestamp > $lastTicketCutoffTime : false;
                    }
                    if ($lastStatusCutoff === null) {
                        return $timestamp > 0;
                    }
                    return $timestamp > $lastStatusCutoff;
                }));
            }
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
                <div class="dropdown me-1">
                    <button class="btn topbar-btn topbar-btn-icon topbar-btn-icon-compact position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifiche" id="notificationsToggle">
                        <i class="fa-solid fa-bell" aria-hidden="true"></i>
                        <span class="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle d-none" id="notificationsBadge" aria-label="Notifiche non lette"></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end notifications-panel" id="notificationsPanel">
                        <div class="notifications-header">
                            <div class="fw-semibold">Notifiche</div>
                            <button class="btn btn-sm btn-outline-secondary" type="button" id="notificationsMarkAll">Segna tutte come lette</button>
                        </div>
                        <div class="notifications-list" id="notificationsList"></div>
                        <div class="notifications-footer">
                            <button class="btn btn-sm btn-outline-primary w-100" type="button" id="notificationsLoadMore">Carica altre</button>
                        </div>
                    </div>
                </div>
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
                    </div>
                    <div class="dropdown d-md-none">
                        <button class="btn topbar-btn topbar-btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Azioni rapide">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/servizi/entrate-uscite/create.php'); ?>"><i class="fa-solid fa-coins me-2"></i>Nuova entrata/uscita</a></li>
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/servizi/brt/create.php'); ?>"><i class="fa-solid fa-truck-fast me-2"></i>Nuova spedizione BRT</a></li>
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/servizi/appuntamenti/create.php'); ?>"><i class="fa-solid fa-calendar-plus me-2"></i>Nuovo appuntamento</a></li>
                        </ul>
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
