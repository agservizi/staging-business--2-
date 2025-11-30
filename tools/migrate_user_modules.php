<?php
require_once __DIR__ . '/../includes/db_connect.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_module_permissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        module_name VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_module (user_id, module_name),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    echo "Migrazione completata: tabella user_module_permissions creata.\n";
} catch (Exception $e) {
    echo "Errore durante la migrazione: " . $e->getMessage() . "\n";
}
?>