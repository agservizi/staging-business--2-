<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Test semplice
$mpdf->WriteHTML('<h1>Test Iliad PDF</h1><p>ID: ' . $id . '</p>');

// Output del PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="test_iliad_' . $id . '.pdf"');
$mpdf->Output('D');
exit;