<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
if ($collaboratorId <= 0) {
    http_response_code(403);
    exit;
}

$statusOptions = $opportunityService->getStatusOptions();
$statusCodes = array_column($statusOptions, 'code');
$categoryOptions = ['telefonia', 'luce', 'gas'];

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
if ($statusFilter !== '' && !in_array($statusFilter, $statusCodes, true)) {
    $statusFilter = '';
}

$categoryFilter = isset($_GET['category']) ? strtolower(trim((string) $_GET['category'])) : '';
if ($categoryFilter !== '' && !in_array($categoryFilter, $categoryOptions, true)) {
    $categoryFilter = '';
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));

$listFilters = [];
if ($statusFilter !== '') {
    $listFilters['status'] = $statusFilter;
}
if ($categoryFilter !== '') {
    $listFilters['category'] = $categoryFilter;
}
if ($searchQuery !== '') {
    $listFilters['search'] = $searchQuery;
}

$records = $opportunityService->listCollaboratorOpportunities($collaboratorId, $listFilters);

$filename = 'opportunity-collaboratore-' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
if ($output === false) {
    exit;
}

fprintf($output, "\xEF\xBB\xBF");
fputcsv($output, ['Codice', 'Categoria', 'Gestore', 'Cliente', 'Stato', 'Creato il']);

foreach ($records as $item) {
    $customerName = trim(((string) ($item['customer_first_name'] ?? '')) . ' ' . ((string) ($item['customer_last_name'] ?? '')));
    $createdAt = format_datetime_locale($item['created_at'] ?? null) ?? '';
    fputcsv($output, [
        (string) ($item['code'] ?? ''),
        ucfirst((string) ($item['category'] ?? '')),
        (string) ($item['provider_label'] ?? ''),
        $customerName,
        (string) ($item['status_label'] ?? $item['status_code'] ?? ''),
        $createdAt,
    ]);
}

fclose($output);
exit;
