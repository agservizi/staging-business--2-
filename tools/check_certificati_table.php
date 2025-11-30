<?php
require_once '../includes/db_connect.php';

try {
    $result = $pdo->query('SHOW TABLES LIKE "certificati_richieste"');
    if ($result->rowCount() > 0) {
        echo 'Tabella certificati_richieste esiste già' . PHP_EOL;
    } else {
        echo 'Tabella certificati_richieste non esiste, creando...' . PHP_EOL;
        $sql = file_get_contents('../database/schema.sql');
        // Estrai solo la parte della tabella certificati_richieste
        preg_match('/CREATE TABLE IF NOT EXISTS certificati_richieste.*?;/s', $sql, $matches);
        if (!empty($matches[0])) {
            if ($pdo->exec($matches[0]) !== false) {
                echo 'Tabella creata con successo' . PHP_EOL;
            } else {
                echo 'Errore creazione tabella' . PHP_EOL;
            }
        }
    }
} catch (Exception $e) {
    echo 'Errore: ' . $e->getMessage() . PHP_EOL;
}
?>