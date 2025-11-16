<?php
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$currentPath = basename(parse_url($currentUri, PHP_URL_PATH) ?? '');
$role = $_SESSION['role'] ?? '';
$isPatronato = $role === 'Patronato';

if (!function_exists('nav_active')) {
    function nav_active(string $needle, string $currentPath): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($needle === $currentPath) {
            return 'active';
        }
        return str_contains($uri, $needle) ? 'active' : '';
    }
}

if (!function_exists('nav_is_active')) {
    function nav_is_active(string $needle, string $currentPath): bool
    {
        return nav_active($needle, $currentPath) === 'active';
    }
}

$serviziItems = [
    [
        'needle' => 'modules/servizi/entrate-uscite',
        'label' => 'Entrate/Uscite',
        'icon' => 'fa-solid fa-arrow-trend-up',
        'href' => base_url('modules/servizi/entrate-uscite/index.php'),
        'color' => 'sky',
    ],
    [
        'needle' => 'modules/servizi/appuntamenti',
        'label' => 'Appuntamenti',
        'icon' => 'fa-solid fa-calendar-check',
        'href' => base_url('modules/servizi/appuntamenti/index.php'),
        'color' => 'violet',
    ],
    [
        'needle' => 'modules/servizi/caf-patronato',
        'label' => 'CAF & Patronato',
    'icon' => 'fa-solid fa-scale-balanced',
    'href' => base_url('modules/servizi/caf-patronato/index.php'),
    'color' => 'emerald',
    ],
    [
        'needle' => 'modules/servizi/fedelta',
        'label' => 'Programma Fedeltà',
        'icon' => 'fa-solid fa-gift',
        'href' => base_url('modules/servizi/fedelta/index.php'),
        'color' => 'amber',
    ],
    [
        'needle' => 'modules/servizi/web',
        'label' => 'Servizi Digitali & Web',
        'icon' => 'fa-solid fa-earth-europe',
        'href' => base_url('modules/servizi/web/index.php'),
        'color' => 'azure',
    ],
    [
        'needle' => 'modules/servizi/curriculum',
        'label' => 'Gestione Curriculum',
        'icon' => 'fa-solid fa-id-card',
        'href' => base_url('modules/servizi/curriculum/index.php'),
        'color' => 'emerald',
    ],
    [
        'needle' => 'modules/servizi/brt',
        'label' => 'BRT Spedizioni',
        'icon' => 'fa-solid fa-truck-fast',
        'href' => base_url('modules/servizi/brt/index.php'),
        'color' => 'azure',
    ],
    [
        'needle' => 'modules/servizi/logistici',
        'label' => 'Pickup Pacchi',
        'icon' => 'fa-solid fa-box-open',
        'href' => base_url('modules/servizi/logistici/index.php'),
        'color' => 'orange',
    ],
    [
        'needle' => 'modules/servizi/visure',
        'label' => 'Visure & Catasto',
        'icon' => 'fa-solid fa-map-location-dot',
        'href' => base_url('modules/servizi/visure/index.php'),
        'color' => 'teal',
    ],
    [
        'needle' => 'modules/servizi/telegrammi',
        'label' => 'Invio telegrammi',
        'icon' => 'fa-solid fa-paper-plane',
        'href' => base_url('modules/servizi/telegrammi/index.php'),
        'color' => 'sky',
    ],
    [
        'needle' => 'modules/servizi/energia',
        'label' => 'Contratti Energia',
        'icon' => 'fa-solid fa-bolt',
        'href' => base_url('modules/servizi/energia/index.php'),
        'color' => 'crimson',
    ],
    [
        'needle' => 'modules/servizi/anpr',
        'label' => 'Servizi ANPR',
        'icon' => 'fa-solid fa-id-card-clip',
        'href' => base_url('modules/servizi/anpr/index.php'),
        'color' => 'amber',
    ],
    [
        'needle' => 'modules/servizi/cie',
        'label' => 'Prenotazione CIE',
    'icon' => 'fa-solid fa-address-card',
    'href' => base_url('modules/servizi/cie/index.php'),
    'color' => 'violet',
    ],
    [
        'needle' => 'modules/servizi/consulenza-fiscale',
        'label' => 'Consulenza fiscale rapida',
        'icon' => 'fa-solid fa-file-invoice-dollar',
        'href' => base_url('modules/servizi/consulenza-fiscale/index.php'),
        'color' => 'crimson',
        'roles' => ['Admin', 'Manager', 'Operatore'],
    ],
];

if ($role) {
    $serviziItems = array_values(array_filter($serviziItems, static function (array $item) use ($role): bool {
        if (empty($item['roles']) || !is_array($item['roles'])) {
            return true;
        }

        return in_array($role, $item['roles'], true);
    }));
}

if ($isPatronato) {
    $serviziItems = array_values(array_filter($serviziItems, static function (array $item): bool {
        return $item['needle'] === 'modules/servizi/caf-patronato';
    }));
}

$sidebarLogoRelative = 'assets/uploads/branding/sidebar-logo.png';
$sidebarLogoAvailable = is_file(public_path($sidebarLogoRelative));
?>
<nav id="sidebarMenu" class="sidebar border-end" aria-label="Menu principale">
    <div class="px-3 py-4 sidebar-inner">
        <div class="sidebar-brand mb-4">
            <a class="sidebar-brand-link" href="<?php echo base_url('dashboard.php'); ?>" aria-label="Coresuite Business">
                <span class="sidebar-logo" aria-hidden="true">
                    <?php if ($sidebarLogoAvailable): ?>
                        <img class="sidebar-logo-img" src="<?php echo asset($sidebarLogoRelative); ?>" alt="">
                    <?php else: ?>
                        <i class="fa-solid fa-building"></i>
                    <?php endif; ?>
                </span>
                <span class="sidebar-brand-text">
                    <span class="sidebar-brand-title">Coresuite Business</span>
                    <span class="sidebar-brand-subtitle">CRM Aziendale</span>
                </span>
            </a>
        </div>
        <ul class="nav nav-pills flex-column gap-1" role="list">
            <?php if (!$isPatronato): ?>
                <?php $dashboardActive = nav_active('dashboard.php', $currentPath); ?>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center <?php echo $dashboardActive; ?>" href="<?php echo base_url('dashboard.php'); ?>" aria-label="Dashboard" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="Dashboard"<?php echo $dashboardActive ? ' aria-current="page"' : ''; ?>>
                        <span class="nav-icon" data-color="sky" aria-hidden="true">
                            <i class="fa-solid fa-gauge-high"></i>
                        </span>
                        <span class="nav-label">Dashboard</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($role !== 'Cliente'): ?>
                <?php if ($isPatronato): ?>
                    <?php $cafActive = nav_active('modules/servizi/caf-patronato', $currentPath); ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center <?php echo $cafActive; ?>" href="<?php echo base_url('modules/servizi/caf-patronato/index.php'); ?>" aria-label="CAF &amp; Patronato" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="CAF &amp; Patronato"<?php echo $cafActive ? ' aria-current="page"' : ''; ?>>
                            <span class="nav-icon" data-color="emerald" aria-hidden="true">
                                <i class="fa-solid fa-scale-balanced"></i>
                            </span>
                            <span class="nav-label">CAF &amp; Patronato</span>
                        </a>
                    </li>
                <?php else: ?>
                    <?php $clientiActive = nav_active('modules/clienti', $currentPath); ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center <?php echo $clientiActive; ?>" href="<?php echo base_url('modules/clienti/index.php'); ?>" aria-label="Clienti" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="Clienti"<?php echo $clientiActive ? ' aria-current="page"' : ''; ?>>
                            <span class="nav-icon" data-color="emerald" aria-hidden="true">
                                <i class="fa-solid fa-users"></i>
                            </span>
                            <span class="nav-label">Clienti</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (!$isPatronato && $serviziItems): ?>
                    <?php $serviziMenuOpen = false; ?>
                    <?php foreach ($serviziItems as $item) {
                        if (nav_is_active($item['needle'], $currentPath)) {
                            $serviziMenuOpen = true;
                            break;
                        }
                    } ?>
                    <?php $serviziButtonActive = $serviziMenuOpen ? 'active' : ''; ?>
                    <li class="nav-item">
                        <button class="nav-link nav-link-toggle d-flex align-items-center w-100 text-start <?php echo $serviziButtonActive; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarServices" aria-expanded="<?php echo $serviziMenuOpen ? 'true' : 'false'; ?>" aria-controls="sidebarServices" aria-label="Servizi" data-tooltip="true" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="Servizi">
                            <span class="nav-icon" data-color="violet" aria-hidden="true">
                                <i class="fa-solid fa-briefcase"></i>
                            </span>
                            <span class="nav-label">Servizi</span>
                            <span class="nav-caret" aria-hidden="true">
                                <i class="fa-solid fa-chevron-down"></i>
                            </span>
                        </button>
                        <div class="collapse<?php echo $serviziMenuOpen ? ' show' : ''; ?>" id="sidebarServices">
                            <ul class="nav flex-column ms-3 border-start ps-3" role="list">
                                <?php foreach ($serviziItems as $item): ?>
                                    <?php $itemActive = nav_active($item['needle'], $currentPath); ?>
                                    <li>
                                        <a class="nav-link d-flex align-items-center <?php echo $itemActive; ?>" href="<?php echo $item['href']; ?>" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $itemActive ? ' aria-current="page"' : ''; ?>>
                                            <span class="nav-subicon" data-color="<?php echo htmlspecialchars($item['color'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
                                                <i class="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                            </span>
                                            <span class="nav-sub-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </li>
                <?php endif; ?>

                <?php if (!$isPatronato && current_user_has_capability('email.marketing.manage', 'email.marketing.view')): ?>
                    <?php $emailMarketingActive = nav_active('modules/email-marketing', $currentPath); ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center <?php echo $emailMarketingActive; ?>" href="<?php echo base_url('modules/email-marketing/index.php'); ?>" aria-label="Email marketing" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="Email marketing"<?php echo $emailMarketingActive ? ' aria-current="page"' : ''; ?>>
                            <span class="nav-icon" data-color="amber" aria-hidden="true">
                                <i class="fa-solid fa-envelope-open-text"></i>
                            </span>
                            <span class="nav-label">Email marketing</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (!$isPatronato): ?>
                    <?php $ticketActive = nav_active('modules/ticket', $currentPath); ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center <?php echo $ticketActive; ?>" href="<?php echo base_url('modules/ticket/index.php'); ?>" aria-label="Ticket" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="Ticket"<?php echo $ticketActive ? ' aria-current="page"' : ''; ?>>
                            <span class="nav-icon" data-color="crimson" aria-hidden="true">
                                <i class="fa-solid fa-life-ring"></i>
                            </span>
                            <span class="nav-label">Ticket</span>
                        </a>
                    </li>

                    <?php $reportActive = nav_active('modules/report', $currentPath); ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center <?php echo $reportActive; ?>" href="<?php echo base_url('modules/report/index.php'); ?>" aria-label="Report" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="Report"<?php echo $reportActive ? ' aria-current="page"' : ''; ?>>
                            <span class="nav-icon" data-color="teal" aria-hidden="true">
                                <i class="fa-solid fa-chart-pie"></i>
                            </span>
                            <span class="nav-label">Report</span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$isPatronato && current_user_has_capability('settings.manage', 'settings.view')): ?>
                <?php $settingsActive = nav_active('modules/impostazioni', $currentPath); ?>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center <?php echo $settingsActive; ?>" href="<?php echo base_url('modules/impostazioni/index.php'); ?>" aria-label="Impostazioni" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-title="Impostazioni"<?php echo $settingsActive ? ' aria-current="page"' : ''; ?>>
                        <span class="nav-icon" data-color="orange" aria-hidden="true">
                            <i class="fa-solid fa-gear"></i>
                        </span>
                        <span class="nav-label">Impostazioni</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer" aria-label="Versione applicazione">
            v. 1.0.0
        </div>
    </div>
</nav>
