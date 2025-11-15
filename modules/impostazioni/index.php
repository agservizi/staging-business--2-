<?php
use App\Services\SettingsService;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager');
require_capability('settings.manage');
$pageTitle = 'Impostazioni';

$csrfToken = csrf_token();

if (!function_exists('settings_is_ajax_request')) {
    function settings_is_ajax_request(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('settings_filter_service_suggestions')) {
    function settings_filter_service_suggestions(array $services, array $suggestions): array
    {
        $existing = [];
        foreach ($services as $typeKey => $serviceList) {
            $typeKey = strtoupper((string) $typeKey);
            if (!is_array($serviceList)) {
                continue;
            }
            foreach ($serviceList as $serviceValue) {
                if (!is_string($serviceValue)) {
                    continue;
                }
                $trimmed = trim($serviceValue);
                if ($trimmed === '') {
                    continue;
                }
                $existing[$typeKey][mb_strtolower($trimmed, 'UTF-8')] = true;
            }
        }

        $filtered = [];
        foreach ($suggestions as $typeKey => $suggestionList) {
            $typeKey = strtoupper((string) $typeKey);
            if (!is_array($suggestionList)) {
                continue;
            }

            foreach ($suggestionList as $suggestion) {
                if (!is_string($suggestion)) {
                    continue;
                }
                $trimmed = trim($suggestion);
                if ($trimmed === '') {
                    continue;
                }
                $hash = mb_strtolower($trimmed, 'UTF-8');
                if (!isset($existing[$typeKey][$hash])) {
                    $filtered[$typeKey][] = $trimmed;
                }
            }
        }

        return $filtered;
    }
}

if (!function_exists('settings_build_service_form')) {
    function settings_build_service_form(array $types, array $services): array
    {
        $form = [];

        foreach ($types as $type) {
            $typeKey = strtoupper((string) ($type['key'] ?? ''));
            if ($typeKey === '') {
                continue;
            }

            $rows = [];
            $servicesForType = [];
            if (isset($services[$typeKey])) {
                $candidate = $services[$typeKey];
                if (is_array($candidate)) {
                    $servicesForType = $candidate;
                } elseif (is_string($candidate) && trim($candidate) !== '') {
                    $servicesForType = [$candidate];
                }
            }

            foreach ($servicesForType as $serviceEntry) {
                if (!is_string($serviceEntry)) {
                    continue;
                }
                $trimmed = trim($serviceEntry);
                if ($trimmed === '') {
                    continue;
                }
                $rows[] = ['name' => $trimmed];
            }

            $rows[] = ['name' => ''];
            $form[$typeKey] = $rows;
        }

        return $form;
    }
}

$vatCountries = [
    'AT' => 'Austria',
    'BE' => 'Belgio',
    'BG' => 'Bulgaria',
    'HR' => 'Croazia',
    'CY' => 'Cipro',
    'CZ' => 'Repubblica Ceca',
    'DK' => 'Danimarca',
    'EE' => 'Estonia',
    'FI' => 'Finlandia',
    'FR' => 'Francia',
    'DE' => 'Germania',
    'GR' => 'Grecia',
    'HU' => 'Ungheria',
    'IE' => 'Irlanda',
    'IT' => 'Italia',
    'LV' => 'Lettonia',
    'LT' => 'Lituania',
    'LU' => 'Lussemburgo',
    'MT' => 'Malta',
    'NL' => 'Paesi Bassi',
    'PL' => 'Polonia',
    'PT' => 'Portogallo',
    'RO' => 'Romania',
    'SK' => 'Slovacchia',
    'SI' => 'Slovenia',
    'ES' => 'Spagna',
    'SE' => 'Svezia',
    'XI' => 'Irlanda del Nord',
];

$companyDefaults = [
    'ragione_sociale' => 'Coresuite Business SRL',
    'indirizzo' => 'Via Plinio 72',
    'cap' => '20129',
    'citta' => 'Milano',
    'provincia' => 'MI',
    'telefono' => '+39 02 1234567',
    'email' => 'info@coresuitebusiness.com',
    'pec' => '',
    'sdi' => '',
    'vat_country' => 'IT',
    'piva' => '',
    'iban' => '',
    'note' => '',
    'company_logo' => '',
];

$projectRoot = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
$settingsService = new SettingsService($pdo, $projectRoot);

$companyConfig = $settingsService->fetchCompanySettings($companyDefaults);
$movementDescriptions = $settingsService->getMovementDescriptions();
$appointmentTypes = $settingsService->getAppointmentTypes();
$appointmentStatuses = $settingsService->getAppointmentStatuses();
$appearanceConfig = $settingsService->getAppearanceSettings();
$themeOptions = SettingsService::availableThemes();
$emailMarketingConfig = $settingsService->getEmailMarketingSettings();
$portalBrtPricingForm = $settingsService->getPortalBrtPricingFormConfig();
$cafPatronatoTypes = $settingsService->getCafPatronatoTypes();
$cafPatronatoStatuses = $settingsService->getCafPatronatoStatuses();
$cafPatronatoServices = $settingsService->getCafPatronatoServices();
$cafPatronatoTypesForm = $cafPatronatoTypes;
$cafPatronatoStatusesForm = $cafPatronatoStatuses;
$cafPatronatoServicesForm = settings_build_service_form($cafPatronatoTypes, $cafPatronatoServices);

$cafPatronatoServiceSuggestions = $settingsService->suggestCafPatronatoServices();
$cafPatronatoServiceSuggestions = settings_filter_service_suggestions($cafPatronatoServices, $cafPatronatoServiceSuggestions);

$backupPerPage = 10;
$backupPage = max(1, (int) ($_GET['page_backup'] ?? 1));
$logPerPage = 20;
$logPage = max(1, (int) ($_GET['page_log'] ?? 1));
$exportFormat = isset($_GET['export']) ? (string) $_GET['export'] : '';

$backupPagination = $settingsService->paginateBackups($backupPage, $backupPerPage);
$availableBackups = $backupPagination['items'];
$backupTotal = $backupPagination['total'];
$backupPages = max(1, (int) ceil(($backupTotal ?: 1) / $backupPerPage));

if ($exportFormat === 'logs') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="logs_impostazioni_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Utente', 'Azione', 'Modulo', 'Data']);

    try {
        $logExportStmt = $pdo->query('SELECT la.created_at, la.azione, la.modulo, u.username FROM log_attivita la LEFT JOIN users u ON la.user_id = u.id ORDER BY la.created_at DESC');
        if ($logExportStmt !== false) {
            while ($row = $logExportStmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [
                    $row['username'] ?? 'Sistema',
                    $row['azione'] ?? '',
                    $row['modulo'] ?? '',
                    $row['created_at'] ?? '',
                ]);
            }
        }
    } catch (Throwable $exception) {
        error_log('Impostazioni: esportazione log fallita - ' . $exception->getMessage());
    }

    fclose($out);
    exit;
}

$alerts = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';
    $isAjax = settings_is_ajax_request();
    $currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

    if ($action === 'company') {
        $payload = [
            'ragione_sociale' => $_POST['ragione_sociale'] ?? '',
            'indirizzo' => $_POST['indirizzo'] ?? '',
            'cap' => $_POST['cap'] ?? '',
            'citta' => $_POST['citta'] ?? '',
            'provincia' => $_POST['provincia'] ?? '',
            'telefono' => $_POST['telefono'] ?? '',
            'email' => $_POST['email'] ?? '',
            'pec' => $_POST['pec'] ?? '',
            'sdi' => $_POST['sdi'] ?? '',
            'vat_country' => $_POST['vat_country'] ?? '',
            'piva' => $_POST['piva'] ?? '',
            'iban' => $_POST['iban'] ?? '',
            'note' => $_POST['note'] ?? '',
        ];

        $logoFile = $_FILES['company_logo'] ?? null;
        $result = $settingsService->updateCompanySettings(
            $payload,
            $vatCountries,
            $companyConfig,
            $logoFile,
            isset($_POST['remove_logo']),
            (int) ($_SESSION['user_id'] ?? 0)
        );

        if ($result['success']) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Dati aziendali aggiornati con successo.',
                    'config' => $result['config'],
                ]);
                exit;
            }
            add_flash('success', 'Dati aziendali aggiornati con successo.');
            header('Location: index.php');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }
        $companyConfig = $result['config'];
        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'config' => $result['config'],
            ]);
            exit;
        }
    }

    if ($action === 'backup') {
        $result = $settingsService->generateBackup((int) ($_SESSION['user_id'] ?? 0));
        if ($result['success']) {
            add_flash('success', 'Backup generato correttamente: ' . $result['file']);
            header('Location: index.php');
            exit;
        }

        $alerts[] = ['type' => 'danger', 'text' => $result['error'] ?? 'Errore durante la generazione del backup.'];
        if ($isAjax) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => [$result['error'] ?? 'Errore durante la generazione del backup.'],
            ]);
            exit;
        }
    }

    if ($action === 'backup_delete') {
        $file = trim((string) ($_POST['filename'] ?? ''));
        $result = $settingsService->deleteBackup($file, (int) ($_SESSION['user_id'] ?? 0));

        if ($result['success']) {
            add_flash('success', 'Backup eliminato correttamente.');
            header('Location: index.php?page_backup=' . $backupPage);
            exit;
        }

        $alerts[] = ['type' => 'danger', 'text' => $result['error'] ?? 'Impossibile eliminare il backup selezionato.'];
    }

    if ($action === 'backup_cleanup') {
        $days = max(1, (int) ($_POST['days'] ?? 30));
        $result = $settingsService->cleanupBackupsOlderThan($days, (int) ($_SESSION['user_id'] ?? 0));

        if ($result['success']) {
            $removed = (int) ($result['removed'] ?? 0);
            add_flash('success', $removed > 0 ? sprintf('%d backup eliminati.', $removed) : 'Nessun backup da eliminare.');
            header('Location: index.php?page_backup=' . $backupPage);
            exit;
        }

        $alerts[] = ['type' => 'danger', 'text' => $result['error'] ?? 'Impossibile completare la pulizia dei backup.'];
    }

    if ($action === 'appearance') {
        $selectedTheme = (string) ($_POST['ui_theme'] ?? '');
        $result = $settingsService->saveAppearanceSettings($selectedTheme, (int) ($_SESSION['user_id'] ?? 0));

        if ($result['success']) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Tema interfaccia aggiornato con successo.',
                    'data' => $result['appearance'],
                ]);
                exit;
            }

            add_flash('success', 'Tema interfaccia aggiornato con successo.');
            header('Location: index.php#appearance-settings');
            exit;
        }

        $appearanceConfig = $result['appearance'];
        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }

        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => $result['appearance'],
            ]);
            exit;
        }
    }

    if ($action === 'email_marketing') {
        $currentEmailConfig = $settingsService->getEmailMarketingSettings(false);
        $result = $settingsService->saveEmailMarketingSettings([
            'sender_name' => $_POST['sender_name'] ?? '',
            'sender_email' => $_POST['sender_email'] ?? '',
            'reply_to_email' => $_POST['reply_to_email'] ?? '',
            'resend_api_key' => $_POST['resend_api_key'] ?? '',
            'remove_resend_api_key' => isset($_POST['remove_resend_api_key']),
            'unsubscribe_base_url' => $_POST['unsubscribe_base_url'] ?? '',
            'webhook_secret' => $_POST['webhook_secret'] ?? '',
            'test_address' => $_POST['test_address'] ?? '',
        ], $currentEmailConfig, (int) ($_SESSION['user_id'] ?? 0));

        $emailMarketingConfig = $result['config'];

        if ($result['success']) {
            add_flash('success', 'Impostazioni email marketing aggiornate con successo.');
            header('Location: index.php#email-marketing-settings');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }
    }

    if ($action === 'email_marketing_test') {
        require_once __DIR__ . '/../../includes/mailer.php';

        $testEmail = trim((string) ($_POST['test_email'] ?? ''));
        $subject = trim((string) ($_POST['test_subject'] ?? 'Email marketing - test invio'));
        $message = trim((string) ($_POST['test_message'] ?? 'Questa è una email di prova inviata dalle impostazioni.'));

        if ($subject === '') {
            $subject = 'Email marketing - test invio';
        }

        if ($message === '') {
            $message = 'Questa è una email di prova inviata dalle impostazioni.';
        }

        if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $alerts[] = ['type' => 'danger', 'text' => 'Inserisci un indirizzo email di test valido.'];
            $emailMarketingConfig['test_address'] = $testEmail;
        } else {
            $htmlMessage = '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
            $htmlBody = render_mail_template($subject, $htmlMessage);
            $sent = send_system_mail($testEmail, $subject, $htmlBody, ['channel' => 'marketing']);

            if ($sent) {
                add_flash('success', 'Email di test inviata a ' . $testEmail . '.');
                header('Location: index.php#email-marketing-tools');
                exit;
            }

            $alerts[] = ['type' => 'danger', 'text' => 'Invio di test fallito. Controlla la configurazione e riprova.'];
            $emailMarketingConfig['test_address'] = $testEmail;
        }
    }

    if ($action === 'movements') {
        $entrateRaw = (string) ($_POST['descrizioni_entrata'] ?? '');
        $usciteRaw = (string) ($_POST['descrizioni_uscita'] ?? '');

        $entrateList = array_filter(array_map('trim', preg_split('/\r?\n/', $entrateRaw) ?: []));
        $usciteList = array_filter(array_map('trim', preg_split('/\r?\n/', $usciteRaw) ?: []));

        $result = $settingsService->saveMovementDescriptions($entrateList, $usciteList, (int) ($_SESSION['user_id'] ?? 0));
        if ($result['success']) {
            if ($isAjax) {
                $updated = $settingsService->getMovementDescriptions();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Descrizioni movimenti aggiornate con successo.',
                    'data' => $updated,
                ]);
                exit;
            }
            add_flash('success', 'Descrizioni movimenti aggiornate con successo.');
            header('Location: index.php#movement-descriptions');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }

        $movementDescriptions = [
            'entrate' => $entrateList,
            'uscite' => $usciteList,
        ];
        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => $movementDescriptions,
            ]);
            exit;
        }
    }

    if ($action === 'appointments_types') {
        $typesRaw = (string) ($_POST['appointment_types_list'] ?? '');
        $typesList = array_filter(array_map('trim', preg_split('/\r?\n/', $typesRaw) ?: []));

        $result = $settingsService->saveAppointmentTypes($typesList, (int) ($_SESSION['user_id'] ?? 0));
        if ($result['success']) {
            if ($isAjax) {
                $updatedTypes = $result['types'] ?? $settingsService->getAppointmentTypes();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Tipologie appuntamenti aggiornate con successo.',
                    'data' => ['types' => array_values($updatedTypes)],
                ]);
                exit;
            }
            add_flash('success', 'Tipologie appuntamenti aggiornate con successo.');
            header('Location: index.php#appointment-types');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }

        if (isset($result['types']) && is_array($result['types'])) {
            $appointmentTypes = $result['types'];
        } else {
            $appointmentTypes = array_values($typesList);
        }
        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => ['types' => $appointmentTypes],
            ]);
            exit;
        }
    }

    if ($action === 'appointments_statuses') {
        $availableRaw = (string) ($_POST['appointments_available'] ?? '');
        $activeRaw = (string) ($_POST['appointments_active'] ?? '');
        $completedRaw = (string) ($_POST['appointments_completed'] ?? '');
        $cancelledRaw = (string) ($_POST['appointments_cancelled'] ?? '');
        $confirmationRaw = trim((string) ($_POST['appointments_confirmation'] ?? ''));

        $availableList = array_filter(array_map('trim', preg_split('/\r?\n/', $availableRaw) ?: []));
        $activeList = array_filter(array_map('trim', preg_split('/\r?\n/', $activeRaw) ?: []));
        $completedList = array_filter(array_map('trim', preg_split('/\r?\n/', $completedRaw) ?: []));
        $cancelledList = array_filter(array_map('trim', preg_split('/\r?\n/', $cancelledRaw) ?: []));

        $result = $settingsService->saveAppointmentStatuses(
            $availableList,
            $activeList,
            $completedList,
            $cancelledList,
            $confirmationRaw,
            (int) ($_SESSION['user_id'] ?? 0)
        );

        if ($result['success']) {
            if ($isAjax) {
                $updatedStatuses = $settingsService->getAppointmentStatuses();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Stati appuntamenti aggiornati con successo.',
                    'data' => $updatedStatuses,
                ]);
                exit;
            }
            add_flash('success', 'Stati appuntamenti aggiornati con successo.');
            header('Location: index.php#appointment-statuses');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }

        $appointmentStatuses = [
            'available' => array_values($availableList),
            'active' => array_values($activeList),
            'completed' => array_values($completedList),
            'cancelled' => array_values($cancelledList),
            'confirmation' => $confirmationRaw,
        ];
        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => $appointmentStatuses,
            ]);
            exit;
        }
    }

    if ($action === 'caf_patronato_types') {
        $typesPayload = $_POST['types'] ?? [];
        if (!is_array($typesPayload)) {
            $typesPayload = [];
        }

    $result = $settingsService->saveCafPatronatoTypes($typesPayload, $currentUserId);

        if ($result['success']) {
            $cafPatronatoTypes = $result['config'];
            $cafPatronatoTypesForm = $result['config'];

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Tipologie CAF & Patronato aggiornate con successo.',
                    'data' => $cafPatronatoTypes,
                ]);
                exit;
            }

            add_flash('success', 'Tipologie CAF & Patronato aggiornate con successo.');
            header('Location: index.php#caf-patronato-types');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }

        $cafPatronatoTypesForm = $result['config'];

        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => $cafPatronatoTypesForm,
            ]);
            exit;
        }
    }

    if ($action === 'caf_patronato_statuses') {
        $statusesPayload = $_POST['statuses'] ?? [];
        if (!is_array($statusesPayload)) {
            $statusesPayload = [];
        }

    $result = $settingsService->saveCafPatronatoStatuses($statusesPayload, $currentUserId);

        if ($result['success']) {
            $cafPatronatoStatuses = $result['config'];
            $cafPatronatoStatusesForm = $result['config'];

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Stati pratiche CAF & Patronato aggiornati con successo.',
                    'data' => $cafPatronatoStatuses,
                ]);
                exit;
            }

            add_flash('success', 'Stati pratiche CAF & Patronato aggiornati con successo.');
            header('Location: index.php#caf-patronato-statuses');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }

        $cafPatronatoStatusesForm = $result['config'];

        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => $cafPatronatoStatusesForm,
            ]);
            exit;
        }
    }

    if ($action === 'caf_patronato_services') {
        $servicesPayload = $_POST['services'] ?? [];
        if (!is_array($servicesPayload)) {
            $servicesPayload = [];
        }

        $result = $settingsService->saveCafPatronatoServices($servicesPayload, $currentUserId);

    $cafPatronatoServices = $result['services'];
    $cafPatronatoServicesForm = settings_build_service_form($cafPatronatoTypes, $cafPatronatoServices);
    $cafPatronatoServiceSuggestions = settings_filter_service_suggestions($cafPatronatoServices, $settingsService->suggestCafPatronatoServices());

        if ($result['success']) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Servizi richiesti aggiornati con successo.',
                    'data' => $cafPatronatoServices,
                ]);
                exit;
            }

            add_flash('success', 'Servizi richiesti aggiornati con successo.');
            header('Location: index.php#caf-patronato-services');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }

        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => $cafPatronatoServicesForm,
            ]);
            exit;
        }
    }

    if ($action === 'caf_patronato_services_import') {
        $result = $settingsService->importCafPatronatoServicesFromPratiche($currentUserId);
    $cafPatronatoServices = $result['services'];
    $cafPatronatoServicesForm = settings_build_service_form($cafPatronatoTypes, $cafPatronatoServices);
    $cafPatronatoServiceSuggestions = settings_filter_service_suggestions($cafPatronatoServices, $settingsService->suggestCafPatronatoServices());

        if ($result['success']) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Servizi richiesti importati dalle pratiche.',
                    'data' => $cafPatronatoServices,
                ]);
                exit;
            }

            add_flash('success', 'Servizi richiesti importati dalle pratiche.');
            header('Location: index.php#caf-patronato-services');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'warning', 'text' => $error];
        }

        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => $result['errors'],
                'data' => $cafPatronatoServicesForm,
            ]);
            exit;
        }
    }

    if ($action === 'portal_brt_pricing') {
        $tiersPayload = $_POST['tiers'] ?? [];
        $result = $settingsService->savePortalBrtPricing([
            'currency' => $_POST['currency'] ?? '',
            'tiers' => is_array($tiersPayload) ? $tiersPayload : [],
        ], $currentUserId);

        $portalBrtPricingForm = $result['config'];

        if ($result['success']) {
            add_flash('success', 'Tariffe BRT aggiornate con successo.');
            header('Location: index.php#portal-brt-pricing');
            exit;
        }

        foreach ($result['errors'] as $error) {
            $alerts[] = ['type' => 'danger', 'text' => $error];
        }
    }
}

$lastTypeRow = $cafPatronatoTypesForm ? end($cafPatronatoTypesForm) : null;
if ($lastTypeRow !== false && $lastTypeRow !== null) {
    $isLastTypeEmpty = trim((string) ($lastTypeRow['key'] ?? '')) === ''
        && trim((string) ($lastTypeRow['label'] ?? '')) === ''
        && trim((string) ($lastTypeRow['prefix'] ?? '')) === '';
} else {
    $isLastTypeEmpty = false;
}
if (!$cafPatronatoTypesForm || !$isLastTypeEmpty) {
    $cafPatronatoTypesForm[] = ['key' => '', 'label' => '', 'prefix' => ''];
}
if ($cafPatronatoTypesForm) {
    reset($cafPatronatoTypesForm);
}

$lastStatusRow = $cafPatronatoStatusesForm ? end($cafPatronatoStatusesForm) : null;
if ($lastStatusRow !== false && $lastStatusRow !== null) {
    $isLastStatusEmpty = trim((string) ($lastStatusRow['value'] ?? '')) === ''
        && trim((string) ($lastStatusRow['label'] ?? '')) === '';
} else {
    $isLastStatusEmpty = false;
}
if (!$cafPatronatoStatusesForm || !$isLastStatusEmpty) {
    $cafPatronatoStatusesForm[] = ['value' => '', 'label' => '', 'category' => 'pending'];
}
if ($cafPatronatoStatusesForm) {
    reset($cafPatronatoStatusesForm);
}

$lastServiceRow = $cafPatronatoServicesForm ? end($cafPatronatoServicesForm) : null;
if ($lastServiceRow !== false && $lastServiceRow !== null) {
    if (is_array($lastServiceRow)) {
        $isLastServiceEmpty = trim((string) ($lastServiceRow['name'] ?? '')) === '';
    } else {
        $isLastServiceEmpty = trim((string) $lastServiceRow) === '';
    }
} else {
    $isLastServiceEmpty = false;
}
if (!$cafPatronatoServicesForm || !$isLastServiceEmpty) {
    $cafPatronatoServicesForm[] = ['name' => ''];
}
if ($cafPatronatoServicesForm) {
    reset($cafPatronatoServicesForm);
}

$emailMarketingTestSubject = 'Email marketing - test invio';
$emailMarketingTestMessage = 'Questa è una email di prova inviata dalle impostazioni.';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_marketing_test') {
    $emailMarketingTestSubject = trim((string) ($_POST['test_subject'] ?? $emailMarketingTestSubject));
    $emailMarketingTestMessage = trim((string) ($_POST['test_message'] ?? $emailMarketingTestMessage));
}

$emailMarketingRemoveKeySelected = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'email_marketing'
    && isset($_POST['remove_resend_api_key']);

$emailMarketingUnsubscribeBase = rtrim((string) ($emailMarketingConfig['unsubscribe_base_url'] ?? base_url()), '/');
$emailMarketingUnsubscribeExample = $emailMarketingUnsubscribeBase . '/email-unsubscribe.php?token=ESEMPIO';
$emailMarketingWebhookEndpoint = base_url('api/email-marketing/resend-webhook.php');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Impostazioni sistema</h1>
                <p class="text-muted mb-0">Configura utenti, azienda, backup e preferenze.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-soft-accent" href="logs.php"><i class="fa-solid fa-scroll me-2"></i>Registro attività</a>
                <a class="btn btn-warning" href="users.php"><i class="fa-solid fa-users-gear me-2"></i>Gestione utenti</a>
            </div>
        </div>
        <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-<?php echo sanitize_output($alert['type']); ?>"><?php echo sanitize_output($alert['text']); ?></div>
        <?php endforeach; ?>

        <!-- Tabs per suddividere le impostazioni in sezioni (non distruttivo) -->
        <ul class="nav nav-tabs mb-3" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section-target="company" type="button">Azienda</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section-target="appearance" type="button">Aspetto / Backup</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section-target="movements" type="button">Descrizioni</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section-target="appointments" type="button">Appuntamenti</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section-target="caf-patronato" type="button">CAF &amp; Patronato</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section-target="portal-brt-pricing" type="button">Tariffe BRT</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-section-target="email-marketing" type="button">Email marketing</button>
            </li>
            <li class="nav-item ms-auto" role="presentation">
                <button class="nav-link" data-section-target="logs" type="button">Log attività</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-section-target="all" type="button">Mostra tutto</button>
            </li>
        </ul>

        <div class="row g-4 align-items-stretch">
            <div class="col-12" data-section="company">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Dati aziendali</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="company">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="ragione_sociale">Ragione sociale *</label>
                                    <input class="form-control" id="ragione_sociale" name="ragione_sociale" required value="<?php echo sanitize_output($companyConfig['ragione_sociale']); ?>">
                                </div>
                                <div class="col-12 col-lg-8">
                                    <label class="form-label" for="indirizzo">Indirizzo</label>
                                    <input class="form-control" id="indirizzo" name="indirizzo" value="<?php echo sanitize_output($companyConfig['indirizzo']); ?>">
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label class="form-label" for="cap">CAP</label>
                                    <input class="form-control" id="cap" name="cap" value="<?php echo sanitize_output($companyConfig['cap']); ?>" maxlength="5" pattern="\d{5}" inputmode="numeric" aria-describedby="capHelp" title="Inserisci un CAP italiano a 5 cifre">
                                    <div class="form-text" id="capHelp">Formato 5 cifre (es. 20129).</div>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label class="form-label" for="provincia">Provincia</label>
                                    <input class="form-control text-uppercase" id="provincia" name="provincia" value="<?php echo sanitize_output($companyConfig['provincia']); ?>" maxlength="2" pattern="[A-Za-z]{2}" aria-describedby="provinciaHelp" title="Inserisci le due lettere della provincia">
                                    <div class="form-text" id="provinciaHelp">Due lettere (es. MI, RM).</div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="citta">Città</label>
                                    <input class="form-control" id="citta" name="citta" value="<?php echo sanitize_output($companyConfig['citta']); ?>">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="telefono">Telefono</label>
                                    <input class="form-control" id="telefono" name="telefono" value="<?php echo sanitize_output($companyConfig['telefono']); ?>" inputmode="tel" pattern="[0-9\+\-\s\(\)]{6,30}" aria-describedby="telefonoHelp" title="Inserisci un numero di telefono valido">
                                    <div class="form-text" id="telefonoHelp">Ammessi numeri, spazi e simboli + - ( ).</div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="email">Email</label>
                                    <input class="form-control" id="email" name="email" type="email" value="<?php echo sanitize_output($companyConfig['email']); ?>" aria-describedby="emailHelp" autocomplete="email">
                                    <div class="form-text" id="emailHelp">Usa un indirizzo email valido per comunicazioni ufficiali.</div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="pec">PEC</label>
                                    <input class="form-control" id="pec" name="pec" type="email" value="<?php echo sanitize_output($companyConfig['pec']); ?>" aria-describedby="pecHelp" autocomplete="email">
                                    <div class="form-text" id="pecHelp">Inserisci la PEC registrata presso il registro imprese.</div>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <label class="form-label" for="sdi">Codice SDI</label>
                                    <input class="form-control text-uppercase" id="sdi" name="sdi" value="<?php echo sanitize_output($companyConfig['sdi']); ?>" maxlength="7" pattern="[A-Z0-9]{7}" aria-describedby="sdiHelp" title="Il codice SDI contiene 7 caratteri alfanumerici">
                                    <div class="form-text" id="sdiHelp">7 caratteri alfanumerici (per aziende senza codice usare 0000000).</div>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <label class="form-label" for="vat_country">Paese IVA</label>
                                    <select class="form-select text-uppercase" id="vat_country" name="vat_country">
                                        <?php foreach ($vatCountries as $code => $label): ?>
                                            <option value="<?php echo sanitize_output($code); ?>" <?php echo $companyConfig['vat_country'] === $code ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?> (<?php echo sanitize_output($code); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <label class="form-label" for="iban">IBAN</label>
                                    <input class="form-control text-uppercase" id="iban" name="iban" value="<?php echo sanitize_output($companyConfig['iban']); ?>" maxlength="34" pattern="[A-Z0-9]{15,34}" aria-describedby="ibanHelp" title="Inserisci un IBAN valido (senza spazi)">
                                    <div class="form-text" id="ibanHelp">Da 15 a 34 caratteri alfanumerici, inserisci l'IBAN senza spazi.</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="piva">Partita IVA</label>
                                    <div class="input-group">
                                        <input class="form-control text-uppercase" id="piva" name="piva" value="<?php echo sanitize_output($companyConfig['piva']); ?>" maxlength="16" pattern="[A-Z0-9]{11,16}" inputmode="numeric" aria-describedby="pivaHelp" placeholder="Es. 12345678901" title="Inserisci la partita IVA (11-16 caratteri)">
                                        <button class="btn btn-soft-accent" type="button" id="viesFetch"><i class="fa-solid fa-building-columns me-2"></i>Recupera da VIES</button>
                                    </div>
                                    <div class="form-text" id="pivaHelp">Inserisci il numero senza prefisso paese. Il servizio VIES è disponibile per le aziende iscritte all'archivio IVA UE.</div>
                                </div>
                                <div class="col-12" id="viesFeedback"></div>
                                <div class="col-12">
                                    <label class="form-label" for="note">Note operative</label>
                                    <textarea class="form-control" id="note" name="note" rows="3" maxlength="2000" aria-describedby="noteHelp"><?php echo sanitize_output($companyConfig['note']); ?></textarea>
                                    <div class="form-text" id="noteHelp">Annotazioni interne visibili al personale autorizzato.</div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="company_logo">Logo aziendale</label>
                                    <input class="form-control" id="company_logo" name="company_logo" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" aria-describedby="logoHelp">
                                    <small class="text-muted" id="logoHelp">Max 2MB. Formati consentiti: PNG, JPG, WEBP, SVG.</small>
                                </div>
                                <div class="col-12 col-lg-6 align-self-end">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1">
                                        <label class="form-check-label" for="remove_logo">Rimuovi logo esistente</label>
                                    </div>
                                </div>
                                <?php if (!empty($companyConfig['company_logo'])): ?>
                                    <div class="col-12">
                                        <label class="form-label">Logo attuale</label>
                                        <div class="border rounded-3 p-3 bg-body-secondary">
                                            <img src="<?php echo sanitize_output(base_url($companyConfig['company_logo'])); ?>" alt="Logo aziendale" class="img-fluid" style="max-height: 120px;">
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end mt-4">
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-save me-2"></i>Salva dati</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6" data-section="appearance">
                <div class="card ag-card h-100" id="appearance-settings">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Aspetto interfaccia</h5>
                    </div>
                    <div class="card-body">
                        <?php
                            $activeTheme = $appearanceConfig['theme'] ?? 'navy';
                            $activeThemeLabel = $themeOptions[$activeTheme]['label'] ?? ucfirst($activeTheme);
                        ?>
                        <p class="text-muted mb-3">Personalizza la palette principale mantenendo i layout e i contrasti ottimizzati per l'uso quotidiano.</p>
                        <span class="badge ag-badge rounded-pill px-3 py-2" id="activeThemeBadge">Tema attivo: <?php echo sanitize_output($activeThemeLabel); ?></span>
                        <form method="post" class="mt-3" data-ajax="settings" data-ajax-section="appearance">
                            <input type="hidden" name="action" value="appearance">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <?php
                                $themeKeys = array_keys($themeOptions);
                                $perSlide = 4;
                                $totalSlides = (int) ceil(count($themeKeys) / $perSlide);
                            ?>
                            <div class="theme-slider" data-theme-slider>
                                <div class="theme-slider-track" data-theme-slider-track>
                                    <?php for ($slide = 0; $slide < $totalSlides; $slide += 1):
                                        $slice = array_slice($themeKeys, $slide * $perSlide, $perSlide);
                                    ?>
                                        <div class="theme-slide<?php echo $slide === 0 ? ' is-active' : ''; ?>" data-theme-slide-index="<?php echo $slide; ?>">
                                            <div class="row g-3">
                                                <?php foreach ($slice as $themeKey):
                                                    $option = $themeOptions[$themeKey];
                                                    $isActive = $activeTheme === $themeKey;
                                                ?>
                                                <div class="col-12 col-sm-6">
                                                    <label class="theme-option<?php echo $isActive ? ' active' : ''; ?>" data-theme-key="<?php echo sanitize_output($themeKey); ?>">
                                                        <input class="theme-option-input" type="radio" name="ui_theme" value="<?php echo sanitize_output($themeKey); ?>" <?php echo $isActive ? 'checked' : ''; ?>>
                                                        <span class="theme-option-swatch">
                                                            <?php foreach (($option['swatches'] ?? []) as $swatch): ?>
                                                                <span class="theme-option-swatch-dot" style="background: <?php echo sanitize_output($swatch); ?>;"></span>
                                                            <?php endforeach; ?>
                                                        </span>
                                                        <span class="theme-option-meta">
                                                            <strong class="theme-option-title d-block"><?php echo sanitize_output($option['label']); ?></strong>
                                                            <small class="text-muted d-block"><?php echo sanitize_output($option['description']); ?></small>
                                                        </span>
                                                    </label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="theme-slider-controls" aria-hidden="false">
                                    <button type="button" class="theme-slider-nav" data-theme-slider-prev aria-label="Slide precedente"><i class="fa-solid fa-chevron-left"></i></button>
                                    <div class="theme-slider-dots" role="tablist">
                                        <?php for ($dot = 0; $dot < $totalSlides; $dot += 1): ?>
                                            <button type="button" class="theme-slider-dot<?php echo $dot === 0 ? ' is-active' : ''; ?>" data-theme-slider-dot="<?php echo $dot; ?>" aria-label="Vai alla pagina <?php echo $dot + 1; ?>" aria-current="<?php echo $dot === 0 ? 'true' : 'false'; ?>"></button>
                                        <?php endfor; ?>
                                    </div>
                                    <button type="button" class="theme-slider-nav" data-theme-slider-next aria-label="Slide successiva"><i class="fa-solid fa-chevron-right"></i></button>
                                </div>
                            </div>
                            <div class="text-end mt-4">
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-save me-2"></i>Salva tema</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6" data-section="backup">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Backup database</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Esegui un'esportazione completa del database. L'operazione può richiedere alcuni secondi; evita di chiudere la pagina finché non compare la conferma.</p>
                        <form method="post" class="d-flex flex-column flex-md-row align-items-md-center gap-3 mb-3">
                            <input type="hidden" name="action" value="backup">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <button class="btn btn-warning" type="submit"><i class="fa-solid fa-database me-2"></i>Genera backup SQL</button>
                            <span class="text-muted small">I file vengono salvati in <code>backups/</code> ed elencati qui sotto.</span>
                        </form>
                        <form method="post" class="row g-2 align-items-center mb-3">
                            <input type="hidden" name="action" value="backup_cleanup">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="col-auto">
                                <label class="form-label mb-0" for="cleanup_days">Pulizia automatica</label>
                            </div>
                            <div class="col-auto">
                                <select class="form-select form-select-sm" id="cleanup_days" name="days">
                                    <option value="30">Oltre 30 giorni</option>
                                    <option value="60">Oltre 60 giorni</option>
                                    <option value="90">Oltre 90 giorni</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-soft-danger btn-sm" type="submit" onclick="return confirm('Eliminare i backup più vecchi del periodo selezionato?');">
                                    <i class="fa-solid fa-broom me-1"></i>Pulisci backup
                                </button>
                            </div>
                            <div class="col-auto text-muted small">
                                Totale backup: <?php echo (int) $backupTotal; ?>
                            </div>
                        </form>
                        <?php if ($availableBackups): ?>
                            <div class="table-responsive">
                                <table class="table table-dark table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>File</th>
                                            <th>Generato il</th>
                                            <th>Dimensione</th>
                                            <th class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($availableBackups as $backup): ?>
                                            <tr>
                                                <td><?php echo sanitize_output($backup['name']); ?></td>
                                                <td>
                                                    <?php if (!empty($backup['mtime'])): ?>
                                                        <?php echo sanitize_output(format_datetime(date('Y-m-d H:i:s', (int) $backup['mtime']))); ?>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo sanitize_output($backup['size']); ?></td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                                        <a class="btn btn-icon btn-soft-accent btn-sm" href="download_backup.php?file=<?php echo urlencode($backup['name']); ?>" title="Scarica backup">
                                                            <i class="fa-solid fa-cloud-arrow-down"></i>
                                                        </a>
                                                        <form class="d-inline-block" method="post" onsubmit="return confirm('Eliminare definitivamente questo backup?');">
                                                            <input type="hidden" name="action" value="backup_delete">
                                                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="filename" value="<?php echo sanitize_output($backup['name']); ?>">
                                                            <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina backup">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($backupPages > 1): ?>
                                <nav aria-label="Paginazione backup" class="mt-3">
                                    <ul class="pagination pagination-sm">
                                        <?php
                                            $backupQuery = $_GET;
                                            unset($backupQuery['export']);
                                            $backupQuery['page_backup'] = max(1, $backupPage - 1);
                                            $prevBackupUrl = 'index.php?' . http_build_query($backupQuery);
                                        ?>
                                        <li class="page-item <?php echo $backupPage <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo sanitize_output($prevBackupUrl); ?>" aria-label="Precedente">&laquo;</a>
                                        </li>
                                        <?php for ($page = 1; $page <= $backupPages; $page++):
                                            $backupQuery['page_backup'] = $page;
                                            $backupPageUrl = 'index.php?' . http_build_query($backupQuery);
                                        ?>
                                            <li class="page-item <?php echo $page === $backupPage ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo sanitize_output($backupPageUrl); ?>"><?php echo $page; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <?php
                                            $backupQuery['page_backup'] = min($backupPages, $backupPage + 1);
                                            $nextBackupUrl = 'index.php?' . http_build_query($backupQuery);
                                        ?>
                                        <li class="page-item <?php echo $backupPage >= $backupPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo sanitize_output($nextBackupUrl); ?>" aria-label="Successivo">&raquo;</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">Non sono ancora presenti backup. Generane uno per iniziare la cronologia.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6" data-section="movements">
                <div class="card ag-card h-100" id="movement-descriptions">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Descrizioni movimenti</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Imposta le descrizioni predefinite per entrate e uscite. Verranno proposte nel modulo Entrate/Uscite.</p>
                        <form method="post" data-ajax="settings" data-ajax-section="movements">
                            <input type="hidden" name="action" value="movements">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="accordion" id="movementDescriptionsAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingEntrate">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseEntrate" aria-expanded="true" aria-controls="collapseEntrate">
                                            Descrizioni entrate
                                        </button>
                                    </h2>
                                    <div id="collapseEntrate" class="accordion-collapse collapse show" aria-labelledby="headingEntrate" data-bs-parent="#movementDescriptionsAccordion">
                                        <div class="accordion-body">
                                            <label class="form-label" for="descrizioni_entrata">Una descrizione per riga</label>
                                            <textarea class="form-control" id="descrizioni_entrata" name="descrizioni_entrata" rows="6" placeholder="Es. Incasso giornaliero&#10;Vendita servizi"><?php echo sanitize_output(implode("\n", $movementDescriptions['entrate'])); ?></textarea>
                                            <div class="form-text">Limite 180 caratteri per descrizione. Le righe vuote saranno ignorate.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingUscite">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseUscite" aria-expanded="false" aria-controls="collapseUscite">
                                            Descrizioni uscite
                                        </button>
                                    </h2>
                                    <div id="collapseUscite" class="accordion-collapse collapse" aria-labelledby="headingUscite" data-bs-parent="#movementDescriptionsAccordion">
                                        <div class="accordion-body">
                                            <label class="form-label" for="descrizioni_uscita">Una descrizione per riga</label>
                                            <textarea class="form-control" id="descrizioni_uscita" name="descrizioni_uscita" rows="6" placeholder="Es. Pagamento fornitori&#10;Spese operative"><?php echo sanitize_output(implode("\n", $movementDescriptions['uscite'])); ?></textarea>
                                            <div class="form-text">Limite 180 caratteri per descrizione. Le righe vuote saranno ignorate.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end mt-3">
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-save me-2"></i>Salva descrizioni</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6" data-section="appointments">
                <div class="card ag-card h-100" id="appointment-types">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Tipologie appuntamenti</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Definisci le tipologie disponibili per gli appuntamenti. Verranno proposte nei moduli di creazione e modifica.</p>
                        <form method="post" data-ajax="settings" data-ajax-section="appointment-types">
                            <input type="hidden" name="action" value="appointments_types">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <label class="form-label" for="appointment_types_list">Una tipologia per riga (max 60 caratteri).</label>
                            <textarea class="form-control" id="appointment_types_list" name="appointment_types_list" rows="6" placeholder="Consulenza&#10;Sopralluogo&#10;Supporto tecnico&#10;Rinnovo servizio"><?php echo sanitize_output(implode("\n", $appointmentTypes)); ?></textarea>
                            <div class="form-text">L'elenco è deduplicato automaticamente e mantiene l'ordine indicato.</div>
                            <div class="text-end mt-4">
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-save me-2"></i>Salva tipologie</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6" data-section="appointments">
                <div class="card ag-card h-100" id="appointment-statuses">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Stati appuntamenti</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Personalizza gli stati disponibili per gli appuntamenti e indica quali sono considerati attivi, conclusi o annullati. Queste impostazioni influenzano promemoria, dashboard e sincronizzazione calendario.</p>
                        <form method="post" data-ajax="settings" data-ajax-section="appointment-statuses">
                            <input type="hidden" name="action" value="appointments_statuses">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="accordion" id="appointmentStatusesAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="appointmentStatusesAvailableHeading">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#appointmentStatusesAvailable" aria-expanded="true" aria-controls="appointmentStatusesAvailable">
                                            Elenco stati disponibili
                                        </button>
                                    </h2>
                                    <div id="appointmentStatusesAvailable" class="accordion-collapse collapse show" aria-labelledby="appointmentStatusesAvailableHeading" data-bs-parent="#appointmentStatusesAccordion">
                                        <div class="accordion-body">
                                            <label class="form-label" for="appointments_available">Inserisci uno stato per riga (max 40 caratteri).</label>
                                            <textarea class="form-control" id="appointments_available" name="appointments_available" rows="6" placeholder="Programmato&#10;Confermato&#10;In corso&#10;Completato&#10;Annullato"><?php echo sanitize_output(implode("\n", $appointmentStatuses['available'])); ?></textarea>
                                            <div class="form-text">Lo stesso elenco verrà proposto nei moduli di creazione e modifica degli appuntamenti.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="appointmentStatusesActiveHeading">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#appointmentStatusesActive" aria-expanded="false" aria-controls="appointmentStatusesActive">
                                            Stati considerati "attivi"
                                        </button>
                                    </h2>
                                    <div id="appointmentStatusesActive" class="accordion-collapse collapse" aria-labelledby="appointmentStatusesActiveHeading" data-bs-parent="#appointmentStatusesAccordion">
                                        <div class="accordion-body">
                                            <label class="form-label" for="appointments_active">Uno stato per riga</label>
                                            <textarea class="form-control" id="appointments_active" name="appointments_active" rows="4" placeholder="Programmato&#10;Confermato&#10;In corso"><?php echo sanitize_output(implode("\n", $appointmentStatuses['active'])); ?></textarea>
                                            <div class="form-text">Gli stati attivi alimentano dashboard, promemoria e conteggi in tempo reale.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="appointmentStatusesCompletedHeading">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#appointmentStatusesCompleted" aria-expanded="false" aria-controls="appointmentStatusesCompleted">
                                            Stati di completamento
                                        </button>
                                    </h2>
                                    <div id="appointmentStatusesCompleted" class="accordion-collapse collapse" aria-labelledby="appointmentStatusesCompletedHeading" data-bs-parent="#appointmentStatusesAccordion">
                                        <div class="accordion-body">
                                            <label class="form-label" for="appointments_completed">Uno stato per riga</label>
                                            <textarea class="form-control" id="appointments_completed" name="appointments_completed" rows="3" placeholder="Completato"><?php echo sanitize_output(implode("\n", $appointmentStatuses['completed'])); ?></textarea>
                                            <div class="form-text">Usato per reportistica e riepiloghi delle attività concluse.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="appointmentStatusesCancelledHeading">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#appointmentStatusesCancelled" aria-expanded="false" aria-controls="appointmentStatusesCancelled">
                                            Stati di annullamento
                                        </button>
                                    </h2>
                                    <div id="appointmentStatusesCancelled" class="accordion-collapse collapse" aria-labelledby="appointmentStatusesCancelledHeading" data-bs-parent="#appointmentStatusesAccordion">
                                        <div class="accordion-body">
                                            <label class="form-label" for="appointments_cancelled">Uno stato per riga</label>
                                            <textarea class="form-control" id="appointments_cancelled" name="appointments_cancelled" rows="3" placeholder="Annullato"><?php echo sanitize_output(implode("\n", $appointmentStatuses['cancelled'])); ?></textarea>
                                            <div class="form-text">Utile per distinguere gli appuntamenti disdetti o non andati a buon fine.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-3 mt-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="appointments_confirmation">Stato per sincronizzazione/confirm</label>
                                    <select class="form-select" id="appointments_confirmation" name="appointments_confirmation">
                                        <option value="">Seleziona stato</option>
                                        <?php foreach ($appointmentStatuses['available'] as $status): ?>
                                            <option value="<?php echo sanitize_output($status); ?>" <?php echo strcasecmp($appointmentStatuses['confirmation'], $status) === 0 ? 'selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Lo stato scelto abilita la sincronizzazione con Google Calendar e altre automazioni.</div>
                                </div>
                            </div>
                            <div class="text-end mt-4">
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-save me-2"></i>Salva stati</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-6" data-section="caf-patronato">
                <div class="card ag-card h-100" id="caf-patronato-types">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Tipologie pratiche CAF &amp; Patronato</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Configura le tipologie di pratica disponibili nel modulo. Ogni tipologia può avere un codice interno e un prefisso che verrà usato per generare i numeri pratica.</p>
                        <form method="post" data-ajax="settings" data-ajax-section="caf-patronato-types">
                            <input type="hidden" name="action" value="caf_patronato_types">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="table-responsive">
                                <table class="table table-dark table-sm align-middle mb-3">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;">#</th>
                                            <th style="width: 160px;">Codice interno</th>
                                            <th>Etichetta mostrata</th>
                                            <th style="width: 160px;">Prefisso pratica</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cafPatronatoTypesForm as $index => $type): ?>
                                            <tr>
                                                <td class="text-muted">#<?php echo (int) ($index + 1); ?></td>
                                                <td>
                                                    <input class="form-control form-control-sm text-uppercase" name="types[<?php echo (int) $index; ?>][key]" maxlength="25" value="<?php echo sanitize_output($type['key'] ?? ''); ?>" placeholder="CAF_ASS">
                                                </td>
                                                <td>
                                                    <input class="form-control form-control-sm" name="types[<?php echo (int) $index; ?>][label]" maxlength="60" value="<?php echo sanitize_output($type['label'] ?? ''); ?>" placeholder="Assistenza CAF">
                                                </td>
                                                <td>
                                                    <input class="form-control form-control-sm text-uppercase" name="types[<?php echo (int) $index; ?>][prefix]" maxlength="10" value="<?php echo sanitize_output($type['prefix'] ?? ''); ?>" placeholder="CAF">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                                <small class="text-muted">Le righe lasciate vuote vengono ignorate. L'ordine definisce il menu a tendina nel modulo.</small>
                                <button class="btn btn-warning btn-sm" type="submit"><i class="fa-solid fa-save me-2"></i>Salva tipologie</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-6" data-section="caf-patronato">
                <div class="card ag-card h-100" id="caf-patronato-services">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Servizi richiesti</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Definisci l'elenco dei servizi richiesti più comuni da associare alle pratiche. L'elenco alimenta il campo suggerito nel modulo di creazione.</p>
                        <form method="post" data-ajax="settings" data-ajax-section="caf-patronato-services">
                            <input type="hidden" name="action" value="caf_patronato_services">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="d-flex flex-column gap-4">
                                <?php foreach ($cafPatronatoTypes as $typeIndex => $type): ?>
                                    <?php
                                        $typeKey = strtoupper((string) ($type['key'] ?? ''));
                                        if ($typeKey === '') {
                                            continue;
                                        }
                                        $typeLabel = (string) ($type['label'] ?? $typeKey);
                                        $servicesRows = $cafPatronatoServicesForm[$typeKey] ?? [['name' => '']];
                                        $suggestions = $cafPatronatoServiceSuggestions[$typeKey] ?? [];
                                    ?>
                                    <div class="border border-dark-subtle rounded-3 p-3">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                            <div>
                                                <span class="fw-semibold text-uppercase small"><?php echo sanitize_output($typeLabel); ?></span>
                                                <span class="badge bg-secondary ms-2"><?php echo sanitize_output($typeKey); ?></span>
                                            </div>
                                            <small class="text-muted">Le righe vuote vengono ignorate.</small>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-dark table-sm align-middle mb-2">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 60px;">#</th>
                                                        <th>Servizio</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($servicesRows as $rowIndex => $serviceEntry): ?>
                                                        <?php
                                                            $serviceValue = '';
                                                            if (is_array($serviceEntry)) {
                                                                $serviceValue = (string) ($serviceEntry['name'] ?? '');
                                                            } elseif (is_string($serviceEntry)) {
                                                                $serviceValue = (string) $serviceEntry;
                                                            }
                                                        ?>
                                                        <tr>
                                                            <td class="text-muted">#<?php echo (int) ($rowIndex + 1); ?></td>
                                                            <td>
                                                                <input class="form-control form-control-sm" name="services[<?php echo sanitize_output($typeKey); ?>][<?php echo (int) $rowIndex; ?>][name]" maxlength="120" value="<?php echo sanitize_output($serviceValue); ?>" placeholder="Es. ISEE, NASpI, 730">
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php if ($suggestions): ?>
                                            <div class="small text-muted">
                                                <strong>Suggeriti dalle pratiche:</strong>
                                                <?php foreach ($suggestions as $suggestion): ?>
                                                    <span class="badge bg-secondary me-1 mb-1"><?php echo sanitize_output($suggestion); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-muted">Nessun nuovo servizio suggerito per questa tipologia.</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mt-3">
                                <small class="text-muted">L'elenco alimenta il menu suggerito nella creazione pratica, ma puoi sempre inserire valori personalizzati.</small>
                                <button class="btn btn-warning btn-sm" type="submit"><i class="fa-solid fa-save me-2"></i>Salva servizi</button>
                            </div>
                        </form>
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mt-4">
                            <div class="small text-muted w-100">
                                <?php if ($cafPatronatoServiceSuggestions): ?>
                                    <strong>Suggerimenti disponibili:</strong> seleziona una tipologia per vedere i servizi proposti.
                                <?php else: ?>
                                    Tutti i servizi presenti nelle pratiche risultano già configurati.
                                <?php endif; ?>
                            </div>
                            <form method="post" data-ajax="settings" data-ajax-section="caf-patronato-services" class="ms-auto">
                                <input type="hidden" name="action" value="caf_patronato_services_import">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <button class="btn btn-outline-warning btn-sm" type="submit">
                                    <i class="fa-solid fa-database me-2"></i>Importa da pratiche
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-6" data-section="caf-patronato">
                <div class="card ag-card h-100" id="caf-patronato-statuses">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Stati pratiche CAF &amp; Patronato</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Imposta gli stati disponibili e indica quelli che corrispondono alle fasi principali del flusso. Queste scelte controllano badge, filtri e report.</p>
                        <form method="post" data-ajax="settings" data-ajax-section="caf-patronato-statuses" data-caf-categories='<?php echo sanitize_output(json_encode(SettingsService::CAF_PATRONATO_STATUS_CATEGORIES, JSON_UNESCAPED_UNICODE)); ?>'>
                            <input type="hidden" name="action" value="caf_patronato_statuses">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="table-responsive">
                                <table class="table table-dark table-sm align-middle mb-3">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;">#</th>
                                            <th>Stato interno</th>
                                            <th>Etichetta mostrata</th>
                                            <th style="width: 160px;">Categoria</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cafPatronatoStatusesForm as $index => $status): ?>
                                            <tr>
                                                <td class="text-muted">#<?php echo (int) ($index + 1); ?></td>
                                                <td>
                                                    <input class="form-control form-control-sm" name="statuses[<?php echo (int) $index; ?>][value]" maxlength="60" value="<?php echo sanitize_output($status['value'] ?? ''); ?>" placeholder="In lavorazione">
                                                </td>
                                                <td>
                                                    <input class="form-control form-control-sm" name="statuses[<?php echo (int) $index; ?>][label]" maxlength="60" value="<?php echo sanitize_output($status['label'] ?? ''); ?>" placeholder="Etichetta visibile">
                                                </td>
                                                <td>
                                                    <select class="form-select form-select-sm" name="statuses[<?php echo (int) $index; ?>][category]">
                                                        <?php foreach (SettingsService::CAF_PATRONATO_STATUS_CATEGORIES as $categoryKey => $categoryLabel): ?>
                                                            <option value="<?php echo sanitize_output($categoryKey); ?>" <?php echo (($status['category'] ?? 'pending') === $categoryKey) ? 'selected' : ''; ?>><?php echo sanitize_output($categoryLabel); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                                <small class="text-muted">Il valore è usato per filtri e automazioni, l'etichetta è visibile agli operatori. La categoria guida badge e notifiche.</small>
                                <button class="btn btn-warning btn-sm" type="submit"><i class="fa-solid fa-save me-2"></i>Salva stati</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-7" data-section="portal-brt-pricing">
                <div class="card ag-card h-100" id="portal-brt-pricing">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Tariffe spedizioni BRT (portale clienti)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Configura gli scaglioni progressivi per peso e volume visualizzati nel portale clienti. I limiti lasciati vuoti indicano "senza limite" per quel parametro.</p>
                        <form method="post" class="portal-brt-pricing-form">
                            <input type="hidden" name="action" value="portal_brt_pricing">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="row g-3 align-items-end">
                                <div class="col-auto">
                                    <label class="form-label" for="portal_brt_currency">Valuta *</label>
                                    <input class="form-control text-uppercase" id="portal_brt_currency" name="currency" value="<?php echo sanitize_output($portalBrtPricingForm['currency'] ?? 'EUR'); ?>" maxlength="3" pattern="[A-Za-z]{3}" title="Inserisci il codice valuta ISO a tre lettere (es. EUR)">
                                    <div class="form-text">Codice ISO 4217 (es. EUR, USD).</div>
                                </div>
                            </div>
                            <div class="table-responsive mt-3">
                                <table class="table table-dark table-sm align-middle mb-0" id="portalBrtPricingTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;">#</th>
                                            <th>Etichetta</th>
                                            <th style="width: 160px;">Peso max (kg)</th>
                                            <th style="width: 170px;">Volume max (m³)</th>
                                            <th style="width: 150px;">Prezzo</th>
                                            <th style="width: 70px;" class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody id="portalBrtPricingRows">
                                        <?php foreach ($portalBrtPricingForm['tiers'] as $index => $tier): ?>
                                            <tr data-index="<?php echo (int) $index; ?>">
                                                <td class="text-muted small portal-brt-tier-index">#<?php echo (int) ($index + 1); ?></td>
                                                <td>
                                                    <input class="form-control form-control-sm" name="tiers[<?php echo (int) $index; ?>][label]" data-field="label" maxlength="60" value="<?php echo sanitize_output($tier['label'] ?? ''); ?>" placeholder="Es. Fino a 10 kg">
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <input class="form-control" name="tiers[<?php echo (int) $index; ?>][max_weight]" data-field="max_weight" value="<?php echo sanitize_output($tier['max_weight'] ?? ''); ?>" inputmode="decimal" placeholder="es. 10.5">
                                                        <span class="input-group-text">kg</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <input class="form-control" name="tiers[<?php echo (int) $index; ?>][max_volume]" data-field="max_volume" value="<?php echo sanitize_output($tier['max_volume'] ?? ''); ?>" inputmode="decimal" placeholder="es. 0.08">
                                                        <span class="input-group-text">m³</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text portal-brt-currency-label"><?php echo sanitize_output($portalBrtPricingForm['currency'] ?? 'EUR'); ?></span>
                                                        <input class="form-control" name="tiers[<?php echo (int) $index; ?>][price]" data-field="price" value="<?php echo sanitize_output($tier['price'] ?? ''); ?>" inputmode="decimal" placeholder="es. 4.99">
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <button class="btn btn-icon btn-soft-danger btn-sm portal-brt-remove-tier" type="button" title="Rimuovi scaglione">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                                <button class="btn btn-soft-accent btn-sm" type="button" id="addPortalBrtTier">
                                    <i class="fa-solid fa-plus me-2"></i>Aggiungi scaglione
                                </button>
                                <button class="btn btn-warning" type="submit">
                                    <i class="fa-solid fa-save me-2"></i>Salva tariffe
                                </button>
                            </div>
                            <p class="text-muted small mt-3 mb-0">Gli scaglioni devono essere ordinati in modo crescente. Lasciare vuoti peso e/o volume rende lo scaglione senza limite per quel valore.</p>
                            <template id="portalBrtTierTemplate">
                                <tr data-index="__INDEX__">
                                    <td class="text-muted small portal-brt-tier-index">#__NUM__</td>
                                    <td>
                                        <input class="form-control form-control-sm" data-field="label" maxlength="60" placeholder="Es. Fino a 10 kg">
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input class="form-control" data-field="max_weight" inputmode="decimal" placeholder="es. 10.5">
                                            <span class="input-group-text">kg</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input class="form-control" data-field="max_volume" inputmode="decimal" placeholder="es. 0.08">
                                            <span class="input-group-text">m³</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text portal-brt-currency-label"><?php echo sanitize_output($portalBrtPricingForm['currency'] ?? 'EUR'); ?></span>
                                            <input class="form-control" data-field="price" inputmode="decimal" placeholder="es. 4.99">
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-icon btn-soft-danger btn-sm portal-brt-remove-tier" type="button" title="Rimuovi scaglione">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-7" data-section="email-marketing">
                <div class="card ag-card h-100" id="email-marketing-settings">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Impostazioni email marketing</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Definisci mittente, chiave Resend e URL di disiscrizione utilizzati per campagne e automazioni.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="email_marketing">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="row g-3">
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="sender_name">Mittente (nome) *</label>
                                    <input class="form-control" id="sender_name" name="sender_name" required value="<?php echo sanitize_output($emailMarketingConfig['sender_name'] ?? ''); ?>">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="sender_email">Mittente (email) *</label>
                                    <input class="form-control" id="sender_email" name="sender_email" type="email" required value="<?php echo sanitize_output($emailMarketingConfig['sender_email'] ?? ''); ?>" autocomplete="email" spellcheck="false">
                                    <div class="form-text">Comparirà come mittente predefinito nelle campagne.</div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="reply_to_email">Rispondi a (opzionale)</label>
                                    <input class="form-control" id="reply_to_email" name="reply_to_email" type="email" value="<?php echo sanitize_output($emailMarketingConfig['reply_to_email'] ?? ''); ?>" placeholder="Lascia vuoto per usare il mittente" autocomplete="email" spellcheck="false">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="test_address">Email test predefinita</label>
                                    <input class="form-control" id="test_address" name="test_address" type="email" value="<?php echo sanitize_output($emailMarketingConfig['test_address'] ?? ''); ?>" autocomplete="email" spellcheck="false">
                                    <div class="form-text">Usata come valore suggerito per gli invii di prova.</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="unsubscribe_base_url">URL base disiscrizione *</label>
                                    <input class="form-control" id="unsubscribe_base_url" name="unsubscribe_base_url" type="url" required value="<?php echo sanitize_output($emailMarketingConfig['unsubscribe_base_url'] ?? $emailMarketingUnsubscribeBase); ?>" placeholder="https://example.com" inputmode="url" spellcheck="false">
                                    <div class="form-text">Esempio: <code><?php echo sanitize_output($emailMarketingUnsubscribeExample); ?></code></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="resend_api_key">Resend API key</label>
                                    <input class="form-control" id="resend_api_key" name="resend_api_key" type="password" value="<?php echo sanitize_output($emailMarketingConfig['resend_api_key'] ?? ''); ?>" autocomplete="off" spellcheck="false" placeholder="<?php echo !empty($emailMarketingConfig['has_resend_api_key']) ? '****************' : 're_...' ; ?>">
                                    <?php if (!empty($emailMarketingConfig['has_resend_api_key'])): ?>
                                        <div class="form-text">Chiave salvata: <code><?php echo sanitize_output($emailMarketingConfig['resend_api_key_hint'] ?? ''); ?></code>. Lascia vuoto per mantenerla.</div>
                                    <?php else: ?>
                                        <div class="form-text">Incolla la chiave Resend (prefisso <code>re_</code>). Verrà usata per l'invio delle campagne.</div>
                                    <?php endif; ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="remove_resend_api_key" name="remove_resend_api_key" value="1" <?php echo $emailMarketingRemoveKeySelected ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="remove_resend_api_key">Rimuovi la chiave configurata</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="webhook_secret">Segreto webhook</label>
                                    <div class="input-group">
                                        <input class="form-control" id="webhook_secret" name="webhook_secret" value="<?php echo sanitize_output($emailMarketingConfig['webhook_secret'] ?? ''); ?>" autocomplete="off" spellcheck="false">
                                        <button class="btn btn-soft-accent" type="button" id="generateWebhookSecret"><i class="fa-solid fa-wand-magic-sparkles me-2"></i>Genera</button>
                                    </div>
                                    <div class="form-text">Utilizzato per verificare le richieste in arrivo da Resend o altri webhook.</div>
                                </div>
                            </div>
                            <div class="text-end mt-4">
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-save me-2"></i>Salva impostazioni</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-5" data-section="email-marketing">
                <div class="card ag-card h-100" id="email-marketing-tools">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Strumenti email marketing</h5>
                    </div>
                    <div class="card-body">
                        <h6 class="fw-semibold">Invio di prova</h6>
                        <p class="text-muted">Verifica rapidamente la configurazione inviando una email di test con il template generico.</p>
                        <form method="post" class="mb-4">
                            <input type="hidden" name="action" value="email_marketing_test">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label" for="test_email">Email destinatario *</label>
                                    <input class="form-control" id="test_email" name="test_email" type="email" required value="<?php echo sanitize_output($emailMarketingConfig['test_address'] ?? ''); ?>" autocomplete="email" spellcheck="false">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="test_subject">Oggetto</label>
                                    <input class="form-control" id="test_subject" name="test_subject" value="<?php echo sanitize_output($emailMarketingTestSubject); ?>" autocomplete="off" spellcheck="false">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="test_message">Messaggio</label>
                                    <textarea class="form-control" id="test_message" name="test_message" rows="3" placeholder="Messaggio di prova" spellcheck="false"><?php echo sanitize_output($emailMarketingTestMessage); ?></textarea>
                                </div>
                            </div>
                            <div class="text-end mt-3">
                                <button class="btn btn-soft-accent" type="submit"><i class="fa-solid fa-paper-plane me-2"></i>Invia test</button>
                            </div>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-semibold">Disiscrizioni</h6>
                        <p class="text-muted">L'URL generato per le campagne punta a:</p>
                        <div class="border rounded-3 bg-body-secondary px-3 py-2 small">
                            <code><?php echo sanitize_output($emailMarketingUnsubscribeExample); ?></code>
                        </div>
                        <p class="text-muted small mt-2">Assicurati che il percorso <code>email-unsubscribe.php</code> sia raggiungibile dall'URL configurato.</p>
                        <h6 class="fw-semibold mt-4">Webhook Resend</h6>
                        <p class="text-muted">Configura Resend per inviare gli eventi (complessivamente consegna, bounce, disiscrizioni) verso:</p>
                        <div class="border rounded-3 bg-body-secondary px-3 py-2 small">
                            <code><?php echo sanitize_output($emailMarketingWebhookEndpoint); ?></code>
                        </div>
                        <p class="text-muted small mt-2 mb-0">Proteggi l'endpoint verificando il segreto configurato sopra.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="card ag-card mt-4" data-section="logs">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title mb-0">Log attività recenti</h5>
                <div class="d-flex align-items-center gap-2">
                    <?php
                        $logsExportQuery = $_GET;
                        unset($logsExportQuery['export']);
                        $logsExportQuery['export'] = 'logs';
                        $logsExportUrl = 'index.php?' . http_build_query($logsExportQuery);
                    ?>
                    <a class="btn btn-soft-accent btn-sm" href="<?php echo sanitize_output($logsExportUrl); ?>">
                        <i class="fa-solid fa-file-csv me-1"></i>Esporta CSV
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="log-table-container">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Utente</th>
                                <th>Azione</th>
                                <th>Modulo</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $logsTotal = 0;
                            $logs = [];
                            try {
                                $logsTotalStmt = $pdo->query('SELECT COUNT(*) FROM log_attivita');
                                if ($logsTotalStmt !== false) {
                                    $logsTotal = (int) $logsTotalStmt->fetchColumn();
                                }

                                $logStmt = $pdo->prepare('SELECT la.*, u.username FROM log_attivita la LEFT JOIN users u ON la.user_id = u.id ORDER BY la.created_at DESC LIMIT :limit OFFSET :offset');
                                $logStmt->bindValue(':limit', $logPerPage, PDO::PARAM_INT);
                                $logStmt->bindValue(':offset', max(0, ($logPage - 1) * $logPerPage), PDO::PARAM_INT);
                                $logStmt->execute();
                                $logs = $logStmt->fetchAll();
                            } catch (Throwable $exception) {
                                error_log('Impostazioni: lettura log fallita - ' . $exception->getMessage());
                                $logs = [];
                            }
                            $logPages = $logsTotal > 0 ? (int) ceil($logsTotal / $logPerPage) : 1;

                            foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo sanitize_output($log['username'] ?? 'Sistema'); ?></td>
                                    <td><?php echo sanitize_output($log['azione']); ?></td>
                                    <td><?php echo sanitize_output($log['modulo']); ?></td>
                                    <td><?php echo sanitize_output(date('d/m/Y H:i', strtotime($log['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Nessuna attività registrata.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($logPages > 1): ?>
                    <div class="log-pagination border-top px-3 py-2">
                        <nav aria-label="Paginazione log" class="d-flex justify-content-center">
                            <ul class="pagination pagination-sm mb-0">
                            <?php
                                $logsQuery = $_GET;
                                unset($logsQuery['export']);
                                $logsQuery['page_log'] = max(1, $logPage - 1);
                                $prevLogsUrl = 'index.php?' . http_build_query($logsQuery);
                                $windowSize = 5;
                                $halfWindow = (int) floor($windowSize / 2);
                                $startPage = max(1, $logPage - $halfWindow);
                                $endPage = min($logPages, $startPage + $windowSize - 1);
                                if (($endPage - $startPage + 1) < $windowSize) {
                                    $startPage = max(1, $endPage - $windowSize + 1);
                                }
                            ?>
                            <li class="page-item <?php echo $logPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo sanitize_output($prevLogsUrl); ?>" aria-label="Precedente">&laquo;</a>
                            </li>
                            <?php if ($startPage > 1):
                                $logsQuery['page_log'] = 1;
                                $firstPageUrl = 'index.php?' . http_build_query($logsQuery);
                            ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo sanitize_output($firstPageUrl); ?>">1</a>
                                </li>
                                <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php for ($page = $startPage; $page <= $endPage; $page++):
                                $logsQuery['page_log'] = $page;
                                $logsPageUrl = 'index.php?' . http_build_query($logsQuery);
                            ?>
                                <li class="page-item <?php echo $page === $logPage ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo sanitize_output($logsPageUrl); ?>"><?php echo $page; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($endPage < $logPages):
                                if ($endPage < $logPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif;
                                $logsQuery['page_log'] = $logPages;
                                $lastPageUrl = 'index.php?' . http_build_query($logsQuery);
                            ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo sanitize_output($lastPageUrl); ?>"><?php echo $logPages; ?></a>
                                </li>
                            <?php endif; ?>
                            <?php
                                $logsQuery['page_log'] = min($logPages, $logPage + 1);
                                $nextLogsUrl = 'index.php?' . http_build_query($logsQuery);
                            ?>
                            <li class="page-item <?php echo $logPage >= $logPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo sanitize_output($nextLogsUrl); ?>" aria-label="Successivo">&raquo;</a>
                            </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableBody = document.getElementById('portalBrtPricingRows');
    const addButton = document.getElementById('addPortalBrtTier');
    const template = document.getElementById('portalBrtTierTemplate');
    const form = document.querySelector('.portal-brt-pricing-form');
    const currencyField = document.getElementById('portal_brt_currency');

    if (!tableBody || !addButton || !template || !form) {
        return;
    }

    const normalizeCurrencyValue = () => {
        if (!currencyField) {
            return 'EUR';
        }
        const value = currencyField.value ? currencyField.value.toUpperCase() : '';
        currencyField.value = value;
        return value || 'EUR';
    };

    const updateCurrencyLabels = () => {
        const currency = normalizeCurrencyValue();
        const labels = form.querySelectorAll('.portal-brt-currency-label');
        labels.forEach((label) => {
            label.textContent = currency;
        });
    };

    const reindexRows = () => {
        const rows = tableBody.querySelectorAll('tr');
        rows.forEach((row, index) => {
            row.setAttribute('data-index', String(index));
            const indexCell = row.querySelector('.portal-brt-tier-index');
            if (indexCell) {
                indexCell.textContent = '#' + (index + 1);
            }
            row.querySelectorAll('[data-field]').forEach((fieldEl) => {
                const field = fieldEl.getAttribute('data-field');
                if (!field) {
                    return;
                }
                fieldEl.setAttribute('name', 'tiers[' + index + '][' + field + ']');
            });
        });

        const removeButtons = tableBody.querySelectorAll('.portal-brt-remove-tier');
        const disableRemoval = rows.length <= 1;
        removeButtons.forEach((button) => {
            button.disabled = disableRemoval;
        });
    };

    const cloneTemplateRow = () => {
        let clone = null;
        if ('content' in template && template.content) {
            const firstChild = template.content.firstElementChild;
            clone = firstChild ? firstChild.cloneNode(true) : null;
        } else {
            const container = document.createElement('tbody');
            container.innerHTML = template.innerHTML.trim();
            clone = container.firstElementChild;
        }

        if (!clone) {
            return null;
        }

        clone.querySelectorAll('input').forEach((input) => {
            input.value = '';
        });

        return clone;
    };

    addButton.addEventListener('click', () => {
        const newRow = cloneTemplateRow();
        if (!newRow) {
            return;
        }
        tableBody.appendChild(newRow);
        reindexRows();
        updateCurrencyLabels();
    });

    tableBody.addEventListener('click', (event) => {
        const target = event.target instanceof HTMLElement ? event.target : null;
        const button = target ? target.closest('.portal-brt-remove-tier') : null;
        if (!button) {
            return;
        }
        const row = button.closest('tr');
        if (!row) {
            return;
        }
        if (tableBody.querySelectorAll('tr').length <= 1) {
            return;
        }
        row.remove();
        reindexRows();
        updateCurrencyLabels();
    });

    if (currencyField) {
        currencyField.addEventListener('input', updateCurrencyLabels);
        currencyField.addEventListener('blur', updateCurrencyLabels);
    }

    reindexRows();
    updateCurrencyLabels();
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const fetchButton = document.getElementById('viesFetch');
    if (!fetchButton) {
        return;
    }
    const form = fetchButton.closest('form');
    const feedbackContainer = document.getElementById('viesFeedback');
    const originalButtonHtml = fetchButton.innerHTML;

    const setFeedback = (html) => {
        if (feedbackContainer) {
            feedbackContainer.innerHTML = html ? '<div class="mt-3">' + html + '</div>' : '';
        }
    };

    fetchButton.addEventListener('click', async () => {
        if (!form) {
            return;
        }

        const tokenField = form.querySelector('input[name="_token"]');
        const countryField = document.getElementById('vat_country');
        const vatField = document.getElementById('piva');
        if (!tokenField || !countryField || !vatField) {
            setFeedback('<div class="alert alert-danger mb-0">Impossibile completare la richiesta: token mancante.</div>');
            return;
        }

        const country = countryField.value.trim().toUpperCase();
        const vat = vatField.value.trim().replace(/\s+/g, '').toUpperCase();

        if (!country || !vat) {
            setFeedback('<div class="alert alert-warning mb-0">Inserisci il paese e la partita IVA prima di interrogare VIES.</div>');
            vatField.focus();
            return;
        }

        setFeedback('<div class="alert alert-info mb-0">Verifica presso VIES in corso…</div>');
        fetchButton.disabled = true;
        fetchButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Verifico…';

        try {
            const formData = new FormData();
            formData.append('_token', tokenField.value);
            formData.append('country', country);
            formData.append('vat', vat);

            const response = await fetch('vies_lookup.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const payload = await response.json();
            if (!response.ok || !payload || !payload.success) {
                const message = payload && payload.message ? payload.message : 'Impossibile completare la verifica VIES.';
                setFeedback('<div class="alert alert-danger mb-0">' + message + '</div>');
                return;
            }

            const data = payload.data || {};
            if (data.name) {
                form.ragione_sociale.value = data.name;
            }
            if (data.address) {
                form.indirizzo.value = data.address;
            }
            if (data.cap) {
                form.cap.value = data.cap;
            }
            if (data.city) {
                form.citta.value = data.city;
            }
            if (data.provincia) {
                form.provincia.value = data.provincia;
            }

            let detailMessage = 'Dati recuperati da VIES. Verifica le informazioni prima di salvare.';
            if (data.rawAddress) {
                detailMessage += '<br><small class="text-muted">Indirizzo completo: ' + data.rawAddress + '</small>';
            }
            setFeedback('<div class="alert alert-success mb-0">' + detailMessage + '</div>');
        } catch (error) {
            console.error('Errore VIES:', error);
            setFeedback('<div class="alert alert-danger mb-0">Errore durante la comunicazione con VIES. Riprova più tardi.</div>');
        } finally {
            fetchButton.disabled = false;
            fetchButton.innerHTML = originalButtonHtml;
        }
    });
});
</script>
<script>
const themeCatalog = <?php echo json_encode($themeOptions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?>;

// Tabs JS: mostra/nasconde sezioni basate su data-section (non distruttivo)
document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('#settingsTabs [data-section-target]');
    const sections = document.querySelectorAll('[data-section]');

    function showSection(name) {
        sections.forEach(el => {
            if (name === 'all') {
                el.classList.remove('d-none');
            } else {
                if (el.getAttribute('data-section') === name) {
                    el.classList.remove('d-none');
                } else {
                    el.classList.add('d-none');
                }
            }
        });
        // update active tab state
        tabs.forEach(t => t.classList.toggle('active', t.getAttribute('data-section-target') === name));
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const name = this.getAttribute('data-section-target');
            showSection(name);
        });
    });

    // default: show all sections in a single view
    showSection('all');
});

document.addEventListener('DOMContentLoaded', function () {
    const slider = document.querySelector('[data-theme-slider]');
    if (!slider) {
        return;
    }

    const track = slider.querySelector('[data-theme-slider-track]');
    const slides = slider.querySelectorAll('[data-theme-slide-index]');
    const prevButton = slider.querySelector('[data-theme-slider-prev]');
    const nextButton = slider.querySelector('[data-theme-slider-next]');
    const dotButtons = slider.querySelectorAll('[data-theme-slider-dot]');
    const totalSlides = slides.length;
    let activeIndex = 0;

    const clamp = (value) => Math.max(0, Math.min(totalSlides - 1, value));

    const updateSlider = (nextIndex) => {
        activeIndex = clamp(nextIndex);
        track.style.setProperty('--active-index', String(activeIndex));

        slides.forEach((slide) => {
            const index = Number(slide.getAttribute('data-theme-slide-index'));
            slide.classList.toggle('is-active', index === activeIndex);
        });

        dotButtons.forEach((dot) => {
            const index = Number(dot.getAttribute('data-theme-slider-dot'));
            const isActive = index === activeIndex;
            dot.classList.toggle('is-active', isActive);
            dot.setAttribute('aria-current', isActive ? 'true' : 'false');
        });

        if (prevButton) {
            prevButton.disabled = activeIndex === 0;
        }
        if (nextButton) {
            nextButton.disabled = activeIndex === totalSlides - 1;
        }
    };

    if (prevButton) {
        prevButton.addEventListener('click', () => updateSlider(activeIndex - 1));
    }

    if (nextButton) {
        nextButton.addEventListener('click', () => updateSlider(activeIndex + 1));
    }

    dotButtons.forEach((dot) => {
        dot.addEventListener('click', () => {
            const index = Number(dot.getAttribute('data-theme-slider-dot'));
            updateSlider(index);
        });
    });

    const radioOptions = slider.querySelectorAll('input[type="radio"][name="ui_theme"]');
    radioOptions.forEach((radio) => {
        radio.addEventListener('change', () => {
            const themeKey = radio.value;
            const targetSlide = Array.from(slides).find((slide) => {
                return Array.from(slide.querySelectorAll('[data-theme-key]')).some(
                    (option) => option.getAttribute('data-theme-key') === themeKey
                );
            });
            if (targetSlide) {
                const targetIndex = Number(targetSlide.getAttribute('data-theme-slide-index'));
                if (!Number.isNaN(targetIndex)) {
                    updateSlider(targetIndex);
                }
            }
        });
    });

    updateSlider(activeIndex);
});

document.addEventListener('DOMContentLoaded', function () {
    const ajaxForms = document.querySelectorAll('form[data-ajax="settings"]');
    if (!ajaxForms.length) {
        return;
    }

    let toastContainer = document.getElementById('settingsToastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'settingsToastContainer';
        toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
        toastContainer.setAttribute('aria-live', 'polite');
        toastContainer.setAttribute('aria-atomic', 'true');
        document.body.appendChild(toastContainer);
    }

    const bootstrapInstance = window.bootstrap || null;

    const showToast = (message, type = 'success') => {
        if (!toastContainer) {
            alert(message);
            return;
        }

        const wrapper = document.createElement('div');
        const levelClass = type === 'success' ? 'text-bg-success' : 'text-bg-danger';
        wrapper.className = `toast align-items-center ${levelClass} border-0 mb-2`;
        wrapper.setAttribute('role', 'status');
        wrapper.setAttribute('aria-live', 'polite');
        wrapper.setAttribute('aria-atomic', 'true');
        const flexWrapper = document.createElement('div');
        flexWrapper.className = 'd-flex';
        const toastBody = document.createElement('div');
        toastBody.className = 'toast-body';
        toastBody.textContent = message;
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
        closeBtn.setAttribute('data-bs-dismiss', 'toast');
        closeBtn.setAttribute('aria-label', 'Chiudi');
        flexWrapper.appendChild(toastBody);
        flexWrapper.appendChild(closeBtn);
        wrapper.appendChild(flexWrapper);
        toastContainer.appendChild(wrapper);

        if (bootstrapInstance && bootstrapInstance.Toast) {
            const toast = new bootstrapInstance.Toast(wrapper, { delay: 3500 });
            toast.show();
            wrapper.addEventListener('hidden.bs.toast', () => wrapper.remove());
        } else {
            wrapper.classList.add('show');
            setTimeout(() => wrapper.remove(), 3500);
        }
    };

    const renderCafPatronatoTypes = (formElement, entries) => {
        const tbody = formElement.querySelector('tbody');
        if (!tbody) {
            return;
        }

        const rows = Array.isArray(entries) ? entries.slice() : [];
        rows.push({ key: '', label: '', prefix: '' });
        tbody.innerHTML = '';

        rows.forEach((entry, index) => {
            const tr = document.createElement('tr');

            const indexCell = document.createElement('td');
            indexCell.className = 'text-muted';
            indexCell.textContent = '#' + (index + 1);
            tr.appendChild(indexCell);

            const keyCell = document.createElement('td');
            const keyInput = document.createElement('input');
            keyInput.type = 'text';
            keyInput.className = 'form-control form-control-sm text-uppercase';
            keyInput.name = `types[${index}][key]`;
            keyInput.maxLength = 25;
            keyInput.placeholder = 'CAF_ASS';
            keyInput.value = typeof entry.key === 'string' ? entry.key : '';
            keyCell.appendChild(keyInput);
            tr.appendChild(keyCell);

            const labelCell = document.createElement('td');
            const labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.className = 'form-control form-control-sm';
            labelInput.name = `types[${index}][label]`;
            labelInput.maxLength = 60;
            labelInput.placeholder = 'Assistenza CAF';
            labelInput.value = typeof entry.label === 'string' ? entry.label : '';
            labelCell.appendChild(labelInput);
            tr.appendChild(labelCell);

            const prefixCell = document.createElement('td');
            const prefixInput = document.createElement('input');
            prefixInput.type = 'text';
            prefixInput.className = 'form-control form-control-sm text-uppercase';
            prefixInput.name = `types[${index}][prefix]`;
            prefixInput.maxLength = 10;
            prefixInput.placeholder = 'CAF';
            prefixInput.value = typeof entry.prefix === 'string' ? entry.prefix : '';
            prefixCell.appendChild(prefixInput);
            tr.appendChild(prefixCell);

            tbody.appendChild(tr);
        });
    };

    const renderCafPatronatoStatuses = (formElement, entries) => {
        const tbody = formElement.querySelector('tbody');
        if (!tbody) {
            return;
        }

        const categoriesRaw = formElement.getAttribute('data-caf-categories') || '{}';
        let categories = {};
        try {
            categories = JSON.parse(categoriesRaw);
        } catch (parseError) {
            console.warn('Impossibile analizzare le categorie CAF/Patronato:', parseError);
            categories = {};
        }

        const rows = Array.isArray(entries) ? entries.slice() : [];
        rows.push({ value: '', label: '', category: 'pending' });
        tbody.innerHTML = '';

        rows.forEach((entry, index) => {
            const tr = document.createElement('tr');

            const indexCell = document.createElement('td');
            indexCell.className = 'text-muted';
            indexCell.textContent = '#' + (index + 1);
            tr.appendChild(indexCell);

            const valueCell = document.createElement('td');
            const valueInput = document.createElement('input');
            valueInput.type = 'text';
            valueInput.className = 'form-control form-control-sm';
            valueInput.name = `statuses[${index}][value]`;
            valueInput.maxLength = 60;
            valueInput.placeholder = 'In lavorazione';
            valueInput.value = typeof entry.value === 'string' ? entry.value : '';
            valueCell.appendChild(valueInput);
            tr.appendChild(valueCell);

            const labelCell = document.createElement('td');
            const labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.className = 'form-control form-control-sm';
            labelInput.name = `statuses[${index}][label]`;
            labelInput.maxLength = 60;
            labelInput.placeholder = 'Etichetta visibile';
            labelInput.value = typeof entry.label === 'string' ? entry.label : '';
            labelCell.appendChild(labelInput);
            tr.appendChild(labelCell);

            const categoryCell = document.createElement('td');
            const select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            select.name = `statuses[${index}][category]`;
            const selectedCategory = typeof entry.category === 'string' ? entry.category : 'pending';
            Object.keys(categories).forEach((categoryKey) => {
                const option = document.createElement('option');
                option.value = categoryKey;
                option.textContent = categories[categoryKey];
                if (categoryKey === selectedCategory) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
            categoryCell.appendChild(select);
            tr.appendChild(categoryCell);

            tbody.appendChild(tr);
        });
    };

    ajaxForms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                form.reportValidity();
                return;
            }

            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            let submitButtonState = null;
            if (submitButton) {
                submitButtonState = submitButton.tagName === 'BUTTON'
                    ? { type: 'button', content: submitButton.innerHTML }
                    : { type: 'input', content: submitButton.value };
                submitButton.disabled = true;
                if (submitButton.tagName === 'BUTTON') {
                    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Salvataggio…';
                } else {
                    submitButton.value = 'Salvataggio…';
                }
            }

            const formData = new FormData(form);

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
                .then(async (response) => {
                    let payload;
                    try {
                        payload = await response.json();
                    } catch (error) {
                        throw new Error('Risposta non valida dal server.');
                    }

                    if (!response.ok || !payload.success) {
                        const errorMessage = payload && Array.isArray(payload.errors) && payload.errors.length
                            ? payload.errors.join('\n')
                            : 'Impossibile completare il salvataggio.';
                        const err = new Error(errorMessage);
                        err.payload = payload;
                        throw err;
                    }

                    return payload;
                })
                .then((payload) => {
                    const section = form.getAttribute('data-ajax-section') || '';
                    const data = payload.data || {};

                    if (section === 'appearance' && data && typeof data.theme === 'string') {
                        const themeKey = data.theme;
                        const docEl = document.documentElement;
                        if (docEl) {
                            docEl.setAttribute('data-ui-theme', themeKey);
                            try {
                                docEl.style.setProperty('--ag-theme-key', `'${themeKey}'`);
                            } catch (styleError) {
                                console.warn('Impossibile aggiornare la variabile CSS del tema:', styleError);
                            }
                        }

                        const themeInfo = themeCatalog && Object.prototype.hasOwnProperty.call(themeCatalog, themeKey)
                            ? themeCatalog[themeKey]
                            : null;

                        const themeMeta = document.querySelector('meta[name="theme-color"]');
                        if (themeMeta && themeInfo && typeof themeInfo.accent === 'string') {
                            themeMeta.setAttribute('content', themeInfo.accent);
                        }

                        const badge = document.getElementById('activeThemeBadge');
                        if (badge) {
                            const label = themeInfo && typeof themeInfo.label === 'string' ? themeInfo.label : themeKey;
                            badge.textContent = 'Tema attivo: ' + label;
                        }

                        const optionLabels = form.querySelectorAll('.theme-option');
                        optionLabels.forEach((labelEl) => {
                            const key = labelEl.getAttribute('data-theme-key');
                            const isActive = key === themeKey;
                            labelEl.classList.toggle('active', isActive);
                            const radio = labelEl.querySelector('input[type="radio"]');
                            if (radio) {
                                radio.checked = isActive;
                            }
                        });
                    }

                    if (section === 'movements') {
                        if (Array.isArray(data.entrate)) {
                            const textarea = form.querySelector('#descrizioni_entrata');
                            if (textarea) {
                                textarea.value = data.entrate.join('\n');
                            }
                        }
                        if (Array.isArray(data.uscite)) {
                            const textarea = form.querySelector('#descrizioni_uscita');
                            if (textarea) {
                                textarea.value = data.uscite.join('\n');
                            }
                        }
                    }

                    if (section === 'appointment-types' && Array.isArray(data.types)) {
                        const textarea = form.querySelector('#appointment_types_list');
                        if (textarea) {
                            textarea.value = data.types.join('\n');
                        }
                    }

                    if (section === 'appointment-statuses' && data) {
                        const mapFields = [
                            { id: '#appointments_available', key: 'available' },
                            { id: '#appointments_active', key: 'active' },
                            { id: '#appointments_completed', key: 'completed' },
                            { id: '#appointments_cancelled', key: 'cancelled' },
                        ];
                        mapFields.forEach(({ id, key }) => {
                            const field = form.querySelector(id);
                            if (field && Array.isArray(data[key])) {
                                field.value = data[key].join('\n');
                            }
                        });
                        const select = form.querySelector('#appointments_confirmation');
                        if (select && typeof data.confirmation === 'string') {
                            select.value = data.confirmation;
                        }
                    }

                    if (section === 'caf-patronato-types' && Array.isArray(data)) {
                        renderCafPatronatoTypes(form, data);
                    }

                    if (section === 'caf-patronato-statuses' && Array.isArray(data)) {
                        renderCafPatronatoStatuses(form, data);
                    }

                    form.classList.remove('was-validated');
                    showToast(payload.message || 'Impostazioni aggiornate con successo.');
                })
                .catch((error) => {
                    let message = error && typeof error.message === 'string' ? error.message : 'Errore imprevisto durante il salvataggio.';
                    if (error && error.payload && error.payload.data) {
                        const data = error.payload.data;
                        const section = form.getAttribute('data-ajax-section') || '';
                        if (section === 'movements') {
                            if (Array.isArray(data.entrate)) {
                                const textarea = form.querySelector('#descrizioni_entrata');
                                if (textarea) {
                                    textarea.value = data.entrate.join('\n');
                                }
                            }
                            if (Array.isArray(data.uscite)) {
                                const textarea = form.querySelector('#descrizioni_uscita');
                                if (textarea) {
                                    textarea.value = data.uscite.join('\n');
                                }
                            }
                        }
                        if (section === 'appointment-types' && Array.isArray(data.types)) {
                            const textarea = form.querySelector('#appointment_types_list');
                            if (textarea) {
                                textarea.value = data.types.join('\n');
                            }
                        }
                        if (section === 'appointment-statuses') {
                            const mapFields = [
                                { id: '#appointments_available', key: 'available' },
                                { id: '#appointments_active', key: 'active' },
                                { id: '#appointments_completed', key: 'completed' },
                                { id: '#appointments_cancelled', key: 'cancelled' },
                            ];
                            mapFields.forEach(({ id, key }) => {
                                const field = form.querySelector(id);
                                if (field && Array.isArray(data[key])) {
                                    field.value = data[key].join('\n');
                                }
                            });
                            const select = form.querySelector('#appointments_confirmation');
                            if (select && typeof data.confirmation === 'string') {
                                select.value = data.confirmation;
                            }
                        }
                        if (section === 'caf-patronato-types' && Array.isArray(data)) {
                            renderCafPatronatoTypes(form, data);
                        }
                        if (section === 'caf-patronato-statuses' && Array.isArray(data)) {
                            renderCafPatronatoStatuses(form, data);
                        }
                    }

                    showToast(message, 'error');
                })
                .finally(() => {
                    if (submitButton) {
                        submitButton.disabled = false;
                        if (submitButtonState) {
                            if (submitButtonState.type === 'button') {
                                submitButton.innerHTML = submitButtonState.content;
                            } else {
                                submitButton.value = submitButtonState.content;
                            }
                        }
                    }
                });
        });
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const apiKeyField = document.getElementById('resend_api_key');
    const removeKeyCheckbox = document.getElementById('remove_resend_api_key');
    const secretField = document.getElementById('webhook_secret');
    const generateSecretButton = document.getElementById('generateWebhookSecret');

    if (removeKeyCheckbox && apiKeyField) {
        const toggleApiKeyState = () => {
            const disable = removeKeyCheckbox.checked;
            apiKeyField.disabled = disable;
            if (disable) {
                apiKeyField.value = '';
            }
        };

        removeKeyCheckbox.addEventListener('change', toggleApiKeyState);
        toggleApiKeyState();
    }

    if (generateSecretButton && secretField) {
        generateSecretButton.addEventListener('click', () => {
            const length = 40;
            const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            const buffer = new Uint32Array(length);

            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(buffer);
            } else {
                for (let i = 0; i < length; i += 1) {
                    buffer[i] = Math.floor(Math.random() * alphabet.length);
                }
            }

            let secret = '';
            for (let i = 0; i < length; i += 1) {
                secret += alphabet.charAt(buffer[i] % alphabet.length);
            }

            secretField.value = secret;
            secretField.focus();
            secretField.select();
        });
    }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
