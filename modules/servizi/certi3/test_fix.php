<?php
require_once '../../../includes/db_connect.php';

// Simula i dati della sessione
$_SESSION['user_id'] = 1;

try {
    $richieste = $pdo->prepare('SELECT cr.*, u.nome, u.cognome FROM certificati_richieste cr JOIN users u ON cr.user_id = u.id WHERE cr.user_id = ? ORDER BY cr.created_at DESC LIMIT 5');
    $richieste->execute([1]);
    $richieste = $richieste->fetchAll(PDO::FETCH_ASSOC);
    
    echo 'Test rendering tabella:\n';
    echo 'Numero richieste: ' . count($richieste) . '\n';
    
    foreach ($richieste as $richiesta) {
        $tipo_display = htmlspecialchars($richiesta['categoria'] . ' - ' . ($richiesta['tipo'] ?? 'N/A'));
        $stato_display = match($richiesta['stato']) {
            'done' => 'Completato',
            'processing' => 'In elaborazione', 
            'error' => 'Errore',
            'pending' => 'In attesa',
            default => htmlspecialchars($richiesta['stato'] ?? 'Sconosciuto')
        };
        
        echo 'ID: ' . $richiesta['id'] . ', Tipo: ' . $tipo_display . ', Stato: ' . $stato_display . '\n';
    }
    
    echo 'Test completato senza errori!\n';
    
} catch (Exception $e) {
    echo 'Errore: ' . $e->getMessage() . '\n';
}
