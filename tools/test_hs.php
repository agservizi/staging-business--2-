<?php
require __DIR__ . '/../app/Services/Customs/HsCodeLookupService.php';
require __DIR__ . '/../app/Services/Customs/HsCodeDatasetRepository.php';
require __DIR__ . '/../app/Services/Customs/HsCodeTranslator.php';
require __DIR__ . '/../app/Services/Customs/HsCodeLookupException.php';

$service = new App\Services\Customs\HsCodeLookupService();
$result = $service->search('telefoni cellulari 8517', 10);
var_dump($result);
