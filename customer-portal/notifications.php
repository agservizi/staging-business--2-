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

$filter = $_GET['filter'] ?? 'all';
$allowedFilters = ['all', 'unread'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filter = $_POST['filter'] ?? $filter;
}

$alerts = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'mark_all') {
                $updated = $pickupService->markAllNotificationsAsRead((int) $customer['id']);
                $alerts[] = $updated > 0
                    ? sprintf('%d notifiche sono state segnate come lette.', $updated)
                    : 'Non ci sono notifiche da segnare come lette.';
            } elseif ($action === 'mark_one') {
                $notificationId = (int) ($_POST['notification_id'] ?? 0);
                if ($notificationId <= 0) {
                    throw new Exception('Notifica non valida.');
                }

                $success = $pickupService->markNotificationAsRead((int) $customer['id'], $notificationId);
                if (!$success) {
                    throw new Exception('Impossibile aggiornare la notifica selezionata.');
                }

                $alerts[] = 'Notifica aggiornata correttamente.';
            } elseif ($action === 'delete_one') {
                $notificationId = (int) ($_POST['notification_id'] ?? 0);
                if ($notificationId <= 0) {
                    throw new Exception('Notifica non valida.');
                }

                $deleted = $pickupService->deleteNotification((int) $customer['id'], $notificationId);
                if (!$deleted) {
                    throw new Exception('Impossibile eliminare la notifica selezionata.');
                }

                $alerts[] = 'Notifica eliminata con successo.';
            } elseif ($action === 'delete_all') {
                $totalDeleted = $pickupService->deleteAllNotifications((int) $customer['id']);
                $alerts[] = $totalDeleted > 0
                    ? sprintf('Sono state eliminate %d notifiche.', $totalDeleted)
                    : 'Non ci sono notifiche da eliminare.';
            }
        } catch (Exception $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$unreadCount = portal_count(
    'SELECT COUNT(*) FROM pickup_customer_notifications WHERE customer_id = ? AND read_at IS NULL',
    [$customer['id']]
);

$notifications = $pickupService->getCustomerNotifications((int) $customer['id'], [
    'unread_only' => $filter === 'unread',
    'limit' => 100
]);

$totalNotifications = portal_count(
    'SELECT COUNT(*) FROM pickup_customer_notifications WHERE customer_id = ?',
    [$customer['id']]
);

$filters = [
    'all' => sprintf('Tutte (%d)', $totalNotifications),
    'unread' => sprintf('Non lette (%d)', $unreadCount)
];

$pageTitle = 'Notifiche';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2"><i class="fa-solid fa-bell text-primary"></i>Centro notifiche</h1>
                <p class="text-muted-soft mb-0">Rivedi tutti gli aggiornamenti dal team Coresuite e monitora le azioni completate.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <form class="d-flex" method="POST" action="notifications.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <input type="hidden" name="action" value="mark_all">
                    <button class="btn topbar-btn" type="submit">
                        <i class="fa-solid fa-envelope-open-text"></i>
                        <span class="topbar-btn-label">Segna tutte come lette</span>
                    </button>
                </form>
            <form class="d-flex" method="POST" action="notifications.php"
                data-confirm="Vuoi davvero eliminare tutte le notifiche? Questa operazione non può essere annullata."
                data-confirm-title="Elimina tutte le notifiche"
                data-confirm-confirm-label="Elimina"
                data-confirm-class="btn btn-danger">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <input type="hidden" name="action" value="delete_all">
                    <button class="btn topbar-btn" type="submit">
                        <i class="fa-solid fa-trash"></i>
                        <span class="topbar-btn-label">Elimina tutte</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <?php foreach ($filters as $filterKey => $label): ?>
                <?php $isActive = $filter === $filterKey; ?>
                <a class="badge rounded-pill <?= $isActive ? 'bg-primary text-white' : 'bg-light text-secondary' ?> px-3 py-2" href="notifications.php?filter=<?= urlencode($filterKey) ?>">
                    <?= htmlspecialchars($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($message) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($alerts as $message): ?>
            <div class="alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
            </div>
        <?php endforeach; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 pb-0">
                <div class="d-flex justify-content-between flex-column flex-lg-row gap-2">
                    <div>
                        <h2 class="h5 mb-1">Storico notifiche</h2>
                        <p class="text-muted-soft mb-0">Le notifiche vengono conservate per 90 giorni e poi archiviate automaticamente.</p>
                    </div>
                    <div class="small text-muted-soft">
                        Totale: <?= $totalNotifications ?> · Non lette: <?= $unreadCount ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($notifications)): ?>
                    <div class="dashboard-empty-state dashboard-empty-state-compact">
                        <span class="dashboard-empty-icon"><i class="fa-regular fa-bell"></i></span>
                        <h3 class="dashboard-empty-title">Non ci sono notifiche<?= $filter === 'unread' ? ' non lette' : '' ?></h3>
                        <p class="dashboard-empty-text">Riceverai un avviso qui appena ci saranno novità sui tuoi pacchi.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $icon = $pickupService->getNotificationIcon($notification['type']);
                            $isRead = !empty($notification['read_at']);
                            $createdAt = strtotime($notification['created_at']);
                            $formattedDate = $createdAt ? date('d/m/Y · H:i', $createdAt) : 'N/D';
                            ?>
                            <div class="list-group-item d-flex flex-column flex-lg-row gap-3 py-3<?= $isRead ? '' : ' bg-primary-subtle border-primary-subtle' ?>">
                                <div class="d-flex align-items-start gap-3 flex-grow-1">
                                    <span class="portal-stat-icon flex-shrink-0"><i class="fa-solid fa-<?= htmlspecialchars($icon) ?>"></i></span>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between gap-3">
                                            <h3 class="h6 mb-1"><?= htmlspecialchars($notification['title']) ?></h3>
                                            <span class="small text-muted-soft"><?= $formattedDate ?></span>
                                        </div>
                                        <p class="text-muted mb-2"><?= htmlspecialchars($notification['message']) ?></p>
                                        <div class="d-flex flex-wrap gap-3 align-items-center">
                                            <?php if (!empty($notification['tracking_code'])): ?>
                                                <span class="badge bg-light text-muted"><i class="fa-solid fa-barcode me-1"></i><?= htmlspecialchars($notification['tracking_code']) ?></span>
                                            <?php endif; ?>
                                            <span class="badge rounded-pill <?= $isRead ? 'bg-secondary text-white' : 'bg-success text-white' ?>"><?= $isRead ? 'Letta' : 'Nuova' ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 ms-lg-auto">
                                    <?php if (!$isRead): ?>
                                        <form method="POST" action="notifications.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                                            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                                            <input type="hidden" name="action" value="mark_one">
                                            <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="fa-solid fa-circle-check me-2"></i>Segna come letta</button>
                                        </form>
                                    <?php endif; ?>
                        <form method="POST" action="notifications.php"
                            data-confirm="Eliminare definitivamente questa notifica?"
                            data-confirm-title="Elimina notifica"
                            data-confirm-confirm-label="Elimina"
                            data-confirm-class="btn btn-danger">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                                        <input type="hidden" name="action" value="delete_one">
                                        <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-trash me-2"></i>Elimina</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
