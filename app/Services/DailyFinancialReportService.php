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

    public function __construct(PDO $pdo, string $rootPath)
    {
        $this->pdo = $pdo;
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{reportDate:string,totalEntrate:float,totalUscite:float,saldo:float,totalMargine:float}
     */
    public function generateForDate(DateTimeImmutable $date): array
    {
        $reportDate = $date->format('Y-m-d');
        $movements = $this->fetchMovementsForDate($reportDate);
        $totals = $this->calculateTotals($movements);

        $this->persistReportRecord($reportDate, $totals['entrate'], $totals['uscite'], $totals['saldo']);
        $this->logGeneration($reportDate, $totals['entrate'], $totals['uscite'], $totals['saldo']);

        return [
            'reportDate' => $reportDate,
            'totalEntrate' => $totals['entrate'],
            'totalUscite' => $totals['uscite'],
            'saldo' => $totals['saldo'],
            'totalMargine' => $totals['margine'],
        ];
    }

    public function renderPdfContent(DateTimeImmutable $date): string
    {
        $reportDate = $date->format('Y-m-d');
        $movements = $this->fetchMovementsForDate($reportDate);
        $totals = $this->calculateTotals($movements);

        return $this->buildPdfContent($date, $movements, $totals['entrate'], $totals['uscite'], $totals['saldo'], $totals['margine']);
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
    eu.listino_costo_rivenditore,
    eu.listino_costo_cliente,
    eu.listino_margine,
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
     * @return array{entrate:float,uscite:float,saldo:float,margine:float}
     */
    private function calculateTotals(array $rows): array
    {
        $entrate = 0.0;
        $uscite = 0.0;
        $margine = 0.0;

        foreach ($rows as $row) {
            $amount = (float) ($row['importo'] ?? 0);
            if (($row['tipo_movimento'] ?? '') === 'Uscita') {
                $uscite += $amount;
            } else {
                $entrate += $amount;
            }

            $marginValue = $row['listino_margine'] ?? null;
            if ($marginValue !== null && $marginValue !== '') {
                $margine += (float) $marginValue;
            }
        }

        $saldo = $entrate - $uscite;

        return [
            'entrate' => round($entrate, 2),
            'uscite' => round($uscite, 2),
            'saldo' => round($saldo, 2),
            'margine' => round($margine, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $movements
     */
    private function buildPdfContent(DateTimeImmutable $date, array $movements, float $entrate, float $uscite, float $saldo, float $margine): string
    {
        $pdf = $this->createPdfInstance();
        $pdf->SetMargins(12.0, 12.0, 12.0);
        $pdf->SetAutoPageBreak(true, 18.0);
        $pdf->AddPage('L');

        $pdf->SetTextColor(11, 47, 107);
    $pdf->SetFont('DejaVu Sans', 'B', 18);
        $pdf->Cell(0, 10, $this->pdfText('Report Finanziario Giornaliero'), 0, 1, 'L');

    $pdf->SetFont('DejaVu Sans', '', 12);
        $pdf->SetTextColor(28, 37, 52);
        $pdf->Cell(0, 7, $this->pdfText('Data report: ' . $date->format('d/m/Y')), 0, 1, 'L');
        $pdf->Cell(0, 7, $this->pdfText('Generato il: ' . (new DateTimeImmutable('now'))->format('d/m/Y H:i')), 0, 1, 'L');
        $pdf->Ln(4);

        $pageWidth = null;
        if (method_exists($pdf, 'GetPageWidth')) {
            $pageWidth = (float) $pdf->GetPageWidth();
        } elseif (property_exists($pdf, 'w')) {
            $pageWidth = (float) $pdf->w;
        }
        $usableWidth = ($pageWidth ?? 297.0) - $pdf->lMargin - $pdf->rMargin;
        $columns = [
            [
                'ratio' => 0.09,
                'title' => 'Data',
                'align' => 'L',
                'value' => fn(array $item): string => $this->formatDateTime($item['data_riferimento'] ?? ''),
            ],
            [
                'ratio' => 0.17,
                'title' => 'Cliente',
                'align' => 'L',
                'value' => fn(array $item): string => $this->buildClientName($item),
                'fit' => true,
            ],
            [
                'ratio' => 0.06,
                'title' => 'Tipo',
                'align' => 'L',
                'value' => fn(array $item): string => (string) ($item['tipo_movimento'] ?? ''),
            ],
            [
                'ratio' => 0.19,
                'title' => 'Descrizione',
                'align' => 'L',
                'value' => fn(array $item): string => (string) ($item['descrizione'] ?? ''),
                'fit' => true,
            ],
            [
                'ratio' => 0.09,
                'title' => 'Riferimento',
                'align' => 'L',
                'value' => fn(array $item): string => (string) ($item['riferimento'] ?? ''),
                'fit' => true,
            ],
            [
                'ratio' => 0.07,
                'title' => 'Metodo',
                'align' => 'L',
                'value' => fn(array $item): string => (string) ($item['metodo'] ?? ''),
                'fit' => true,
            ],
            [
                'ratio' => 0.05,
                'title' => 'Stato',
                'align' => 'L',
                'value' => fn(array $item): string => (string) ($item['stato'] ?? ''),
                'fit' => true,
            ],
            [
                'ratio' => 0.09,
                'title' => 'Costo riv.',
                'align' => 'R',
                'value' => fn(array $item): string => $this->formatNullableCurrency($item['listino_costo_rivenditore'] ?? null),
            ],
            [
                'ratio' => 0.09,
                'title' => 'Costo cliente',
                'align' => 'R',
                'value' => fn(array $item): string => $this->formatNullableCurrency($item['listino_costo_cliente'] ?? null),
            ],
            [
                'ratio' => 0.05,
                'title' => 'Margine',
                'align' => 'R',
                'value' => fn(array $item): string => $this->formatNullableCurrency($item['listino_margine'] ?? null),
            ],
            [
                'ratio' => 0.05,
                'title' => 'Importo',
                'align' => 'R',
                'value' => fn(array $item): string => $this->formatCurrency((float) ($item['importo'] ?? 0)),
            ],
        ];

        foreach ($columns as &$column) {
            $column['width'] = round($usableWidth * (float) $column['ratio'], 2);
        }
        unset($column);

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
                foreach ($columns as $column) {
                    /** @var callable|null $valueCallback */
                    $valueCallback = $column['value'] ?? null;
                    $cellValue = $valueCallback ? $valueCallback($item) : '';
                    if (!empty($column['fit'])) {
                        $cellValue = $this->fitTextToWidth($pdf, $cellValue, (float) $column['width']);
                    }
                    $pdf->Cell($column['width'], 7, $this->pdfText($cellValue), 1, 0, $column['align']);
                }
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
        $pdf->Cell(60, 7, $this->pdfText('Totale Margine'), 0, 0, 'L');
        if ($margine >= 0) {
            $pdf->SetTextColor(21, 87, 36);
        } else {
            $pdf->SetTextColor(114, 28, 36);
        }
    $pdf->SetFont('DejaVu Sans', '', 11);
        $pdf->Cell(40, 7, $this->pdfText($this->formatCurrency($margine)), 0, 1, 'L');
        $pdf->SetTextColor(28, 37, 52);

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

        return $pdf->Output('', 'S');
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
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 18,
        ]);

        return $instance;
    }

    private function persistReportRecord(string $reportDate, float $entrate, float $uscite, float $saldo): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO daily_financial_reports (report_date, total_entrate, total_uscite, saldo, file_path, generated_at)
            VALUES (:report_date, :entrate, :uscite, :saldo, NULL, NOW())
            ON DUPLICATE KEY UPDATE total_entrate = VALUES(total_entrate), total_uscite = VALUES(total_uscite), saldo = VALUES(saldo), generated_at = VALUES(generated_at)');

        $stmt->execute([
            ':report_date' => $reportDate,
            ':entrate' => $entrate,
            ':uscite' => $uscite,
            ':saldo' => $saldo,
        ]);
    }

    private function logGeneration(string $reportDate, float $entrate, float $uscite, float $saldo): void
    {
        try {
            $payload = json_encode([
                'report_date' => $reportDate,
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

    private function formatNullableCurrency($value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return $this->formatCurrency((float) $value);
    }

    private function fitTextToWidth(object $pdf, string $value, float $width): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }

        $availableWidth = max(1.0, $width - 1.5);
        if (!method_exists($pdf, 'GetStringWidth')) {
            $maxChars = (int) max(1, floor($availableWidth / 2.4));
            return $this->trimText($text, max(2, $maxChars));
        }

        if ($pdf->GetStringWidth($text) <= $availableWidth) {
            return $text;
        }

        $ellipsis = '…';
        $low = 1;
        $high = mb_strlen($text, 'UTF-8');
        $best = '';

        while ($low <= $high) {
            $mid = (int) floor(($low + $high) / 2);
            $candidate = rtrim(mb_substr($text, 0, $mid, 'UTF-8')) . $ellipsis;
            if ($pdf->GetStringWidth($candidate) <= $availableWidth) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best !== '' ? $best : mb_substr($text, 0, 1, 'UTF-8') . $ellipsis;
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
