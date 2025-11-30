<?php
// Test di richiesta reale all'API DocuEngine per certificati comunali
require_once '../../../includes/db_connect.php';
require_once '../../../includes/helpers.php';
require_once 'api/comunali.php';

$api = new ComuniAPI();

// Dati di test per stato di famiglia
$testData = [
    'tipo' => 'famiglia', // Tipo di certificato
    'nome' => 'Mario',
    'cognome' => 'Rossi',
    'data_nascita' => '1985-01-10',
    'luogo_nascita' => 'Roma', // Comune di nascita
    'codice_fiscale' => 'RSSMRA85T10A562S',
    'comune' => 'Roma', // Comune di residenza
    'exemption_reason' => 'MINORI' // Motivo esenzione (obbligatorio)
    // 'exemption_document' => 'test_document.pdf' // Commentato per test
];

echo "Test richiesta certificato comunale - Stato di Famiglia\n";
echo "====================================================\n\n";

echo "Dati inviati:\n";
echo json_encode($testData, JSON_PRETTY_PRINT) . "\n\n";

// Effettua la richiesta
$result = $api->richiediCertificato($testData['tipo'], $testData);

echo "Risultato:\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

if ($result['success']) {
    echo "✅ Richiesta completata con successo!\n";
    echo "Request ID: " . ($result['request_id'] ?? 'N/A') . "\n";
    echo "Stato: " . ($result['state'] ?? 'N/A') . "\n";
} else {
    echo "❌ Errore nella richiesta:\n";
    echo $result['error'] . "\n";
}
?>