<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Avvia output buffering
ob_start();

require_once __DIR__ . '/../../vendor/autoload.php';

$id = (int) ($_GET['id'] ?? 0);

// Verifica che mPDF sia disponibile
if (!class_exists('Mpdf\Mpdf')) {
    ob_end_clean();
    http_response_code(500);
    echo 'Errore: mPDF non disponibile.';
    exit;
}

$mpdf = new \Mpdf\Mpdf();
$mpdf->WriteHTML('<h1>Test Semplice</h1><p>ID: ' . $id . '</p>');

// Pulisci buffer
ob_end_clean();

// Disabilita compression
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', '0');

// Output inline
header('Content-Type: application/pdf');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
$mpdf->Output('I');
exit;