<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$brtPages = ['brt-shipments.php', 'brt-shipment-create.php'];
?>
<aside id="sidebarMenu" class="sidebar">
    <div class="sidebar-inner p-4">
        <div class="sidebar-brand px-2 mb-4">
            <a class="sidebar-brand-link" href="dashboard.php">
                <span class="sidebar-logo" aria-hidden="true">
                    <i class="fa-solid fa-box-open"></i>
                </span>
                <span class="sidebar-brand-text">
                    <span class="sidebar-brand-title">Pickup Portal</span>
                    <span class="sidebar-brand-subtitle">Coresuite Business</span>
                </span>
            </a>
        </div>

        <nav class="nav flex-column sidebar-primary-nav">
            <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php" title="Dashboard">
                <span class="nav-icon" data-color="sky"><i class="fa-solid fa-gauge-high"></i></span>
                <span class="nav-label">Dashboard</span>
            </a>
            <a class="nav-link <?= $currentPage === 'packages.php' ? 'active' : '' ?>" href="packages.php" title="I miei pacchi">
                <span class="nav-icon" data-color="emerald"><i class="fa-solid fa-boxes-stacked"></i></span>
                <span class="nav-label">I miei pacchi</span>
                <span class="badge bg-success-subtle text-success-emphasis ms-auto" id="nav-ready-count" style="display: none;">0</span>
            </a>
            <a class="nav-link <?= in_array($currentPage, $brtPages, true) ? 'active' : '' ?>" href="brt-shipments.php" title="Spedizioni BRT">
                <span class="nav-icon" data-color="indigo"><i class="fa-solid fa-truck-fast"></i></span>
                <span class="nav-label">Spedizioni BRT</span>
            </a>
            <a class="nav-link <?= $currentPage === 'report.php' ? 'active' : '' ?>" href="report.php" title="Segnala pacco">
                <span class="nav-icon" data-color="amber"><i class="fa-solid fa-plus"></i></span>
                <span class="nav-label">Segnala pacco</span>
            </a>
            <a class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>" href="notifications.php" title="Notifiche">
                <span class="nav-icon" data-color="violet"><i class="fa-solid fa-bell"></i></span>
                <span class="nav-label">Notifiche</span>
                <span class="notification-count-sidebar badge bg-danger ms-auto" style="display: none;">0</span>
            </a>
        </nav>

        <div class="sidebar-section mt-4 pt-3 border-top border-light">
            <span class="sidebar-section-label text-uppercase small text-white">Account</span>
            <nav class="nav flex-column mt-3 sidebar-account-nav">
                <a class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>" href="profile.php" title="Profilo">
                    <span class="nav-icon" data-color="orange"><i class="fa-solid fa-user"></i></span>
                    <span class="nav-label">Profilo</span>
                </a>
                <a class="nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>" href="settings.php" title="Impostazioni">
                    <span class="nav-icon" data-color="teal"><i class="fa-solid fa-sliders"></i></span>
                    <span class="nav-label">Impostazioni</span>
                </a>
                <a class="nav-link <?= $currentPage === 'help.php' ? 'active' : '' ?>" href="help.php" title="Supporto">
                    <span class="nav-icon" data-color="sky"><i class="fa-solid fa-circle-question"></i></span>
                    <span class="nav-label">Supporto</span>
                </a>
            </nav>
        </div>

        <div class="sidebar-section mt-4 pt-3 border-top border-light">
            <a class="nav-link text-danger" href="logout.php" title="Esci">
                <span class="nav-icon" data-color="crimson"><i class="fa-solid fa-right-from-bracket"></i></span>
                <span class="nav-label">Esci</span>
            </a>
        </div>

        <div class="sidebar-bottom mt-4">
            <div class="quick-info sidebar-quick-info">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-uppercase text-white-50">In attesa</span>
                    <span class="fw-bold" id="quick-pending">-</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-uppercase text-white-50">Pronti al ritiro</span>
                    <span class="fw-bold" id="quick-ready">-</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-uppercase text-white-50">Questo mese</span>
                    <span class="fw-bold" id="quick-monthly">-</span>
                </div>
            </div>

            <div class="sidebar-footer">
                Pickup Portal · v<?= htmlspecialchars(portal_config('portal_version')) ?>
            </div>
        </div>
    </div>
</aside>

<script>
function updateSidebarCounters() {
    fetch('api/stats.php')
        .then((response) => response.json())
        .then((data) => {
            if (!data.success) {
                return;
            }
            const stats = data.stats || {};
            const pending = document.getElementById('quick-pending');
            const ready = document.getElementById('quick-ready');
            const monthly = document.getElementById('quick-monthly');
            const navReady = document.getElementById('nav-ready-count');
            const notifyBadge = document.querySelector('.notification-count-sidebar');

            if (pending) {
                pending.textContent = stats.pending_packages ?? 0;
            }
            if (ready) {
                ready.textContent = stats.ready_packages ?? 0;
            }
            if (monthly) {
                monthly.textContent = stats.monthly_delivered ?? 0;
            }

            if (navReady) {
                const readyValue = stats.ready_packages ?? 0;
                if (readyValue > 0) {
                    navReady.textContent = readyValue > 99 ? '99+' : readyValue;
                    navReady.style.display = 'inline-flex';
                } else {
                    navReady.style.display = 'none';
                }
            }

            if (notifyBadge) {
                const unread = stats.unread_notifications ?? 0;
                if (unread > 0) {
                    notifyBadge.textContent = unread > 99 ? '99+' : unread;
                    notifyBadge.style.display = 'inline-flex';
                } else {
                    notifyBadge.style.display = 'none';
                }
            }
        })
        .catch((error) => {
            console.error('Error updating sidebar counters:', error);
        });
}

document.addEventListener('DOMContentLoaded', () => {
    updateSidebarCounters();
    setInterval(updateSidebarCounters, 120000);
});
</script>