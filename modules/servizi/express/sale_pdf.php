<?php
declare(strict_types=1);

use Mpdf\Mpdf;
use Mpdf\MpdfException;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

express_module_require_access($pdo, (int) ($_SESSION['user_id'] ?? 0));

$saleId = (int) ($_GET['id'] ?? 0);
if ($saleId <= 0) {
    add_flash('warning', 'Vendita non valida.');
    header('Location: sales.php');
    exit;
}

express_module_bootstrap_schema($pdo);

$sale = express_module_sale_detail($pdo, $saleId);
if ($sale === null) {
    add_flash('warning', 'Vendita non trovata.');
    header('Location: sales.php');
    exit;
}

if (!class_exists(Mpdf::class)) {
    add_flash('warning', 'Libreria PDF non disponibile.');
    header('Location: view_sale.php?id=' . $saleId);
    exit;
}

$companyHeader = express_module_company_print_header($pdo);
$customerLabel = express_module_sale_customer_label($sale);
$operatorLabel = trim((string) (($sale['user_nome'] ?? '') . ' ' . ($sale['user_cognome'] ?? '')));
$documentNote = express_module_sale_document_note($sale);

try {
    $mpdf = new Mpdf([
        'format' => [80, 180],
        'margin_top' => 6,
        'margin_bottom' => 6,
        'margin_left' => 5,
        'margin_right' => 5,
        'default_font' => 'dejavusans',
    ]);
} catch (MpdfException $exception) {
    add_flash('warning', 'Impossibile inizializzare il PDF: ' . $exception->getMessage());
    header('Location: view_sale.php?id=' . $saleId);
    exit;
}

$styles = '<style>
    body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #111; }
    .center { text-align: center; }
    .company-name { font-size: 15pt; font-weight: bold; text-transform: uppercase; line-height: 1.2; margin-bottom: 4px; }
    .company-line, .meta-line, .footer-line { font-size: 9pt; line-height: 1.4; }
    .document-title { margin-top: 8px; font-size: 10pt; font-weight: bold; text-transform: uppercase; }
    .meta { margin-top: 8px; }
    .separator { border-top: 1px dashed #111; margin: 10px 0; }
    .item { margin-bottom: 8px; }
    .item-table, .total-table { width: 100%; border-collapse: collapse; }
    .item-table td, .total-table td { vertical-align: top; padding: 0; }
    .item-description { font-size: 10pt; line-height: 1.35; width: 75%; }
    .item-price { font-size: 10pt; font-weight: bold; text-align: right; white-space: nowrap; width: 25%; }
    .item-meta { font-size: 8pt; color: #333; margin-top: 2px; }
    .note { font-size: 9pt; line-height: 1.45; }
    .total-label, .total-value { font-size: 14pt; font-weight: bold; }
    .total-value { text-align: right; }
    .payment-line { margin-top: 4px; font-size: 10pt; font-weight: bold; }
</style>';

$html = $styles;
$html .= '<div class="center">';
$html .= '<div class="company-name">' . sanitize_output((string) $companyHeader['company_name']) . '</div>';
foreach (($companyHeader['address_lines'] ?? []) as $line) {
    $html .= '<div class="company-line">' . sanitize_output((string) $line) . '</div>';
}
if (($companyHeader['phone'] ?? '') !== '') {
    $html .= '<div class="company-line">Tel. ' . sanitize_output((string) $companyHeader['phone']) . '</div>';
}
if (($companyHeader['email'] ?? '') !== '') {
    $html .= '<div class="company-line">' . sanitize_output((string) $companyHeader['email']) . '</div>';
}
$html .= '<div class="document-title">Documento gestionale #' . (int) $sale['id'] . '</div>';
$html .= '</div>';

$html .= '<div class="meta">';
$html .= '<div class="meta-line">Data: ' . sanitize_output(format_datetime_locale((string) ($sale['data_vendita'] ?? ''))) . '</div>';
$html .= '<div class="meta-line">Operatore: ' . sanitize_output($operatorLabel !== '' ? $operatorLabel : 'Non assegnato') . '</div>';
if ($customerLabel !== '') {
    $html .= '<div class="meta-line">Cliente: ' . sanitize_output($customerLabel) . '</div>';
}
$html .= '</div>';

$html .= '<div class="separator"></div>';

foreach (($sale['items'] ?? []) as $item) {
    $metaParts = [];
    if (($item['operatore'] ?? '') !== '') {
        $metaParts[] = (string) $item['operatore'];
    }
    if (($item['iccid'] ?? '') !== '') {
        $metaParts[] = 'ICCID ' . (string) $item['iccid'];
    }

    $description = (string) ($item['descrizione'] ?? '');
    if ((int) ($item['quantita'] ?? 1) > 1) {
        $description .= ' x' . (int) ($item['quantita'] ?? 1);
    }

    $html .= '<div class="item">';
    $html .= '<table class="item-table"><tr>';
    $html .= '<td class="item-description">' . sanitize_output($description) . '</td>';
    $html .= '<td class="item-price">&euro; ' . number_format((float) ($item['totale_riga'] ?? 0), 2, ',', '.') . '</td>';
    $html .= '</tr></table>';
    if ($metaParts !== []) {
        $html .= '<div class="item-meta">' . sanitize_output(implode(' · ', $metaParts)) . '</div>';
    }
    $html .= '</div>';
}

$html .= '<div class="separator"></div>';
$html .= '<div class="note">' . nl2br(sanitize_output($documentNote)) . '</div>';

if (!empty($sale['note'])) {
    $html .= '<div class="separator"></div>';
    $html .= '<div class="note"><strong>Note:</strong><br>' . nl2br(sanitize_output((string) $sale['note'])) . '</div>';
}

$html .= '<div class="separator"></div>';
$html .= '<table class="total-table"><tr>';
$html .= '<td class="total-label">Totale:</td>';
$html .= '<td class="total-value">&euro; ' . number_format((float) ($sale['totale'] ?? 0), 2, ',', '.') . '</td>';
$html .= '</tr></table>';
$html .= '<div class="payment-line">Pagamento: ' . sanitize_output((string) ($sale['metodo_pagamento'] ?? '')) . '</div>';
$html .= '<div class="center" style="margin-top: 10px;"><div class="footer-line">Grazie per il tuo acquisto!</div></div>';

try {
    $mpdf->SetTitle('Documento gestionale #' . (int) $sale['id']);
    $mpdf->WriteHTML($html);
    $mpdf->Output('documento_gestionale_' . (int) $sale['id'] . '.pdf', 'I');
    exit;
} catch (MpdfException $exception) {
    add_flash('warning', 'Impossibile generare il PDF: ' . $exception->getMessage());
    header('Location: view_sale.php?id=' . $saleId);
    exit;
}
