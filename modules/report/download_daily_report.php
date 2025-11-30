<?php
use App\Services\DailyFinancialReportService;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit('Parametro id non valido.');
}

$stmt = $pdo->prepare('SELECT report_date FROM daily_financial_reports WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$report = $stmt->fetch();

if (!$report) {
    http_response_code(404);
    exit('Report non trovato.');
}

try {
    $service = new DailyFinancialReportService($pdo, project_root_path());
    $pdfContent = $service->renderPdfContent(new DateTimeImmutable((string) $report['report_date']));
} catch (Throwable $exception) {
    error_log('Daily report download failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Impossibile generare il report richiesto.');
}

$mode = filter_input(INPUT_GET, 'mode', FILTER_UNSAFE_RAW) ?: '';
$inline = strtolower((string) $mode) === 'inline';
$downloadName = 'report_finanziario_' . ($report['report_date'] ?? date('Y_m_d')) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . strlen($pdfContent));

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

echo $pdfContent;

exit;
