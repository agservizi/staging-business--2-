<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class DailyFinancialReportService
{
    private const MODULE_NAME = 'Report/Giornalieri';

    private PDO $pdo;
    private string $rootPath;
    private string $storagePath;

    public function __construct(PDO $pdo, string $rootPath)
    {
        $this->pdo = $pdo;
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->storagePath = $this->rootPath . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'daily-reports';
    }

    /**
     * @return array{reportDate:string,filePath:string,totalEntrate:float,totalUscite:float,saldo:float}
     */
    public function generateForDate(DateTimeImmutable $date): array
    {
        $reportDate = $date->format('Y-m-d');
        $movements = $this->fetchMovementsForDate($reportDate);
        $totals = $this->calculateTotals($movements);

        $relativePath = $this->storePdf($date, $movements, $totals['entrate'], $totals['uscite'], $totals['saldo']);

        $this->persistReportRecord($reportDate, $relativePath, $totals['entrate'], $totals['uscite'], $totals['saldo']);
        $this->logGeneration($reportDate, $relativePath, $totals['entrate'], $totals['uscite'], $totals['saldo']);

        return [
            'reportDate' => $reportDate,
            'filePath' => $relativePath,
            'totalEntrate' => $totals['entrate'],
            'totalUscite' => $totals['uscite'],
            'saldo' => $totals['saldo'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchMovementsForDate(string $reportDate): array
    {
        $sql = <<<SQL
SELECT eu.id,
       eu.tipo_movimento,
       eu.descrizione,
       eu.riferimento,
       eu.metodo,
       eu.stato,
       eu.importo,
       eu.data_pagamento,
       eu.data_scadenza,
       eu.created_at,
       c.ragione_sociale,
       c.nome,
       c.cognome,
       COALESCE(eu.data_pagamento, eu.data_scadenza, eu.created_at) AS data_riferimento
FROM entrate_uscite eu
LEFT JOIN clienti c ON c.id = eu.cliente_id
WHERE DATE(COALESCE(eu.data_pagamento, eu.data_scadenza, eu.created_at)) = :report_date
ORDER BY data_riferimento ASC, eu.id ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':report_date' => $reportDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{entrate:float,uscite:float,saldo:float}
     */
    private function calculateTotals(array $rows): array
    {
        $entrate = 0.0;
        $uscite = 0.0;

        foreach ($rows as $row) {
            $amount = (float) ($row['importo'] ?? 0);
            if (($row['tipo_movimento'] ?? '') === 'Uscita') {
                $uscite += $amount;
            } else {
                $entrate += $amount;
            }
        }

        $saldo = $entrate - $uscite;

        return [
            'entrate' => round($entrate, 2),
            'uscite' => round($uscite, 2),
            'saldo' => round($saldo, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $movements
     */
    private function storePdf(DateTimeImmutable $date, array $movements, float $entrate, float $uscite, float $saldo): string
    {
        $this->ensureStorageDirectory();

        $pdf = $this->createPdfInstance();
    $pdf->SetMargins(15.0, 15.0, 15.0);
    $pdf->SetAutoPageBreak(true, 20.0);
    $pdf->AddPage('L');

        $pdf->SetTextColor(11, 47, 107);
    $pdf->SetFont('DejaVu Sans', 'B', 18);
        $pdf->Cell(0, 10, $this->pdfText('Report Finanziario Giornaliero'), 0, 1, 'L');

    $pdf->SetFont('DejaVu Sans', '', 12);
        $pdf->SetTextColor(28, 37, 52);
        $pdf->Cell(0, 7, $this->pdfText('Data report: ' . $date->format('d/m/Y')), 0, 1, 'L');
        $pdf->Cell(0, 7, $this->pdfText('Generato il: ' . (new DateTimeImmutable('now'))->format('d/m/Y H:i')), 0, 1, 'L');
        $pdf->Ln(4);

        $columns = [
            ['width' => 22.0, 'title' => 'Data', 'align' => 'L'],
            ['width' => 32.0, 'title' => 'Cliente', 'align' => 'L'],
            ['width' => 18.0, 'title' => 'Tipo', 'align' => 'L'],
            ['width' => 52.0, 'title' => 'Descrizione', 'align' => 'L'],
            ['width' => 24.0, 'title' => 'Riferimento', 'align' => 'L'],
            ['width' => 20.0, 'title' => 'Metodo', 'align' => 'L'],
            ['width' => 12.0, 'title' => 'Stato', 'align' => 'L'],
            ['width' => 24.0, 'title' => 'Importo', 'align' => 'R'],
        ];

    $pdf->SetFont('DejaVu Sans', 'B', 10);
        $pdf->SetFillColor(11, 47, 107);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($columns as $column) {
            $pdf->Cell($column['width'], 8, $this->pdfText($column['title']), 1, 0, $column['align'], true);
        }
        $pdf->Ln();

    $pdf->SetFont('DejaVu Sans', '', 9);
        $pdf->SetTextColor(28, 37, 52);

        if (!$movements) {
            $pdf->Cell(array_sum(array_column($columns, 'width')), 10, $this->pdfText('Nessun movimento registrato per la giornata.'), 1, 1, 'C');
        } else {
            foreach ($movements as $item) {
                $pdf->Cell($columns[0]['width'], 7, $this->pdfText($this->formatDateTime($item['data_riferimento'] ?? '')), 1, 0, $columns[0]['align']);
                $pdf->Cell($columns[1]['width'], 7, $this->pdfText($this->trimText($this->buildClientName($item), 24)), 1, 0, $columns[1]['align']);
                $pdf->Cell($columns[2]['width'], 7, $this->pdfText((string) ($item['tipo_movimento'] ?? '')), 1, 0, $columns[2]['align']);
                $pdf->Cell($columns[3]['width'], 7, $this->pdfText($this->trimText((string) ($item['descrizione'] ?? ''), 36)), 1, 0, $columns[3]['align']);
                $pdf->Cell($columns[4]['width'], 7, $this->pdfText($this->trimText((string) ($item['riferimento'] ?? ''), 18)), 1, 0, $columns[4]['align']);
                $pdf->Cell($columns[5]['width'], 7, $this->pdfText($this->trimText((string) ($item['metodo'] ?? ''), 16)), 1, 0, $columns[5]['align']);
                $pdf->Cell($columns[6]['width'], 7, $this->pdfText($this->trimText((string) ($item['stato'] ?? ''), 12)), 1, 0, $columns[6]['align']);
                $pdf->Cell($columns[7]['width'], 7, $this->pdfText($this->formatCurrency((float) ($item['importo'] ?? 0))), 1, 0, $columns[7]['align']);
                $pdf->Ln();
            }
        }

        $pdf->Ln(4);
    $pdf->SetFont('DejaVu Sans', 'B', 11);
        $pdf->Cell(60, 7, $this->pdfText('Totale Entrate'), 0, 0, 'L');
    $pdf->SetFont('DejaVu Sans', '', 11);
        $pdf->Cell(40, 7, $this->pdfText($this->formatCurrency($entrate)), 0, 1, 'L');

    $pdf->SetFont('DejaVu Sans', 'B', 11);
        $pdf->Cell(60, 7, $this->pdfText('Totale Uscite'), 0, 0, 'L');
    $pdf->SetFont('DejaVu Sans', '', 11);
        $pdf->Cell(40, 7, $this->pdfText($this->formatCurrency($uscite)), 0, 1, 'L');

    $pdf->SetFont('DejaVu Sans', 'B', 11);
        $pdf->Cell(60, 7, $this->pdfText('Saldo'), 0, 0, 'L');
        if ($saldo >= 0) {
            $pdf->SetTextColor(21, 87, 36);
        } else {
            $pdf->SetTextColor(114, 28, 36);
        }
    $pdf->SetFont('DejaVu Sans', '', 11);
        $pdf->Cell(40, 7, $this->pdfText($this->formatCurrency($saldo)), 0, 1, 'L');
        $pdf->SetTextColor(28, 37, 52);

        $fileName = sprintf('report_finanziario_%s.pdf', $date->format('Ymd'));
        $fullPath = $this->storagePath . DIRECTORY_SEPARATOR . $fileName;
    $pdf->Output($fullPath, 'F');

        return 'backups/daily-reports/' . $fileName;
    }

    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException('Impossibile creare la cartella dei report giornalieri.');
        }
    }
    
    private function createPdfInstance(): object
    {
        $className = '\\Mpdf\\Mpdf';
        if (!class_exists($className)) {
            throw new RuntimeException('Libreria mPDF non disponibile.');
        }

        /** @var object $instance */
        $instance = new $className([
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 20,
        ]);

        return $instance;
    }

    private function persistReportRecord(string $reportDate, string $filePath, float $entrate, float $uscite, float $saldo): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO daily_financial_reports (report_date, total_entrate, total_uscite, saldo, file_path, generated_at)
            VALUES (:report_date, :entrate, :uscite, :saldo, :file_path, NOW())
            ON DUPLICATE KEY UPDATE total_entrate = VALUES(total_entrate), total_uscite = VALUES(total_uscite), saldo = VALUES(saldo), file_path = VALUES(file_path), generated_at = VALUES(generated_at)');

        $stmt->execute([
            ':report_date' => $reportDate,
            ':entrate' => $entrate,
            ':uscite' => $uscite,
            ':saldo' => $saldo,
            ':file_path' => $filePath,
        ]);
    }

    private function logGeneration(string $reportDate, string $filePath, float $entrate, float $uscite, float $saldo): void
    {
        try {
            $payload = json_encode([
                'report_date' => $reportDate,
                'file_path' => $filePath,
                'total_entrate' => $entrate,
                'total_uscite' => $uscite,
                'saldo' => $saldo,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $this->pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                VALUES (NULL, :modulo, :azione, :dettagli, NOW())');
            $stmt->execute([
                ':modulo' => self::MODULE_NAME,
                ':azione' => 'Report giornaliero generato',
                ':dettagli' => $payload,
            ]);
        } catch (PDOException $exception) {
            error_log('Daily report log failed: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildClientName(array $row): string
    {
        $company = trim((string) ($row['ragione_sociale'] ?? ''));
        if ($company !== '') {
            return $company;
        }

        $first = trim((string) ($row['nome'] ?? ''));
        $last = trim((string) ($row['cognome'] ?? ''));
        $full = trim($first . ' ' . $last);
        return $full !== '' ? $full : 'N/D';
    }

    private function formatCurrency(float $value): string
    {
        $formatted = number_format($value, 2, ',', '.');
        return '€ ' . $formatted;
    }

    private function formatDateTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            $date = new DateTimeImmutable($value);
            return $date->format('d/m/Y H:i');
        } catch (Throwable $exception) {
            return $value;
        }
    }

    private function trimText(string $value, int $maxLength): string
    {
        if (mb_strlen($value, 'UTF-8') <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 1, 'UTF-8')) . '…';
    }

    private function pdfText(string $value): string
    {
        return $value;
    }
}
