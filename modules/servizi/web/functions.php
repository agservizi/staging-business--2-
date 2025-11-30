<?php
declare(strict_types=1);

use App\Services\ServiziWeb\HostingerClient;

require_once __DIR__ . '/../../../includes/helpers.php';

const SERVIZI_WEB_LOG_MODULE = 'Servizi/Web';

const SERVIZI_WEB_ALLOWED_STATUSES = [
    'preventivo',
    'in_attesa_cliente',
    'in_lavorazione',
    'consegnato',
    'annullato',
];

const SERVIZI_WEB_SERVICE_TYPES = [
    'Sito vetrina',
    'E-commerce',
    'Branding e grafica',
    'Domini e hosting',
    'Servizi di stampa',
];

const SERVIZI_WEB_HOSTINGER_SELECTION_SEPARATOR = '::';

function servizi_web_generate_code(PDO $pdo): string
{
    $prefix = 'WEB-' . date('Y');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM servizi_web_progetti WHERE YEAR(created_at) = :year');
    $stmt->execute([':year' => date('Y')]);
    $count = (int) $stmt->fetchColumn();

    return sprintf('%s-%04d', $prefix, $count + 1);
}

function servizi_web_project_storage_path(int $projectId): string
{
    $relative = 'uploads/servizi-web/allegati/' . $projectId;

    return public_path($relative);
}

function servizi_web_cleanup_project_storage(int $projectId): void
{
    $storageDir = servizi_web_project_storage_path($projectId);
    if (!is_dir($storageDir)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($storageDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) {
            @rmdir($fileInfo->getPathname());
        } else {
            @unlink($fileInfo->getPathname());
        }
    }

    @rmdir($storageDir);
}

function servizi_web_store_attachment(array $file, int $projectId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Errore durante il caricamento del file.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload non valido.');
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Il file supera la dimensione massima consentita di 10 MB.');
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['application/pdf', 'image/png', 'image/jpeg'];
    if ($mime === false || !in_array($mime, $allowed, true)) {
        throw new RuntimeException('Formato file non supportato.');
    }

    $storageDir = servizi_web_project_storage_path($projectId);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Impossibile creare la cartella di archiviazione.');
    }

    $name = sanitize_filename($file['name']);
    $fileName = sprintf('allegato_%s_%s', date('YmdHis'), $name);
    $destination = $storageDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Impossibile salvare il file caricato.');
    }

    $relative = 'uploads/servizi-web/allegati/' . $projectId . '/' . $fileName;

    return [
        'path' => $relative,
        'hash' => hash_file('sha256', $destination),
    ];
}

function servizi_web_delete_attachment(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $absolute = public_path($relativePath);
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function servizi_web_fetch_projects(PDO $pdo, array $filters = []): array
{
    $sql = 'SELECT swp.*, c.nome, c.cognome, c.ragione_sociale
        FROM servizi_web_progetti swp
        LEFT JOIN clienti c ON swp.cliente_id = c.id';

    $where = [];
    $params = [];

    if (!empty($filters['stato']) && in_array($filters['stato'], SERVIZI_WEB_ALLOWED_STATUSES, true)) {
        $where[] = 'swp.stato = :stato';
        $params[':stato'] = $filters['stato'];
    }

    if (!empty($filters['search'])) {
        $where[] = '(swp.codice LIKE :search OR swp.titolo LIKE :search OR c.ragione_sociale LIKE :search OR c.cognome LIKE :search OR c.nome LIKE :search)';
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY swp.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function servizi_web_fetch_project(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT swp.*, c.nome, c.cognome, c.ragione_sociale, c.email, c.telefono
        FROM servizi_web_progetti swp
        LEFT JOIN clienti c ON swp.cliente_id = c.id
        WHERE swp.id = :id');
    $stmt->execute([':id' => $id]);
    $project = $stmt->fetch();

    return $project ?: null;
}

function servizi_web_hostinger_is_configured(): bool
{
    if (!function_exists('env')) {
        return false;
    }

    return trim((string) env('HOSTINGER_API_TOKEN', '')) !== '';
}

function servizi_web_hostinger_encode_selection(string $itemId, string $priceId): string
{
    $itemId = trim($itemId);
    $priceId = trim($priceId);

    return $itemId . SERVIZI_WEB_HOSTINGER_SELECTION_SEPARATOR . $priceId;
}

function servizi_web_hostinger_decode_selection(?string $value): array
{
    $value = trim((string) $value);
    if ($value === '') {
        return [
            'item_id' => null,
            'price_id' => null,
        ];
    }

    if (!str_contains($value, SERVIZI_WEB_HOSTINGER_SELECTION_SEPARATOR)) {
        return [
            'item_id' => null,
            'price_id' => $value,
        ];
    }

    [$itemId, $priceId] = explode(SERVIZI_WEB_HOSTINGER_SELECTION_SEPARATOR, $value, 2);
    $itemId = trim($itemId);
    $priceId = trim($priceId);

    return [
        'item_id' => $itemId !== '' ? $itemId : null,
        'price_id' => $priceId !== '' ? $priceId : null,
    ];
}

function servizi_web_hostinger_client(): ?HostingerClient
{
    static $client;

    if ($client instanceof HostingerClient) {
        return $client;
    }

    if (!servizi_web_hostinger_is_configured()) {
        return null;
    }

    $token = (string) env('HOSTINGER_API_TOKEN');
    $baseUri = (string) env('HOSTINGER_API_BASE_URI', '');
    $options = [];

    $verifySetting = env('HOSTINGER_API_VERIFY_SSL', null);
    if ($verifySetting !== null && $verifySetting !== '') {
        $normalized = strtolower((string) $verifySetting);
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            $options['verify_ssl'] = false;
        }
    }

    $caBundle = (string) env('HOSTINGER_API_CA_PATH', '');
    if ($caBundle !== '') {
        $options['ca_path'] = $caBundle;
    }

    $timeoutSetting = env('HOSTINGER_API_TIMEOUT', null);
    if ($timeoutSetting !== null && $timeoutSetting !== '') {
        $timeout = (int) $timeoutSetting;
        if ($timeout > 0) {
            $options['timeout'] = $timeout;
        }
    }

    try {
        $client = new HostingerClient($token, $baseUri !== '' ? $baseUri : null, $options);
    } catch (\Throwable $exception) {
        error_log('Servizi Web hostinger init failed: ' . $exception->getMessage());
        return null;
    }

    return $client;
}

function servizi_web_hostinger_catalog_items(?string $category = null): array
{
    static $fetched = false;
    static $cache = [];

    if (!$fetched) {
        $fetched = true;
        $client = servizi_web_hostinger_client();
        if (!$client) {
            return [];
        }

        try {
            $items = $client->listCatalog(null);
            if (is_array($items)) {
                $cache = array_values(array_filter($items, static function ($item): bool {
                    return is_array($item) && !empty($item['id']);
                }));
            } else {
                $cache = [];
            }
        } catch (\Throwable $exception) {
            error_log('Servizi Web hostinger catalog fetch failed: ' . $exception->getMessage());
            $cache = [];
        }
    }

    if ($category === null || $category === '') {
        return $cache;
    }

    $normalized = strtoupper($category);

    return array_values(array_filter($cache, static function (array $item) use ($normalized): bool {
        $itemCategory = strtoupper((string) ($item['category'] ?? ''));

        return $itemCategory === $normalized;
    }));
}

function servizi_web_hostinger_catalog_lookup(): array
{
    static $lookup;

    if ($lookup !== null) {
        return $lookup;
    }

    $lookup = [
        'items' => [],
        'prices' => [],
    ];

    foreach (servizi_web_hostinger_catalog_items(null) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemId = trim((string) ($item['id'] ?? ''));
        if ($itemId === '') {
            continue;
        }

        $lookup['items'][$itemId] = $item;

        $prices = $item['prices'] ?? [];
        if (!is_array($prices)) {
            continue;
        }

        foreach ($prices as $price) {
            if (!is_array($price)) {
                continue;
            }

            $priceId = trim((string) ($price['id'] ?? ''));
            if ($priceId === '') {
                continue;
            }

            $lookup['prices'][$priceId] = [
                'price' => $price,
                'item_id' => $itemId,
            ];
        }
    }

    return $lookup;
}

function servizi_web_hostinger_find_item(?string $itemId): ?array
{
    if ($itemId === null || $itemId === '') {
        return null;
    }

    $lookup = servizi_web_hostinger_catalog_lookup();

    return $lookup['items'][$itemId] ?? null;
}

function servizi_web_hostinger_find_price(?string $priceId): ?array
{
    if ($priceId === null || $priceId === '') {
        return null;
    }

    $lookup = servizi_web_hostinger_catalog_lookup();

    return $lookup['prices'][$priceId] ?? null;
}

function servizi_web_hostinger_format_currency(int $amount, string $currency): string
{
    $currency = strtoupper($currency);

    if ($currency === 'EUR') {
        $value = $amount / 100;
        return '€' . number_format($value, 2, ',', '.');
    }

    $value = $amount / 100;

    return $currency . ' ' . number_format($value, 2, '.', ',');
}

function servizi_web_hostinger_format_period_label(int $period, string $unit): string
{
    $period = $period > 0 ? $period : 1;
    $unit = strtolower($unit);

    if ($unit === 'month') {
        return $period === 1 ? 'mensile' : 'ogni ' . $period . ' mesi';
    }

    if ($unit === 'year') {
        return $period === 1 ? 'annuale' : 'ogni ' . $period . ' anni';
    }

    return 'ogni ' . $period . ' ' . $unit;
}

function servizi_web_hostinger_build_price_label(string $itemName, array $price): string
{
    $currency = strtoupper((string) ($price['currency'] ?? 'EUR'));
    $listPrice = isset($price['price']) ? servizi_web_hostinger_format_currency((int) $price['price'], $currency) : '';

    $period = isset($price['period']) ? (int) $price['period'] : 1;
    $periodUnit = (string) ($price['period_unit'] ?? 'month');
    $periodLabel = servizi_web_hostinger_format_period_label($period, $periodUnit);

    $firstPrice = isset($price['first_period_price']) ? (int) $price['first_period_price'] : null;

    if ($firstPrice !== null && $listPrice !== '' && $firstPrice !== (int) ($price['price'] ?? null)) {
        $promo = servizi_web_hostinger_format_currency($firstPrice, $currency);
        $priceLabel = $promo . ' promo / ' . $listPrice;
    } else {
        $priceLabel = $listPrice !== '' ? $listPrice : '—';
    }

    return $itemName . ' • ' . $periodLabel . ' • ' . $priceLabel;
}

function servizi_web_hostinger_plan_options(string $type): array
{
    $items = servizi_web_hostinger_catalog_items(null);
    if (!$items) {
        return [];
    }

    $category = '';
    $filter = '';

    if ($type === 'hosting') {
        $category = strtoupper(trim((string) env('HOSTINGER_API_HOSTING_CATEGORY', 'VPS')));
        $filter = strtolower(trim((string) env('HOSTINGER_API_HOSTING_FILTER', '')));
    } elseif ($type === 'email') {
        $category = strtoupper(trim((string) env('HOSTINGER_API_EMAIL_CATEGORY', '')));
        $filter = strtolower(trim((string) env('HOSTINGER_API_EMAIL_FILTER', 'email')));
    }

    $currency = strtoupper(trim((string) env('HOSTINGER_API_CURRENCY', 'EUR')));

    $options = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemId = trim((string) ($item['id'] ?? ''));
        if ($itemId === '') {
            continue;
        }

        $itemName = trim((string) ($item['name'] ?? $itemId));
        $itemCategory = strtoupper((string) ($item['category'] ?? ''));

        if ($category !== '' && $itemCategory !== $category) {
            continue;
        }

        if ($filter !== '' && stripos($itemId . ' ' . $itemName, $filter) === false) {
            continue;
        }

        $prices = $item['prices'] ?? [];
        if (!is_array($prices)) {
            continue;
        }

        foreach ($prices as $price) {
            if (!is_array($price)) {
                continue;
            }

            $priceId = trim((string) ($price['id'] ?? ''));
            if ($priceId === '') {
                continue;
            }

            $priceCurrency = strtoupper((string) ($price['currency'] ?? ''));
            if ($priceCurrency !== '' && $currency !== '' && $priceCurrency !== $currency) {
                continue;
            }

            $label = servizi_web_hostinger_build_price_label($itemName, $price);

            $options[] = [
                'value' => servizi_web_hostinger_encode_selection($itemId, $priceId),
                'label' => $label,
                'item_id' => $itemId,
                'item_name' => $itemName,
                'price_id' => $priceId,
                'currency' => $priceCurrency,
                'price' => isset($price['price']) ? (int) $price['price'] : null,
                'first_price' => isset($price['first_period_price']) ? (int) $price['first_period_price'] : null,
                'period' => isset($price['period']) ? (int) $price['period'] : null,
                'period_unit' => (string) ($price['period_unit'] ?? ''),
                'category' => $itemCategory,
                'metadata' => $item['metadata'] ?? null,
            ];
        }
    }

    usort($options, static function (array $a, array $b): int {
        return strcmp($a['label'], $b['label']);
    });

    return $options;
}

function servizi_web_hostinger_selection_label(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $decoded = servizi_web_hostinger_decode_selection($value);
    $priceId = $decoded['price_id'] ?? null;

    if ($priceId) {
        $priceData = servizi_web_hostinger_find_price($priceId);
        if ($priceData) {
            $item = servizi_web_hostinger_find_item($priceData['item_id'] ?? null);
            $price = $priceData['price'] ?? null;

            if ($item && is_array($price)) {
                $itemName = trim((string) ($item['name'] ?? ($priceData['item_id'] ?? '')));

                return servizi_web_hostinger_build_price_label($itemName, $price);
            }
        }
    }

    $itemId = $decoded['item_id'] ?? null;
    if ($itemId) {
        $item = servizi_web_hostinger_find_item($itemId);
        if ($item) {
            return trim((string) ($item['name'] ?? $itemId));
        }
    }

    return $decoded['price_id'] ?? $value;
}

function servizi_web_hostinger_create_order(array $items, ?int $paymentMethodId = null, ?string $currency = null): array
{
    $filtered = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemId = trim((string) ($item['item_id'] ?? ''));
        $priceId = trim((string) ($item['price_id'] ?? ''));
        $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;

        if ($itemId === '' || $priceId === '') {
            continue;
        }

        $filtered[] = [
            'item_id' => $itemId,
            'price_id' => $priceId,
            'quantity' => $quantity > 0 ? $quantity : 1,
        ];
    }

    if (!$filtered) {
        return [
            'success' => false,
            'error' => 'Nessun articolo valido per creare un ordine Hostinger.',
        ];
    }

    $paymentMethodId = $paymentMethodId !== null ? $paymentMethodId : (int) env('HOSTINGER_API_PAYMENT_METHOD_ID', 0);
    if ($paymentMethodId <= 0) {
        return [
            'success' => false,
            'error' => 'Configura HOSTINGER_API_PAYMENT_METHOD_ID nelle variabili ambiente per generare ordini automatici.',
        ];
    }

    $payload = [
        'payment_method_id' => $paymentMethodId,
        'items' => $filtered,
    ];

    $currency = $currency !== null ? trim($currency) : trim((string) env('HOSTINGER_API_CURRENCY', ''));
    if ($currency !== '') {
        $payload['currency'] = strtoupper($currency);
    }

    $client = servizi_web_hostinger_client();
    if (!$client) {
        return [
            'success' => false,
            'error' => 'Client Hostinger non disponibile.',
        ];
    }

    try {
        $response = $client->createOrder($payload);

        return [
            'success' => true,
            'data' => $response,
        ];
    } catch (\Throwable $exception) {
        error_log('Servizi Web hostinger order failed: ' . $exception->getMessage());

        return [
            'success' => false,
            'error' => $exception->getMessage(),
        ];
    }
}

function servizi_web_hostinger_datacenters(): array
{
    static $cache;

    if ($cache !== null) {
        return $cache;
    }

    $client = servizi_web_hostinger_client();
    if (!$client) {
        $cache = [];
        return $cache;
    }

    try {
        $datacenters = $client->listDatacenters();
        $cache = is_array($datacenters) ? $datacenters : [];
    } catch (\Throwable $exception) {
        error_log('Servizi Web hostinger datacenters failed: ' . $exception->getMessage());
        $cache = [];
    }

    return $cache;
}

function servizi_web_hostinger_datacenter_label(?string $datacenterId): ?string
{
    $datacenterId = trim((string) $datacenterId);
    if ($datacenterId === '') {
        return null;
    }

    static $labels;

    if ($labels === null) {
        $labels = [];
        foreach (servizi_web_hostinger_datacenters() as $datacenter) {
            if (!is_array($datacenter)) {
                continue;
            }

            $id = (string) ($datacenter['id'] ?? $datacenter['code'] ?? '');
            if ($id === '') {
                continue;
            }

            $labelParts = array_filter([
                $datacenter['name'] ?? null,
                $datacenter['location'] ?? null,
                $datacenter['country'] ?? ($datacenter['country_code'] ?? null),
            ]);

            $labels[$id] = $labelParts ? implode(' • ', $labelParts) : $id;
        }
    }

    return $labels[$datacenterId] ?? null;
}

function servizi_web_hostinger_catalog(?string $category = null): array
{
    return servizi_web_hostinger_catalog_items($category);
}

function servizi_web_hostinger_check_domain(string $domain): array
{
    $client = servizi_web_hostinger_client();
    if (!$client) {
        return [
            'items' => [],
            'error' => 'Integrazione Hostinger non disponibile.',
        ];
    }

    try {
        $items = $client->checkDomainAvailability([$domain]);

        return [
            'items' => $items,
            'error' => null,
        ];
    } catch (\Throwable $exception) {
        error_log('Servizi Web hostinger domain check failed: ' . $exception->getMessage());

        return [
            'items' => [],
            'error' => $exception->getMessage(),
        ];
    }
}

function servizi_web_log_action(PDO $pdo, string $action, string $details): void
{
    try {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $stmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
            VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $stmt->execute([
            ':user_id' => $userId,
            ':modulo' => SERVIZI_WEB_LOG_MODULE,
            ':azione' => $action,
            ':dettagli' => $details,
        ]);
    } catch (\Throwable $exception) {
        error_log('Servizi Web log error: ' . $exception->getMessage());
    }
}

function servizi_web_format_cliente(array $project): string
{
    $ragione = trim((string) ($project['ragione_sociale'] ?? ''));
    $fullName = trim(($project['cognome'] ?? '') . ' ' . ($project['nome'] ?? ''));

    return $ragione !== '' ? $ragione : ($fullName !== '' ? $fullName : 'Cliente');
}
