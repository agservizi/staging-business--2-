<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Debug: invia un messaggio di testo prima di tutto
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo "Debug: Script iniziato\n";
    echo "ID: " . ($_GET['id'] ?? 'null') . "\n";
    exit;
}

// Avvia output buffering per evitare output indesiderato
ob_start();

require_once __DIR__ . '/bootstrap.php';

// Debug dopo bootstrap
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo "Debug: Bootstrap completato\n";
    echo "Session user_id: " . ($_SESSION['user_id'] ?? 'null') . "\n";
    echo "Session role: " . ($_SESSION['role'] ?? 'null') . "\n";
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$credential = $iliadService->getCredential($id);

if (!$credential) {
    ob_end_clean();
    http_response_code(404);
    echo 'Credenziale non trovata.';
    exit;
}

// Verifica che mPDF sia disponibile
if (!class_exists('Mpdf\Mpdf')) {
    ob_end_clean();
    http_response_code(500);
    echo 'Errore di configurazione: Libreria mPDF mancante. Assicurati che sia installata tramite Composer.';
    exit;
}

try {
    $mpdf = new \Mpdf\Mpdf();
    $mpdf->SetTitle('Credenziali Iliad');

    $includeFibra = !empty($credential['include_fibra']);
    $includeMobile = !empty($credential['include_mobile']);
    $fibraId = $includeFibra ? (string) ($credential['fibra_id'] ?? '') : '';
    $mobileId = $includeMobile ? (string) ($credential['mobile_id'] ?? '') : '';
    $fibraPassword = $includeFibra ? (string) ($credential['fibra_password'] ?? '') : '';
    $mobilePassword = $includeMobile ? (string) ($credential['mobile_password'] ?? '') : '';

    $html = '
        <style>
            body { font-family: sans-serif; font-size: 12px; color: #111827; }
            h1 { font-size: 18px; margin: 0 0 8px; }
            .meta { font-size: 11px; color: #6b7280; margin-bottom: 16px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; }
            th { background: #f9fafb; }
            .muted { color: #6b7280; }
        </style>
        <h1>Credenziali Iliad</h1>
        <div class="meta">ID credenziale: ' . (int) $credential['id'] . ' · Creata il: ' . htmlspecialchars((string) ($credential['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>
        <table>
            <thead>
                <tr>
                    <th>Servizio</th>
                    <th>ID</th>
                    <th>Password</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Fibra</td>
                    <td>' . ($includeFibra ? htmlspecialchars($fibraId, ENT_QUOTES, 'UTF-8') : '<span class="muted">Non incluso</span>') . '</td>
                    <td>' . ($includeFibra ? htmlspecialchars($fibraPassword, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</td>
                </tr>
                <tr>
                    <td>Mobile</td>
                    <td>' . ($includeMobile ? htmlspecialchars($mobileId, ENT_QUOTES, 'UTF-8') : '<span class="muted">Non incluso</span>') . '</td>
                    <td>' . ($includeMobile ? htmlspecialchars($mobilePassword, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</td>
                </tr>
            </tbody>
        </table>
    ';

    $mpdf->WriteHTML($html);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo 'Errore nella generazione PDF: ' . $e->getMessage();
    exit;
}

// Pulisci il buffer di output
ob_end_clean();

// Disabilita compression per evitare corruzione del PDF
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', '0');

// Output del PDF
header('Content-Type: application/pdf');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
$mpdf->Output('', 'I');
exit;