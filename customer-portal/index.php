<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Reindirizza in base allo stato di autenticazione
if (CustomerAuth::isAuthenticated()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;