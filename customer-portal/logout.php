<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Effettua logout
CustomerAuth::logout();

// Reindirizza alla pagina di login con messaggio
header('Location: login.php?message=' . urlencode('Disconnessione effettuata con successo'));
exit;