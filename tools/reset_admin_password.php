<?php
echo "Inizio reset admin...\n";
require_once __DIR__ . '/../includes/db_connect.php';

try {
    $newHash = password_hash('admin', PASSWORD_DEFAULT);

    echo "Hash generato\n";

    $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE username = :username LIMIT 1');
    echo "Statement preparato\n";
    $success = $stmt->execute([
        ':password' => $newHash,
        ':username' => 'admin',
    ]);

    echo "Statement eseguito\n";
    echo $success ? "Password aggiornata con successo\n" : "Aggiornamento fallito\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
