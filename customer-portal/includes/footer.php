    <footer class="portal-footer mt-auto border-top py-3">
        <div class="container-fluid portal-footer__bar">
            <div class="portal-footer__left text-muted-soft">
                <span class="portal-footer__brand text-primary fw-semibold">Pickup Portal</span>
                <span>© <?= date('Y') ?> Coresuite Business · v<?= portal_config('portal_version') ?></span>
            </div>
            <nav class="portal-footer__right" aria-label="Link rapidi portale">
                <a href="help.php" class="portal-footer__link"><i class="fa-solid fa-circle-question me-1"></i>Aiuto</a>
                <a href="privacy.php" class="portal-footer__link"><i class="fa-solid fa-shield-halved me-1"></i>Privacy</a>
                <a href="mailto:support@coresuite.it" class="portal-footer__link"><i class="fa-solid fa-envelope me-1"></i>Supporto</a>
                <span class="portal-footer__secure text-muted-soft"><i class="fa-solid fa-lock me-1"></i>Connessione protetta</span>
            </nav>
        </div>
    </footer>
</div>
<div id="portalCookieBanner" class="portal-cookie-banner" role="dialog" aria-live="polite" aria-label="Informativa sui cookie" hidden>
    <div class="portal-cookie-banner__inner">
        <div class="portal-cookie-banner__text">
            <h2 class="portal-cookie-banner__title">Usiamo solo cookie tecnici</h2>
            <p class="mb-0">Il portale impiega cookie necessari per mantenere la sessione e ricordare la tua scelta. Nessun tracciamento di terze parti è attivo. Continuando accetti i cookie tecnici descritti nell'informativa.</p>
        </div>
        <div class="portal-cookie-banner__actions">
            <button type="button" class="btn btn-primary" id="portalCookieAccept">
                <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Accetta e continua
            </button>
            <a class="btn btn-link text-decoration-none" href="privacy.php#cookies">
                <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>Dettagli privacy
            </a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="assets/js/portal.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (window.portalConfig && Number(window.portalConfig.customerId) > 0) {
        loadNotifications();
        setInterval(loadNotifications, 60000);
    }
    if (window.PickupPortal?.CookieConsent) {
        window.PickupPortal.CookieConsent.init();
    }
});

function loadNotifications() {
    fetch('api/notifications.php?unread=1')
        .then((response) => response.json())
        .then((data) => {
            if (!data.success) {
                return;
            }
            updateNotificationBadge(data.count || 0);
            updateNotificationDropdown(data.notifications || []);
        })
        .catch((error) => {
            console.error('Error loading notifications:', error);
        });
}

function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationCount');
    if (!badge) {
        return;
    }
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'inline-flex';
    } else {
        badge.style.display = 'none';
    }
}

function updateNotificationDropdown(notifications) {
    const list = document.getElementById('notificationList');
    if (!list) {
        return;
    }
    if (notifications.length === 0) {
        list.innerHTML = '<li><span class="dropdown-item-text text-muted">Nessuna notifica</span></li>';
        return;
    }
    list.innerHTML = notifications.slice(0, 5).map((notification) => `
        <li>
            <a class="dropdown-item notification-item" href="notifications.php#${notification.id}">
                <div class="d-flex">
                    <div class="me-3 pt-1">
                        <i class="fa-solid fa-${getNotificationIcon(notification.type)} text-primary"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold text-truncate">${notification.title}</div>
                        <div class="text-muted small text-truncate-2">${notification.message}</div>
                        <div class="text-muted small">${formatDate(notification.created_at)}</div>
                    </div>
                </div>
            </a>
        </li>
    `).join('');
}

function getNotificationIcon(type) {
    const icons = {
        package_arrived: 'box',
        package_ready: 'check-circle',
        package_reminder: 'clock',
        package_expired: 'triangle-exclamation',
        system_message: 'info-circle'
    };
    return icons[type] || 'bell';
}

function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) {
        return 'Ora';
    }
    if (diffMins < 60) {
        return `${diffMins}m fa`;
    }
    if (diffHours < 24) {
        return `${diffHours}h fa`;
    }
    if (diffDays < 7) {
        return `${diffDays}g fa`;
    }
    return date.toLocaleDateString('it-IT');
}
</script>
</body>
</html>