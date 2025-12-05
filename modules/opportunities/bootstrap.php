<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore', 'Collaboratore');

use App\Services\Opportunities\OpportunityService;

$opportunityService = new OpportunityService($pdo);
