<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

express_module_require_access($pdo, (int) ($_SESSION['user_id'] ?? 0));

$saleId = (int) ($_GET['id'] ?? 0);
$autoPrint = (int) ($_GET['autoprint'] ?? 0) === 1;
$embedded = (int) ($_GET['embedded'] ?? 0) === 1;

express_module_bootstrap_schema($pdo);

$sale = $saleId > 0 ? express_module_sale_detail($pdo, $saleId) : null;
if ($sale === null) {
    add_flash('warning', 'Vendita non trovata.');
    header('Location: ' . express_module_url('sales'));
    exit;
}

$companyHeader = express_module_company_print_header($pdo);
$customerLabel = express_module_sale_customer_label($sale);
$operatorLabel = trim((string) (($sale['user_nome'] ?? '') . ' ' . ($sale['user_cognome'] ?? '')));
$documentNote = express_module_sale_document_note($sale);
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documento gestionale #<?php echo (int) $sale['id']; ?></title>
    <style>
        :root {
            color-scheme: light;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Helvetica Neue", Arial, sans-serif;
            background: <?php echo $embedded ? "'#ffffff'" : "'#eef1f5'"; ?>;
            color: #111;
        }

        .page {
            max-width: 480px;
            margin: 0 auto;
            padding: <?php echo $embedded ? '0' : '24px 16px 40px'; ?>;
        }

        .toolbar {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .toolbar a,
        .toolbar button {
            appearance: none;
            border: 0;
            border-radius: 999px;
            padding: 10px 16px;
            font: inherit;
            cursor: pointer;
            text-decoration: none;
        }

        .toolbar .primary {
            background: #111;
            color: #fff;
        }

        .toolbar .secondary {
            background: #fff;
            color: #111;
            border: 1px solid #d8dce3;
        }

        .receipt {
            width: 80mm;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            color: #111;
            padding: 18px 14px 22px;
            box-shadow: 0 12px 40px rgba(10, 20, 40, 0.12);
        }

        .center {
            text-align: center;
        }

        .company-name {
            font-size: 20px;
            font-weight: 800;
            line-height: 1.2;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .company-line,
        .meta-line,
        .footer-line {
            font-size: 12px;
            line-height: 1.45;
        }

        .document-title {
            margin-top: 12px;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .meta {
            margin-top: 10px;
        }

        .separator {
            border-top: 1px dashed #111;
            margin: 14px 0;
        }

        .item + .item {
            margin-top: 12px;
        }

        .item-row,
        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: flex-start;
        }

        .item-description {
            font-size: 14px;
            line-height: 1.35;
            flex: 1 1 auto;
            min-width: 0;
        }

        .item-price {
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            text-align: right;
        }

        .item-meta {
            font-size: 11px;
            color: #333;
            margin-top: 4px;
            line-height: 1.4;
        }

        .note {
            font-size: 12px;
            line-height: 1.45;
        }

        .total-label,
        .total-value {
            font-size: 18px;
            font-weight: 800;
        }

        .payment-line {
            margin-top: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        .customer-line {
            margin-top: 8px;
            font-size: 12px;
        }

        .footer {
            margin-top: 14px;
        }

        @page {
            size: 80mm auto;
            margin: 4mm;
        }

        @media print {
            body {
                background: #fff;
            }

            .page {
                max-width: none;
                padding: 0;
                margin: 0;
            }

            .toolbar {
                display: none;
            }

            .receipt {
                width: 72mm;
                padding: 0;
                box-shadow: none;
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php if (!$embedded): ?>
            <div class="toolbar">
                <button class="primary" type="button" onclick="window.print();">Stampa</button>
                <a class="secondary" href="<?php echo sanitize_output(express_module_url('view_sale', ['id' => (int) $sale['id']])); ?>">Dettaglio vendita</a>
                <a class="secondary" href="<?php echo sanitize_output(express_module_url('create_sale')); ?>">Nuova vendita</a>
            </div>
        <?php endif; ?>

        <article class="receipt">
            <header class="center">
                <div class="company-name"><?php echo nl2br(sanitize_output((string) $companyHeader['company_name'])); ?></div>
                <?php foreach (($companyHeader['address_lines'] ?? []) as $line): ?>
                    <div class="company-line"><?php echo sanitize_output((string) $line); ?></div>
                <?php endforeach; ?>
                <?php if (($companyHeader['phone'] ?? '') !== ''): ?>
                    <div class="company-line">Tel. <?php echo sanitize_output((string) $companyHeader['phone']); ?></div>
                <?php endif; ?>
                <?php if (($companyHeader['email'] ?? '') !== ''): ?>
                    <div class="company-line"><?php echo sanitize_output((string) $companyHeader['email']); ?></div>
                <?php endif; ?>

                <div class="document-title">Documento gestionale #<?php echo (int) $sale['id']; ?></div>
            </header>

            <section class="meta">
                <div class="meta-line">Data: <?php echo sanitize_output(format_datetime_locale((string) ($sale['data_vendita'] ?? ''))); ?></div>
                <div class="meta-line">Operatore: <?php echo sanitize_output($operatorLabel !== '' ? $operatorLabel : 'Non assegnato'); ?></div>
                <?php if ($customerLabel !== ''): ?>
                    <div class="customer-line">Cliente: <?php echo sanitize_output($customerLabel); ?></div>
                <?php endif; ?>
            </section>

            <div class="separator"></div>

            <section>
                <?php foreach (($sale['items'] ?? []) as $item): ?>
                    <div class="item">
                        <div class="item-row">
                            <div class="item-description">
                                <?php echo sanitize_output((string) ($item['descrizione'] ?? '')); ?>
                                <?php if ((int) ($item['quantita'] ?? 1) > 1): ?>
                                    x<?php echo (int) ($item['quantita'] ?? 1); ?>
                                <?php endif; ?>
                            </div>
                            <div class="item-price">&euro; <?php echo number_format((float) ($item['totale_riga'] ?? 0), 2, ',', '.'); ?></div>
                        </div>
                        <?php if (($item['operatore'] ?? '') !== '' || ($item['iccid'] ?? '') !== ''): ?>
                            <div class="item-meta">
                                <?php
                                $metaParts = [];
                                if (($item['operatore'] ?? '') !== '') {
                                    $metaParts[] = (string) $item['operatore'];
                                }
                                if (($item['iccid'] ?? '') !== '') {
                                    $metaParts[] = 'ICCID ' . (string) $item['iccid'];
                                }
                                echo sanitize_output(implode(' · ', $metaParts));
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <div class="separator"></div>

            <section class="note">
                <?php echo nl2br(sanitize_output($documentNote)); ?>
            </section>

            <?php if (!empty($sale['note'])): ?>
                <div class="separator"></div>
                <section class="note">
                    <strong>Note:</strong><br>
                    <?php echo nl2br(sanitize_output((string) $sale['note'])); ?>
                </section>
            <?php endif; ?>

            <div class="separator"></div>

            <section>
                <div class="total-row">
                    <div class="total-label">Totale:</div>
                    <div class="total-value">&euro; <?php echo number_format((float) ($sale['totale'] ?? 0), 2, ',', '.'); ?></div>
                </div>
                <div class="payment-line">Pagamento: <?php echo sanitize_output((string) ($sale['metodo_pagamento'] ?? '')); ?></div>
            </section>

            <footer class="footer center">
                <div class="footer-line">Grazie per il tuo acquisto!</div>
            </footer>
        </article>
    </div>

    <?php if ($autoPrint): ?>
        <script>
        window.addEventListener('load', function () {
            window.print();
        });
        </script>
    <?php endif; ?>
</body>
</html>
