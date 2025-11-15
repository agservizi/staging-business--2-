<?php
declare(strict_types=1);

namespace App\Services\Brt;

use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_values;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function preg_split;
use function sprintf;
use function strlen;
use function strtoupper;
use function trim;

final class BrtPudoService
{
    private const SEARCH_MODE_ADDRESS = 'address';

    private const SEARCH_MODE_COORDINATES = 'coordinates';

    private const DEFAULT_LIMIT = 25;

    private const MIN_LIMIT = 1;

    private const MAX_LIMIT = 25;

    private const DEFAULT_DISTANCE = 40000;

    private const MIN_DISTANCE = 100;

    private const MAX_DISTANCE = 50000;

    /**
     * @var array<string, string>
     */
    private const COUNTRY_ALPHA2_TO_ALPHA3 = [
        'AD' => 'AND',
        'AL' => 'ALB',
        'AT' => 'AUT',
        'BA' => 'BIH',
        'BE' => 'BEL',
        'BG' => 'BGR',
        'CH' => 'CHE',
        'CY' => 'CYP',
        'CZ' => 'CZE',
        'DE' => 'DEU',
        'DK' => 'DNK',
        'EE' => 'EST',
        'ES' => 'ESP',
        'FI' => 'FIN',
        'FR' => 'FRA',
        'GB' => 'GBR',
        'GR' => 'GRC',
        'HR' => 'HRV',
        'HU' => 'HUN',
        'IE' => 'IRL',
        'IS' => 'ISL',
        'IT' => 'ITA',
        'LI' => 'LIE',
        'LT' => 'LTU',
        'LU' => 'LUX',
        'LV' => 'LVA',
        'MC' => 'MCO',
        'ME' => 'MNE',
        'MK' => 'MKD',
        'MT' => 'MLT',
        'NL' => 'NLD',
        'NO' => 'NOR',
        'PL' => 'POL',
        'PT' => 'PRT',
        'RO' => 'ROU',
        'RS' => 'SRB',
        'SE' => 'SWE',
        'SI' => 'SVN',
        'SK' => 'SVK',
        'SM' => 'SMR',
        'TR' => 'TUR',
        'UA' => 'UKR',
    ];

    private BrtConfig $config;

    private ?string $authToken;

    private ?string $restApiKey;

    private BrtHttpClient $openPickupClient;

    public function __construct(?BrtConfig $config = null)
    {
        $this->config = $config ?? new BrtConfig();
        $this->authToken = $this->config->getPudoApiAuth();
        $this->restApiKey = $this->config->getRestApiKey();

        $pudoHeaders = [];
        if ($this->authToken !== null && $this->authToken !== '') {
            $pudoHeaders[] = 'X-API-Auth: ' . $this->authToken;
        }
        if ($this->restApiKey !== null && $this->restApiKey !== '') {
            $pudoHeaders[] = 'X-Api-Key: ' . $this->restApiKey;
        }

        $this->openPickupClient = new BrtHttpClient(
            $this->config->getPudoBaseUrl(),
            $pudoHeaders,
            $this->config->getCaBundlePath()
        );
    }

    /**
     * @param array<string, string|null> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function search(array $criteria): array
    {
        if ($this->authToken === null || $this->authToken === '') {
            throw new BrtException('Configurare BRT_PUDO_API_AUTH nel file .env per utilizzare la ricerca PUDO.');
        }

        $mode = $this->detectSearchMode($criteria);
        $query = $this->buildOpenPickupQuery($criteria, $mode);
        $path = $mode === self::SEARCH_MODE_COORDINATES ? 'get-pudo-by-lat-lng' : 'get-pudo-by-address';

        $response = $this->openPickupClient->request('GET', $path, $query);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = 'Ricerca PUDO BRT fallita.';
            $body = $response['body'];

            if (is_array($body)) {
                if (isset($body['message'])) {
                    $message = (string) $body['message'];
                } elseif (isset($body['error'])) {
                    $message = is_array($body['error']) && isset($body['error']['message'])
                        ? (string) $body['error']['message']
                        : (string) $body['error'];
                } elseif (isset($body['detail'])) {
                    $message = (string) $body['detail'];
                } elseif (isset($body['executionMessage']) && is_array($body['executionMessage'])) {
                    $exec = $body['executionMessage'];
                    $message = (string) ($exec['codeDesc'] ?? $exec['message'] ?? $message);
                } elseif (isset($body['errors']) && is_array($body['errors']) && $body['errors'] !== []) {
                    $firstError = reset($body['errors']);
                    if (is_array($firstError) && isset($firstError['message'])) {
                        $message = (string) $firstError['message'];
                    } elseif (is_string($firstError)) {
                        $message = $firstError;
                    }
                } else {
                    $encoded = json_encode($body);
                    if (is_string($encoded) && $encoded !== '') {
                        $message = sprintf('%s Dettagli: %s', $message, $encoded);
                    }
                }
            } elseif (is_string($body) && $body !== '') {
                $message = $body;
            }

            throw new BrtException(sprintf('%s (HTTP %d).', $message, $response['status']));
        }

        $body = $response['body'];
        if (!is_array($body)) {
            throw new BrtException('Risposta inattesa dal servizio PUDO BRT.');
        }

        if (isset($body['executionMessage']) && is_array($body['executionMessage'])) {
            $message = $body['executionMessage'];
            $code = isset($message['code']) ? (int) $message['code'] : 0;
            if ($code < 0) {
                $description = isset($message['codeDesc']) ? (string) $message['codeDesc'] : 'Errore PUDO';
                $details = isset($message['message']) ? (string) $message['message'] : '';
                $error = $description;
                if ($details !== '') {
                    $error .= ' ' . $details;
                }
                throw new BrtException($error);
            }
        }

        $rawItems = [];
        if (isset($body['pudoList']) && is_array($body['pudoList'])) {
            $rawItems = $body['pudoList'];
        } elseif (isset($body['pudo']) && is_array($body['pudo'])) {
            $rawItems = $body['pudo'];
        } elseif ($this->isSequentialArray($body)) {
            $rawItems = $body;
        }

        if (!is_array($rawItems)) {
            throw new BrtException('Impossibile interpretare i PUDO restituiti da BRT.');
        }

        $normalized = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalizedItem = $this->normalizeItem($item);
            if ($normalizedItem !== null) {
                $normalized[] = $normalizedItem;
            }
        }

        return $normalized;
    }

    private function detectSearchMode(array $criteria): string
    {
        $latitude = $this->clean($criteria['latitude'] ?? $criteria['lat'] ?? null);
        $longitude = $this->clean($criteria['longitude'] ?? $criteria['lng'] ?? $criteria['lon'] ?? null);

        if ($latitude !== '' && $longitude !== '') {
            return self::SEARCH_MODE_COORDINATES;
        }

        return self::SEARCH_MODE_ADDRESS;
    }

    /**
     * @param array<string, string|null> $criteria
     * @return array<string, string>
     */
    private function buildOpenPickupQuery(array $criteria, string $mode): array
    {
        $countryRaw = $this->clean($criteria['country'] ?? $criteria['countryCode'] ?? null);
        if ($countryRaw === '') {
            $countryRaw = $this->config->getDefaultCountryIsoAlpha2() ?? 'IT';
        }

        $countryCode = $this->toIsoAlpha3($countryRaw);
        if ($countryCode === null) {
            throw new BrtException('Specifica un paese valido per la ricerca PUDO.');
        }

        $query = [
            'countryCode' => $countryCode,
            'max_pudo_number' => (string) $this->normalizeLimit($criteria['limit'] ?? null),
        ];

        $distance = $this->normalizeDistance($criteria['maxDistanceSearch'] ?? $criteria['distance'] ?? $criteria['radius'] ?? null);
        if ($distance !== null) {
            $query['maxDistanceSearch'] = (string) $distance;
        }

        $language = $this->clean($criteria['language'] ?? null);
        if ($language !== '') {
            $query['language'] = $language;
        }

        $destCountry = $this->clean($criteria['destcountrycode'] ?? $criteria['destCountryCode'] ?? null);
        if ($destCountry !== '') {
            $normalizedDest = $this->normalizeDestinationCountryList($destCountry);
            if ($normalizedDest !== '') {
                $query['destcountrycode'] = $normalizedDest;
            }
        }

        $holidayTolerance = $this->clean($criteria['holiday_tolerant'] ?? $criteria['holidayTolerant'] ?? null);
        if ($holidayTolerance !== '') {
            $query['holiday_tolerant'] = $holidayTolerance;
        }

        $servicePudo = $this->clean($criteria['servicePudo'] ?? null);
        if ($servicePudo !== '') {
            $query['servicePudo'] = $servicePudo;
        }

        $servicePudoDisplay = $this->clean($criteria['servicePudo_display'] ?? $criteria['servicePudoDisplay'] ?? null);
        if ($servicePudoDisplay !== '') {
            $query['servicePudo_display'] = $servicePudoDisplay;
        }

        $category = $this->clean($criteria['category'] ?? null);
        if ($category !== '') {
            $query['category'] = $category;
        }

        $weight = $this->clean($criteria['weight'] ?? null);
        if ($weight !== '') {
            $query['weight'] = $weight;
        }

        $dateFrom = $this->clean($criteria['date_from'] ?? $criteria['dateFrom'] ?? null);
        if ($dateFrom !== '') {
            $query['date_from'] = $dateFrom;
        }

        $province = $this->clean($criteria['province'] ?? null);
        if ($province !== '') {
            $query['province'] = strtoupper($province);
        }

        if ($mode === self::SEARCH_MODE_COORDINATES) {
            $latitude = $this->clean($criteria['latitude'] ?? $criteria['lat'] ?? null);
            $longitude = $this->clean($criteria['longitude'] ?? $criteria['lng'] ?? $criteria['lon'] ?? null);

            if ($latitude === '' || $longitude === '') {
                throw new BrtException('Specifica sia latitudine che longitudine per cercare i PUDO.');
            }

            $query['latitude'] = $latitude;
            $query['longitude'] = $longitude;

            $city = $this->clean($criteria['city'] ?? null);
            if ($city !== '') {
                $query['city'] = strtoupper($city);
            }

            $zip = $this->clean($criteria['zipCode'] ?? $criteria['zip'] ?? null);
            if ($zip !== '') {
                $query['zipCode'] = $zip;
            }
        } else {
            $zip = $this->clean($criteria['zipCode'] ?? $criteria['zip'] ?? null);
            $city = $this->clean($criteria['city'] ?? null);
            if ($zip === '' || $city === '') {
                throw new BrtException('Specifica CAP e città per cercare i PUDO.');
            }

            $query['zipCode'] = $zip;
            $query['city'] = strtoupper($city);

            $address = $this->clean($criteria['address'] ?? $criteria['street'] ?? null);
            if ($address !== '') {
                $query['address'] = $address;
            }
        }

        return $query;
    }

    private function normalizeLimit($limit): int
    {
        $value = self::DEFAULT_LIMIT;

        if (is_numeric($limit)) {
            $value = (int) $limit;
        } elseif (is_string($limit)) {
            $trimmed = $this->clean($limit);
            if ($trimmed !== '' && is_numeric($trimmed)) {
                $value = (int) $trimmed;
            }
        }

        return (int) max(self::MIN_LIMIT, min(self::MAX_LIMIT, $value));
    }

    /**
     * @param mixed $distance
     */
    private function normalizeDistance($distance): ?int
    {
        if ($distance === null) {
            return null;
        }

        if (is_numeric($distance)) {
            $value = (int) $distance;
        } elseif (is_string($distance)) {
            $trimmed = $this->clean($distance);
            if ($trimmed === '' || !is_numeric($trimmed)) {
                return null;
            }
            $value = (int) $trimmed;
        } else {
            return null;
        }

        return (int) max(self::MIN_DISTANCE, min(self::MAX_DISTANCE, $value));
    }

    private function normalizeDestinationCountryList(string $raw): string
    {
        $tokens = preg_split('/[,;\s]+/', $raw) ?: [];
        $converted = [];

        foreach ($tokens as $token) {
            $normalized = strtoupper(trim($token));
            if ($normalized === '') {
                continue;
            }

            if (strlen($normalized) === 3) {
                $converted[] = $normalized;
                continue;
            }

            if (strlen($normalized) === 2) {
                $mapped = self::COUNTRY_ALPHA2_TO_ALPHA3[$normalized] ?? null;
                if ($mapped !== null) {
                    $converted[] = $mapped;
                }
            }
        }

        $unique = array_values(array_unique($converted));

        return $unique === [] ? '' : implode(';', $unique);
    }

    private function toIsoAlpha3(?string $country): ?string
    {
        if ($country === null) {
            return null;
        }

        $normalized = strtoupper(trim($country));
        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) === 3) {
            return $normalized;
        }

        if (strlen($normalized) === 2) {
            return self::COUNTRY_ALPHA2_TO_ALPHA3[$normalized] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function normalizeItem(array $item): ?array
    {
        $id = $this->clean($item['pudoId'] ?? $item['id'] ?? null);
        if ($id === '') {
            return null;
        }

        $name = $this->clean($item['name'] ?? $item['pudoName'] ?? null);
        $address = $this->clean($item['address'] ?? $item['street'] ?? null);
        $zip = $this->clean($item['zipCode'] ?? $item['zip'] ?? null);
        $city = $this->clean($item['city'] ?? null);
        $province = $this->clean($item['province'] ?? $item['region'] ?? null);
        $country = $this->clean($item['country'] ?? $item['countryCode'] ?? null);

        $latitude = $this->parseFloat($item['latitude'] ?? $item['lat'] ?? null);
        $longitude = $this->parseFloat($item['longitude'] ?? $item['lng'] ?? $item['lon'] ?? null);

        $openingHours = [];
        if (isset($item['openingHours']) && is_array($item['openingHours'])) {
            foreach ($item['openingHours'] as $slot) {
                if (is_string($slot)) {
                    $cleanSlot = trim($slot);
                    if ($cleanSlot !== '') {
                        $openingHours[] = $cleanSlot;
                    }
                    continue;
                }
                if (is_array($slot)) {
                    $day = $this->clean($slot['day'] ?? $slot['dayOfWeek'] ?? null);
                    $from = $this->clean($slot['from'] ?? $slot['open'] ?? null);
                    $to = $this->clean($slot['to'] ?? $slot['close'] ?? null);
                    $range = '';
                    if ($from !== '' && $to !== '') {
                        $range = $from . '-' . $to;
                    } elseif ($from !== '') {
                        $range = $from;
                    } elseif ($to !== '') {
                        $range = $to;
                    }
                    $entry = trim($day . ' ' . $range);
                    if ($entry !== '') {
                        $openingHours[] = $entry;
                    }
                }
            }
        }

        if ($openingHours === [] && isset($item['openingHoursText']) && is_string($item['openingHoursText'])) {
            $openingHours = [trim($item['openingHoursText'])];
        }

        $distance = null;
        if (array_key_exists('distance', $item)) {
            $distanceValue = $item['distance'];
            if (is_numeric($distanceValue)) {
                $distance = (float) $distanceValue;
            } elseif (is_string($distanceValue) && $distanceValue !== '') {
                $distance = (float) $distanceValue;
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'address' => $address,
            'zipCode' => $zip,
            'city' => $city,
            'province' => $province,
            'country' => $country,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'openingHours' => array_values(array_filter($openingHours, static fn ($value) => $value !== '')),
            'distanceKm' => $distance,
        ];
    }

    private function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return trim($value);
    }

    /**
     * @param mixed $value
     */
    private function parseFloat($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && $value !== '') {
            return (float) $value;
        }
        return null;
    }

    /**
     * @param array<mixed> $value
     */
    private function isSequentialArray(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }
}
