<?php
declare(strict_types=1);

use Mpdf\Mpdf;
use Mpdf\MpdfException;

if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
    define('CORESUITE_BRT_BOOTSTRAP', true);
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$shipmentId = (int) ($_GET['id'] ?? 0);
if ($shipmentId <= 0) {
    add_flash('warning', 'Seleziona una spedizione valida.');
    header('Location: index.php');
    exit;
}

$shipment = brt_get_shipment($shipmentId);
if ($shipment === null) {
    add_flash('warning', 'Spedizione BRT non trovata.');
    header('Location: index.php');
    exit;
}

if (empty($shipment['label_path'])) {
    add_flash('warning', 'Genera prima l\'etichetta per questa spedizione.');
    header('Location: index.php');
    exit;
}

try {
    $mpdf = new Mpdf([
        'format' => 'A4',
        'margin_top' => 18,
        'margin_bottom' => 18,
        'margin_left' => 15,
        'margin_right' => 15,
    ]);
} catch (MpdfException $exception) {
    add_flash('warning', 'Impossibile inizializzare la libreria PDF: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}

$agencyName = 'AG SERVIZI VIA PLINIO 72 DI CAVALIERE CARMINE';
$agencyAddress = 'Via Plinio il Vecchio 72, 80053 Castellammare di Stabia (NA)';
$disclaimer = "Il pacco è sotto la tutela di $agencyName sita in $agencyAddress e, dal momento in cui il pacco verrà affidato al corriere incaricato al ritiro, l'agenzia non avrà più nulla a pretendere su eventuali smarrimenti o distruzioni del pacco/collo.";

$generatedAt = format_datetime_locale(date('Y-m-d H:i:s'));
$consignee = trim((string) ($shipment['consignee_name'] ?? ''));
$addressParts = array_filter([
    (string) ($shipment['consignee_address'] ?? ''),
    trim(sprintf('%s %s', $shipment['consignee_zip'] ?? '', $shipment['consignee_city'] ?? '')),
    strtoupper((string) ($shipment['consignee_country'] ?? '')),
]);
$consigneeAddress = implode(', ', $addressParts);
$parcelId = (string) ($shipment['parcel_id'] ?? '');
$tracking = (string) ($shipment['tracking_by_parcel_id'] ?? '');
$numericReference = (string) ($shipment['numeric_sender_reference'] ?? '');

$styles = '<style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #111; }
    .header { text-align: center; margin-bottom: 20px; }
    .header .title { font-size: 20px; margin: 0; text-transform: uppercase; }
    .meta { text-align: center; color: #555; margin-bottom: 24px; }
    .section { margin-bottom: 18px; }
    .section-title { font-weight: bold; text-transform: uppercase; font-size: 12px; margin-bottom: 6px; }
    table.details { width: 100%; border-collapse: collapse; }
    table.details td { padding: 6px 8px; border: 1px solid #d1d5db; vertical-align: top; font-size: 11px; }
    .disclaimer { border: 1px solid #111; padding: 14px; line-height: 1.5; font-size: 12px; }
    .signature { margin-top: 36px; display: flex; justify-content: space-between; }
    .signature div { width: 45%; text-align: center; }
    .muted { color: #555; }
</style>';

$html = $styles;
$html .= '<div class="header">'
    . '<div class="title">Comunicazione al cliente</div>'
    . '<div>' . sanitize_output($agencyName) . '</div>'
    . '<div class="muted">' . sanitize_output($agencyAddress) . '</div>'
    . '</div>';

$html .= '<div class="meta">Documento generato il ' . sanitize_output($generatedAt) . '</div>';

$html .= '<div class="section">
    <div class="section-title">Dettagli spedizione</div>
    <table class="details">
        <tr>
            <td><strong>ID spedizione</strong><br>#' . (int) $shipment['id'] . '</td>
            <td><strong>Rif. mittente</strong><br>' . sanitize_output($numericReference) . '</td>
        </tr>
        <tr>
            <td><strong>Destinatario</strong><br>' . sanitize_output($consignee) . '</td>
            <td><strong>Indirizzo</strong><br>' . sanitize_output($consigneeAddress) . '</td>
        </tr>
        <tr>
            <td><strong>ParcelID</strong><br>' . sanitize_output($parcelId) . '</td>
            <td><strong>Tracking</strong><br>' . sanitize_output($tracking) . '</td>
        </tr>
    </table>
</div>';

$html .= '<div class="section">
    <div class="section-title">Tutela del pacco</div>
    <div class="disclaimer">' . sanitize_output($disclaimer) . '</div>
</div>';

$html .= '<div class="signature">
    <div>
        _______________________________<br>
        Firma Cliente
    </div>
    <div>
        _______________________________<br>
        Firma per ' . sanitize_output($agencyName) . '
    </div>
</div>';

try {
    $mpdf->WriteHTML($html);
    $filename = sprintf('comunicazione_cliente_spedizione_%d.pdf', $shipmentId);
    $mpdf->Output($filename, 'I');
    exit;
} catch (MpdfException $exception) {
    brt_log_event('error', 'Errore generazione documento tutela cliente', [
        'shipment_id' => $shipmentId,
        'error' => $exception->getMessage(),
    ]);
    add_flash('warning', 'Impossibile generare il documento: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}
