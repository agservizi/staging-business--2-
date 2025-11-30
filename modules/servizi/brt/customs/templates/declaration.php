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

$formatNumber = static function (float $value, int $decimals = 2) use ($escape): string {
    return $escape(number_format($value, $decimals, ',', '.'));
};

$generatedAt = $document['generated_at'] ?? date('Y-m-d H:i:s');
$reference = $shipment['numeric_sender_reference'] ?? $shipment['id'] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?php echo $escape('Dichiarazione doganale - Spedizione #' . $reference); ?></title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #1a1a1a; }
        h1 { font-size: 18pt; margin: 0 0 6px 0; text-transform: uppercase; }
        h2 { font-size: 13pt; margin: 18px 0 6px 0; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #666; padding: 6px 8px; }
        th { background: #f6f6f6; font-size: 10pt; }
        .small { font-size: 9pt; color: #555; }
        .signature { margin-top: 30px; }
        .signature-line { width: 60%; border-top: 1px solid #000; margin-top: 40px; }
        ul { margin: 0; padding-left: 18px; }
    </style>
</head>
<body>
    <h1>Dichiarazione doganale di esportazione</h1>
    <p class="small">Documento generato automaticamente il <?php echo $escape($generatedAt); ?>. Destinazione: Svizzera.</p>

    <h2>Dati mittente</h2>
    <table>
        <tbody>
            <tr>
                <th>Ragione sociale</th>
                <td><?php echo $escape($sender['company'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Indirizzo</th>
                <td><?php echo $escape(($sender['address'] ?? '') . ' - ' . ($sender['zip'] ?? '') . ' ' . ($sender['city'] ?? '') . ' (' . ($sender['province'] ?? '') . ')'); ?></td>
            </tr>
            <tr>
                <th>Nazione</th>
                <td><?php echo $escape($sender['country'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Partita IVA / EORI</th>
                <td><?php echo $escape(trim(($sender['vat'] ?? '') . ' ' . ($sender['eori'] ?? ''))); ?></td>
            </tr>
        </tbody>
    </table>

    <h2>Dati destinatario</h2>
    <table>
        <tbody>
            <tr>
                <th>Ragione sociale</th>
                <td><?php echo $escape($consignee['company'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Indirizzo</th>
                <td><?php echo $escape(($consignee['address'] ?? '') . ' - ' . ($consignee['zip'] ?? '') . ' ' . ($consignee['city'] ?? '') . ' (' . ($consignee['province'] ?? '') . ')'); ?></td>
            </tr>
            <tr>
                <th>Nazione</th>
                <td><?php echo $escape($consignee['country'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Partita IVA / EORI</th>
                <td><?php echo $escape(trim(($consignee['vat'] ?? '') . ' ' . ($consignee['eori'] ?? ''))); ?></td>
            </tr>
        </tbody>
    </table>

    <h2>Informazioni merce</h2>
    <table>
        <tbody>
            <tr>
                <th>Descrizione dettagliata</th>
                <td><?php echo $escape($goods['description'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Categoria</th>
                <td><?php echo $escape($goods['category'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Codice HS</th>
                <td><?php echo $escape($goods['hs_code'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Paese di origine</th>
                <td><?php echo $escape($goods['origin_country'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Incoterm</th>
                <td><?php echo $escape($goods['incoterm'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>Numero colli</th>
                <td><?php echo $escape((string) ($goods['parcels'] ?? '')); ?></td>
            </tr>
            <tr>
                <th>Peso netto (Kg)</th>
                <td><?php echo $formatNumber((float) ($goods['weight_kg'] ?? 0), 3); ?></td>
            </tr>
            <tr>
                <th>Valore dichiarato</th>
                <td><?php echo $escape(number_format((float) ($goods['value'] ?? 0), 2, ',', '.') . ' ' . strtoupper((string) ($goods['currency'] ?? 'EUR'))); ?></td>
            </tr>
        </tbody>
    </table>

    <h2>Dichiarazione</h2>
    <p>Il sottoscritto dichiara che:</p>
    <ul>
        <li>le merci sopra descritte sono di libera esportazione verso la Svizzera;</li>
        <li>le informazioni fornite sono veritiere e corrispondono alla documentazione commerciale;</li>
        <li>i valori indicati rappresentano il valore reale delle merci ai fini doganali;</li>
        <li>l&#39;incoterm selezionato riflette gli accordi commerciali con il destinatario.</li>
    </ul>
    <?php if (($goods['notes'] ?? '') !== ''): ?>
        <p class="small"><strong>Note aggiuntive:</strong> <?php echo $escape($goods['notes']); ?></p>
    <?php endif; ?>

    <div class="signature">
        <p class="small">Firma autorizzata</p>
        <div class="signature-line"></div>
        <p class="small">Nome e cognome, qualifica e timbro aziendale</p>
    </div>
</body>
</html>
