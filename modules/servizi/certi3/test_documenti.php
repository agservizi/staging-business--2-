<?php
// Test per vedere quali documenti comunali sono disponibili
require_once 'api/comunali.php';

$api = new ComuniAPI();

echo "Documenti comunali disponibili:\n";
echo "==============================\n\n";

// Usa reflection per accedere al metodo privato
$reflection = new ReflectionClass($api);
$method = $reflection->getMethod('getDocumentiDisponibili');
$method->setAccessible(true);

$documenti = $method->invoke($api);

if ($documenti['success']) {
    echo "Trovati " . count($documenti['data']) . " documenti:\n\n";
    foreach ($documenti['data'] as $doc) {
        echo "- ID: " . $doc['id'] . "\n";
        echo "  Nome: " . $doc['name'] . "\n";
        echo "  Tipo: " . ($doc['type'] ?? 'N/A') . "\n";
        echo "  Categoria: " . ($doc['category'] ?? 'N/A') . "\n\n";
    }
} else {
    echo "Errore nel recupero documenti: " . $documenti['error'] . "\n";
}
?>