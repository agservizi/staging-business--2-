<?php
require_once __DIR__ . '/../includes/db_connect.php';

$username = $argv[1] ?? null;
$role = $argv[2] ?? null;

if ($username === '--describe') {
    $columns = $pdo->query('SHOW COLUMNS FROM users');
    foreach ($columns as $column) {
        echo $column['Field'] . ': ' . $column['Type'] . PHP_EOL;
    }
    exit(0);
}

if ($username === '--alter-patronato') {
    $sql = "ALTER TABLE users MODIFY ruolo ENUM('Admin','Manager','Operatore','Patronato','Cliente') NOT NULL DEFAULT 'Operatore'";
    $pdo->exec($sql);
    echo "Role enum updated\n";
    exit(0);
}

if (!$username) {
    fwrite(STDERR, "Usage: php tools/debug_role.php <username>|--describe|--alter-patronato|--list-blanks [role]\n");
    exit(1);
}

if ($username === '--list-blanks') {
    $stmt = $pdo->query("SELECT id, username, email, ruolo, LENGTH(ruolo) AS len FROM users WHERE ruolo = '' OR ruolo IS NULL");
    if (!$stmt) {
        fwrite(STDERR, "Query failed\n");
        exit(3);
    }
    $found = false;
    foreach ($stmt as $row) {
        $found = true;
        echo sprintf("%d\t%s\t%s\t%s (len=%s)\n", $row['id'], $row['username'], $row['email'], $row['ruolo'], $row['len']);
    }
    if (!$found) {
        echo "No blank roles found\n";
    }
    exit(0);
}

$stmt = $pdo->prepare('SELECT id, username, ruolo FROM users WHERE username = :username LIMIT 1');
$stmt->execute([':username' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fwrite(STDERR, "User not found\n");
    exit(2);
}

echo "Current record:\n";
print_r($user);
echo 'Raw ruolo: ' . var_export($user['ruolo'], true) . PHP_EOL;

if ($role === null) {
    exit(0);
}

$update = $pdo->prepare('UPDATE users SET ruolo = :role WHERE id = :id');
$update->execute([
    ':role' => $role,
    ':id' => $user['id'],
]);

echo "Rows updated: " . $update->rowCount() . "\n";
