<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore', 'Collaboratore');

require_once __DIR__ . '/../../app/Services/IliadCredentialsService.php';

$iliadService = new IliadCredentialsService($pdo);