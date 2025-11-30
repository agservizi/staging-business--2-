<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager', 'Patronato');

add_flash('info', 'La gestione appuntamenti per il modulo CAF & Patronato è stata dismessa.');
header('Location: index.php');
exit;
