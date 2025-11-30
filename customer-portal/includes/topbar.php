<?php
if (!isset($customer)) {
    $customer = CustomerAuth::getAuthenticatedCustomer();
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$customerDisplay = htmlspecialchars($customer['name'] ?? $customer['email'] ?? 'Cliente', ENT_QUOTES, 'UTF-8');
?>
<header class="topbar border-bottom sticky-top">
    <div class="container-fluid">
        <div class="topbar-toolbar">
            <div class="topbar-left">
                <button class="btn topbar-btn topbar-btn-icon d-lg-none" type="button" id="sidebarMobileToggle" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Apri il menu laterale">
                    <i class="fa-solid fa-bars" aria-hidden="true"></i>
                </button>
                <button class="btn topbar-btn topbar-btn-icon d-none d-lg-inline-flex" type="button" id="sidebarToggle" aria-expanded="true" aria-label="Riduci barra laterale">
                    <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
                </button>
                <div class="topbar-brand">
                    <span class="topbar-brand-title">Pickup Portal</span>
                    <span class="topbar-brand-subtitle">Area Clienti</span>
                </div>
            </div>
            <div class="topbar-actions">
                <a class="btn topbar-btn d-none d-md-inline-flex" href="report.php" title="Segnala un nuovo pacco" data-bs-toggle="tooltip" data-bs-placement="bottom">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span class="topbar-btn-label">Segnala pacco</span>
                </a>
                <div class="dropdown">
                    <button class="btn topbar-btn topbar-btn-icon position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifiche">
                        <i class="fa-solid fa-bell" aria-hidden="true"></i>
                        <span class="notification-badge badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle" id="notificationCount" style="display: none;">0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
                        <li><h6 class="dropdown-header">Notifiche</h6></li>
                        <div id="notificationList">
                            <li><span class="dropdown-item-text text-muted">Caricamento...</span></li>
                        </div>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center" href="notifications.php">Vedi tutte</a></li>
                    </ul>
                </div>
                <div class="dropdown">
                    <button class="btn topbar-btn topbar-btn-user dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <span class="topbar-btn-label"><?= $customerDisplay ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end topbar-dropdown" aria-labelledby="userDropdown">
                        <li class="dropdown-header text-uppercase small text-muted">Account</li>
                        <li><a class="dropdown-item<?= $currentPage === 'profile.php' ? ' active' : '' ?>" href="profile.php"><i class="fa-solid fa-id-badge me-2"></i>Profilo</a></li>
                        <li><a class="dropdown-item<?= $currentPage === 'settings.php' ? ' active' : '' ?>" href="settings.php"><i class="fa-solid fa-sliders me-2"></i>Impostazioni</a></li>
                        <li><a class="dropdown-item<?= $currentPage === 'help.php' ? ' active' : '' ?>" href="help.php"><i class="fa-solid fa-circle-question me-2"></i>Supporto</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Esci</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</header>
