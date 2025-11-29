<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Report e Statistiche';

if (!function_exists('report_validate_date')) {
    function report_validate_date(?string $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $date = DateTime::createFromFormat('Y-m-d', $value);
        if ($date instanceof DateTime && $date->format('Y-m-d') === $value) {
            return $value;
        }

        return $fallback;
    }
}

if (!function_exists('report_quote_identifier')) {
    function report_quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('report_format_value')) {
    function report_format_value(array $current, array $row, string $column)
    {
        $table = (string) ($current['table'] ?? '');
        $rawValue = $row[$column] ?? null;

        if ($column === 'importo') {
            $factor = 1;
            if ($table === 'entrate_uscite') {
                $factor = (($row['tipo_movimento'] ?? 'Entrata') === 'Uscita') ? -1 : 1;
            }
            return format_currency(((float) $rawValue) * $factor);
        }

        if ($table === 'fedelta_movimenti') {
            if ($column === 'punti') {
                $points = (int) $rawValue;
                $prefix = $points > 0 ? '+' : ($points < 0 ? '-' : '');
                return sprintf('%s%d pt', $prefix, abs($points));
            }

            if ($column === 'saldo_post_movimento') {
                return number_format((int) $rawValue, 0, ',', '.') . ' pt';
            }
        }

        if (in_array($column, ['data_scadenza', 'data_pagamento', 'data_operazione', 'created_at'], true)) {
            return $rawValue ? format_date_locale((string) $rawValue) : '—';
        }

        if (in_array($column, ['data_inizio', 'data_fine', 'data_movimento', 'last_generated_at', 'updated_at'], true)) {
            return $rawValue ? format_datetime_locale((string) $rawValue) : '—';
        }

        $value = (string) ($rawValue ?? '');
        return $value !== '' ? $value : '—';
    }
}

if (!function_exists('report_format_date')) {
    function report_format_date(array $current, array $row)
    {
        $dateColumn = (string) ($current['date_column'] ?? '');
        $value = $row[$dateColumn] ?? null;
        if (!$value) {
            return '—';
        }

        if (in_array($dateColumn, ['data_inizio', 'data_fine', 'data_movimento', 'last_generated_at', 'updated_at'], true)) {
            return format_datetime_locale((string) $value);
        }

        return format_date_locale((string) $value);
    }
}

if (!function_exists('report_fetch_rows')) {
    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    function report_fetch_rows(PDO $pdo, array $current, array $filters, string $service, string $owner, int $limit = 0): array
    {
        $tableName = report_quote_identifier($current['table']);
        $dateColumn = report_quote_identifier($current['date_column']);
        $selectColumns = array_unique(array_merge(['id'], $current['columns'], [$current['date_column']]));
        $select = implode(', ', array_map(static fn($column) => report_quote_identifier($column), $selectColumns));

        $queryFilters = $filters;
        $query = 'SELECT ' . $select . ' FROM ' . $tableName . ' WHERE ' . $dateColumn . ' BETWEEN :from AND :to';

        if ($service === 'appuntamenti' && $owner !== '') {
            $query .= ' AND ' . report_quote_identifier('responsabile') . ' = :responsabile';
            $queryFilters[':responsabile'] = $owner;
        }

        $query .= ' ORDER BY ' . $dateColumn . ' DESC';

        $limitForQuery = $limit > 0 ? $limit + 1 : 0;
        if ($limitForQuery > 0) {
            $query .= ' LIMIT ' . $limitForQuery;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($queryFilters);
        $rows = $stmt->fetchAll();

        $limitReached = false;
        if ($limitForQuery > 0 && count($rows) === $limitForQuery) {
            $limitReached = true;
            array_pop($rows);
        }

        return [$rows, $limitReached];
    }
}

if (!function_exists('report_fetch_overview')) {
    function report_fetch_overview(PDO $pdo, array $serviceMap, array $filters, string $owner): array
    {
        $overview = [];

        foreach ($serviceMap as $key => $config) {
            $tableName = report_quote_identifier($config['table']);
            $dateColumn = report_quote_identifier($config['date_column']);
            $queryFilters = $filters;

            $query = 'SELECT COUNT(*) AS total FROM ' . $tableName . ' WHERE ' . $dateColumn . ' BETWEEN :from AND :to';

            if ($key === 'appuntamenti' && $owner !== '') {
                $query .= ' AND ' . report_quote_identifier('responsabile') . ' = :responsabile';
                $queryFilters[':responsabile'] = $owner;
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($queryFilters);
            $count = (int) $stmt->fetchColumn();

            $overview[] = [
                'service' => $key,
                'label' => $config['label'] ?? ucfirst($key),
                'count' => $count,
            ];
        }

        return $overview;
    }
}

$serviceMap = [
    'entrate-uscite' => [
        'table' => 'entrate_uscite',
        'columns' => ['tipo_movimento', 'descrizione', 'riferimento', 'metodo', 'stato', 'importo', 'data_scadenza', 'data_pagamento'],
        'date_column' => 'created_at',
        'label' => 'Entrate/Uscite',
        'limit' => 500,
    ],
    'appuntamenti' => [
        'table' => 'servizi_appuntamenti',
        'columns' => ['titolo', 'tipo_servizio', 'responsabile', 'stato', 'data_inizio', 'data_fine', 'luogo'],
        'date_column' => 'data_inizio',
        'label' => 'Appuntamenti',
        'limit' => 500,
    ],
    'fedelta' => [
        'table' => 'fedelta_movimenti',
        'columns' => ['tipo_movimento', 'descrizione', 'punti', 'saldo_post_movimento', 'ricompensa', 'operatore'],
        'date_column' => 'data_movimento',
        'label' => 'Programma Fedeltà',
        'limit' => 500,
    ],
    'curriculum' => [
        'table' => 'curriculum',
        'columns' => ['titolo', 'status', 'last_generated_at', 'updated_at'],
        'date_column' => 'updated_at',
        'label' => 'Gestione Curriculum',
        'limit' => 500,
    ],
    'logistica' => [
        'table' => 'spedizioni',
        'columns' => ['tipo_spedizione', 'tracking_number', 'stato', 'created_at'],
        'date_column' => 'created_at',
        'label' => 'Pickup',
        'limit' => 500,
    ],
];

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-t');

$from = report_validate_date($_GET['from'] ?? null, $defaultFrom);
$to = report_validate_date($_GET['to'] ?? null, $defaultTo);

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$serviceInput = strtolower(trim((string) ($_GET['service'] ?? 'all')));
if ($serviceInput === '') {
    $serviceInput = 'all';
}

$aliasMap = [
    'pagamenti' => 'entrate-uscite',
    'digitali' => 'fedelta',
];
$serviceInput = $aliasMap[$serviceInput] ?? $serviceInput;

$allowedServices = array_merge(['all'], array_keys($serviceMap));
if (!in_array($serviceInput, $allowedServices, true)) {
    $serviceInput = 'all';
}
$service = $serviceInput;

$owner = trim((string) ($_GET['responsabile'] ?? ($_GET['operator'] ?? '')));
$format = (isset($_GET['export']) && $_GET['export'] === 'csv') ? 'csv' : '';

$filters = [':from' => $from, ':to' => $to];

$owners = [];
try {
    $ownersStmt = $pdo->query("SELECT DISTINCT responsabile FROM servizi_appuntamenti WHERE responsabile IS NOT NULL AND responsabile <> '' ORDER BY responsabile");
    if ($ownersStmt !== false) {
        $owners = $ownersStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
} catch (Throwable $exception) {
    error_log('Report owners fetch failed: ' . $exception->getMessage());
    $owners = [];
}

if ($owner !== '' && !in_array($owner, $owners, true)) {
    $owner = '';
}

$current = $serviceMap[$service] ?? null;

$dataset = [];
$datasetLimitReached = false;
if ($current) {
    $limit = (int) ($current['limit'] ?? 0);
    [$dataset, $datasetLimitReached] = report_fetch_rows($pdo, $current, $filters, $service, $owner, $limit);
}

$summary = [
    'clients' => (int) $pdo->query('SELECT COUNT(*) FROM clienti')->fetchColumn(),
    'tickets' => (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('RESOLVED','CLOSED','ARCHIVED')")->fetchColumn(),
    'revenue' => 0.0,
];

// Paginazione report giornalieri: 10 elementi per pagina
$dailyReports = [];
$dailyTotal = 0;
$perPage = 10;
$pageDaily = max(1, (int) ($_GET['page_daily'] ?? 1));
$offset = ($pageDaily - 1) * $perPage;
try {
    // conteggio totale
    $countStmt = $pdo->query('SELECT COUNT(*) FROM daily_financial_reports');
    if ($countStmt !== false) {
        $dailyTotal = (int) $countStmt->fetchColumn();
    }

    $dailyStmt = $pdo->prepare('SELECT id, report_date, total_entrate, total_uscite, saldo, generated_at FROM daily_financial_reports ORDER BY report_date DESC LIMIT :limit OFFSET :offset');
    $dailyStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dailyStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dailyStmt->execute();
    $dailyReports = $dailyStmt->fetchAll();
} catch (Throwable $exception) {
    error_log('Report giornaliero: lettura elenco fallita - ' . $exception->getMessage());
    $dailyReports = [];
    $dailyTotal = 0;
}

$dailyPages = $dailyTotal > 0 ? (int) ceil($dailyTotal / $perPage) : 1;

// Data dell'ultimo report (globale)
$latestReportDate = null;
if ($dailyTotal > 0) {
    try {
        $latestReportDate = $pdo->query('SELECT report_date FROM daily_financial_reports ORDER BY report_date DESC LIMIT 1')->fetchColumn();
    } catch (Throwable $e) {
        $latestReportDate = null;
    }
}

$revenueStmt = $pdo->prepare("SELECT COALESCE(SUM(importo),0) FROM (
    SELECT CASE WHEN tipo_movimento = 'Entrata' THEN importo ELSE -importo END AS importo,
           COALESCE(data_pagamento, data_scadenza, created_at) AS data_riferimento
    FROM entrate_uscite WHERE stato = 'Completato'
) AS revenues WHERE data_riferimento BETWEEN :from AND :to");
$revenueStmt->execute([':from' => $from, ':to' => $to]);
$summary['revenue'] = (float) $revenueStmt->fetchColumn();

if ($format === 'csv' && $current) {
    [$exportDataset] = report_fetch_rows($pdo, $current, $filters, $service, $owner, 0);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_' . $service . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['ID'], $current['columns'], ['Data']));
    foreach ($exportDataset as $row) {
        $dataRow = [$row['id']];
        foreach ($current['columns'] as $col) {
            $value = $row[$col] ?? '';
            if ($current['table'] === 'entrate_uscite' && $col === 'importo') {
                $sign = (($row['tipo_movimento'] ?? 'Entrata') === 'Uscita') ? -1 : 1;
                $value = number_format(((float) $value) * $sign, 2, '.', '');
            } elseif ($current['table'] === 'fedelta_movimenti' && in_array($col, ['punti', 'saldo_post_movimento'], true)) {
                $value = (string) (int) $value;
            }
            $dataRow[] = $value;
        }
        $dataRow[] = $row[$current['date_column']] ?? '';
        fputcsv($out, $dataRow);
    }
    fclose($out);
    exit;
}

$overview = [];
if ($service === 'all') {
    $overview = report_fetch_overview($pdo, $serviceMap, $filters, $owner);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="row g-4 mb-4">
            <div class="col-lg-4 col-md-6">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="card-title">Clienti attivi</div>
                        <div class="fs-2 fw-bold"><?php echo number_format($summary['clients']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="card-title">Ticket aperti</div>
                        <div class="fs-2 fw-bold"><?php echo number_format($summary['tickets']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="card-title">Saldo periodo</div>
                        <div class="fs-2 fw-bold"><?php echo sanitize_output(format_currency($summary['revenue'])); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($service === 'all'): ?>
            <div class="card ag-card mb-4">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Panoramica servizi</h5>
                    <span class="text-muted small">Periodo: <?php echo sanitize_output(format_date_locale($from)); ?> → <?php echo sanitize_output(format_date_locale($to)); ?></span>
                </div>
                <div class="card-body">
                    <?php if ($overview): ?>
                        <div class="row g-3">
                            <?php foreach ($overview as $entry): ?>
                                <div class="col-lg-4 col-md-6">
                                    <div class="border rounded-3 p-3 h-100 d-flex flex-column justify-content-between">
                                        <div>
                                            <span class="text-muted text-uppercase small"><?php echo sanitize_output($entry['label']); ?></span>
                                            <div class="display-6 fw-semibold mt-2"><?php echo number_format((int) $entry['count'], 0, ',', '.'); ?></div>
                                        </div>
                                        <div class="mt-3">
                                            <?php
                                                $queryParams = [
                                                    'service' => $entry['service'],
                                                    'from' => $from,
                                                    'to' => $to,
                                                ];
                                                if ($owner !== '') {
                                                    $queryParams['responsabile'] = $owner;
                                                }
                                                $detailsUrl = 'index.php?' . http_build_query($queryParams);
                                            ?>
                                            <a class="btn btn-sm btn-outline-warning" href="<?php echo sanitize_output($detailsUrl); ?>">
                                                Apri dettaglio
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">Nessun dato trovato per il periodo selezionato.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h5 class="card-title mb-0">Filtri report</h5>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-md-3">
                        <label class="form-label" for="from">Dal</label>
                        <input class="form-control" id="from" type="date" name="from" value="<?php echo sanitize_output($from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="to">Al</label>
                        <input class="form-control" id="to" type="date" name="to" value="<?php echo sanitize_output($to); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="service">Servizio</label>
                        <select class="form-select" id="service" name="service">
                            <option value="all" <?php echo $service === 'all' ? 'selected' : ''; ?>>Tutti</option>
                            <?php foreach ($serviceMap as $key => $config): ?>
                                <option value="<?php echo $key; ?>" <?php echo $service === $key ? 'selected' : ''; ?>><?php echo sanitize_output($config['label'] ?? ucfirst($key)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($service === 'appuntamenti'): ?>
                        <div class="col-md-3">
                            <label class="form-label" for="responsabile">Responsabile</label>
                            <select class="form-select" id="responsabile" name="responsabile">
                                <option value="">Tutti</option>
                                <?php foreach ($owners as $responsabile): ?>
                                    <option value="<?php echo sanitize_output($responsabile); ?>" <?php echo $owner === $responsabile ? 'selected' : ''; ?>><?php echo sanitize_output($responsabile); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <button class="btn btn-warning text-dark w-100" type="submit">Applica filtri</button>
                    </div>
                    <?php if ($current): ?>
                        <div class="col-md-3">
                            <button class="btn btn-outline-warning w-100" type="submit" name="export" value="csv"><i class="fa-solid fa-file-csv me-2"></i>Esportazione CSV</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card ag-card mb-4">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Report finanziari giornalieri</h5>
                <?php if ($dailyTotal > 0): ?>
                    <span class="text-muted small">Ultimo report: <?php echo sanitize_output(format_date_locale((string) $latestReportDate)); ?></span>
                <?php else: ?>
                    <span class="text-muted small">Nessun report generato</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($dailyReports): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Entrate</th>
                                    <th>Uscite</th>
                                    <th>Saldo</th>
                                    <th>Generato il</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dailyReports as $report): ?>
                                    <?php
                                        $reportDate = $report['report_date'] ?? '';
                                        $generatedAt = $report['generated_at'] ?? '';
                                        $saldoValue = isset($report['saldo']) ? (float) $report['saldo'] : 0.0;
                                        $saldoClass = $saldoValue >= 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold';
                                        $reportId = (int) ($report['id'] ?? 0);
                                        $downloadUrl = base_url('modules/report/download_daily_report.php?id=' . $reportId);
                                        $previewUrl = base_url('modules/report/download_daily_report.php?id=' . $reportId . '&mode=inline');
                                    ?>
                                    <tr>
                                        <td><?php echo sanitize_output($reportDate ? format_date_locale((string) $reportDate) : '—'); ?></td>
                                        <td><?php echo sanitize_output(format_currency((float) ($report['total_entrate'] ?? 0))); ?></td>
                                        <td><?php echo sanitize_output(format_currency((float) ($report['total_uscite'] ?? 0))); ?></td>
                                        <td class="<?php echo $saldoClass; ?>"><?php echo sanitize_output(format_currency($saldoValue)); ?></td>
                                        <td><?php echo sanitize_output($generatedAt ? format_datetime_locale((string) $generatedAt) : '—'); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary me-2" href="<?php echo sanitize_output($previewUrl); ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-eye me-1"></i>Anteprima
                                            </a>
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($downloadUrl); ?>">
                                                <i class="fa-solid fa-download me-1"></i>Scarica
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                        <?php if ($dailyPages > 1): ?>
                            <nav aria-label="Paginazione report giornalieri" class="mt-3">
                                <ul class="pagination">
                                    <?php
                                        $baseQuery = $_GET;
                                        // Prev
                                        $prevPage = max(1, $pageDaily - 1);
                                        $baseQuery['page_daily'] = $prevPage;
                                        $prevUrl = 'index.php?' . http_build_query($baseQuery);
                                    ?>
                                    <li class="page-item <?php echo $pageDaily <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo sanitize_output($prevUrl); ?>" aria-label="Previous">&laquo;</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $dailyPages; $i++):
                                        $baseQuery['page_daily'] = $i;
                                        $pageUrl = 'index.php?' . http_build_query($baseQuery);
                                    ?>
                                        <li class="page-item <?php echo $i === $pageDaily ? 'active' : ''; ?>"><a class="page-link" href="<?php echo sanitize_output($pageUrl); ?>"><?php echo $i; ?></a></li>
                                    <?php endfor; ?>
                                    <?php
                                        $nextPage = min($dailyPages, $pageDaily + 1);
                                        $baseQuery['page_daily'] = $nextPage;
                                        $nextUrl = 'index.php?' . http_build_query($baseQuery);
                                    ?>
                                    <li class="page-item <?php echo $pageDaily >= $dailyPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo sanitize_output($nextUrl); ?>" aria-label="Next">&raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        Nessun report giornaliero è stato ancora generato. I report vengono creati automaticamente ogni mattina.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($current): ?>
            <div class="card ag-card">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Risultati</h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small"><?php echo count($dataset); ?> record</span>
                        <?php if ($datasetLimitReached && !empty($current['limit'])): ?>
                            <span class="badge bg-warning text-white">Limite <?php echo (int) $current['limit']; ?> record</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($dataset): ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover" data-datatable="true">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <?php foreach ($current['columns'] as $col): ?>
                                            <th><?php echo ucwords(str_replace('_', ' ', $col)); ?></th>
                                        <?php endforeach; ?>
                                        <th>Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dataset as $row): ?>
                                        <tr>
                                            <td>#<?php echo (int) $row['id']; ?></td>
                                            <?php foreach ($current['columns'] as $col): ?>
                                                <td><?php echo sanitize_output(report_format_value($current, $row, $col)); ?></td>
                                            <?php endforeach; ?>
                                            <td><?php echo sanitize_output(report_format_date($current, $row)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">Nessun risultato per i filtri selezionati.</div>
                    <?php endif; ?>
                    <?php if ($datasetLimitReached && !empty($current['limit'])): ?>
                        <p class="text-muted small mb-0 mt-3"><i class="fa-solid fa-circle-info me-1"></i>Mostrati i primi <?php echo (int) $current['limit']; ?> record. Affina i filtri per ridurre la quantità di dati.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
