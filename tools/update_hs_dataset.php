<?php
declare(strict_types=1);

$repoUrl = 'https://raw.githubusercontent.com/datasets/harmonized-system/master/data/harmonized-system.csv';
$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fwrite(STDERR, "Impossibile determinare la directory del progetto." . PHP_EOL);
    exit(1);
}

$datasetPath = $projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'harmonized-system.csv';
$cachePath = $projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'hs-translation-cache.json';

$arguments = $argv;
array_shift($arguments);
$keepCache = in_array('--keep-cache', $arguments, true);

$temporaryFile = tempnam(sys_get_temp_dir(), 'hs_dataset_');
if ($temporaryFile === false) {
    fwrite(STDERR, "Impossibile creare un file temporaneo per il download." . PHP_EOL);
    exit(1);
}

$ch = curl_init($repoUrl);
if ($ch === false) {
    fwrite(STDERR, "Impossibile inizializzare la richiesta HTTP." . PHP_EOL);
    @unlink($temporaryFile);
    exit(1);
}

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_FAILONERROR, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);

if ($response === false) {
    fwrite(STDERR, 'Download fallito: ' . curl_error($ch) . PHP_EOL);
    curl_close($ch);
    @unlink($temporaryFile);
    exit(1);
}

$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    fwrite(STDERR, "Download fallito con codice HTTP {$httpCode}." . PHP_EOL);
    @unlink($temporaryFile);
    exit(1);
}

if (file_put_contents($temporaryFile, $response) === false) {
    fwrite(STDERR, "Impossibile scrivere il file temporaneo." . PHP_EOL);
    @unlink($temporaryFile);
    exit(1);
}

if (filesize($temporaryFile) === 0) {
    fwrite(STDERR, "Il file scaricato risulta vuoto, aggiornamento annullato." . PHP_EOL);
    @unlink($temporaryFile);
    exit(1);
}

if (!is_dir(dirname($datasetPath))) {
    if (!mkdir(dirname($datasetPath), 0775, true) && !is_dir(dirname($datasetPath))) {
        fwrite(STDERR, 'Impossibile creare la directory dati: ' . dirname($datasetPath) . PHP_EOL);
        @unlink($temporaryFile);
        exit(1);
    }
}

$backupPath = $datasetPath . '.bak';
if (file_exists($datasetPath)) {
    if (!@rename($datasetPath, $backupPath)) {
        fwrite(STDERR, 'Impossibile creare il backup del dataset esistente.' . PHP_EOL);
        @unlink($temporaryFile);
        exit(1);
    }
}

if (!@rename($temporaryFile, $datasetPath)) {
    @rename($backupPath, $datasetPath);
    fwrite(STDERR, 'Impossibile sostituire il dataset, ripristino del backup completato.' . PHP_EOL);
    @unlink($temporaryFile);
    exit(1);
}

@chmod($datasetPath, 0664);

if (!$keepCache && file_exists($cachePath)) {
    if (!@unlink($cachePath)) {
        fwrite(STDERR, 'Aggiornamento completato, ma impossibile eliminare la cache: ' . $cachePath . PHP_EOL);
    } else {
        echo 'Cache delle traduzioni rimossa.' . PHP_EOL;
    }
}

echo 'Dataset HS aggiornato correttamente da ' . $repoUrl . PHP_EOL;
exit(0);
