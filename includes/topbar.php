<?php
$username = current_user_display_name();
$role = $_SESSION['role'] ?? '';
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
                <?php if ($role !== 'Cliente' && $role !== 'Patronato'): ?>
                    <div class="topbar-quick-actions d-none d-md-flex">
                        <a class="btn topbar-btn topbar-btn-action" href="<?php echo base_url('modules/servizi/entrate-uscite/create.php'); ?>" aria-label="Registra una nuova entrata o uscita" title="Registra una nuova entrata o uscita" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-trigger="hover focus" data-bs-title="Registra una nuova entrata o uscita">
                            <i class="fa-solid fa-coins topbar-btn-icon-lead" aria-hidden="true"></i>
                            <span class="topbar-btn-label d-none d-xxl-inline">Nuova entrata/uscita</span>
                        </a>
                        <a class="btn topbar-btn topbar-btn-action" href="<?php echo base_url('modules/servizi/energia/create.php'); ?>" aria-label="Crea un nuovo contratto energia" title="Crea un nuovo contratto energia" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-trigger="hover focus" data-bs-title="Crea un nuovo contratto energia">
                            <i class="fa-solid fa-bolt topbar-btn-icon-lead" aria-hidden="true"></i>
                            <span class="topbar-btn-label d-none d-xxl-inline">Nuovo contratto energia</span>
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
                            <li><a class="dropdown-item" href="<?php echo base_url('modules/servizi/energia/create.php'); ?>"><i class="fa-solid fa-bolt me-2"></i>Nuovo contratto energia</a></li>
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
