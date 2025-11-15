<?php
declare(strict_types=1);

/**
 * Script di migrazione per il Customer Portal
 * Esegue le migration necessarie per il portale clienti
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

echo "=== Customer Portal Migration Script ===" . PHP_EOL;
echo "Data: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

try {
    $pdo = PortalDatabase::getConnection();
    
    // File di migrazione
    $migrationFile = __DIR__ . '/../database/migrations/20251027_140000_create_customer_portal_tables.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("File di migrazione non trovato: $migrationFile");
    }
    
    echo "Lettura file di migrazione..." . PHP_EOL;
    $sql = file_get_contents($migrationFile);
    
    if (!$sql) {
        throw new Exception("Impossibile leggere il file di migrazione");
    }
    
    echo "Esecuzione migrazione..." . PHP_EOL;
    
    // Dividi il file SQL in statement singoli
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $pdo->beginTransaction();
    
    $executedStatements = 0;
    foreach ($statements as $statement) {
        // Rimuove eventuali commenti SQL lasciando solo lo statement eseguibile
        $statement = preg_replace('/^\s*--.*$/m', '', $statement);
        $statement = preg_replace('/\/\*.*?\*\//s', '', $statement);
        $statement = trim($statement);

        if ($statement === '') {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $executedStatements++;
            echo "✓ Statement eseguito con successo" . PHP_EOL;
        } catch (PDOException $e) {
            // Ignora errori per tabelle già esistenti
            if (strpos($e->getMessage(), 'Table') !== false && strpos($e->getMessage(), 'already exists') !== false) {
                echo "ℹ Tabella già esistente, saltata" . PHP_EOL;
                continue;
            }
            throw $e;
        }
    }
    
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    
    echo PHP_EOL . "✅ Migrazione completata con successo!" . PHP_EOL;
    echo "Statement eseguiti: $executedStatements" . PHP_EOL;
    
    // Verifica tabelle create
    echo PHP_EOL . "Verifica tabelle create:" . PHP_EOL;
    
    $tables = [
        'pickup_customers',
        'pickup_customer_otps', 
        'pickup_customer_sessions',
        'pickup_customer_reports',
        'pickup_customer_notifications',
        'pickup_api_rate_limits',
        'pickup_customer_activity_logs',
        'pickup_customer_preferences'
    ];
    
    foreach ($tables as $table) {
        $exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if ($exists) {
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo "✓ $table (record: $count)" . PHP_EOL;
        } else {
            echo "✗ $table - NON TROVATA" . PHP_EOL;
        }
    }
    
    // Crea utente di test se in modalità debug
    if (env('APP_DEBUG', false)) {
        echo PHP_EOL . "Creazione utente di test..." . PHP_EOL;
        
        try {
            $testCustomer = [
                'email' => 'test@example.com',
                'phone' => '+39123456789',
                'name' => 'Cliente Test',
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $customerId = portal_insert('pickup_customers', $testCustomer);
            
            // Crea preferenze per il cliente di test
            portal_insert('pickup_customer_preferences', [
                'customer_id' => $customerId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            echo "✓ Cliente di test creato (ID: $customerId)" . PHP_EOL;
            echo "  Email: test@example.com" . PHP_EOL;
            echo "  Telefono: +39123456789" . PHP_EOL;
            
        } catch (Exception $e) {
            echo "ℹ Cliente di test già esistente o errore: " . $e->getMessage() . PHP_EOL;
        }
    }
    
    echo PHP_EOL . "🎉 Migrazione completata!" . PHP_EOL;
    echo "Il Customer Portal è pronto per l'uso." . PHP_EOL;
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    echo PHP_EOL . "❌ Errore durante la migrazione:" . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
    echo PHP_EOL . "Stack trace:" . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    
    exit(1);
}