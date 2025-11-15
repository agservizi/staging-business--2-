<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtPudoService;
use App\Services\Brt\BrtShipmentService;
use App\Services\Brt\BrtTrackingService;
use App\Services\SettingsService;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/database.php';

if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
    define('CORESUITE_BRT_BOOTSTRAP', true);
}

require_once __DIR__ . '/../../modules/servizi/brt/functions.php';

final class PickupBrtService
{
    private BrtConfig $config;
    private BrtShipmentService $shipmentService;
    private BrtTrackingService $trackingService;
    private BrtPudoService $pudoService;
    private PDO $portalPdo;

    public function __construct(?BrtConfig $config = null)
    {
        $this->portalPdo = portal_db();
        $this->config = $config ?? new BrtConfig();
        ensure_brt_tables();
        $this->ensurePortalTable();
        $this->shipmentService = new BrtShipmentService($this->config);
        $this->trackingService = new BrtTrackingService($this->config);
        $this->pudoService = new BrtPudoService($this->config);
    }

    /**
     * @return array{currency:string,currency_symbol:string,tiers:array<int,array{label:string,max_weight:float|null,max_volume:float|null,price:float,display:array{label:string,price:string,criteria:string,weight:string,volume:string}}>,has_pricing:bool,unlimited_hint:string}
     */
    public function getPortalPricing(): array
    {
        $defaults = [
            'currency' => 'EUR',
            'currency_symbol' => '€',
            'tiers' => [],
            'has_pricing' => false,
            'unlimited_hint' => 'Quando un limite di peso o volume è vuoto, si considera senza limite.',
        ];

        $rawValue = null;
        try {
            $stmt = $this->portalPdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => SettingsService::PORTAL_BRT_PRICING_KEY]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && $value !== '') {
                $rawValue = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (Throwable $exception) {
            portal_error_log('Portal BRT pricing load failed: ' . $exception->getMessage());
            return array_merge($defaults, ['tiers' => []]);
        }

        if (!is_array($rawValue)) {
            return $defaults;
        }

        $normalized = SettingsService::normalizePortalBrtPricing($rawValue);
        $currency = strtoupper($normalized['currency'] ?? 'EUR');
        $symbol = $this->resolveCurrencySymbol($currency);

        $tiers = [];
        foreach ($normalized['tiers'] as $index => $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $label = isset($tier['label']) && $tier['label'] !== ''
                ? (string) $tier['label']
                : 'Scaglione #' . ($index + 1);

            $maxWeight = array_key_exists('max_weight', $tier) ? $tier['max_weight'] : null;
            $maxVolume = array_key_exists('max_volume', $tier) ? $tier['max_volume'] : null;
            $price = array_key_exists('price', $tier) ? (float) $tier['price'] : null;
            if ($price === null) {
                continue;
            }

            $weightLabel = $this->formatLimitLabel($maxWeight, 'Peso', 'kg', 3);
            $volumeLabel = $this->formatLimitLabel($maxVolume, 'Volume', 'm³', 4);
            $criteria = trim($weightLabel . ' · ' . $volumeLabel, ' ·');

            $tiers[] = [
                'index' => $index,
                'label' => $label,
                'max_weight' => $maxWeight,
                'max_volume' => $maxVolume,
                'price' => round($price, 2),
                'display' => [
                    'label' => $label,
                    'price' => $symbol . ' ' . $this->formatMoney($price),
                    'criteria' => $criteria,
                    'weight' => $weightLabel,
                    'volume' => $volumeLabel,
                ],
            ];
        }

        return [
            'currency' => $currency,
            'currency_symbol' => $symbol,
            'tiers' => $tiers,
            'has_pricing' => $tiers !== [],
            'unlimited_hint' => 'Quando il peso o il volume sono lasciati vuoti nello scaglione, vengono considerati senza limite.',
        ];
    }

    /**
     * @return array{index:int,label:string,price:float,max_weight:float|null,max_volume:float|null}|null
     */
    public function matchPortalPricingTier(float $weightKg, float $volumeM3): ?array
    {
        $pricing = $this->getPortalPricing();
        $tiers = $pricing['tiers'];

        if ($tiers === []) {
            return null;
        }

        $epsilon = 0.0001;
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $maxWeight = array_key_exists('max_weight', $tier) ? $tier['max_weight'] : null;
            $maxVolume = array_key_exists('max_volume', $tier) ? $tier['max_volume'] : null;

            $weightOk = $maxWeight === null || $weightKg <= ($maxWeight + $epsilon);
            $volumeOk = $maxVolume === null || $volumeM3 <= ($maxVolume + $epsilon);

            if ($weightOk && $volumeOk) {
                return [
                    'index' => isset($tier['index']) ? (int) $tier['index'] : 0,
                    'label' => isset($tier['label']) ? (string) $tier['label'] : 'Scaglione',
                    'price' => isset($tier['price']) ? (float) $tier['price'] : 0.0,
                    'max_weight' => $maxWeight !== null ? (float) $maxWeight : null,
                    'max_volume' => $maxVolume !== null ? (float) $maxVolume : null,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function searchPudos(array $criteria): array
    {
        $this->requireConfigured();

        $zip = strtoupper(trim((string) ($criteria['zip'] ?? $criteria['zipCode'] ?? '')));
        $city = trim((string) ($criteria['city'] ?? ''));
        $searchLatitude = trim((string) ($criteria['latitude'] ?? $criteria['lat'] ?? ''));
        $searchLongitude = trim((string) ($criteria['longitude'] ?? $criteria['lng'] ?? $criteria['lon'] ?? ''));

        $hasAddressCriteria = $zip !== '' && $city !== '';
        $hasCoordinateCriteria = $searchLatitude !== '' && $searchLongitude !== '';

        if (!$hasAddressCriteria && !$hasCoordinateCriteria) {
            throw new RuntimeException('Specifica CAP e città oppure coordinate valide per cercare un punto di ritiro BRT.');
        }

        $country = strtoupper(trim((string) ($criteria['country'] ?? $this->config->getDefaultCountryIsoAlpha2() ?? 'IT')));
        if ($country === '') {
            $country = 'IT';
        }

        if (!$this->config->isDestinationCountryAllowed($country)) {
            throw new RuntimeException('La nazione selezionata non è abilitata per la ricerca PUDO.');
        }

        $request = [
            'country' => $country,
        ];

        if ($hasAddressCriteria) {
            $request['zipCode'] = $zip;
            $request['city'] = $city;
        }

        if ($hasCoordinateCriteria) {
            $request['latitude'] = $searchLatitude;
            $request['longitude'] = $searchLongitude;
            if ($zip !== '') {
                $request['zipCode'] = $zip;
            }
            if ($city !== '') {
                $request['city'] = $city;
            }
        }

        $limit = $criteria['limit'] ?? null;
        if (is_numeric($limit)) {
            $request['limit'] = (int) $limit;
        } elseif (is_string($limit) && trim($limit) !== '') {
            $request['limit'] = (int) trim($limit);
        } else {
            $request['limit'] = 15;
        }

        $province = strtoupper(trim((string) ($criteria['province'] ?? '')));
        if ($province !== '') {
            $request['province'] = $province;
        }

        $distanceRaw = $criteria['distance'] ?? $criteria['maxDistance'] ?? $criteria['max_distance'] ?? null;
        if (is_numeric($distanceRaw)) {
            $request['maxDistanceSearch'] = (int) $distanceRaw;
        } elseif (is_string($distanceRaw) && trim($distanceRaw) !== '') {
            $request['maxDistanceSearch'] = trim($distanceRaw);
        }

        $address = trim((string) ($criteria['address'] ?? ''));
        if ($address !== '') {
            $request['address'] = $address;
        }

        try {
            $results = $this->pudoService->search($request);
        } catch (BrtException $exception) {
            throw new RuntimeException($exception->getMessage());
        }

        $normalized = [];
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            $addressValue = trim((string) ($entry['address'] ?? ''));
            $zipValue = trim((string) ($entry['zipCode'] ?? ''));
            $cityValue = trim((string) ($entry['city'] ?? ''));
            $provinceValue = trim((string) ($entry['province'] ?? ''));
            $countryValue = trim((string) ($entry['country'] ?? ''));

            if ($cityValue === '' && $city !== '') {
                $cityValue = $city;
            }

            if ($provinceValue === '' && $province !== '') {
                $provinceValue = $province;
            }

            if ($countryValue === '' && $country !== '') {
                $countryValue = $country;
            }

            if ($name === '' && $addressValue !== '') {
                $name = $addressValue;
            }

            $cityLine = trim($zipValue . ' ' . $cityValue);
            $labelParts = array_filter([
                $name !== '' ? $name : null,
                $addressValue !== '' ? $addressValue : null,
                $cityLine !== '' ? $cityLine : null,
                $provinceValue !== '' ? $provinceValue : null,
            ]);
            if ($labelParts === [] && $cityValue !== '') {
                $labelParts[] = $cityValue;
            }
            if ($labelParts === [] && $zipValue !== '') {
                $labelParts[] = $zipValue;
            }
            $label = $labelParts !== [] ? implode(' · ', $labelParts) : 'PUDO ' . $id;

            $openingHours = [];
            if (isset($entry['openingHours']) && is_array($entry['openingHours'])) {
                foreach ($entry['openingHours'] as $slot) {
                    if (is_string($slot)) {
                        $cleaned = trim($slot);
                        if ($cleaned !== '') {
                            $openingHours[] = $cleaned;
                        }
                    }
                }
            }

            $latitudeValue = isset($entry['latitude']) && $entry['latitude'] !== null
                ? (float) $entry['latitude']
                : null;
            $longitudeValue = isset($entry['longitude']) && $entry['longitude'] !== null
                ? (float) $entry['longitude']
                : null;
            $distanceValue = isset($entry['distanceKm']) && $entry['distanceKm'] !== null
                ? (float) $entry['distanceKm']
                : null;

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'address' => $addressValue,
                'zip' => $zipValue,
                'city' => $cityValue,
                'province' => $provinceValue,
                'country' => $countryValue,
                'latitude' => $latitudeValue,
                'longitude' => $longitudeValue,
                'distance_km' => $distanceValue,
                'opening_hours' => $openingHours,
                'label' => $label,
                'search_context' => [
                    'zip' => $zip,
                    'city' => $city,
                    'province' => $province,
                    'country' => $country,
                    'latitude' => $hasCoordinateCriteria ? $searchLatitude : null,
                    'longitude' => $hasCoordinateCriteria ? $searchLongitude : null,
                ],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{suggestion: array<string, string>, summary: array<string, mixed>|null, message: string|null}
     */
    public function getRoutingSuggestion(array $payload): array
    {
        $this->requireConfigured();

        $zip = strtoupper(trim((string) ($payload['zip'] ?? '')));
        $country = strtoupper(trim((string) ($payload['country'] ?? $this->config->getDefaultCountryIsoAlpha2() ?? 'IT')));

        if ($zip === '' || $country === '') {
            throw new RuntimeException('Specifica CAP e paese per ottenere i suggerimenti BRT.');
        }

        if (!$this->config->isDestinationCountryAllowed($country)) {
            throw new RuntimeException('La nazione selezionata non è abilitata per il calcolo dei suggerimenti BRT.');
        }

        $parcels = $this->parseDecimalValue($payload['parcels'] ?? null);
        $parcelsValue = $parcels !== null ? (int) round($parcels) : (int) ($payload['parcels'] ?? 1);
        $parcelsValue = max(1, $parcelsValue);

        $weight = $this->parseDecimalValue($payload['weight'] ?? null);
        $weightValue = $weight !== null ? (float) $weight : (float) ($payload['weight'] ?? 0);
        if ($weightValue <= 0) {
            throw new RuntimeException('Indica un peso valido per calcolare i suggerimenti BRT.');
        }

        $length = $this->parseDecimalValue($payload['length_cm'] ?? null);
        $depth = $this->parseDecimalValue($payload['depth_cm'] ?? null);
        $height = $this->parseDecimalValue($payload['height_cm'] ?? null);

        if ($length === null || $length <= 0 || $depth === null || $depth <= 0 || $height === null || $height <= 0) {
            throw new RuntimeException('Completa dimensioni valide (altezza, lunghezza e profondità) per ottenere i suggerimenti BRT.');
        }

        $volumeCandidate = $this->parseDecimalValue($payload['volume'] ?? null);
        $perParcelVolume = ($height * $length * $depth) / 1_000_000;
        $computedVolume = $perParcelVolume * $parcelsValue;
        if ($computedVolume <= 0) {
            throw new RuntimeException('Il volume calcolato risulta nullo. Verifica le dimensioni inserite.');
        }
        $volume = $volumeCandidate !== null && $volumeCandidate > 0 ? $volumeCandidate : $computedVolume;
        $volume = round($volume, 3);

        $volumetricWeightCandidate = $this->parseDecimalValue($payload['volumetric_weight'] ?? null);
        $volumetricWeight = $volumetricWeightCandidate !== null && $volumetricWeightCandidate > 0
            ? $volumetricWeightCandidate
            : (($height * $length * $depth) / 4000) * $parcelsValue;

        $routingPayload = [
            'senderCustomerCode' => $this->config->getSenderCustomerCode(),
            'departureDepot' => $this->config->getDepartureDepot(),
            'numberOfParcels' => $parcelsValue,
            'weightKG' => round($weightValue, 3),
            'volumeM3' => $volume,
            'consigneeZIPCode' => $zip,
            'consigneeCountryAbbreviationISOAlpha2' => $country,
            'dimensionLengthCM' => $length,
            'dimensionDepthCM' => $depth,
            'dimensionHeightCM' => $height,
        ];

        if ($volumetricWeight > 0) {
            $routingPayload['volumetricWeightKG'] = round($volumetricWeight, 2);
        }

        $providedPricing = strtoupper(trim((string) ($payload['pricing_condition_code'] ?? '')));
        if ($providedPricing !== '') {
            $routingPayload['pricingConditionCode'] = $providedPricing;
        }

        $providedNetwork = strtoupper(trim((string) ($payload['network'] ?? '')));
        if ($providedNetwork !== '') {
            $routingPayload['network'] = $providedNetwork;
        }

        $providedService = strtoupper(trim((string) ($payload['service_type'] ?? '')));
        if ($providedService !== '') {
            $routingPayload['serviceType'] = $providedService;
        }

        $providedDelivery = strtoupper(trim((string) ($payload['delivery_type'] ?? '')));
        if ($providedDelivery !== '') {
            $routingPayload['deliveryType'] = $providedDelivery;
        }

        $pudoId = trim((string) ($payload['pudo_id'] ?? ''));
        if ($pudoId !== '') {
            $routingPayload['pudoId'] = $pudoId;
        }

        try {
            $response = $this->shipmentService->getRoutingQuote($routingPayload);
        } catch (BrtException $exception) {
            throw new RuntimeException($exception->getMessage());
        }

        $summary = brt_extract_routing_quote_summary($response);
        $suggestion = [
            'network' => $this->extractRoutingString($response, ['network', 'networkcode', 'routingNetwork', 'network_code']),
            'service_type' => $this->extractRoutingString($response, ['serviceType', 'service', 'service_code', 'servicecode']),
            'delivery_type' => $this->extractRoutingString($response, ['deliveryType', 'delivery_type', 'delivery']),
            'pricing_condition_code' => $this->extractRoutingString($response, ['pricingConditionCode', 'pricing_condition_code']),
        ];

        if (($suggestion['pricing_condition_code'] ?? '') === '' && isset($suggestion['network']) && $suggestion['network'] !== '') {
            $resolvedPricing = $this->config->getPricingConditionCode($suggestion['network']);
            if (is_string($resolvedPricing) && $resolvedPricing !== '') {
                $suggestion['pricing_condition_code'] = strtoupper(trim($resolvedPricing));
            }
        }

        $message = $this->extractRoutingMessage($response);

        return [
            'suggestion' => array_filter(
                $suggestion,
                static fn($value) => is_string($value) && trim($value) !== ''
            ),
            'summary' => $summary,
            'message' => $message,
        ];
    }

    private function resolveCurrencySymbol(string $currency): string
    {
        $map = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            'PLN' => 'zł',
        ];

        return $map[$currency] ?? $currency;
    }

    private function formatLimitLabel(?float $value, string $label, string $unit, int $decimals): string
    {
        if ($value === null) {
            return $label . ' senza limite';
        }

        $formatted = $this->formatDecimal($value, $decimals);
        return sprintf('%s ≤ %s %s', $label, $formatted, $unit);
    }

    private function formatDecimal(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, ',', '.');
        $trimmed = rtrim(rtrim($formatted, '0'), ',');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private function formatMoney(float $value): string
    {
        $formatted = number_format($value, 2, ',', '.');
        return $formatted;
    }

    public function listCustomerShipments(int $customerId, array $options = []): array
    {
        $this->requireConfigured();

        $limit = (int) ($options['limit'] ?? 25);
        $limit = max(1, min($limit, 100));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $statusFilter = trim((string) ($options['status'] ?? ''));
        $search = trim((string) ($options['search'] ?? ''));

        $where = ['pcs.customer_id = :customer_id'];
        $params = [':customer_id' => $customerId];

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $where[] = '(pcs.status = :status OR bs.status = :status)';
            $params[':status'] = $statusFilter;
        }

        if ($search !== '') {
            $where[] = '(
                pcs.alphanumeric_sender_reference LIKE :search
                OR pcs.destination_name LIKE :search
                OR bs.parcel_id LIKE :search
                OR bs.tracking_by_parcel_id LIKE :search
            )';
            $params[':search'] = '%' . $search . '%';
        }

        $baseSql = 'FROM pickup_customer_brt_shipments pcs
            INNER JOIN brt_shipments bs ON bs.id = pcs.brt_shipment_id';
        if ($where !== []) {
            $baseSql .= ' WHERE ' . implode(' AND ', $where);
        }

        $countStmt = $this->portalPdo->prepare('SELECT COUNT(*) ' . $baseSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT
                pcs.id AS portal_id,
                pcs.customer_id AS portal_customer_id,
                pcs.brt_shipment_id AS portal_brt_shipment_id,
                pcs.numeric_sender_reference AS portal_numeric_sender_reference,
                pcs.alphanumeric_sender_reference AS portal_alphanumeric_sender_reference,
                pcs.parcel_id AS portal_parcel_id,
                pcs.tracking_by_parcel_id AS portal_tracking_by_parcel_id,
                pcs.status AS portal_status,
                pcs.label_path AS portal_label_path,
                pcs.parcels AS portal_parcels,
                pcs.total_weight_kg AS portal_total_weight_kg,
                pcs.total_volume_m3 AS portal_total_volume_m3,
                pcs.destination_name AS portal_destination_name,
                pcs.destination_address AS portal_destination_address,
                pcs.destination_zip AS portal_destination_zip,
                pcs.destination_city AS portal_destination_city,
                pcs.destination_province AS portal_destination_province,
                pcs.destination_country AS portal_destination_country,
                pcs.metadata AS portal_metadata,
                pcs.last_synced_at AS portal_last_synced_at,
                pcs.created_at AS portal_created_at,
                pcs.updated_at AS portal_updated_at,
                bs.status AS core_status,
                bs.parcel_id AS core_parcel_id,
                bs.tracking_by_parcel_id AS core_tracking_by_parcel_id,
                bs.label_path AS core_label_path,
                bs.confirmed_at AS core_confirmed_at,
                bs.deleted_at AS core_deleted_at,
                bs.created_at AS core_created_at,
                bs.updated_at AS core_updated_at,
                bs.execution_message AS core_execution_message,
                bs.execution_code AS core_execution_code,
                bs.execution_code_description AS core_execution_desc
            ' . $baseSql . '
            ORDER BY COALESCE(bs.confirmed_at, bs.created_at) DESC, pcs.id DESC
            LIMIT :limit OFFSET :offset';

        $stmt = $this->portalPdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $shipments = [];
        foreach ($rows as $row) {
            $portal = $this->extractPortalSlice($row);
            $core = $this->extractCoreSlice($row);
            $portal = $this->synchronizePortalRow($portal, $core);
            $shipments[] = $this->mapShipmentPayload($portal, $core);
        }

        return [
            'shipments' => $shipments,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    public function getShipment(int $customerId, int $portalShipmentId): array
    {
        $record = $this->fetchShipment($customerId, $portalShipmentId);
        $portal = $this->synchronizePortalRow($record['portal'], $record['core']);
        return $this->mapShipmentPayload($portal, $record['core']);
    }

    public function createShipment(int $customerId, array $payload): array
    {
        $this->requireConfigured();

        $recipientName = trim((string) ($payload['recipient_name'] ?? ''));
        $senderRecord = portal_fetch_one('SELECT name FROM pickup_customers WHERE id = ?', [$customerId]);
        $senderDisplayName = '';
        if (is_array($senderRecord) && array_key_exists('name', $senderRecord)) {
            $senderDisplayName = trim((string) $senderRecord['name']);
        }
        $address = trim((string) ($payload['address'] ?? ''));
        $zip = strtoupper(trim((string) ($payload['zip'] ?? '')));
        $city = trim((string) ($payload['city'] ?? ''));
        $province = strtoupper(trim((string) ($payload['province'] ?? '')));
        $country = strtoupper(trim((string) ($payload['country'] ?? 'IT')));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $mobile = trim((string) ($payload['mobile'] ?? ''));
        $contactName = trim((string) ($payload['contact_name'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
    $deliveryType = strtoupper(trim((string) ($payload['delivery_type'] ?? '')));
    $network = strtoupper(trim((string) ($payload['network'] ?? '')));
    $serviceType = strtoupper(trim((string) ($payload['service_type'] ?? '')));
    $pricingConditionCode = strtoupper(trim((string) ($payload['pricing_condition_code'] ?? '')));
        $alphanumericInput = trim((string) ($payload['alphanumeric_reference'] ?? ''));
        $pudoId = trim((string) ($payload['pudo_id'] ?? ''));
        $pudoDescription = trim((string) ($payload['pudo_description'] ?? ''));
        $labelRequired = (string) ($payload['label_required'] ?? '') === '1';

        $parcelsCandidate = $this->parseDecimalValue($payload['parcels'] ?? null);
        $parcels = max(1, $parcelsCandidate !== null ? (int) round($parcelsCandidate) : (int) ($payload['parcels'] ?? 1));

        $weightCandidate = $this->parseDecimalValue($payload['weight'] ?? null);
        $weight = max(0.1, $weightCandidate !== null ? (float) $weightCandidate : (float) ($payload['weight'] ?? 1));

        $lengthCm = $this->parseDecimalValue($payload['length_cm'] ?? null);
        $depthCm = $this->parseDecimalValue($payload['depth_cm'] ?? null);
        $heightCm = $this->parseDecimalValue($payload['height_cm'] ?? null);
        if ($lengthCm === null || $lengthCm <= 0 || $depthCm === null || $depthCm <= 0 || $heightCm === null || $heightCm <= 0) {
            throw new RuntimeException('Inserisci dimensioni valide (altezza, lunghezza e profondità in centimetri).');
        }

        $volumeCandidate = $this->parseDecimalValue($payload['volume'] ?? null);
        $perParcelVolume = ($heightCm * $lengthCm * $depthCm) / 1_000_000;
        $computedVolume = $perParcelVolume * $parcels;
        if ($computedVolume <= 0) {
            throw new RuntimeException('Il volume calcolato risulta nullo. Verifica le dimensioni inserite.');
        }
        $volume = $volumeCandidate !== null && $volumeCandidate > 0 ? (float) $volumeCandidate : $computedVolume;
        $volume = round($volume, 3);
        $volumetricWeight = (($heightCm * $lengthCm * $depthCm) / 4000) * $parcels;

        $routingSuggestionData = null;
        if ($network === '' || $serviceType === '' || $deliveryType === '' || $pricingConditionCode === '') {
            try {
                $routingSuggestionData = $this->getRoutingSuggestion([
                    'zip' => $zip,
                    'country' => $country,
                    'parcels' => $parcels,
                    'weight' => $weight,
                    'length_cm' => $lengthCm,
                    'depth_cm' => $depthCm,
                    'height_cm' => $heightCm,
                    'volume' => $volume,
                    'volumetric_weight' => $volumetricWeight,
                    'pudo_id' => $pudoId,
                    'network' => $network,
                    'service_type' => $serviceType,
                    'delivery_type' => $deliveryType,
                    'pricing_condition_code' => $pricingConditionCode,
                ]);
            } catch (RuntimeException $suggestionException) {
                portal_error_log('Impossibile ottenere suggerimenti BRT durante la creazione spedizione', [
                    'error' => $suggestionException->getMessage(),
                ]);
            }

            if (is_array($routingSuggestionData)) {
                $suggestion = $routingSuggestionData['suggestion'] ?? null;
                if (is_array($suggestion)) {
                    if ($network === '' && isset($suggestion['network']) && is_string($suggestion['network'])) {
                        $network = strtoupper(trim($suggestion['network']));
                    }
                    if ($serviceType === '' && isset($suggestion['service_type']) && is_string($suggestion['service_type'])) {
                        $serviceType = strtoupper(trim($suggestion['service_type']));
                    }
                    if ($deliveryType === '' && isset($suggestion['delivery_type']) && is_string($suggestion['delivery_type'])) {
                        $deliveryType = strtoupper(trim($suggestion['delivery_type']));
                    }
                    if ($pricingConditionCode === '' && isset($suggestion['pricing_condition_code']) && is_string($suggestion['pricing_condition_code'])) {
                        $pricingConditionCode = strtoupper(trim($suggestion['pricing_condition_code']));
                    }
                }
            }
        }

        if ($pricingConditionCode === '') {
            $resolvedPricing = $this->config->getPricingConditionCode($network !== '' ? $network : null);
            if (is_string($resolvedPricing) && $resolvedPricing !== '') {
                $pricingConditionCode = strtoupper(trim($resolvedPricing));
            }
        }

        if ($recipientName === '' || $address === '' || $zip === '' || $city === '') {
            throw new RuntimeException('Compila tutti i campi obbligatori del destinatario.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Inserisci un indirizzo email valido.');
        }

        if ($phone !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            throw new RuntimeException('Inserisci un numero di telefono valido.');
        }

        if ($mobile !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $mobile)) {
            throw new RuntimeException('Inserisci un numero di cellulare valido.');
        }

        $senderCode = $this->config->getSenderCustomerCode();
        $departureDepot = $this->config->getDepartureDepot();
        if ($senderCode === null || $senderCode === '') {
            throw new RuntimeException('Configurazione BRT incompleta: codice cliente non impostato.');
        }
        if ($departureDepot === null || $departureDepot === '') {
            throw new RuntimeException('Configurazione BRT incompleta: deposito di partenza non impostato.');
        }

    $numericReference = brt_next_numeric_reference((string) $senderCode);
    $alphanumericReference = $this->buildPortalReference($customerId, $numericReference, $recipientName, $senderDisplayName);
        if ($alphanumericInput !== '') {
            $trimmed = function_exists('mb_substr')
                ? mb_substr($alphanumericInput, 0, 80, 'UTF-8')
                : substr($alphanumericInput, 0, 80);
            if ($trimmed !== '') {
                $alphanumericReference = $trimmed;
            }
        }

        $insuranceAmountValue = null;
        $insuranceCurrency = strtoupper(trim((string) ($payload['insurance_currency'] ?? 'EUR')));
        $insuranceRaw = trim((string) ($payload['insurance_amount'] ?? ''));
        if ($insuranceRaw !== '') {
            $insuranceAmountValue = $this->parseDecimalValue($insuranceRaw);
            if ($insuranceAmountValue === null) {
                throw new RuntimeException("L'importo assicurazione non è valido.");
            }
            if ($insuranceAmountValue <= 0) {
                throw new RuntimeException("L'importo assicurazione deve essere maggiore di zero.");
            }
            if ($insuranceAmountValue > 99999.99) {
                throw new RuntimeException("L'importo assicurazione non può superare 99.999,99.");
            }
            if ($insuranceCurrency === '' || !preg_match('/^[A-Z]{3}$/', $insuranceCurrency)) {
                throw new RuntimeException('Se indichi un importo assicurato specifica una valuta ISO a 3 lettere.');
            }
        }

        $codAmountValue = null;
        $codCurrency = strtoupper(trim((string) ($payload['cod_currency'] ?? 'EUR')));
        $codPaymentType = strtoupper(trim((string) ($payload['cod_payment_type'] ?? '')));
        $isCodMandatory = (string) ($payload['cod_mandatory'] ?? '') === '1';
        $codRaw = trim((string) ($payload['cod_amount'] ?? ''));
        if ($codRaw !== '') {
            $codAmountValue = $this->parseDecimalValue($codRaw);
            if ($codAmountValue === null) {
                throw new RuntimeException("L'importo contrassegno non è valido.");
            }
            if ($codAmountValue <= 0) {
                throw new RuntimeException("L'importo contrassegno deve essere maggiore di zero.");
            }
            if ($codAmountValue > 99999.99) {
                throw new RuntimeException("L'importo contrassegno non può superare 99.999,99.");
            }
            if ($codCurrency === '' || !preg_match('/^[A-Z]{3}$/', $codCurrency)) {
                throw new RuntimeException('Se indichi un contrassegno specifica una valuta ISO a 3 lettere.');
            }
        }

        if ($codPaymentType !== '' && !preg_match('/^[A-Z0-9]{1,2}$/', $codPaymentType)) {
            throw new RuntimeException('Il tipo pagamento contrassegno deve contenere 1 o 2 caratteri alfanumerici.');
        }

        if ($isCodMandatory && $codAmountValue === null) {
            throw new RuntimeException('Per impostare il contrassegno obbligatorio devi indicare un importo maggiore di zero.');
        }

        $createPayload = [
            'senderCustomerCode' => $senderCode,
            'departureDepot' => $departureDepot,
            'numericSenderReference' => $numericReference,
            'alphanumericSenderReference' => $alphanumericReference,
            'consigneeCompanyName' => $recipientName,
            'consigneeAddress' => $address,
            'consigneeZIPCode' => $zip,
            'consigneeCity' => $city,
            'consigneeProvinceAbbreviation' => $province,
            'consigneeCountryAbbreviationISOAlpha2' => $country,
            'consigneeContactName' => $contactName !== '' ? $contactName : null,
            'consigneeEMail' => $email !== '' ? $email : null,
            'consigneeTelephone' => $phone !== '' ? $phone : null,
            'consigneeMobilePhoneNumber' => $mobile !== '' ? $mobile : ($phone !== '' ? $phone : null),
            'numberOfParcels' => $parcels,
            'weightKG' => $weight,
            'volumeM3' => $volume,
        ];

        if ($notes !== '') {
            $createPayload['notes'] = $notes;
        }

        if ($deliveryType !== '') {
            $createPayload['deliveryType'] = $deliveryType;
        }

        if ($serviceType !== '') {
            $createPayload['serviceType'] = $serviceType;
        }

        if ($network !== '') {
            $createPayload['network'] = $network;
        }

        if ($pudoId !== '') {
            $createPayload['pudoId'] = $pudoId;
        }

        if ($pricingConditionCode !== '') {
            $createPayload['pricingConditionCode'] = $pricingConditionCode;
        }

        $createPayload['isLabelRequired'] = $labelRequired ? 1 : 0;

        if ($insuranceAmountValue !== null) {
            $createPayload['insuranceAmount'] = round($insuranceAmountValue, 2);
            $createPayload['insuranceAmountCurrency'] = $insuranceCurrency !== '' ? $insuranceCurrency : 'EUR';
        }

        $createPayload['isCODMandatory'] = $isCodMandatory ? '1' : '0';

        if ($codAmountValue !== null) {
            $createPayload['cashOnDeliveryAmount'] = round($codAmountValue, 2);
            $createPayload['codCurrency'] = $codCurrency !== '' ? $codCurrency : 'EUR';
        }

        if ($codPaymentType !== '') {
            $createPayload['codPaymentType'] = $codPaymentType;
        }

        $metadata = [
            'source' => 'pickup_portal',
            'customer_id' => $customerId,
            'created_at' => date('c'),
            'dimensions' => [
                'length_cm' => round($lengthCm, 2),
                'depth_cm' => round($depthCm, 2),
                'height_cm' => round($heightCm, 2),
                'volume_m3' => $volume,
                'volumetric_weight_kg' => round($volumetricWeight, 2),
            ],
            'label_required' => $labelRequired,
        ];

        $senderMeta = [
            'profile_name' => $senderDisplayName !== '' ? $senderDisplayName : null,
            'reference_source' => $senderDisplayName !== '' ? 'profile_name' : 'recipient_name',
        ];

        if ($alphanumericInput === '') {
            $senderMeta['generated_reference'] = $alphanumericReference;
        }

        $senderMeta = array_filter(
            $senderMeta,
            static fn($value) => $value !== null && $value !== ''
        );

        if ($senderMeta !== []) {
            $metadata['sender'] = $senderMeta;
        }

        if ($deliveryType !== '') {
            $metadata['delivery_type'] = $deliveryType;
        }

        if ($serviceType !== '') {
            $metadata['service_type'] = $serviceType;
        }

        if ($network !== '') {
            $metadata['network'] = $network;
        }

        if ($pricingConditionCode !== '') {
            $metadata['pricing_condition_code'] = $pricingConditionCode;
        }

        $contactMeta = [];
        if ($contactName !== '') {
            $contactMeta['name'] = $contactName;
        }
        if ($phone !== '') {
            $contactMeta['phone'] = $phone;
        }
        if ($mobile !== '') {
            $contactMeta['mobile'] = $mobile;
        }
        if ($email !== '') {
            $contactMeta['email'] = $email;
        }
        if ($contactMeta !== []) {
            $metadata['contact'] = $contactMeta;
        }

        if ($pudoId !== '' || $pudoDescription !== '') {
            $metadata['pudo'] = array_filter([
                'id' => $pudoId !== '' ? $pudoId : null,
                'description' => $pudoDescription !== '' ? $pudoDescription : null,
            ], static fn($value) => $value !== null && $value !== '');
        }

        if (is_array($routingSuggestionData)) {
            if (isset($routingSuggestionData['summary']) && is_array($routingSuggestionData['summary'])) {
                $metadata['routing_summary'] = $routingSuggestionData['summary'];
            }
            if (isset($routingSuggestionData['suggestion']) && is_array($routingSuggestionData['suggestion'])) {
                $metadata['routing_suggestion'] = $routingSuggestionData['suggestion'];
            }
            if (isset($routingSuggestionData['message']) && is_string($routingSuggestionData['message']) && $routingSuggestionData['message'] !== '') {
                $metadata['routing_message'] = $routingSuggestionData['message'];
            }
        }

        $servicesMeta = [];
        if ($insuranceAmountValue !== null) {
            $servicesMeta['insurance'] = [
                'amount' => round($insuranceAmountValue, 2),
                'currency' => $insuranceCurrency,
            ];
        }
        if ($codAmountValue !== null || $isCodMandatory || $codPaymentType !== '') {
            $servicesMeta['cash_on_delivery'] = [
                'amount' => $codAmountValue !== null ? round($codAmountValue, 2) : null,
                'currency' => $codAmountValue !== null ? $codCurrency : null,
                'payment_type' => $codPaymentType !== '' ? $codPaymentType : null,
                'mandatory' => $isCodMandatory,
            ];
        }
        if ($servicesMeta !== []) {
            $metadata['services'] = $servicesMeta;
        }

        try {
            $createResponse = $this->shipmentService->createShipment($createPayload);
        } catch (BrtException $exception) {
            throw new RuntimeException($exception->getMessage());
        }

        $shipmentId = brt_store_shipment($createPayload, $createResponse, $metadata);
        portal_info_log('Nuova spedizione BRT creata dal portale clienti', [
            'customer_id' => $customerId,
            'shipment_id' => $shipmentId,
            'numeric_reference' => $numericReference,
        ]);

        $labelPath = null;
        try {
            $confirmResponse = $this->shipmentService->confirmShipment([
                'senderCustomerCode' => $senderCode,
                'numericSenderReference' => $numericReference,
                'alphanumericSenderReference' => $alphanumericReference,
            ]);
            brt_mark_shipment_confirmed($shipmentId, $confirmResponse);
            if (isset($confirmResponse['labels']['label'][0]) && is_array($confirmResponse['labels']['label'][0])) {
                $labelPath = brt_attach_label($shipmentId, $confirmResponse['labels']['label'][0]);
            }
        } catch (BrtException $exception) {
            portal_error_log('Conferma spedizione BRT dal portale non riuscita', [
                'shipment_id' => $shipmentId,
                'error' => $exception->getMessage(),
            ]);
        }

        $core = brt_get_shipment($shipmentId);
        if ($core === null) {
            throw new RuntimeException('Spedizione creata ma impossibile recuperare i dettagli dal database BRT.');
        }

        $portalInsert = [
            'customer_id' => $customerId,
            'brt_shipment_id' => $shipmentId,
            'numeric_sender_reference' => $numericReference,
            'alphanumeric_sender_reference' => $alphanumericReference,
            'parcel_id' => $core['parcel_id'] ?? null,
            'tracking_by_parcel_id' => $core['tracking_by_parcel_id'] ?? null,
            'status' => $core['status'] ?? 'created',
            'label_path' => $labelPath ?? ($core['label_path'] ?? null),
            'parcels' => (int) ($core['number_of_parcels'] ?? $parcels),
            'total_weight_kg' => (float) ($core['weight_kg'] ?? $weight),
            'total_volume_m3' => (float) ($core['volume_m3'] ?? $volume),
            'destination_name' => $core['consignee_name'] ?? $recipientName,
            'destination_address' => $core['consignee_address'] ?? $address,
            'destination_zip' => $core['consignee_zip'] ?? $zip,
            'destination_city' => $core['consignee_city'] ?? $city,
            'destination_province' => $core['consignee_province'] ?? ($province !== '' ? $province : null),
            'destination_country' => $core['consignee_country'] ?? $country,
            'metadata' => $this->encodeMetadata($metadata),
            'last_synced_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $portalShipmentId = portal_insert('pickup_customer_brt_shipments', $portalInsert);

        return $this->getShipment($customerId, $portalShipmentId);
    }

    public function refreshTracking(int $customerId, int $portalShipmentId): array
    {
        $record = $this->fetchShipment($customerId, $portalShipmentId);
        $portal = $record['portal'];
        $core = $record['core'];

        $trackingId = $portal['tracking_by_parcel_id'] ?? '';
        if ($trackingId === '') {
            $trackingId = $core['core_tracking_by_parcel_id'] ?? '';
        }
        if ($trackingId === '' && ($portal['parcel_id'] ?? '') !== '') {
            $trackingId = (string) $portal['parcel_id'];
        }
        if ($trackingId === '' && ($core['core_parcel_id'] ?? '') !== '') {
            $trackingId = (string) $core['core_parcel_id'];
        }

        if ($trackingId === '') {
            throw new RuntimeException('Non è disponibile un tracking ID per questa spedizione.');
        }

        try {
            $tracking = $this->trackingService->trackingByParcelId($trackingId);
            brt_update_tracking((int) $portal['brt_shipment_id'], $tracking);
            $metadata = $this->decodeMetadata($portal['metadata'] ?? null);
            $metadata['tracking'] = $tracking;
            $metadata['tracking_synced_at'] = date('c');
            portal_update('pickup_customer_brt_shipments', [
                'metadata' => $this->encodeMetadata($metadata),
                'last_synced_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $portal['id']]);
        } catch (BrtException $exception) {
            throw new RuntimeException($exception->getMessage());
        }

        return $this->getShipment($customerId, $portalShipmentId);
    }

    public function reprintLabel(int $customerId, int $portalShipmentId): array
    {
        $record = $this->fetchShipment($customerId, $portalShipmentId);
        $portal = $record['portal'];
        $core = $record['core'];

        $payload = [
            'senderCustomerCode' => $this->config->getSenderCustomerCode(),
            'numericSenderReference' => (int) $portal['numeric_sender_reference'],
            'alphanumericSenderReference' => (string) $portal['alphanumeric_sender_reference'],
        ];

        try {
            $response = $this->shipmentService->reprintShipmentLabel($payload, ['forceLabel' => true]);
            brt_mark_shipment_confirmed((int) $portal['brt_shipment_id'], $response, ['preserve_confirmed_at' => true]);
            $labelPath = null;
            if (isset($response['labels']['label'][0]) && is_array($response['labels']['label'][0])) {
                $labelPath = brt_attach_label((int) $portal['brt_shipment_id'], $response['labels']['label'][0]);
                if ($labelPath !== null) {
                    portal_update('pickup_customer_brt_shipments', [
                        'label_path' => $labelPath,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['id' => (int) $portal['id']]);
                }
            }
            portal_info_log('Etichetta BRT ristampata dal portale clienti', [
                'customer_id' => $customerId,
                'shipment_id' => $portal['brt_shipment_id'],
                'label_path' => $labelPath,
            ]);
        } catch (BrtException $exception) {
            throw new RuntimeException($exception->getMessage());
        }

        return $this->getShipment($customerId, $portalShipmentId);
    }

    /**
     * @return array{absolute_path:string, relative_path:string, filename:string}
     */
    public function resolveLabelPath(int $customerId, int $portalShipmentId): array
    {
        $record = $this->fetchShipment($customerId, $portalShipmentId);
        $portal = $record['portal'];
        $core = $record['core'];

        $labelPath = $portal['label_path'] ?? '';
        if ($labelPath === '') {
            $labelPath = $core['core_label_path'] ?? '';
        }

        if ($labelPath === '') {
            throw new RuntimeException('Nessuna etichetta disponibile per questa spedizione.');
        }

        $relative = ltrim($labelPath, '/');
        $absolute = rtrim(project_root_path(), '/') . '/' . $relative;
        if (!is_file($absolute)) {
            throw new RuntimeException('File etichetta non trovato sul server.');
        }

        return [
            'absolute_path' => $absolute,
            'relative_path' => $relative,
            'filename' => basename($absolute),
        ];
    }

    private function ensurePortalTable(): void
    {
        $stmt = $this->portalPdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_customer_brt_shipments'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Tabella pickup_customer_brt_shipments mancante. Esegui le migrazioni del progetto prima di procedere.');
        }
    }

    /**
     * @param mixed $value
     */
    private function parseDecimalValue($value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }
        return (float) $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $keys
     */
    private function extractRoutingString(array $node, array $keys): ?string
    {
        if ($node === [] || $keys === []) {
            return null;
        }

        $targets = array_map(static fn($key) => strtolower($key), $keys);
        $stack = [$node];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                    continue;
                }

                if (!is_string($key)) {
                    continue;
                }

                if (!is_scalar($value)) {
                    continue;
                }

                $normalizedKey = strtolower($key);
                if (!in_array($normalizedKey, $targets, true)) {
                    continue;
                }

                $stringValue = strtoupper(trim((string) $value));
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractRoutingMessage(array $response): ?string
    {
        if (isset($response['executionMessage']) && is_array($response['executionMessage'])) {
            $execution = $response['executionMessage'];
            $parts = [];

            if (isset($execution['codeDesc']) && is_string($execution['codeDesc'])) {
                $parts[] = trim($execution['codeDesc']);
            }

            if (isset($execution['message']) && is_string($execution['message'])) {
                $parts[] = trim($execution['message']);
            }

            $message = trim(implode(' ', array_filter($parts, static fn($part) => $part !== '')));
            if ($message !== '') {
                return $message;
            }
        }

        return null;
    }

    private function requireConfigured(): void
    {
        $senderCode = $this->config->getSenderCustomerCode();
        $departureDepot = $this->config->getDepartureDepot();

        if ($senderCode === null || $senderCode === '') {
            throw new RuntimeException('Configurazione BRT non disponibile: impostare il codice cliente.');
        }

        if ($departureDepot === null || $departureDepot === '') {
            throw new RuntimeException('Configurazione BRT non disponibile: impostare il deposito di partenza.');
        }
    }

    /**
     * @return array{portal:array<string,mixed>, core:array<string,mixed>}
     */
    private function fetchShipment(int $customerId, int $portalShipmentId): array
    {
        $sql = 'SELECT
                pcs.id AS portal_id,
                pcs.customer_id AS portal_customer_id,
                pcs.brt_shipment_id AS portal_brt_shipment_id,
                pcs.numeric_sender_reference AS portal_numeric_sender_reference,
                pcs.alphanumeric_sender_reference AS portal_alphanumeric_sender_reference,
                pcs.parcel_id AS portal_parcel_id,
                pcs.tracking_by_parcel_id AS portal_tracking_by_parcel_id,
                pcs.status AS portal_status,
                pcs.label_path AS portal_label_path,
                pcs.parcels AS portal_parcels,
                pcs.total_weight_kg AS portal_total_weight_kg,
                pcs.total_volume_m3 AS portal_total_volume_m3,
                pcs.destination_name AS portal_destination_name,
                pcs.destination_address AS portal_destination_address,
                pcs.destination_zip AS portal_destination_zip,
                pcs.destination_city AS portal_destination_city,
                pcs.destination_province AS portal_destination_province,
                pcs.destination_country AS portal_destination_country,
                pcs.metadata AS portal_metadata,
                pcs.last_synced_at AS portal_last_synced_at,
                pcs.created_at AS portal_created_at,
                pcs.updated_at AS portal_updated_at,
                bs.status AS core_status,
                bs.parcel_id AS core_parcel_id,
                bs.tracking_by_parcel_id AS core_tracking_by_parcel_id,
                bs.label_path AS core_label_path,
                bs.confirmed_at AS core_confirmed_at,
                bs.deleted_at AS core_deleted_at,
                bs.created_at AS core_created_at,
                bs.updated_at AS core_updated_at,
                bs.execution_message AS core_execution_message,
                bs.execution_code AS core_execution_code,
                bs.execution_code_description AS core_execution_desc
            FROM pickup_customer_brt_shipments pcs
            INNER JOIN brt_shipments bs ON bs.id = pcs.brt_shipment_id
            WHERE pcs.customer_id = :customer_id AND pcs.id = :id
            LIMIT 1';

        $stmt = $this->portalPdo->prepare($sql);
        $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $portalShipmentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Spedizione BRT non trovata per questo account.');
        }

        return [
            'portal' => $this->extractPortalSlice($row),
            'core' => $this->extractCoreSlice($row),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function extractPortalSlice(array $row): array
    {
        return [
            'id' => (int) $row['portal_id'],
            'customer_id' => (int) $row['portal_customer_id'],
            'brt_shipment_id' => (int) $row['portal_brt_shipment_id'],
            'numeric_sender_reference' => (int) $row['portal_numeric_sender_reference'],
            'alphanumeric_sender_reference' => (string) $row['portal_alphanumeric_sender_reference'],
            'parcel_id' => $row['portal_parcel_id'] !== null ? (string) $row['portal_parcel_id'] : null,
            'tracking_by_parcel_id' => $row['portal_tracking_by_parcel_id'] !== null ? (string) $row['portal_tracking_by_parcel_id'] : null,
            'status' => (string) $row['portal_status'],
            'label_path' => $row['portal_label_path'] !== null ? (string) $row['portal_label_path'] : null,
            'parcels' => (int) $row['portal_parcels'],
            'total_weight_kg' => (float) $row['portal_total_weight_kg'],
            'total_volume_m3' => (float) $row['portal_total_volume_m3'],
            'destination_name' => (string) $row['portal_destination_name'],
            'destination_address' => (string) $row['portal_destination_address'],
            'destination_zip' => (string) $row['portal_destination_zip'],
            'destination_city' => (string) $row['portal_destination_city'],
            'destination_province' => $row['portal_destination_province'] !== null ? (string) $row['portal_destination_province'] : null,
            'destination_country' => (string) $row['portal_destination_country'],
            'metadata' => $row['portal_metadata'],
            'last_synced_at' => $row['portal_last_synced_at'],
            'created_at' => $row['portal_created_at'],
            'updated_at' => $row['portal_updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function extractCoreSlice(array $row): array
    {
        return [
            'core_status' => $row['core_status'] !== null ? (string) $row['core_status'] : null,
            'core_parcel_id' => $row['core_parcel_id'] !== null ? (string) $row['core_parcel_id'] : null,
            'core_tracking_by_parcel_id' => $row['core_tracking_by_parcel_id'] !== null ? (string) $row['core_tracking_by_parcel_id'] : null,
            'core_label_path' => $row['core_label_path'] !== null ? (string) $row['core_label_path'] : null,
            'core_confirmed_at' => $row['core_confirmed_at'],
            'core_deleted_at' => $row['core_deleted_at'],
            'core_created_at' => $row['core_created_at'],
            'core_updated_at' => $row['core_updated_at'],
            'core_execution_message' => $row['core_execution_message'],
            'core_execution_code' => $row['core_execution_code'],
            'core_execution_desc' => $row['core_execution_desc'],
        ];
    }

    /**
     * @param array<string,mixed> $portal
     * @param array<string,mixed> $core
     * @return array<string,mixed>
     */
    private function synchronizePortalRow(array $portal, array $core): array
    {
        $updates = [];

        $status = $core['core_status'] ?? $portal['status'];
        if (($core['core_deleted_at'] ?? null) !== null) {
            $status = 'cancelled';
        }

        if ($status !== $portal['status']) {
            $updates['status'] = $status;
        }

        $coreParcel = $core['core_parcel_id'] ?? null;
        if ($coreParcel !== null && $coreParcel !== '' && $coreParcel !== ($portal['parcel_id'] ?? null)) {
            $updates['parcel_id'] = $coreParcel;
        }

        $coreTracking = $core['core_tracking_by_parcel_id'] ?? null;
        if ($coreTracking !== null && $coreTracking !== '' && $coreTracking !== ($portal['tracking_by_parcel_id'] ?? null)) {
            $updates['tracking_by_parcel_id'] = $coreTracking;
        }

        $coreLabel = $core['core_label_path'] ?? null;
        if ($coreLabel !== null && $coreLabel !== '' && $coreLabel !== ($portal['label_path'] ?? null)) {
            $updates['label_path'] = $coreLabel;
        }

        if ($updates !== []) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
            portal_update('pickup_customer_brt_shipments', $updates, ['id' => (int) $portal['id']]);
            $portal = array_merge($portal, $updates);
        }

        return $portal;
    }

    /**
     * @param array<string,mixed> $portal
     * @param array<string,mixed> $core
     * @return array<string,mixed>
     */
    private function mapShipmentPayload(array $portal, array $core): array
    {
        $statusContext = $this->describeStatus($portal['status'] ?? ($core['core_status'] ?? 'created'), $core);
        $labelPath = $portal['label_path'] ?? $core['core_label_path'] ?? null;

        return [
            'id' => (int) $portal['id'],
            'core_id' => (int) $portal['brt_shipment_id'],
            'status' => $statusContext['code'],
            'status_label' => $statusContext['label'],
            'status_badge' => $statusContext['badge'],
            'status_hint' => $statusContext['hint'],
            'reference' => [
                'numeric' => (int) $portal['numeric_sender_reference'],
                'alphanumeric' => (string) $portal['alphanumeric_sender_reference'],
            ],
            'parcel_id' => $portal['parcel_id'] ?? $core['core_parcel_id'],
            'tracking_id' => $portal['tracking_by_parcel_id'] ?? $core['core_tracking_by_parcel_id'],
            'parcels' => (int) $portal['parcels'],
            'weight_kg' => (float) $portal['total_weight_kg'],
            'volume_m3' => (float) $portal['total_volume_m3'],
            'destination' => [
                'name' => $portal['destination_name'],
                'address' => $portal['destination_address'],
                'zip' => $portal['destination_zip'],
                'city' => $portal['destination_city'],
                'province' => $portal['destination_province'],
                'country' => $portal['destination_country'],
            ],
            'created_at' => $core['core_created_at'] ?? $portal['created_at'],
            'updated_at' => $core['core_updated_at'] ?? $portal['updated_at'],
            'confirmed_at' => $core['core_confirmed_at'],
            'label_available' => $labelPath !== null && $labelPath !== '',
            'label_path' => $labelPath,
            'metadata' => $this->decodeMetadata($portal['metadata'] ?? null),
            'last_synced_at' => $portal['last_synced_at'],
            'execution' => [
                'code' => $core['core_execution_code'] !== null ? (int) $core['core_execution_code'] : null,
                'description' => $core['core_execution_desc'],
                'message' => $core['core_execution_message'],
            ],
        ];
    }

    /**
     * @return array{code:string,label:string,badge:string,hint:string}
     */
    private function describeStatus(string $status, array $core): array
    {
        $normalized = strtolower(trim($status));
        if (($core['core_deleted_at'] ?? null) !== null) {
            $normalized = 'cancelled';
        }

        $map = [
            'created' => ['label' => 'Creato', 'badge' => 'warning', 'hint' => 'In attesa di conferma'],
            'warning' => ['label' => 'Attenzione', 'badge' => 'warning', 'hint' => 'Verifica i dettagli della spedizione'],
            'confirmed' => ['label' => 'Confermato', 'badge' => 'success', 'hint' => 'Etichetta pronta per la stampa'],
            'cancelled' => ['label' => 'Annullato', 'badge' => 'secondary', 'hint' => 'Spedizione annullata'],
        ];

        $descriptor = $map[$normalized] ?? ['label' => ucfirst($normalized), 'badge' => 'secondary', 'hint' => ''];

        return [
            'code' => $normalized,
            'label' => $descriptor['label'],
            'badge' => $descriptor['badge'],
            'hint' => $descriptor['hint'],
        ];
    }

    private function normalizeReferenceComponent(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT', $trimmed);
            if (is_string($converted) && $converted !== '') {
                $trimmed = $converted;
            }
        }

        $upper = strtoupper($trimmed);
        $sanitized = preg_replace('/[^A-Z0-9]/', '', $upper);
        return is_string($sanitized) ? $sanitized : '';
    }

    private function buildPortalReference(int $customerId, int $numericReference, string $recipientName, ?string $senderName = null): string
    {
        $prefix = 'PP' . date('ymd');

        $senderCandidate = trim((string) ($senderName ?? ''));
        $fallbackRecipient = trim($recipientName);
        $sourceValue = $senderCandidate !== '' ? $senderCandidate : $fallbackRecipient;
        if ($sourceValue === '') {
            $sourceValue = 'Cliente ' . $customerId;
        }

        $tokens = preg_split('/[\s,;]+/', $sourceValue) ?: [];
        $tokens = array_values(array_filter($tokens, static fn($token) => $token !== ''));

        $slugParts = [];

        if ($tokens !== []) {
            $firstTokenRaw = $tokens[0];
            $lastTokenRaw = $tokens[count($tokens) - 1];

            $firstToken = $this->normalizeReferenceComponent($firstTokenRaw);
            if ($firstToken !== '') {
                $slugParts[] = substr($firstToken, 0, 10);
            }

            if (strcasecmp($firstTokenRaw, $lastTokenRaw) !== 0) {
                $lastToken = $this->normalizeReferenceComponent($lastTokenRaw);
                if ($lastToken !== '') {
                    $slugParts[] = substr($lastToken, 0, 10);
                }
            }
        }

        if ($slugParts === []) {
            $fallbackToken = $this->normalizeReferenceComponent($sourceValue);
            if ($fallbackToken === '') {
                $fallbackToken = 'CLIENTE' . $customerId;
            }
            $slugParts[] = substr($fallbackToken, 0, 12);
        }

        $slug = implode('-', array_filter($slugParts));
        if ($slug === '') {
            $slug = 'CLIENTE-' . $customerId;
        }

        $reference = sprintf('%s-%s-%d', $prefix, $slug, $numericReference);
        return strlen($reference) > 80 ? substr($reference, 0, 80) : $reference;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMetadata(?string $metadata): array
    {
        if ($metadata === null || $metadata === '') {
            return [];
        }

        try {
            $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $exception) {
            portal_error_log('Metadati BRT non decodificabili', [
                'error' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    private function encodeMetadata(array $metadata): string
    {
        try {
            return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            portal_error_log('Metadati BRT non serializzabili', [
                'error' => $exception->getMessage(),
            ]);
            return '{}';
        }
    }
}
