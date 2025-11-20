<?php
declare(strict_types=1);

namespace App\Services\Brt;

use function env;

final class BrtConfig
{
    private string $restBaseUrl;

    private string $ormBaseUrl;

    private string $pudoBaseUrl;

    private ?string $accountUserId;

    private ?string $accountPassword;

    private ?string $senderCustomerCode;

    private ?string $departureDepot;

    private string $defaultDeliveryFreightTypeCode;

    private ?string $pricingConditionCode;

    /**
     * @var array<string, string>
     */
    private array $pricingConditionCodesByNetwork;

    private ?string $defaultNetwork;

    private ?string $defaultServiceType;

    private ?string $defaultPudoId;

    private ?string $defaultBrtServiceCode;

    private ?string $returnServiceCode;

    private ?string $defaultReturnDepot;

    private bool $labelRequiredByDefault;

    private string $labelOutputType;

    private ?string $labelOffsetX;

    private ?string $labelOffsetY;

    private bool $labelBorder;

    private bool $labelLogo;

    private bool $labelBarcodeRow;

    private ?string $caBundlePath;

    private ?string $restApiKey;

    private ?string $ormApiKey;

    private ?string $pudoApiAuth;

    private bool $autoConfirm;

    private ?string $defaultCountryIsoAlpha2;

    /**
     * @var array<string, string>
     */
    private array $allowedDestinationCountries;

    private bool $trackingSchedulerEnabled;

    private int $trackingIntervalMinutes;

    private int $trackingBatchSize;

    private int $trackingStaleMinutes;

    private int $trackingMaxAgeDays;

    /**
     * @var array<int, string>
     */
    private array $trackingStatuses;

    private bool $manifestEnabled;

    private bool $manifestStorePdf;

    private ?string $portalBaseUrl;

    private ?string $portalCustomerCode;

    private string $routingEndpoint;

    private string $manifestEndpoint;

    private const DEFAULT_ALLOWED_DESTINATION_COUNTRIES = [
        'IT' => 'Italia',
        'AT' => 'Austria',
        'BE' => 'Belgio',
        'BG' => 'Bulgaria',
        'HR' => 'Croazia',
        'CY' => 'Cipro',
        'CZ' => 'Repubblica Ceca',
        'DK' => 'Danimarca',
        'EE' => 'Estonia',
        'FI' => 'Finlandia',
        'FR' => 'Francia',
        'DE' => 'Germania',
        'GR' => 'Grecia',
        'HU' => 'Ungheria',
        'IE' => 'Irlanda',
        'LV' => 'Lettonia',
        'LT' => 'Lituania',
        'LU' => 'Lussemburgo',
        'MT' => 'Malta',
        'NL' => 'Paesi Bassi',
        'PL' => 'Polonia',
        'PT' => 'Portogallo',
        'RO' => 'Romania',
        'SK' => 'Slovacchia',
        'SI' => 'Slovenia',
        'ES' => 'Spagna',
        'SE' => 'Svezia',
        'AL' => 'Albania',
        'AD' => 'Andorra',
        'BA' => 'Bosnia ed Erzegovina',
        'CH' => 'Svizzera',
        'IS' => 'Islanda',
        'LI' => 'Liechtenstein',
        'MK' => 'Macedonia del Nord',
        'MC' => 'Monaco',
        'ME' => 'Montenegro',
        'NO' => 'Norvegia',
        'RS' => 'Serbia',
        'SM' => 'San Marino',
        'TR' => 'Turchia',
        'UA' => 'Ucraina',
        'GB' => 'Regno Unito',
    ];

    public function __construct()
    {
        $this->restBaseUrl = $this->normalizeBaseUrl(env('BRT_REST_BASE_URL', 'https://api.brt.it/rest/v1'));
        $this->ormBaseUrl = $this->normalizeBaseUrl(env('BRT_ORM_BASE_URL', 'https://api.brt.it/api/geodata/v410'));
        $this->pudoBaseUrl = $this->normalizeBaseUrl(env('BRT_PUDO_BASE_URL', 'https://api.brt.it/pudo/v1/open/pickup'));

        $this->accountUserId = $this->clean(env('BRT_ACCOUNT_USER_ID'));
        $this->accountPassword = $this->clean(env('BRT_ACCOUNT_PASSWORD'));
        $this->senderCustomerCode = $this->clean(env('BRT_SENDER_CUSTOMER_CODE'));
        $this->departureDepot = $this->clean(env('BRT_DEPARTURE_DEPOT'));

        $this->defaultDeliveryFreightTypeCode = $this->clean(env('BRT_DELIVERY_FREIGHT_TYPE_CODE', 'DAP')) ?? 'DAP';
        $this->pricingConditionCode = $this->clean(env('BRT_PRICING_CONDITION_CODE', '000'));
        $this->pricingConditionCodesByNetwork = array_filter([
            'ITALIA' => $this->clean(env('BRT_PRICING_CONDITION_CODE_ITALIA')),
            'PUDO' => $this->clean(env('BRT_PRICING_CONDITION_CODE_PUDO')),
            'DPD' => $this->clean(env('BRT_PRICING_CONDITION_CODE_DPD')),
        ], static fn ($value) => $value !== null && $value !== '');
        $this->defaultNetwork = $this->clean(env('BRT_DEFAULT_NETWORK'));
        $this->defaultServiceType = $this->clean(env('BRT_DEFAULT_SERVICE_TYPE'));
        $this->defaultPudoId = $this->clean(env('BRT_DEFAULT_PUDO_ID'));
    $this->defaultBrtServiceCode = $this->clean(env('BRT_SERVICE_CODE'));
    $this->returnServiceCode = $this->clean(env('BRT_RETURN_SERVICE_CODE', 'B15'));
    $this->defaultReturnDepot = $this->clean(env('BRT_RETURN_DEPOT'));

        $this->labelRequiredByDefault = $this->boolEnv('BRT_LABEL_REQUIRED', true);
        $this->labelOutputType = $this->clean(env('BRT_LABEL_OUTPUT_TYPE', 'PDF')) ?? 'PDF';
        $this->labelOffsetX = $this->clean(env('BRT_LABEL_OFFSET_X'));
        $this->labelOffsetY = $this->clean(env('BRT_LABEL_OFFSET_Y'));
        $this->labelBorder = $this->boolEnv('BRT_LABEL_BORDER', false);
        $this->labelLogo = $this->boolEnv('BRT_LABEL_LOGO', true);
        $this->labelBarcodeRow = $this->boolEnv('BRT_LABEL_BARCODE_ROW', false);

        $this->caBundlePath = $this->clean(env('BRT_CA_BUNDLE_PATH', __DIR__ . '/../../../certs/cacert.pem'));
        if ($this->caBundlePath !== null) {
            $this->caBundlePath = $this->resolvePath($this->caBundlePath);
        }

        $this->restApiKey = $this->clean(env('BRT_API_KEY'));
        $this->ormApiKey = $this->resolveApiKey('BRT_ORM_API_KEY');
        $this->pudoApiAuth = $this->resolveApiKey('BRT_PUDO_API_AUTH');

        $this->routingEndpoint = $this->resolveRoutingEndpoint(
            $this->clean(env('BRT_ROUTING_ENDPOINT', '/shipments/routing'))
        );

        $this->autoConfirm = $this->boolEnv('BRT_AUTO_CONFIRM', false);
        $this->defaultCountryIsoAlpha2 = $this->clean(env('BRT_DEFAULT_COUNTRY', 'IT'));
        $this->allowedDestinationCountries = $this->buildAllowedDestinationCountries(
            $this->clean(env('BRT_ALLOWED_DESTINATION_COUNTRIES'))
        );

        $this->trackingSchedulerEnabled = $this->boolEnv('BRT_TRACKING_ENABLED', false);
        $this->trackingIntervalMinutes = $this->intEnv('BRT_TRACKING_INTERVAL_MINUTES', 30, 5, 240);
        $this->trackingBatchSize = $this->intEnv('BRT_TRACKING_BATCH_SIZE', 10, 1, 50);
        $this->trackingStaleMinutes = $this->intEnv('BRT_TRACKING_STALE_MINUTES', 180, 15, 1440);
        $this->trackingMaxAgeDays = $this->intEnv('BRT_TRACKING_MAX_AGE_DAYS', 15, 0, 90, true);
        $this->trackingStatuses = $this->parseStatuses($this->clean(env('BRT_TRACKING_STATUSES')), ['confirmed', 'warning', 'in_transit', 'out_for_delivery']);

        $this->manifestEnabled = $this->boolEnv('BRT_MANIFEST_ENABLED', false);
        $this->manifestStorePdf = $this->boolEnv('BRT_MANIFEST_STORE_PDF', true);
        $this->manifestEndpoint = $this->resolveManifestEndpoint(
            $this->clean(env('BRT_MANIFEST_ENDPOINT', '/manifests/official'))
        );
        $this->portalBaseUrl = $this->normalizePortalBaseUrl($this->clean(env('BRT_PORTAL_BASE_URL', 'https://vas.brt.it/vas99')));
        $this->portalCustomerCode = $this->clean(env('BRT_PORTAL_CUSTOMER_CODE'));

        // Ricerca codici HS gestita da dataset locale, nessuna configurazione aggiuntiva richiesta
    }

    public function getRestBaseUrl(): string
    {
        return $this->restBaseUrl;
    }

    public function getOrmBaseUrl(): string
    {
        return $this->ormBaseUrl;
    }

    public function getPudoBaseUrl(): string
    {
        return $this->pudoBaseUrl;
    }

    public function getAccountUserId(): string
    {
        $value = $this->accountUserId ?? '';
        if ($value === '') {
            throw new BrtException('Configurare BRT_ACCOUNT_USER_ID nel file .env.');
        }
        return $value;
    }

    public function getAccountPassword(): string
    {
        $value = $this->accountPassword ?? '';
        if ($value === '') {
            throw new BrtException('Configurare BRT_ACCOUNT_PASSWORD nel file .env.');
        }
        return $value;
    }

    public function getSenderCustomerCode(): string
    {
        $value = $this->senderCustomerCode ?? '';
        if ($value === '') {
            throw new BrtException('Configurare BRT_SENDER_CUSTOMER_CODE nel file .env.');
        }
        return $value;
    }

    public function getDepartureDepot(): string
    {
        $value = $this->departureDepot ?? '';
        if ($value === '') {
            throw new BrtException('Configurare BRT_DEPARTURE_DEPOT nel file .env.');
        }
        return $value;
    }

    public function getDefaultDeliveryFreightTypeCode(): string
    {
        return $this->defaultDeliveryFreightTypeCode;
    }

    public function getPricingConditionCode(?string $network = null): ?string
    {
        if ($network !== null) {
            $normalized = strtoupper(trim($network));
            if ($normalized !== '' && isset($this->pricingConditionCodesByNetwork[$normalized])) {
                return $this->pricingConditionCodesByNetwork[$normalized];
            }
        }

        return $this->pricingConditionCode;
    }

    /**
     * @return array<string, string>
     */
    public function getPricingConditionCodesByNetwork(): array
    {
        return $this->pricingConditionCodesByNetwork;
    }

    public function getDefaultNetwork(): ?string
    {
        return $this->defaultNetwork;
    }

    public function getDefaultServiceType(): ?string
    {
        return $this->defaultServiceType;
    }

    public function getDefaultPudoId(): ?string
    {
        return $this->defaultPudoId;
    }

    public function getDefaultBrtServiceCode(): ?string
    {
        return $this->defaultBrtServiceCode;
    }

    public function getReturnServiceCode(): ?string
    {
        return $this->returnServiceCode;
    }

    public function getDefaultReturnDepot(): ?string
    {
        if ($this->defaultReturnDepot !== null) {
            return $this->defaultReturnDepot;
        }

        return $this->departureDepot;
    }

    public function isLabelRequiredByDefault(): bool
    {
        return $this->labelRequiredByDefault;
    }

    public function getLabelOutputType(): string
    {
        return $this->labelOutputType;
    }

    public function getLabelOffsetX(): ?string
    {
        return $this->labelOffsetX;
    }

    public function getLabelOffsetY(): ?string
    {
        return $this->labelOffsetY;
    }

    public function isLabelBorderEnabled(): bool
    {
        return $this->labelBorder;
    }

    public function isLabelLogoEnabled(): bool
    {
        return $this->labelLogo;
    }

    public function isLabelBarcodeRowEnabled(): bool
    {
        return $this->labelBarcodeRow;
    }

    public function getCaBundlePath(): ?string
    {
        return $this->caBundlePath;
    }

    public function getApiKey(): ?string
    {
        return $this->getRestApiKey();
    }

    public function getRestApiKey(): ?string
    {
        return $this->restApiKey;
    }

    public function getOrmApiKey(): ?string
    {
        return $this->ormApiKey;
    }

    public function getPudoApiAuth(): ?string
    {
        return $this->pudoApiAuth;
    }

    public function getRoutingEndpoint(): string
    {
        return $this->routingEndpoint;
    }

    public function shouldAutoConfirm(): bool
    {
        return $this->autoConfirm;
    }

    public function getDefaultCountryIsoAlpha2(): ?string
    {
        return $this->defaultCountryIsoAlpha2;
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedDestinationCountries(): array
    {
        return $this->allowedDestinationCountries;
    }

    public function isDestinationCountryAllowed(string $countryIsoAlpha2): bool
    {
        $normalized = strtoupper(trim($countryIsoAlpha2));
        return $normalized !== '' && isset($this->allowedDestinationCountries[$normalized]);
    }

    public function isTrackingSchedulerEnabled(): bool
    {
        return $this->trackingSchedulerEnabled;
    }

    public function getTrackingIntervalMinutes(): int
    {
        return $this->trackingIntervalMinutes;
    }

    public function getTrackingBatchSize(): int
    {
        return $this->trackingBatchSize;
    }

    public function getTrackingStaleMinutes(): int
    {
        return $this->trackingStaleMinutes;
    }

    public function getTrackingMaxAgeDays(): int
    {
        return $this->trackingMaxAgeDays;
    }

    /**
     * @return array<int, string>
     */
    public function getTrackingStatuses(): array
    {
        return $this->trackingStatuses;
    }

    public function isManifestEnabled(): bool
    {
        return $this->manifestEnabled;
    }

    public function shouldStoreOfficialManifestPdf(): bool
    {
        return $this->manifestStorePdf;
    }

    public function getPortalBaseUrl(): ?string
    {
        return $this->portalBaseUrl;
    }

    public function getPortalCustomerCode(): ?string
    {
        return $this->portalCustomerCode;
    }

    public function getManifestEndpoint(): string
    {
        return $this->manifestEndpoint;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $trimmed = trim($baseUrl);
        if ($trimmed === '') {
            return '';
        }
        return rtrim($trimmed, '/');
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function boolEnv(string $key, bool $default): bool
    {
        $raw = env($key, $default ? 'true' : 'false');
        if (is_bool($raw)) {
            return $raw;
        }
        $normalized = strtolower((string) $raw);
        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        return $default;
    }

    private function intEnv(string $key, int $default, int $min, int $max, bool $allowZero = false): int
    {
        $raw = env($key, $default);
        if (is_bool($raw)) {
            $raw = $raw ? 1 : 0;
        }

        if (!is_numeric($raw)) {
            $value = $default;
        } else {
            $value = (int) $raw;
        }

        if ($allowZero && $value === 0) {
            return 0;
        }

        if ($value < $min) {
            $value = $min;
        }

        if ($value > $max) {
            $value = $max;
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function buildAllowedDestinationCountries(?string $allowedCountries): array
    {
        if ($allowedCountries === null) {
            return self::DEFAULT_ALLOWED_DESTINATION_COUNTRIES;
        }

        $tokens = preg_split('/[,;]+/', $allowedCountries) ?: [];
        $result = [];
        foreach ($tokens as $token) {
            $code = strtoupper(trim($token));
            if ($code === '') {
                continue;
            }
            $result[$code] = self::DEFAULT_ALLOWED_DESTINATION_COUNTRIES[$code] ?? $code;
        }

        if ($result === []) {
            return self::DEFAULT_ALLOWED_DESTINATION_COUNTRIES;
        }

        return $result;
    }

    /**
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function parseStatuses(?string $value, array $default): array
    {
        if ($value === null) {
            return $default;
        }

        $tokens = preg_split('/[,;\|]+/', $value) ?: [];
        $result = [];
        foreach ($tokens as $token) {
            $normalized = strtolower(trim($token));
            if ($normalized === '') {
                continue;
            }
            $result[$normalized] = $normalized;
        }

        if ($result === []) {
            return $default;
        }

        return array_values($result);
    }

    private function resolveApiKey(string $primaryEnvKey): ?string
    {
        $primary = $this->clean(env($primaryEnvKey));
        if ($primary !== null) {
            return $primary;
        }

        return $this->restApiKey;
    }

    private function normalizePortalBaseUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $trimmed = rtrim($url, '/');
        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveManifestEndpoint(?string $endpoint): string
    {
        $normalized = $endpoint ?? '';
        if ($normalized === '') {
            return '/manifests/official';
        }

        if ($normalized[0] !== '/') {
            $normalized = '/' . $normalized;
        }

        return $normalized;
    }

    private function resolveRoutingEndpoint(?string $endpoint): string
    {
        $normalized = $endpoint ?? '';
        if ($normalized === '') {
            return '/shipments/routing';
        }

        $trimmed = trim($normalized);
        if ($trimmed === '') {
            return '/shipments/routing';
        }

        if ($trimmed[0] !== '/') {
            $trimmed = '/' . $trimmed;
        }

        return $trimmed;
    }

    private function resolvePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return $trimmed;
        }

        $isAbsolute = preg_match('#^[A-Za-z]:[\\/]#', $trimmed) === 1
            || strncmp($trimmed, '\\', 1) === 0
            || strncmp($trimmed, '/', 1) === 0;
        if ($isAbsolute) {
            $resolved = realpath($trimmed);
            return $resolved !== false ? $resolved : $trimmed;
        }

        $baseDir = realpath(__DIR__ . '/../../..');
        if ($baseDir === false) {
            return $trimmed;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($trimmed, '\/'));
        $candidate = $baseDir . DIRECTORY_SEPARATOR . $normalized;
        $resolved = realpath($candidate);
        return $resolved !== false ? $resolved : $candidate;
    }
}
