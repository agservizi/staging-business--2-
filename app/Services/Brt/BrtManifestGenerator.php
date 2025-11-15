<?php
declare(strict_types=1);

namespace App\Services\Brt;

use DateTimeImmutable;
use DateTimeInterface;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use const DIRECTORY_SEPARATOR;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

use function array_map;
use function array_filter;
use function class_exists;
use function count;
use function htmlspecialchars;
use function implode;
use function is_dir;
use function mkdir;
use function number_format;
use function sprintf;
use function str_replace;
use function strtoupper;

final class BrtManifestGenerator
{
    private const RELATIVE_DIRECTORY = 'uploads/brt/manifests';

    /**
     * @param array<int, array<string, mixed>> $shipments
     * @param array<string, mixed> $context
     * @return array{
     *     reference: string,
     *     relative_path: string,
     *     absolute_path: string,
     *     generated_at: DateTimeInterface
     * }
     */
    public function generate(array $shipments, array $context = []): array
    {
        if ($shipments === []) {
            throw new BrtException('Nessuna spedizione confermata disponibile per la generazione del borderò.');
        }

        if (!class_exists(Mpdf::class)) {
            throw new BrtException('Libreria mPDF non disponibile. Eseguire composer install.');
        }

        $timestamp = new DateTimeImmutable('now');

        $html = $this->buildHtml($shipments, $context, $timestamp);

        $mpdf = $this->createPdfInstance();
        $mpdf->SetTitle('Borderò BRT ' . $timestamp->format('Y-m-d H:i'));
        $mpdf->SetAuthor('Coresuite Business');
        $mpdf->WriteHTML($html);

        $this->ensureDirectoryExists();

        $filename = sprintf('bordero_brt_%s.pdf', $timestamp->format('Ymd_His'));
        $relativePath = self::RELATIVE_DIRECTORY . '/' . $filename;
        $absolutePath = $this->buildAbsolutePath($filename);

        try {
            $mpdf->Output($absolutePath, Destination::FILE);
        } catch (MpdfException $exception) {
            throw new BrtException('Impossibile salvare il borderò BRT: ' . $exception->getMessage(), 0, $exception);
        }

        $reference = sprintf('BRT-%s', $timestamp->format('Ymd-His'));

        return [
            'reference' => $reference,
            'relative_path' => $this->normalizeRelativePath($relativePath),
            'absolute_path' => $absolutePath,
            'generated_at' => $timestamp,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     * @param array<string, mixed> $context
     */
    private function buildHtml(array $shipments, array $context, DateTimeInterface $generatedAt): string
    {
        $senderCode = (string) ($context['senderCustomerCode'] ?? ($shipments[0]['sender_customer_code'] ?? ''));
        $departureDepot = (string) ($context['departureDepot'] ?? ($shipments[0]['departure_depot'] ?? ''));

        $rows = [];
        $totalParcels = 0;
        $totalWeight = 0.0;
        $totalVolume = 0.0;

        foreach ($shipments as $index => $shipment) {
            $rowNumber = $index + 1;
            $parcels = (int) ($shipment['number_of_parcels'] ?? 0);
            $weight = (float) ($shipment['weight_kg'] ?? 0);
            $volume = (float) ($shipment['volume_m3'] ?? 0);

            $totalParcels += $parcels;
            $totalWeight += $weight;
            $totalVolume += $volume;

            $rows[] = sprintf(
                '<tr>' .
                    '<td class="row-num">%d</td>' .
                    '<td class="text-start"><div class="fw">%s</div><div class="muted">%s</div></td>' .
                    '<td class="text-start"><div class="fw">%s</div><div class="muted">%s</div></td>' .
                    '<td class="text-start">%s</td>' .
                    '<td class="text-center">%s</td>' .
                    '<td class="text-center">%s</td>' .
                    '<td class="text-end">%s</td>' .
                    '<td class="text-end">%s</td>' .
                '</tr>',
                $rowNumber,
                $this->escape($shipment['alphanumeric_sender_reference'] ?? ''),
                $this->escape((string) ($shipment['numeric_sender_reference'] ?? '')),
                $this->escape($shipment['consignee_name'] ?? ''),
                $this->escape($this->formatAddress($shipment)),
                $this->escape($shipment['parcel_id'] ?? ''),
                $this->escape((string) ($shipment['departure_depot'] ?? '')),
                $this->formatNumber($parcels, 0),
                $this->formatNumber($weight, 2)
            );
        }

        $rowsHtml = implode('', $rows);

        $totalsHtml = sprintf(
            '<tr class="totals">' .
                '<td colspan="6" class="text-end">Totali</td>' .
                '<td class="text-end">%s</td>' .
                '<td class="text-end">%s Kg</td>' .
            '</tr>' .
            '<tr class="totals muted">' .
                '<td colspan="6" class="text-end">Volume complessivo</td>' .
                '<td colspan="2" class="text-end">%s m³</td>' .
            '</tr>',
            $this->formatNumber($totalParcels, 0),
            $this->formatNumber($totalWeight, 2),
            $this->formatNumber($totalVolume, 3)
        );

        $generatedAtText = $generatedAt->format('d/m/Y H:i');

        $css = $this->buildStyles();

        return sprintf(
            '<style>%s</style>' .
            '<div class="header">' .
                '<div class="title">Borderò spedizioni BRT</div>' .
                '<div class="meta">Generato il %s &mdash; Mittente %s &mdash; Filiale %s</div>' .
            '</div>' .
            '<table class="manifest-table">' .
                '<thead>' .
                    '<tr>' .
                        '<th>#</th>' .
                        '<th>Riferimenti mittente</th>' .
                        '<th>Destinatario</th>' .
                        '<th>Indirizzo</th>' .
                        '<th>ParcelID</th>' .
                        '<th>Filiale partenza</th>' .
                        '<th>Colli</th>' .
                        '<th>Peso (Kg)</th>' .
                    '</tr>' .
                '</thead>' .
                '<tbody>%s%s</tbody>' .
            '</table>' .
            '<div class="signature">' .
                '<div>Firma corriere __________________________</div>' .
                '<div>Firma mittente __________________________</div>' .
            '</div>',
            $css,
            $this->escape($generatedAtText),
            $this->escape($senderCode),
            $this->escape($departureDepot),
            $rowsHtml,
            $totalsHtml
        );
    }

    private function buildStyles(): string
    {
        return <<<'CSS'
body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f1f1f; }
.header { text-align: center; margin-bottom: 12px; }
.header .title { font-size: 20px; font-weight: 700; text-transform: uppercase; }
.header .meta { font-size: 11px; color: #555; margin-top: 4px; }
.manifest-table { width: 100%; border-collapse: collapse; }
.manifest-table th { background: #f3f4f6; font-weight: 600; border: 0.4pt solid #9ca3af; padding: 6px 4px; font-size: 10px; }
.manifest-table td { border: 0.4pt solid #d1d5db; padding: 6px 4px; font-size: 10px; }
.manifest-table td .fw { font-weight: 600; }
.manifest-table td .muted { color: #6b7280; font-size: 9px; }
.manifest-table td.text-center { text-align: center; }
.manifest-table td.text-end { text-align: right; }
.manifest-table td.text-start { text-align: left; }
.manifest-table .row-num { width: 24px; text-align: center; font-weight: 600; }
.manifest-table tr:nth-child(even) td { background: #f9fafb; }
.manifest-table tr.totals td { background: #111827; color: #f9fafb; font-size: 11px; font-weight: 600; }
.manifest-table tr.totals.muted td { background: #374151; color: #f3f4f6; font-weight: 400; }
.signature { margin-top: 30px; display: flex; justify-content: space-between; font-size: 11px; }
CSS;
    }

    private function formatAddress(array $shipment): string
    {
        $parts = array_map(
            static fn ($value) => trim((string) $value),
            [
                $shipment['consignee_address'] ?? '',
                sprintf('%s %s', $shipment['consignee_zip'] ?? '', $shipment['consignee_city'] ?? ''),
                strtoupper((string) ($shipment['consignee_province'] ?? '')),
                strtoupper((string) ($shipment['consignee_country'] ?? '')),
            ]
        );

        return implode(' ', array_filter($parts, static fn ($value) => $value !== ''));
    }

    private function formatNumber(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function ensureDirectoryExists(): void
    {
        $directory = $this->buildDirectoryPath();
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new BrtException('Impossibile creare la cartella per i borderò BRT.');
        }
    }

    private function buildDirectoryPath(): string
    {
        $root = dirname(__DIR__, 3);
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_DIRECTORY);
    }

    private function buildAbsolutePath(string $filename): string
    {
        return $this->buildDirectoryPath() . DIRECTORY_SEPARATOR . $filename;
    }

    private function normalizeRelativePath(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    private function createPdfInstance(): Mpdf
    {
        try {
            return new Mpdf([
                'format' => 'A4-L',
                'margin_top' => 16,
                'margin_bottom' => 16,
                'margin_left' => 12,
                'margin_right' => 12,
                'tempDir' => $this->resolveTempDir(),
            ]);
        } catch (MpdfException $exception) {
            throw new BrtException('Impossibile inizializzare la libreria mPDF: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function resolveTempDir(): string
    {
        $root = dirname(__DIR__, 3);
        $temp = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($temp) && !mkdir($temp, 0775, true) && !is_dir($temp)) {
            throw new BrtException('Impossibile creare la cartella temporanea per mPDF.');
        }
        return $temp;
    }
}
