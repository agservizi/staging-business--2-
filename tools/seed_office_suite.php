#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db_connect.php';

use App\Services\OfficeSuite\DocumentService;
use App\Services\OfficeSuite\SpreadsheetService;

$documentService = new DocumentService($pdo);
$spreadsheetService = new SpreadsheetService($pdo);
$actorId = isset($argv[1]) ? (int) $argv[1] : 1;
if ($actorId <= 0) {
    $actorId = 1;
}

$documentSeeds = [
    [
        'title' => 'Contratto quadro forniture',
        'category' => 'Contratti',
        'status' => 'draft',
        'tags' => 'contratto,fornitori,ufficio',
        'content' => "# Contratto quadro forniture\n\nQuesto documento definisce i termini generali per l'approvvigionamento di servizi B2B.\n\n## Clausole principali\n- Oggetto del contratto\n- SLA operativi\n- Penali e tempi di reazione\n\nFirma digitale richiesta.",
    ],
    [
        'title' => 'Lettera incarico commerciale standard',
        'category' => 'Template',
        'status' => 'published',
        'tags' => 'template,vendite,onboarding',
        'content' => "Gentile Cliente,\n\ncon la presente comunichiamo l'incarico ufficiale per la gestione commerciale.\n\nCordiali saluti,\nTeam Coresuite Business",
    ],
    [
        'title' => 'Avviso disservizio logistico',
        'category' => 'Comunicazioni',
        'status' => 'review',
        'tags' => 'logistica,avvisi,BRT',
        'content' => "Oggetto: Avviso disservizio logistico\n\nStiamo riscontrando rallentamenti sulle spedizioni BRT.\n\nAggiorneremo i clienti ogni 2 ore con stato e nuova ETA.",
    ],
];

$spreadsheetSeeds = [
    [
        'title' => 'KPI Vendite Settimanali',
        'category' => 'Finance',
        'status' => 'draft',
        'tags' => 'vendite,kpi',
        'grid' => [
            ['Week', 'Lead', 'Chiusure', 'Valore'],
            ['45', '32', '8', '24.500'],
            ['46', '28', '6', '18.750'],
            ['47', '40', '11', '32.100'],
        ],
    ],
    [
        'title' => 'Budget Energia 2026',
        'category' => 'Finance',
        'status' => 'review',
        'tags' => 'energia,budget',
        'grid' => [
            ['Mese', 'Previsione kWh', 'Costo medio'],
            ['Gen', '12.300', '0,22'],
            ['Feb', '11.950', '0,21'],
            ['Mar', '13.100', '0,23'],
        ],
    ],
];

$insertedDocuments = 0;
foreach ($documentSeeds as $seed) {
    if (document_exists($pdo, $seed['title'])) {
        echo "Documento già presente: {$seed['title']}\n";
        continue;
    }

    $payload = [
        'title' => $seed['title'],
        'category' => $seed['category'],
        'status' => $seed['status'],
        'tags' => $seed['tags'],
        'content' => $seed['content'],
        'owner_id' => $actorId,
    ];

    $document = $documentService->saveDocument($payload, $actorId);
    echo 'Creato documento #' . $document['id'] . ' - ' . $document['titolo'] . PHP_EOL;
    $insertedDocuments++;
}

echo "Totale documenti inseriti: {$insertedDocuments}\n";

$insertedSheets = 0;
foreach ($spreadsheetSeeds as $seed) {
    if (sheet_exists($pdo, $seed['title'])) {
        echo "Foglio già presente: {$seed['title']}\n";
        continue;
    }

    $payload = [
        'title' => $seed['title'],
        'category' => $seed['category'],
        'status' => $seed['status'],
        'tags' => $seed['tags'],
        'grid' => json_encode($seed['grid'], JSON_UNESCAPED_UNICODE),
        'owner_id' => $actorId,
    ];

    $sheet = $spreadsheetService->saveSheet($payload, $actorId);
    echo 'Creato foglio #' . $sheet['id'] . ' - ' . $sheet['titolo'] . PHP_EOL;
    $insertedSheets++;
}

echo "Totale fogli inseriti: {$insertedSheets}\n";

// Esegue una modifica rapida sul primo documento disponibile per simulare l'editor
$firstDoc = fetch_first_document($pdo);
if ($firstDoc !== null) {
    $docData = $documentService->getDocument((int) $firstDoc['id']);
    if ($docData !== null) {
        $latestRevision = $documentService->getLatestRevision((int) $docData['id']);
        $latestContent = $latestRevision['contenuto'] ?? '';
        $documentService->saveDocument([
            'id' => (int) $docData['id'],
            'title' => (string) $docData['titolo'],
            'category' => (string) $docData['categoria'],
            'status' => (string) $docData['stato'],
            'tags' => $docData['tags'] ? implode(', ', (array) $docData['tags']) : '',
            'content' => trim((string) $latestContent) . "\n\nAggiornamento automatico seed " . date('d/m/Y H:i'),
            'owner_id' => $actorId,
        ], $actorId);
        echo 'Verifica editor documento completata su ID ' . $docData['id'] . PHP_EOL;
    }
}

$firstSheet = fetch_first_sheet($pdo);
if ($firstSheet !== null) {
    $sheetData = $spreadsheetService->getSheet((int) $firstSheet['id']);
    if ($sheetData !== null) {
        $latest = $spreadsheetService->getLatestRevision((int) $sheetData['id']);
        $grid = $latest ? json_decode((string) ($latest['grid_state'] ?? '[]'), true) : [];
        if (!is_array($grid)) {
            $grid = [];
        }
        $grid[] = ['Nota', 'Aggiornato da seed il ' . date('d/m/Y H:i')];
        $spreadsheetService->saveSheet([
            'id' => (int) $sheetData['id'],
            'title' => (string) $sheetData['titolo'],
            'category' => (string) $sheetData['categoria'],
            'status' => (string) $sheetData['stato'],
            'tags' => $sheetData['tags'] ? implode(', ', (array) $sheetData['tags']) : '',
            'grid' => json_encode($grid, JSON_UNESCAPED_UNICODE),
            'owner_id' => $actorId,
        ], $actorId);
        echo 'Verifica editor foglio completata su ID ' . $sheetData['id'] . PHP_EOL;
    }
}

echo "Seed Office Suite completato.\n";

function document_exists(PDO $pdo, string $title): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM office_documents WHERE titolo = :titolo LIMIT 1');
    $stmt->execute([':titolo' => $title]);
    return (bool) $stmt->fetchColumn();
}

function sheet_exists(PDO $pdo, string $title): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM office_spreadsheets WHERE titolo = :titolo LIMIT 1');
    $stmt->execute([':titolo' => $title]);
    return (bool) $stmt->fetchColumn();
}

function fetch_first_document(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT id FROM office_documents ORDER BY id ASC LIMIT 1');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return $row ?: null;
}

function fetch_first_sheet(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT id FROM office_spreadsheets ORDER BY id ASC LIMIT 1');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return $row ?: null;
}
