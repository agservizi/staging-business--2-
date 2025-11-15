<?php
declare(strict_types=1);

namespace App\Services\Brt;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;
use Throwable;

use function env;
use function is_dir;
use function is_file;
use function is_string;
use function rtrim;

final class BrtCustomsDocumentService
{
    private string $templateDirectory;

    private string $outputDirectory;

    private const DEFAULT_MARGIN_LEFT = 15;

    private const DEFAULT_MARGIN_RIGHT = 15;

    private const DEFAULT_MARGIN_TOP = 18;

    private const DEFAULT_MARGIN_BOTTOM = 18;

    private const DEFAULT_MARGIN_HEADER = 8;

    private const DEFAULT_MARGIN_FOOTER = 8;

    public function __construct(string $templateDirectory, string $outputDirectory)
    {
        $this->templateDirectory = rtrim($templateDirectory, '/\\');
        $this->outputDirectory = rtrim($outputDirectory, '/\\');
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $customs
     *
     * @return array{invoice: string, declaration: string}
     */
    public function generate(array $shipment, array $customs): array
    {
        $this->ensureDirectory($this->outputDirectory);

        $timestamp = date('Ymd_His');
        $context = $this->buildContext($shipment, $customs);

        $invoiceFile = $this->renderTemplateToPdf('proforma.php', $context, 'proforma_invoice_' . $timestamp . '.pdf');
        $declarationFile = $this->renderTemplateToPdf('declaration.php', $context, 'customs_declaration_' . $timestamp . '.pdf');

        return [
            'invoice' => $invoiceFile,
            'declaration' => $declarationFile,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTemplateToPdf(string $templateName, array $context, string $filename): string
    {
        $templatePath = $this->templateDirectory . '/' . ltrim($templateName, '/\\');
        if (!is_file($templatePath)) {
            throw new RuntimeException('Template doganale mancante: ' . $templateName);
        }

        $html = $this->renderHtml($templatePath, $context);

        try {
            $mpdf = new Mpdf([
                'format' => 'A4',
                'margin_left' => self::DEFAULT_MARGIN_LEFT,
                'margin_right' => self::DEFAULT_MARGIN_RIGHT,
                'margin_top' => self::DEFAULT_MARGIN_TOP,
                'margin_bottom' => self::DEFAULT_MARGIN_BOTTOM,
                'margin_header' => self::DEFAULT_MARGIN_HEADER,
                'margin_footer' => self::DEFAULT_MARGIN_FOOTER,
            ]);
            $mpdf->SetAuthor($context['sender']['company'] ?? '');
            $mpdf->SetTitle($context['document']['title'] ?? 'Documentazione doganale');
            $mpdf->WriteHTML($html);
            $mpdf->Output($this->outputDirectory . '/' . $filename, Destination::FILE);
        } catch (MpdfException $exception) {
            throw new RuntimeException('Generazione PDF doganale non riuscita: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $filename;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderHtml(string $templatePath, array $context): string
    {
        ob_start();
        try {
            $document = $context['document'] ?? [];
            $shipment = $context['shipment'] ?? [];
            $sender = $context['sender'] ?? [];
            $consignee = $context['consignee'] ?? [];
            $customs = $context['customs'] ?? [];
            $goods = $context['goods'] ?? [];
            require $templatePath;
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $customs
     *
     * @return array<string, mixed>
     */
    private function buildContext(array $shipment, array $customs): array
    {
        $sender = $this->buildSenderInfo($customs);
        $consignee = $this->buildConsigneeInfo($shipment, $customs);
        $goods = $this->buildGoodsInfo($shipment, $customs);

        $documentTitle = 'Documenti doganali spedizione #' . ($shipment['id'] ?? '');

        return [
            'document' => [
                'title' => $documentTitle,
                'generated_at' => date('Y-m-d H:i:s'),
            ],
            'shipment' => $shipment,
            'sender' => $sender,
            'consignee' => $consignee,
            'customs' => $customs,
            'goods' => $goods,
        ];
    }

    /**
     * @param array<string, mixed> $customs
     *
     * @return array<string, string>
     */
    private function buildSenderInfo(array $customs): array
    {
        $company = $this->stringEnv('BRT_SENDER_COMPANY_NAME', 'Mittente non configurato');
        $address = $this->stringEnv('BRT_SENDER_ADDRESS', '');
        $zip = $this->stringEnv('BRT_SENDER_ZIP', '');
        $city = $this->stringEnv('BRT_SENDER_CITY', '');
        $province = $this->stringEnv('BRT_SENDER_PROVINCE', '');
        $country = $this->stringEnv('BRT_SENDER_COUNTRY', 'IT');

        $vat = $customs['sender_vat'] ?? $this->stringEnv('BRT_SENDER_VAT', '');
        $eori = $customs['sender_eori'] ?? $this->stringEnv('BRT_SENDER_EORI', '');

        return [
            'company' => is_string($company) ? $company : '',
            'address' => is_string($address) ? $address : '',
            'zip' => is_string($zip) ? $zip : '',
            'city' => is_string($city) ? $city : '',
            'province' => is_string($province) ? $province : '',
            'country' => is_string($country) ? $country : 'IT',
            'vat' => is_string($vat) ? $vat : '',
            'eori' => is_string($eori) ? $eori : '',
        ];
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $customs
     *
     * @return array<string, string>
     */
    private function buildConsigneeInfo(array $shipment, array $customs): array
    {
        $receiverVat = $customs['receiver_vat'] ?? '';
        $receiverEori = $customs['receiver_eori'] ?? '';

        return [
            'company' => $this->stringValue($shipment['consignee_name'] ?? ''),
            'address' => $this->stringValue($shipment['consignee_address'] ?? ''),
            'zip' => $this->stringValue($shipment['consignee_zip'] ?? ''),
            'city' => $this->stringValue($shipment['consignee_city'] ?? ''),
            'province' => $this->stringValue($shipment['consignee_province'] ?? ''),
            'country' => strtoupper($this->stringValue($shipment['consignee_country'] ?? '')),
            'vat' => $this->stringValue($receiverVat),
            'eori' => $this->stringValue($receiverEori),
        ];
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $customs
     *
     * @return array<string, mixed>
     */
    private function buildGoodsInfo(array $shipment, array $customs): array
    {
        $value = (float) ($customs['goods_value_number'] ?? $customs['goods_value'] ?? 0);
        $unitValue = 0.0;
        $parcels = (int) ($shipment['number_of_parcels'] ?? 0);
        if ($parcels > 0 && $value > 0) {
            $unitValue = $value / $parcels;
        }

        return [
            'description' => $this->stringValue($customs['goods_description'] ?? ''),
            'category' => $this->stringValue($customs['category'] ?? ''),
            'hs_code' => $this->stringValue($customs['hs_code'] ?? ''),
            'incoterm' => strtoupper($this->stringValue($customs['incoterm'] ?? '')), 
            'origin_country' => strtoupper($this->stringValue($customs['goods_origin_country'] ?? '')),
            'value' => $value,
            'unit_value' => $unitValue,
            'currency' => strtoupper($this->stringValue($customs['goods_currency'] ?? 'EUR')),
            'weight_kg' => (float) ($shipment['weight_kg'] ?? 0),
            'parcels' => $parcels,
            'notes' => $this->stringValue($customs['additional_notes'] ?? ''),
        ];
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossibile creare la cartella per i documenti doganali: ' . $directory);
        }
    }

    private function stringEnv(string $key, string $default): string
    {
        $value = env($key, $default);
        if (!is_string($value)) {
            return $default;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? $default : $trimmed;
    }

    private function stringValue(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }
}
