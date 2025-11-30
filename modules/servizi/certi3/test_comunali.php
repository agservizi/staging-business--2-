<?php
// Test semplice per verificare la preparazione dei dati comunali
// Simula il metodo preparaDatiRichiesta senza caricare tutto il framework

function preparaDatiRichiesta($dati) {
    // Mappa i dati del form ai campi API
    $search = [];

    if (!empty($dati['codice_fiscale'])) {
        $search['field0'] = $dati['codice_fiscale']; // Codice fiscale
    }

    if (!empty($dati['nome'])) {
        $search['field1'] = $dati['nome']; // Nome
    }

    if (!empty($dati['cognome'])) {
        $search['field2'] = $dati['cognome']; // Cognome
    }

    if (!empty($dati['comune'])) {
        $search['field3'] = $dati['comune']; // Comune
    }

    if (!empty($dati['provincia'])) {
        $search['field4'] = $dati['provincia']; // Provincia
    }

    if (!empty($dati['data_nascita'])) {
        $search['field5'] = $dati['data_nascita']; // Data di nascita
    }

    if (!empty($dati['luogo_nascita'])) {
        $search['field6'] = $dati['luogo_nascita']; // Luogo di nascita
    }

    if (!empty($dati['sesso'])) {
        $search['field7'] = $dati['sesso']; // Sesso
    }

    if (!empty($dati['indirizzo'])) {
        $search['field8'] = $dati['indirizzo']; // Indirizzo
    }

    return $search;
}

// Dati di test completi per stato di famiglia
$testData = [
    'codice_fiscale' => 'RSSMRA85T10A562S',
    'nome' => 'Mario',
    'cognome' => 'Rossi',
    'comune' => 'Roma',
    'provincia' => 'RM',
    'data_nascita' => '1985-01-10',
    'luogo_nascita' => 'Roma',
    'sesso' => 'M',
    'indirizzo' => 'Via Roma 123'
];

$searchData = preparaDatiRichiesta($testData);

echo "Dati preparati per la richiesta:\n";
echo json_encode($searchData, JSON_PRETTY_PRINT) . "\n";
echo "\nNumero di campi: " . count($searchData) . "\n";
echo "Campi richiesti dall'API: field0-field7 (8 campi) o field0-field8 (9 campi)\n";
echo "Stiamo passando: " . (count($searchData) >= 8 ? "✅ OK" : "❌ INSUFFICIENTI") . "\n";

// Test con dati incompleti (come prima)
$testDataIncompleto = [
    'codice_fiscale' => 'RSSMRA85T10A562S',
    'nome' => 'Mario',
    'cognome' => 'Rossi',
    'comune' => 'Roma',
    'provincia' => 'RM'
];

$searchDataIncompleto = preparaDatiRichiesta($testDataIncompleto);
echo "\n--- Test con dati incompleti (come prima) ---\n";
echo "Numero di campi: " . count($searchDataIncompleto) . "\n";
echo "Stiamo passando: " . (count($searchDataIncompleto) >= 8 ? "✅ OK" : "❌ INSUFFICIENTI") . "\n";
?>