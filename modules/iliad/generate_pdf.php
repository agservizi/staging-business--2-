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
            body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; }
            .page { border: 1px solid #e5e5e5; }
            .header { background: #e30613; color: #fff; padding: 18px 20px; }
            .header-title { font-size: 20px; font-weight: bold; letter-spacing: 0.5px; }
            .header-sub { font-size: 11px; opacity: 0.9; margin-top: 4px; }
            .content { padding: 18px 20px; }
            .section-title { font-size: 14px; font-weight: bold; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.6px; }
            .meta { font-size: 11px; color: #555; margin-bottom: 14px; }
            .card { border: 1px solid #eee; padding: 12px; margin-bottom: 12px; }
            .row { display: table; width: 100%; }
            .col { display: table-cell; width: 50%; vertical-align: top; }
            .label { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 0.4px; }
            .value { font-size: 13px; font-weight: bold; margin-top: 4px; }
            .muted { color: #888; font-weight: normal; }
            .divider { height: 1px; background: #f0f0f0; margin: 12px 0; }
            .footer { font-size: 10px; color: #777; padding: 12px 20px 16px; }
        </style>
        <div class="page">
            <div class="header">
                <div class="header-title">ILIAD · Credenziali</div>
                <div class="header-sub">Documento riservato al cliente</div>
            </div>
            <div class="content">
                <div class="section-title">Dati credenziali</div>
                <div class="meta">ID credenziale: ' . (int) $credential['id'] . ' · Creata il: ' . htmlspecialchars((string) ($credential['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>

                <div class="card">
                    <div class="row">
                        <div class="col">
                            <div class="label">Servizio</div>
                            <div class="value">Fibra</div>
                        </div>
                        <div class="col">
                            <div class="label">Stato</div>
                            <div class="value">' . ($includeFibra ? 'Attivo' : '<span class="muted">Non incluso</span>') . '</div>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="row">
                        <div class="col">
                            <div class="label">ID Fibra</div>
                            <div class="value">' . ($includeFibra ? htmlspecialchars($fibraId, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</div>
                        </div>
                        <div class="col">
                            <div class="label">Password</div>
                            <div class="value">' . ($includeFibra ? htmlspecialchars($fibraPassword, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="row">
                        <div class="col">
                            <div class="label">Servizio</div>
                            <div class="value">Mobile</div>
                        </div>
                        <div class="col">
                            <div class="label">Stato</div>
                            <div class="value">' . ($includeMobile ? 'Attivo' : '<span class="muted">Non incluso</span>') . '</div>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="row">
                        <div class="col">
                            <div class="label">ID Mobile</div>
                            <div class="value">' . ($includeMobile ? htmlspecialchars($mobileId, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</div>
                        </div>
                        <div class="col">
                            <div class="label">Password</div>
                            <div class="value">' . ($includeMobile ? htmlspecialchars($mobilePassword, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="footer">Questo documento contiene credenziali riservate. Conservalo in luogo sicuro.</div>
        </div>
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