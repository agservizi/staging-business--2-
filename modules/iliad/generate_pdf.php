<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Avvia output buffering per evitare output indesiderato
ob_start();

require_once __DIR__ . '/bootstrap.php';

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
    $mpdf->WriteHTML('<h1>Test Iliad PDF</h1><p>ID: ' . $id . '</p>');
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
$mpdf->Output('I');
exit;