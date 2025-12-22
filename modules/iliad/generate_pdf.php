<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$credential = $iliadService->getCredential($id);

if (!$credential) {
    http_response_code(404);
    echo 'Credenziale non trovata.';
    exit;
}

// Verifica che mPDF sia disponibile
if (!class_exists('Mpdf\Mpdf')) {
    http_response_code(500);
    echo 'Errore di configurazione: Libreria mPDF mancante. Assicurati che sia installata tramite Composer.';
    exit;
}

$mpdf = new \Mpdf\Mpdf();

// Crea il contenuto HTML del PDF
$html = '
<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h1 { text-align: center; color: #333; }
    h2 { color: #0066cc; margin-top: 30px; }
    .credential { margin-bottom: 20px; }
    .label { font-weight: bold; display: inline-block; width: 120px; }
    .footer { text-align: center; font-size: 10px; color: #666; margin-top: 50px; }
</style>

<h1>Credenziali Iliad</h1>
';

if ($credential['include_fibra']) {
    $html .= '<h2>Credenziali Fibra</h2>';
    $html .= '<div class="credential">';
    if (!empty($credential['fibra_id'])) {
        $html .= '<div><span class="label">ID Fibra:</span> ' . htmlspecialchars($credential['fibra_id']) . '</div>';
    }
    $html .= '<div><span class="label">Password:</span> ' . htmlspecialchars($credential['fibra_password']) . '</div>';
    $html .= '</div>';
}

if ($credential['include_mobile']) {
    $html .= '<h2>Credenziali Mobile</h2>';
    $html .= '<div class="credential">';
    if (!empty($credential['mobile_id'])) {
        $html .= '<div><span class="label">ID Mobile:</span> ' . htmlspecialchars($credential['mobile_id']) . '</div>';
    }
    $html .= '<div><span class="label">Password:</span> ' . htmlspecialchars($credential['mobile_password']) . '</div>';
    $html .= '</div>';
}

$html .= '<div class="footer">Generato il ' . date('d/m/Y H:i') . '</div>';

// Scrivi l'HTML nel PDF
$mpdf->WriteHTML($html);

// Output del PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="credenziali_iliad_' . $id . '.pdf"');
$mpdf->Output('D');
exit;