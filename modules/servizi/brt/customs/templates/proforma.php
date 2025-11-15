<?php
declare(strict_types=1);

/** @var array<string, mixed> $document */
/** @var array<string, mixed> $shipment */
/** @var array<string, string> $sender */
/** @var array<string, string> $consignee */
/** @var array<string, mixed> $customs */
/** @var array<string, mixed> $goods */

$escape = static function (mixed $value): string {
    if (!is_string($value)) {
        $value = (string) $value;
    }
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
};

$formatCurrency = static function (float $value, string $currency) use ($escape): string {
    $formatted = number_format($value, 2, ',', '.');
    return $escape($formatted . ' ' . strtoupper($currency));
};

$formatDateTime = static function (?string $value) use ($escape): string {
    if ($value === null || trim($value) === '') {
        return 'N/D';
    }
    return $escape($value);
};

$currency = strtoupper((string) ($goods['currency'] ?? 'EUR'));
$unitValue = (float) ($goods['unit_value'] ?? 0.0);
$totalValue = (float) ($goods['value'] ?? 0.0);
$parcels = (int) ($goods['parcels'] ?? 0);
$weightKg = (float) ($goods['weight_kg'] ?? 0.0);
$generatedAt = $document['generated_at'] ?? date('Y-m-d H:i:s');
$reference = $shipment['numeric_sender_reference'] ?? $shipment['id'] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?php echo $escape('Proforma Invoice - Spedizione #' . $reference); ?></title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #1a1a1a; }
        h1, h2, h3 { margin: 0; }
        h1 { font-size: 20pt; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #999; padding: 6px 8px; vertical-align: top; }
        th { background-color: #f0f0f0; text-transform: uppercase; font-size: 10pt; letter-spacing: 0.5px; }
        .section { margin-top: 18px; }
        .small { font-size: 9pt; color: #555; }
        .two-column { display: table; width: 100%; }
        .two-column > div { display: table-cell; width: 50%; vertical-align: top; padding: 4px 6px; }
        .badge { display: inline-block; padding: 2px 8px; background-color: #1d3557; color: #fff; font-size: 9pt; border-radius: 4px; text-transform: uppercase; }
        .mt-6 { margin-top: 6px; }
        .mt-12 { margin-top: 12px; }
    </style>
</head>
<body>
    <h1>Proforma / Commercial Invoice</h1>
    <p class="small">Documento generato automaticamente il <?php echo $formatDateTime($generatedAt); ?>.</p>

    <div class="section">
        <div class="two-column">
            <div>
                <h2>Mittente</h2>
                <strong><?php echo $escape($sender['company'] ?? ''); ?></strong><br>
                <?php echo $escape($sender['address'] ?? ''); ?><br>
                <?php echo $escape(($sender['zip'] ?? '') . ' ' . ($sender['city'] ?? '')); ?><br>
                <?php echo $escape($sender['country'] ?? ''); ?><br>
                <?php if (($sender['vat'] ?? '') !== ''): ?>Partita IVA: <?php echo $escape($sender['vat']); ?><br><?php endif; ?>
                <?php if (($sender['eori'] ?? '') !== ''): ?>EORI: <?php echo $escape($sender['eori']); ?><br><?php endif; ?>
                Cod. cliente BRT: <?php echo $escape($shipment['sender_customer_code'] ?? ''); ?><br>
            </div>
            <div>
                <h2>Destinatario</h2>
                <strong><?php echo $escape($consignee['company'] ?? ''); ?></strong><br>
                <?php echo $escape($consignee['address'] ?? ''); ?><br>
                <?php echo $escape(($consignee['zip'] ?? '') . ' ' . ($consignee['city'] ?? '')); ?><br>
                <?php echo $escape($consignee['country'] ?? ''); ?><br>
                <?php if (($consignee['vat'] ?? '') !== ''): ?>Partita IVA: <?php echo $escape($consignee['vat']); ?><br><?php endif; ?>
                <?php if (($consignee['eori'] ?? '') !== ''): ?>EORI: <?php echo $escape($consignee['eori']); ?><br><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>Descrizione merce</th>
                    <th>Codice HS</th>
                    <th>Origine</th>
                    <th>Incoterm</th>
                    <th>Colli</th>
                    <th>Peso (Kg)</th>
                    <th>Valore unitario</th>
                    <th>Valore totale</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong><?php echo $escape($goods['description'] ?? ''); ?></strong><br>
                        <span class="small">Categoria: <?php echo $escape($goods['category'] ?? ''); ?></span>
                    </td>
                    <td><?php echo $escape($goods['hs_code'] ?? ''); ?></td>
                    <td><?php echo $escape($goods['origin_country'] ?? ''); ?></td>
                    <td><span class="badge"><?php echo $escape($goods['incoterm'] ?? ''); ?></span></td>
                    <td><?php echo $escape((string) $parcels); ?></td>
                    <td><?php echo $escape(number_format($weightKg, 2, ',', '.')); ?></td>
                    <td><?php echo $formatCurrency($unitValue, $currency); ?></td>
                    <td><?php echo $formatCurrency($totalValue, $currency); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Riferimenti spedizione</h2>
        <table>
            <tbody>
                <tr>
                    <th>Rif. mittente</th>
                    <td><?php echo $escape($shipment['numeric_sender_reference'] ?? ''); ?></td>
                    <th>Rif. alfanumerico</th>
                    <td><?php echo $escape($shipment['alphanumeric_sender_reference'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Parcel ID</th>
                    <td><?php echo $escape($shipment['parcel_id'] ?? ''); ?></td>
                    <th>Data creazione</th>
                    <td><?php echo $formatDateTime($shipment['created_at'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Filiale partenza</th>
                    <td><?php echo $escape($shipment['departure_depot'] ?? ''); ?></td>
                    <th>Note doganali</th>
                    <td><?php echo $escape($goods['notes'] ?? ''); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="section small">Dichiaro che le informazioni fornite sono corrette e che i beni indicati sono di libera esportazione verso la Svizzera.</p>
</body>
</html>
