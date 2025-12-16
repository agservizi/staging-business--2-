<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

$csrfToken = csrf_token();
$apiBaseUrl = rtrim((string) env('COPERTURA_API_BASE_URL', 'https://api.pianetafibra.it/v2/api.php'), '/');
$apiToken = trim((string) env('COPERTURA_API_TOKEN', ''));
$apiReady = $apiToken !== '';

function copertura_log(string $message): void
{
    error_log('[Copertura] ' . $message);
}

function copertura_file_log(string $endpoint, array $params, int $status, string $body): void
{
    $logPath = __DIR__ . '/../../../logs/app.log';
    $payload = [
        'ts' => date('c'),
        'endpoint' => $endpoint,
        'status' => $status,
        'params' => $params,
        'body_snippet' => substr($body, 0, 400),
    ];
    $line = '[Copertura] ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND);
}

function copertura_api_call(string $endpoint, array $params, string $apiBaseUrl, string $apiToken): array
{
    $url = $apiBaseUrl . '?' . http_build_query(array_merge(['endpoint' => $endpoint], $params));

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
    ]);

    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('cURL error: ' . $error);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $json = json_decode($response, true);
    if ($json === null) {
        copertura_file_log($endpoint, $params, $status, $response);
        throw new RuntimeException('Risposta non valida (HTTP ' . $status . '): ' . substr($response, 0, 400));
    }
    if (!is_array($json)) {
        copertura_file_log($endpoint, $params, $status, $response);
        throw new RuntimeException('Risposta inattesa (HTTP ' . $status . '), atteso array JSON: ' . substr($response, 0, 400));
    }
    if ($status >= 400) {
        copertura_file_log($endpoint, $params, $status, $response);
        throw new RuntimeException('API ' . $endpoint . ' ha risposto HTTP ' . $status . ' con payload: ' . substr($response, 0, 400));
    }

    copertura_file_log($endpoint, $params, $status, $response);
    copertura_log('Chiamata ' . $endpoint . ' OK (HTTP ' . $status . ')');
    return ['status' => $status, 'data' => $json, 'raw' => $response];
}

function copertura_normalize_cities(array $payload): array
{
    $items = $payload['CNL_AREA_OUT']['CNL'] ?? [];
    $normalized = [];
    foreach ($items as $item) {
        $cityId = (int) ($item['CDPOBJCNL']['lValue'] ?? 0);
        $cityName = trim((string) ($item['DSXOBJCNL'] ?? ''));
        if ($cityId <= 0 || $cityName === '') {
            continue;
        }
        $normalized[] = [
            'id' => $cityId,
            'name' => $cityName,
            'zip' => trim((string) ($item['CDXZIP'] ?? '')),
            'province' => trim((string) ($item['DSXOBJDPT'] ?? '')),
            'region' => trim((string) ($item['DSXOBJREG'] ?? '')),
        ];
    }
    return $normalized;
}

function copertura_normalize_streets(array $payload): array
{
    $items = $payload['STR_AREA_OUT']['STR'] ?? [];
    $normalized = [];
    foreach ($items as $item) {
        $streetId = (int) ($item['CDPOBJSTR']['lValue'] ?? 0);
        $streetName = trim((string) ($item['DSXOBJSTR'] ?? ''));
        if ($streetId <= 0 || $streetName === '') {
            continue;
        }
        $normalized[] = [
            'id' => $streetId,
            'name' => $streetName,
            'zip' => trim((string) ($item['CDXZIP'] ?? '')),
        ];
    }
    return $normalized;
}

function copertura_normalize_houses(array $payload): array
{
    $items = $payload['CIV_AREA_OUT']['CIV'] ?? [];
    $normalized = [];
    foreach ($items as $item) {
        $houseId = (int) ($item['CDPOBJCIV']['lValue'] ?? 0);
        $number = trim((string) ($item['NRPNUMCIV']['lValue'] ?? ''));
        if ($houseId <= 0 || $number === '') {
            continue;
        }
        $suffix = trim((string) ($item['DSXESP'] ?? '')); // eventuale estensione civico
        $normalized[] = [
            'id' => $houseId,
            'number' => $number . ($suffix !== '' ? ' ' . $suffix : ''),
        ];
    }
    return $normalized;
}

/**
 * Ricerca comune via dataset ISTAT locale (fallback rapido e offline-friendly).
 * Usa customer-portal/assets/data/comuni.json se presente.
 *
 * @return array<int,array{id:int,name:string,zip:string,province:string,region:string}>
 */
function load_local_cities(string $query): array
{
    static $cache = null;
    $query = trim(mb_strtolower($query));
    if ($query === '' || mb_strlen($query) < 3) {
        return [];
    }

    if ($cache === null) {
        $localPath = __DIR__ . '/../../../customer-portal/assets/data/comuni.json';
        if (!is_file($localPath)) {
            $cache = [];
        } else {
            $json = file_get_contents($localPath);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                $cache = [];
            } else {
                $cache = array_map(static function (array $row): array {
                    $cap = $row['cap'] ?? '';
                    if (is_array($cap)) {
                        $cap = $cap[0] ?? '';
                    }

                    $provinceName = $row['provincia']['nome'] ?? ($row['provincia']['sigla'] ?? '');
                    if (is_array($provinceName)) {
                        $provinceName = $provinceName[0] ?? '';
                    }

                    $regionName = $row['regione']['nome'] ?? '';
                    if (is_array($regionName)) {
                        $regionName = $regionName[0] ?? '';
                    }

                    return [
                        'id' => (int) ($row['codice'] ?? 0),
                        'name' => trim((string) ($row['nome'] ?? '')),
                        'zip' => trim((string) $cap),
                        'province' => trim((string) $provinceName),
                        'region' => trim((string) $regionName),
                    ];
                }, $data);
            }
        }
    }

    if ($cache === []) {
        return [];
    }

    $filtered = [];
    foreach ($cache as $city) {
        $name = mb_strtolower($city['name'] ?? '');
        if ($name === '' || $city['id'] === 0) {
            continue;
        }
        if (str_contains($name, $query)) {
            $filtered[] = $city;
        }
        if (count($filtered) >= 25) {
            break; // limite sicurezza
        }
    }

    return $filtered;
}

function copertura_coverage_result(array $payload): array
{
    $result = is_array($payload[0] ?? null) ? $payload[0] : $payload;
    return [
        'available' => (bool) ($result['IsAvailable'] ?? false),
        'technology' => trim((string) ($result['TechnologyCode'] ?? '')),
        'area_code' => trim((string) ($result['AreaCode'] ?? '')),
        'coverage' => is_array($result['Coverage'] ?? null) ? $result['Coverage'] : [],
        'raw' => $result,
    ];
}

$cityQuery = trim((string) ($_GET['city_query'] ?? ''));
$cityId = (int) ($_GET['city_id'] ?? 0);
$cityName = trim((string) ($_GET['city_name'] ?? ''));
$cityZip = trim((string) ($_GET['city_zip'] ?? ''));
$cityProvince = trim((string) ($_GET['city_province'] ?? ''));
$cityRegion = trim((string) ($_GET['city_region'] ?? ''));
$streetQuery = trim((string) ($_GET['street_query'] ?? ''));
$streetId = (int) ($_GET['street_id'] ?? 0);
$streetName = trim((string) ($_GET['street_name'] ?? ''));
$houseQuery = trim((string) ($_GET['house_query'] ?? ''));
$houseId = (int) ($_GET['house_id'] ?? 0);
$houseNumber = trim((string) ($_GET['house_number'] ?? ''));
$customerType = trim((string) ($_GET['customer_type'] ?? 'privato'));
if (!in_array($customerType, ['privato', 'azienda'], true)) {
    $customerType = 'privato';
}

$cityResults = [];
$streetResults = [];
$houseResults = [];
$coverageResult = null;
$messages = [];

if ($apiReady) {
    try {
        if ($cityQuery !== '' && mb_strlen($cityQuery) >= 3) {
            $cityResults = load_local_cities($cityQuery);
            if (!$cityResults) {
                $response = copertura_api_call('city', ['city' => $cityQuery], $apiBaseUrl, $apiToken);
                $cityResults = copertura_normalize_cities($response['data']);
            }
            if (!$cityResults) {
                $messages[] = 'Nessun comune trovato per la ricerca indicata.';
            }
        }

        if ($cityId > 0 && $streetQuery !== '' && strlen($streetQuery) >= 3) {
            $response = copertura_api_call('address', ['street' => $streetQuery, 'id_city' => $cityId], $apiBaseUrl, $apiToken);
            $streetResults = copertura_normalize_streets($response['data']);
            if (!$streetResults) {
                $messages[] = 'Nessuna via trovata per "' . $streetQuery . '" nel comune selezionato.';
            }
        }

        if ($streetId > 0 && $houseQuery !== '') {
            $response = copertura_api_call('housenumber', ['num' => $houseQuery, 'id_street' => $streetId], $apiBaseUrl, $apiToken);
            $houseResults = copertura_normalize_houses($response['data']);
            if (!$houseResults) {
                $messages[] = 'Nessun civico trovato per "' . $houseQuery . '" nella via selezionata.';
            }
        }

        if (isset($_GET['check_coverage'])) {
            if ($cityId <= 0 || $streetId <= 0 || $houseId <= 0 || $cityName === '' || $streetName === '' || $houseNumber === '' || $cityZip === '' || $cityProvince === '' || $cityRegion === '') {
                $messages[] = 'Completa comune, via, civico, CAP, provincia e regione per verificare la copertura.';
            } else {
                $response = copertura_api_call(
                    'coverage',
                    [
                        'customer_type' => $customerType,
                        'myStreet' => $streetName,
                        'myNum' => $houseNumber,
                        'myCity' => $cityName,
                        'myCap' => $cityZip,
                        'myPrv' => $cityProvince,
                        'myER' => $houseId,
                        'myStrg' => $streetId,
                        'myReg' => $cityRegion,
                    ],
                    $apiBaseUrl,
                    $apiToken
                );
                $coverageResult = copertura_coverage_result($response['data']);
            }
        }
    } catch (Throwable $e) {
        copertura_log('Errore: ' . $e->getMessage());
        $messages[] = $e->getMessage();
    }
} else {
    $messages[] = 'Configura il token API in COPERTURA_API_TOKEN per utilizzare il servizio.';
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Verifica copertura</p>
                <h1 class="h4 mb-0">Copertura rete PianetaFibra</h1>
                <p class="text-muted mb-0">Workflow a 4 step: comune, via, civico, verifica tecnologia disponibile.</p>
            </div>
            <a class="btn btn-outline-secondary" href="https://my.pianetafibra.it/api.php" target="_blank" rel="noreferrer">Apri area API</a>
        </div>

        <?php if ($messages): ?>
            <div class="alert alert-warning" role="alert">
                <ul class="mb-0 ps-3">
                    <?php foreach ($messages as $message): ?>
                        <li><?php echo sanitize_output($message); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Step 1</p>
                        <h2 class="h6 mb-3">Cerca comune</h2>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="customer_type" value="<?php echo sanitize_output($customerType); ?>">
                            <input class="form-control" type="search" name="city_query" value="<?php echo sanitize_output($cityQuery); ?>" placeholder="Inserisci almeno 3 caratteri" minlength="3" required>
                            <button class="btn btn-primary" type="submit">Cerca</button>
                        </form>
                        <?php if ($cityResults): ?>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Comune</th>
                                            <th>CAP</th>
                                            <th>Provincia</th>
                                            <th>Regione</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cityResults as $city): ?>
                                            <?php
                                                $selectParams = [
                                                    'city_id' => $city['id'],
                                                    'city_name' => $city['name'],
                                                    'city_zip' => $city['zip'],
                                                    'city_province' => $city['province'],
                                                    'city_region' => $city['region'],
                                                    'customer_type' => $customerType,
                                                ];
                                                $selectUrl = asset('modules/opportunities/collaborator/copertura.php?' . http_build_query($selectParams));
                                            ?>
                                            <tr>
                                                <td><?php echo sanitize_output($city['name']); ?></td>
                                                <td><?php echo sanitize_output($city['zip']); ?></td>
                                                <td><?php echo sanitize_output($city['province']); ?></td>
                                                <td><?php echo sanitize_output($city['region']); ?></td>
                                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($selectUrl); ?>">Seleziona</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Step 2</p>
                        <h2 class="h6 mb-3">Cerca via</h2>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="city_id" value="<?php echo (int) $cityId; ?>">
                            <input type="hidden" name="city_name" value="<?php echo sanitize_output($cityName); ?>">
                            <input type="hidden" name="city_zip" value="<?php echo sanitize_output($cityZip); ?>">
                            <input type="hidden" name="city_province" value="<?php echo sanitize_output($cityProvince); ?>">
                            <input type="hidden" name="city_region" value="<?php echo sanitize_output($cityRegion); ?>">
                            <input type="hidden" name="customer_type" value="<?php echo sanitize_output($customerType); ?>">
                            <input class="form-control" type="search" name="street_query" value="<?php echo sanitize_output($streetQuery); ?>" placeholder="Inserisci almeno 3 caratteri" minlength="3" <?php echo $cityId > 0 ? 'required' : 'disabled'; ?>>
                            <button class="btn btn-primary" type="submit" <?php echo $cityId > 0 ? '' : 'disabled'; ?>>Cerca</button>
                        </form>
                        <?php if ($cityId > 0): ?>
                            <div class="small text-muted mt-2">Comune selezionato: <?php echo sanitize_output($cityName ?: 'N/D'); ?> (<?php echo sanitize_output($cityZip); ?>)</div>
                        <?php endif; ?>
                        <?php if ($streetResults): ?>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Via</th>
                                            <th>CAP</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($streetResults as $street): ?>
                                            <?php
                                                $selectParams = [
                                                    'city_id' => $cityId,
                                                    'city_name' => $cityName,
                                                    'city_zip' => $cityZip,
                                                    'city_province' => $cityProvince,
                                                    'city_region' => $cityRegion,
                                                    'street_id' => $street['id'],
                                                    'street_name' => $street['name'],
                                                    'customer_type' => $customerType,
                                                ];
                                                $selectUrl = asset('modules/opportunities/collaborator/copertura.php?' . http_build_query($selectParams));
                                            ?>
                                            <tr>
                                                <td><?php echo sanitize_output($street['name']); ?></td>
                                                <td><?php echo sanitize_output($street['zip']); ?></td>
                                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($selectUrl); ?>">Seleziona</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Step 3</p>
                        <h2 class="h6 mb-3">Cerca civico</h2>
                        <form class="d-flex gap-2" method="get">
                            <input type="hidden" name="city_id" value="<?php echo (int) $cityId; ?>">
                            <input type="hidden" name="city_name" value="<?php echo sanitize_output($cityName); ?>">
                            <input type="hidden" name="city_zip" value="<?php echo sanitize_output($cityZip); ?>">
                            <input type="hidden" name="city_province" value="<?php echo sanitize_output($cityProvince); ?>">
                            <input type="hidden" name="city_region" value="<?php echo sanitize_output($cityRegion); ?>">
                            <input type="hidden" name="street_id" value="<?php echo (int) $streetId; ?>">
                            <input type="hidden" name="street_name" value="<?php echo sanitize_output($streetName); ?>">
                            <input type="hidden" name="customer_type" value="<?php echo sanitize_output($customerType); ?>">
                            <input class="form-control" type="search" name="house_query" value="<?php echo sanitize_output($houseQuery); ?>" placeholder="Inserisci il civico" <?php echo $streetId > 0 ? 'required' : 'disabled'; ?>>
                            <button class="btn btn-primary" type="submit" <?php echo $streetId > 0 ? '' : 'disabled'; ?>>Cerca</button>
                        </form>
                        <?php if ($streetId > 0): ?>
                            <div class="small text-muted mt-2">Via selezionata: <?php echo sanitize_output($streetName ?: 'N/D'); ?></div>
                        <?php endif; ?>
                        <?php if ($houseResults): ?>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Civico</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($houseResults as $house): ?>
                                            <?php
                                                $selectParams = [
                                                    'city_id' => $cityId,
                                                    'city_name' => $cityName,
                                                    'city_zip' => $cityZip,
                                                    'city_province' => $cityProvince,
                                                    'city_region' => $cityRegion,
                                                    'street_id' => $streetId,
                                                    'street_name' => $streetName,
                                                    'house_id' => $house['id'],
                                                    'house_number' => $house['number'],
                                                    'customer_type' => $customerType,
                                                ];
                                                $selectUrl = asset('modules/opportunities/collaborator/copertura.php?' . http_build_query($selectParams));
                                            ?>
                                            <tr>
                                                <td><?php echo sanitize_output($house['number']); ?></td>
                                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($selectUrl); ?>">Seleziona</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Step 4</p>
                        <h2 class="h6 mb-3">Verifica copertura</h2>
                        <form method="get" class="d-flex flex-column gap-3">
                            <input type="hidden" name="city_id" value="<?php echo (int) $cityId; ?>">
                            <input type="hidden" name="city_name" value="<?php echo sanitize_output($cityName); ?>">
                            <input type="hidden" name="city_zip" value="<?php echo sanitize_output($cityZip); ?>">
                            <input type="hidden" name="city_province" value="<?php echo sanitize_output($cityProvince); ?>">
                            <input type="hidden" name="city_region" value="<?php echo sanitize_output($cityRegion); ?>">
                            <input type="hidden" name="street_id" value="<?php echo (int) $streetId; ?>">
                            <input type="hidden" name="street_name" value="<?php echo sanitize_output($streetName); ?>">
                            <input type="hidden" name="house_id" value="<?php echo (int) $houseId; ?>">
                            <input type="hidden" name="house_number" value="<?php echo sanitize_output($houseNumber); ?>">
                            <div>
                                <label class="form-label text-uppercase small text-muted">Tipologia cliente</label>
                                <div class="d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="customer_type" id="customerPrivato" value="privato" <?php echo $customerType === 'privato' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="customerPrivato">Privato</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="customer_type" id="customerAzienda" value="azienda" <?php echo $customerType === 'azienda' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="customerAzienda">Azienda</label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-light border rounded p-3 small">
                                <div><strong>Comune:</strong> <?php echo sanitize_output($cityName ?: 'N/D'); ?></div>
                                <div><strong>Via:</strong> <?php echo sanitize_output($streetName ?: 'N/D'); ?></div>
                                <div><strong>Civico:</strong> <?php echo sanitize_output($houseNumber ?: 'N/D'); ?></div>
                                <div><strong>CAP/Provincia/Regione:</strong> <?php echo sanitize_output(trim($cityZip . ' ' . $cityProvince . ' ' . $cityRegion)); ?></div>
                            </div>
                            <button class="btn btn-primary" type="submit" name="check_coverage" value="1" <?php echo ($cityId && $streetId && $houseId) ? '' : 'disabled'; ?>>Verifica copertura</button>
                        </form>
                        <?php if ($coverageResult): ?>
                            <hr>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge <?php echo $coverageResult['available'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo $coverageResult['available'] ? 'Disponibile' : 'Non disponibile'; ?>
                                </span>
                                <?php if ($coverageResult['area_code'] !== ''): ?>
                                    <span class="badge bg-secondary">Area <?php echo sanitize_output($coverageResult['area_code']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="mb-2"><strong>Tecnologia:</strong> <?php echo sanitize_output($coverageResult['technology'] ?: 'N/D'); ?></div>
                            <?php if ($coverageResult['coverage']): ?>
                                <div class="mb-2"><strong>Profili disponibili:</strong></div>
                                <ul class="mb-0">
                                    <?php foreach ($coverageResult['coverage'] as $profile): ?>
                                        <li>
                                            <?php echo sanitize_output($profile['type'] ?? ''); ?>
                                            <?php if (!empty($profile['url'])): ?>
                                                - <a href="<?php echo sanitize_output($profile['url']); ?>" target="_blank" rel="noreferrer">Ordina</a>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <details class="mt-3">
                                <summary class="small text-muted">Dettaglio grezzo</summary>
                                <pre class="bg-light border rounded p-3 small mb-0"><?php echo sanitize_output(json_encode($coverageResult['raw'], JSON_PRETTY_PRINT)); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
