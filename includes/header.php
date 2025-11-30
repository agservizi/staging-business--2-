<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Coresuite Business';
}
$csrfToken = csrf_token();
$flashMessages = get_flashes();
$themePdo = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $themePdo = $pdo;
}
$appearanceConfig = get_ui_theme_config($themePdo);
$activeTheme = $appearanceConfig['theme'] ?? 'navy';
$themeCatalog = \App\Services\SettingsService::availableThemes();
$themeAccent = $themeCatalog[$activeTheme]['accent'] ?? '#0b2f6b';

$pickupFeedConfig = null;
$runtimeConfig = [
    'apiBaseUrl' => base_url('api/'),
    'assetsBaseUrl' => base_url('assets/'),
    'assets' => [
        'leafletMarker' => 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        'leafletMarkerRetina' => 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
    ],
];
if (isset($pdo) && $pdo instanceof PDO && current_user_can('Admin', 'Operatore', 'Manager')) {
    try {
        $latestId = (int) $pdo->query('SELECT MAX(id) FROM pickup_customer_reports')->fetchColumn();
        $intervalMs = (int) env('PICKUP_REPORT_FEED_INTERVAL_MS', 30000);
        if ($intervalMs < 5000) {
            $intervalMs = 5000;
        }
        $pickupFeedConfig = [
            'endpoint' => base_url('api/pickup-report-feed.php'),
            'pollInterval' => $intervalMs,
            'initialLastId' => $latestId,
        ];
    } catch (Throwable $exception) {
        error_log('Pickup report feed bootstrap failed: ' . $exception->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light" data-ui-theme="<?php echo sanitize_output($activeTheme); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo $csrfToken; ?>">
    <meta name="theme-color" content="<?php echo sanitize_output($themeAccent); ?>">
    <link rel="manifest" href="<?php echo base_url('manifest.webmanifest'); ?>">
    <title><?php echo sanitize_output($pageTitle); ?> | Coresuite Business</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='3' ry='3' fill='%230b2f6b'/%3E%3Ctext x='8' y='11' font-family='Arial' font-size='7' font-weight='bold' text-anchor='middle' fill='white'%3ECB%3C/text%3E%3C/svg%3E">
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer" />
    <link href="https://cdn.datatables.net/v/bs5/dt-1.13.8/datatables.min.css" rel="stylesheet">
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
    <?php if (!empty($extraStyles) && is_array($extraStyles)): ?>
        <?php foreach ($extraStyles as $styleAsset): ?>
            <link href="<?php echo sanitize_output($styleAsset); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="layout-wrapper">
<div id="app" class="d-flex">
<?php if ($flashMessages): ?>
    <script>
        window.CS_INITIAL_FLASHES = <?php echo json_encode($flashMessages, JSON_THROW_ON_ERROR); ?>;
    </script>
<?php endif; ?>
<script>
    window.CS = window.CS || {};
    window.CS.apiBaseUrl = <?php echo json_encode($runtimeConfig['apiBaseUrl'], JSON_THROW_ON_ERROR); ?>;
    window.CS.assetsBaseUrl = <?php echo json_encode($runtimeConfig['assetsBaseUrl'], JSON_THROW_ON_ERROR); ?>;
    window.CS.assets = <?php echo json_encode($runtimeConfig['assets'], JSON_THROW_ON_ERROR); ?>;
    <?php if ($pickupFeedConfig !== null): ?>
    window.CS.pickupReportFeed = <?php echo json_encode($pickupFeedConfig, JSON_THROW_ON_ERROR); ?>;
    <?php endif; ?>
</script>
