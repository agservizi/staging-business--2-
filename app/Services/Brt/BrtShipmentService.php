<?php
declare(strict_types=1);

namespace App\Services\Brt;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;

final class BrtShipmentService
{
    private const NETWORK_ALIAS_BY_INPUT = [
        'I' => 'ITALIA',
        'IT' => 'ITALIA',
        'ITA' => 'ITALIA',
        'ITALIA' => 'ITALIA',
        'ITALY' => 'ITALIA',
        'E' => 'EUROPE',
        'EUR' => 'EUROPE',
        'EUROPE' => 'EUROPE',
        'EUROPA' => 'EUROPE',
        'P' => 'PUDO',
        'PUDO' => 'PUDO',
        'FERMOPOINT' => 'PUDO',
        'B' => 'B2C',
        'B2C' => 'B2C',
        'D' => 'DPD',
        'DPD' => 'DPD',
        'S' => 'SWISS',
        'SWISS' => 'SWISS',
        'CH' => 'SWISS',
    ];

    private const NETWORK_CODE_BY_ALIAS = [
        'ITALIA' => 'I',
        'EUROPE' => 'E',
        'PUDO' => 'P',
        'B2C' => 'B',
        'DPD' => 'D',
        'SWISS' => 'S',
    ];

    private BrtConfig $config;

    private BrtHttpClient $client;

    public function __construct(?BrtConfig $config = null)
    {
        $this->config = $config ?? new BrtConfig();
        $defaultHeaders = [];
        $apiKey = $this->config->getRestApiKey();
        if ($apiKey === null || $apiKey === '') {
            throw new BrtException('Configurare BRT_API_KEY nel file .env per utilizzare le API spedizioni BRT.');
        }

        $defaultHeaders[] = 'X-API-Key: ' . $apiKey;
        $this->client = new BrtHttpClient($this->config->getRestBaseUrl(), $defaultHeaders, $this->config->getCaBundlePath());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createShipment(array $input): array
    {
        $payload = [
            'account' => $this->buildAccountData(),
            'createData' => $this->buildCreateData($input),
            'isLabelRequired' => $this->resolveLabelRequired($input),
        ];

        $actualSender = $this->buildActualSenderData($input);
        if ($actualSender !== null) {
            $payload['actualSender'] = $actualSender;
        }

        $labelOverrides = $this->buildLabelParameters($input);
        if ($labelOverrides !== null) {
            $payload['labelParameters'] = $labelOverrides;
        }

        $response = $this->client->request('POST', '/shipments/shipment', null, $payload);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            if (!$message && isset($response['raw']) && is_string($response['raw'])) {
                $thinRaw = trim($response['raw']);
                if ($thinRaw !== '') {
                    $message = $this->truncateMessage($thinRaw);
                }
            }
            if (!$message) {
                $message = sprintf('Creazione spedizione BRT fallita (HTTP %d).', $response['status']);
            }
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body) || !isset($body['createResponse']) || !is_array($body['createResponse'])) {
            throw new BrtException('Risposta inattesa durante la creazione della spedizione BRT.');
        }

        $this->assertExecutionSuccess($body['createResponse']);

        return $body['createResponse'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function getRoutingQuote(array $input): array
    {
        $payload = [
            'account' => $this->buildAccountData(),
            'routingData' => $this->buildRoutingData($input),
        ];

    $response = $this->client->request('POST', $this->config->getRoutingEndpoint(), null, $payload);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            if (!$message && isset($response['raw']) && is_string($response['raw'])) {
                $thinRaw = trim($response['raw']);
                if ($thinRaw !== '') {
                    $message = $this->truncateMessage($thinRaw);
                }
            }
            if (!$message) {
                $message = sprintf('Calcolo dei costi BRT non riuscito (HTTP %d).', $response['status']);
            }
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body) || !isset($body['routingResponse']) || !is_array($body['routingResponse'])) {
            throw new BrtException('Risposta inattesa durante il calcolo dei costi BRT.');
        }

        return $body['routingResponse'];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function confirmShipment(array $input, array $options = []): array
    {
        $payload = [
            'account' => $this->buildAccountData(),
            'confirmData' => $this->buildConfirmOrDeleteData($input),
        ];

        $labelParametersOverride = $options['labelParameters'] ?? null;
        $forceLabel = (bool) ($options['forceLabel'] ?? false);

        if ($labelParametersOverride !== null && !is_array($labelParametersOverride)) {
            $labelParametersOverride = [];
        }

        $labelOverrides = null;
        if (is_array($labelParametersOverride)) {
            $labelOverrides = $this->buildLabelParameters($labelParametersOverride);
        } elseif ($forceLabel) {
            $labelOverrides = $this->buildLabelParameters([]);
        }

        if ($labelOverrides !== null && $labelOverrides !== []) {
            $payload['labelParameters'] = $labelOverrides;
        }

        $response = $this->client->request('PUT', '/shipments/shipment', null, $payload);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            if (!$message && isset($response['raw']) && is_string($response['raw'])) {
                $thinRaw = trim($response['raw']);
                if ($thinRaw !== '') {
                    $message = $this->truncateMessage($thinRaw);
                }
            }
            if (!$message) {
                $message = sprintf('Conferma spedizione BRT fallita (HTTP %d).', $response['status']);
            }
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body) || !isset($body['confirmResponse']) || !is_array($body['confirmResponse'])) {
            throw new BrtException('Risposta inattesa durante la conferma della spedizione BRT.');
        }

        $this->assertExecutionSuccess($body['confirmResponse']);

        return $body['confirmResponse'];
    }

    /**
     * @param array<string, mixed> $originalReferences
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function updateShipment(array $originalReferences, array $input, array $options = []): array
    {
        $reference = $this->buildConfirmOrDeleteData($originalReferences);
        $updateData = $this->buildCreateData($input);

        $updateData['originalNumericSenderReference'] = (int) ($reference['numericSenderReference'] ?? 0);
        if (isset($reference['alphanumericSenderReference'])) {
            $updateData['originalAlphanumericSenderReference'] = $reference['alphanumericSenderReference'];
        }

        $payload = [
            'account' => $this->buildAccountData(),
            'updateData' => array_merge($reference, $updateData),
        ];

        $labelParametersOverride = $options['labelParameters'] ?? null;
        $forceLabel = (bool) ($options['forceLabel'] ?? false);

        if ($labelParametersOverride !== null && !is_array($labelParametersOverride)) {
            $labelParametersOverride = [];
        }

        $labelOverrides = null;
        if (is_array($labelParametersOverride)) {
            $labelOverrides = $this->buildLabelParameters($labelParametersOverride);
        } elseif ($forceLabel) {
            $labelOverrides = $this->buildLabelParameters([]);
        }

        if ($labelOverrides !== null && $labelOverrides !== []) {
            $payload['labelParameters'] = $labelOverrides;
        }

        $response = $this->client->request('PUT', '/shipments/update', null, $payload);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            if (!$message && isset($response['raw']) && is_string($response['raw'])) {
                $thinRaw = trim($response['raw']);
                if ($thinRaw !== '') {
                    $message = $this->truncateMessage($thinRaw);
                }
            }
            if (!$message) {
                $message = sprintf('Aggiornamento spedizione BRT fallito (HTTP %d).', $response['status']);
            }
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body) || !isset($body['updateResponse']) || !is_array($body['updateResponse'])) {
            throw new BrtException('Risposta inattesa durante l\'aggiornamento della spedizione BRT.');
        }

        $this->assertExecutionSuccess($body['updateResponse']);

        return $body['updateResponse'];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function reprintShipmentLabel(array $input, array $options = []): array
    {
        $options['forceLabel'] = true;
        $response = $this->confirmShipment($input, $options);

        $labels = $response['labels']['label'] ?? null;
        if (!is_array($labels) || $labels === []) {
            throw new BrtException('Il webservice BRT non ha restituito nessuna etichetta da ristampare.');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function deleteShipment(array $input): array
    {
        $payload = [
            'account' => $this->buildAccountData(),
            'deleteData' => $this->buildConfirmOrDeleteData($input),
        ];

        $response = $this->client->request('PUT', '/shipments/delete', null, $payload);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            if (!$message && isset($response['raw']) && is_string($response['raw'])) {
                $thinRaw = trim($response['raw']);
                if ($thinRaw !== '') {
                    $message = $this->truncateMessage($thinRaw);
                }
            }
            if (!$message) {
                $message = sprintf('Cancellazione spedizione BRT fallita (HTTP %d).', $response['status']);
            }
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body) || !isset($body['deleteResponse']) || !is_array($body['deleteResponse'])) {
            throw new BrtException('Risposta inattesa durante la cancellazione della spedizione BRT.');
        }

        $this->assertExecutionSuccess($body['deleteResponse']);

        return $body['deleteResponse'];
    }

    /**
     * @return array<string, string>
     */
    private function buildAccountData(): array
    {
        return [
            'userID' => $this->config->getAccountUserId(),
            'password' => $this->config->getAccountPassword(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildCreateData(array $input): array
    {
    $senderCustomerCode = $input['senderCustomerCode'] ?? $this->config->getSenderCustomerCode();
    $departureDepot = $input['departureDepot'] ?? $this->config->getDepartureDepot();
    $hasNetworkOverride = array_key_exists('network', $input);
    $networkInput = $hasNetworkOverride ? $input['network'] : null;
    $requestedNetwork = $this->sanitizeNetworkCode($networkInput ?? '');
    $defaultNetworkCode = $this->sanitizeNetworkCode($this->config->getDefaultNetwork() ?? '');
    $networkAlias = $this->normalizeNetworkAlias($networkInput ?? $this->config->getDefaultNetwork());
    $pricingCondition = $input['pricingConditionCode'] ?? $this->config->getPricingConditionCode($networkAlias);
        $returnServiceCode = strtoupper($this->config->getReturnServiceCode() ?? '');
        $brtServiceCode = strtoupper($this->toString($input['brtServiceCode'] ?? $this->config->getDefaultBrtServiceCode() ?? ''));
        $returnDepotCode = $this->toString($input['returnDepot'] ?? '');
        if ($returnDepotCode === '' && $brtServiceCode !== '' && $returnServiceCode !== '' && $brtServiceCode === $returnServiceCode) {
            $configuredReturnDepot = $this->config->getDefaultReturnDepot();
            if ($configuredReturnDepot !== null) {
                $returnDepotCode = $this->toString($configuredReturnDepot);
            }
        }
        $alphanumericReference = $this->sanitizeAlphanumericSenderReference($input['alphanumericSenderReference'] ?? null);

        $defaults = [
            'departureDepot' => $this->toString($departureDepot),
            'senderCustomerCode' => $this->toString($senderCustomerCode),
            'deliveryFreightTypeCode' => $input['deliveryFreightTypeCode'] ?? $this->config->getDefaultDeliveryFreightTypeCode(),
            'pricingConditionCode' => $pricingCondition ?? '',
            'serviceType' => $input['serviceType'] ?? $this->config->getDefaultServiceType() ?? '',
            'pudoId' => $input['pudoId'] ?? $this->config->getDefaultPudoId() ?? '',
            'senderParcelType' => $input['senderParcelType'] ?? '',
            'numberOfParcels' => max(1, (int) ($input['numberOfParcels'] ?? 1)),
            'weightKG' => $this->normalizeFloat($input['weightKG'] ?? 1),
            'volumeM3' => $this->normalizeFloat($input['volumeM3'] ?? 0),
            'quantityToBeInvoiced' => $this->normalizeFloat($input['quantityToBeInvoiced'] ?? 0),
            'cashOnDelivery' => $this->toString($input['cashOnDelivery'] ?? '0'),
            'isCODMandatory' => $this->toString($input['isCODMandatory'] ?? '0'),
            'codPaymentType' => $input['codPaymentType'] ?? null,
            'codCurrency' => $input['codCurrency'] ?? 'EUR',
            'numericSenderReference' => (int) ($input['numericSenderReference'] ?? 0),
            'notes' => $input['notes'] ?? null,
            'parcelsHandlingCode' => $input['parcelsHandlingCode'] ?? '',
            'deliveryDateRequired' => $input['deliveryDateRequired'] ?? '',
            'deliveryType' => $input['deliveryType'] ?? '',
            'declaredParcelValue' => $this->normalizeFloat($input['declaredParcelValue'] ?? 0),
            'declaredParcelValueCurrency' => $input['declaredParcelValueCurrency'] ?? '',
            'particularitiesDeliveryManagementCode' => $input['particularitiesDeliveryManagementCode'] ?? '',
            'particularitiesHoldOnStockManagementCode' => $input['particularitiesHoldOnStockManagementCode'] ?? '',
            'variousParticularitiesManagementCode' => $input['variousParticularitiesManagementCode'] ?? '',
            'particularDelivery1' => $input['particularDelivery1'] ?? '',
            'particularDelivery2' => $input['particularDelivery2'] ?? '',
            'palletType1' => $input['palletType1'] ?? '',
            'palletType1Number' => (int) ($input['palletType1Number'] ?? 0),
            'palletType2' => $input['palletType2'] ?? '',
            'palletType2Number' => (int) ($input['palletType2Number'] ?? 0),
            'originalSenderCompanyName' => $input['originalSenderCompanyName'] ?? '',
            'originalSenderZIPCode' => $input['originalSenderZIPCode'] ?? '',
            'originalSenderCountryAbbreviationISOAlpha2' => $input['originalSenderCountryAbbreviationISOAlpha2'] ?? $this->config->getDefaultCountryIsoAlpha2(),
            'cmrCode' => $input['cmrCode'] ?? '',
            'neighborNameMandatoryAuthorization' => $input['neighborNameMandatoryAuthorization'] ?? '',
            'pinCodeMandatoryAuthorization' => $input['pinCodeMandatoryAuthorization'] ?? '',
            'packingListPDFName' => $input['packingListPDFName'] ?? '',
            'packingListPDFFlagPrint' => $input['packingListPDFFlagPrint'] ?? '',
            'packingListPDFFlagEmail' => $input['packingListPDFFlagEmail'] ?? '',
        ];

        if ($brtServiceCode !== '') {
            $defaults['brtServiceCode'] = $brtServiceCode;
        }

        if ($returnDepotCode !== '') {
            $defaults['returnDepot'] = $returnDepotCode;
        }

        if ($alphanumericReference !== null) {
            $defaults['alphanumericSenderReference'] = $alphanumericReference;
        }

        $consigneeData = $this->extractConsigneeData($input);

        $insuranceAmount = $this->normalizeFloat($input['insuranceAmount'] ?? 0);
        $insuranceCurrency = $input['insuranceAmountCurrency'] ?? 'EUR';
        if ($insuranceAmount > 0) {
            $defaults['insuranceAmount'] = $insuranceAmount;
            $defaults['insuranceAmountCurrency'] = $insuranceCurrency;
        }

        if (isset($input['cashOnDeliveryAmount'])) {
            $defaults['cashOnDelivery'] = $this->toString($input['cashOnDeliveryAmount']);
        }

        if (!empty($input['alerts']) && is_array($input['alerts'])) {
            $defaults['alerts'] = $input['alerts'];
        }

        if ($requestedNetwork !== '' && $hasNetworkOverride) {
            $defaults['network'] = $requestedNetwork;
        } elseif (!$hasNetworkOverride && $defaultNetworkCode !== '') {
            $defaults['network'] = $defaultNetworkCode;
        }

        return array_merge($defaults, $consigneeData);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildConfirmOrDeleteData(array $input): array
    {
        $numericReference = (int) ($input['numericSenderReference'] ?? 0);
        if ($numericReference <= 0) {
            throw new BrtException('Il riferimento numerico mittente è obbligatorio per conferma/cancellazione.');
        }

        $payload = [
            'senderCustomerCode' => $this->toString($input['senderCustomerCode'] ?? $this->config->getSenderCustomerCode()),
            'numericSenderReference' => $numericReference,
        ];

        $alphaReference = $this->sanitizeAlphanumericSenderReference($input['alphanumericSenderReference'] ?? null);
        if ($alphaReference !== null) {
            $payload['alphanumericSenderReference'] = $alphaReference;
        }

        $cmrCode = $this->toString($input['cmrCode'] ?? '');
        if ($cmrCode !== '') {
            $payload['cmrCode'] = $cmrCode;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildRoutingData(array $input): array
    {
    $hasNetworkOverride = array_key_exists('network', $input);
    $networkValue = $hasNetworkOverride ? $input['network'] : null;
    $network = $this->sanitizeNetworkCode($networkValue ?? '');
    $defaultNetworkCode = $this->sanitizeNetworkCode($this->config->getDefaultNetwork() ?? '');
    $networkAlias = $this->normalizeNetworkAlias($networkValue ?? $this->config->getDefaultNetwork());
    $pricingConditionCode = $input['pricingConditionCode'] ?? $this->config->getPricingConditionCode($networkAlias);

        $zipCode = $this->toString($input['consigneeZIPCode'] ?? $input['zipCode'] ?? '');
        $country = $this->toString($input['consigneeCountryAbbreviationISOAlpha2'] ?? $input['countryAbbreviationISOAlpha2'] ?? $this->config->getDefaultCountryIsoAlpha2() ?? 'IT');

        $routing = [
            'senderCustomerCode' => $this->toString($input['senderCustomerCode'] ?? $this->config->getSenderCustomerCode()),
            'departureDepot' => $this->toString($input['departureDepot'] ?? $this->config->getDepartureDepot()),
            'deliveryFreightTypeCode' => $this->toString($input['deliveryFreightTypeCode'] ?? $this->config->getDefaultDeliveryFreightTypeCode()),
            'pricingConditionCode' => $this->toString($pricingConditionCode ?? ''),
            'numberOfParcels' => max(1, (int) ($input['numberOfParcels'] ?? 1)),
            'weightKG' => $this->normalizeFloat($input['weightKG'] ?? 0),
            'volumeM3' => $this->normalizeFloat($input['volumeM3'] ?? 0),
            'zipCode' => $zipCode,
            'countryAbbreviationISOAlpha2' => $country,
        ];

        $numericReference = isset($input['numericSenderReference']) ? (int) $input['numericSenderReference'] : 0;
        if ($numericReference > 0) {
            $routing['numericSenderReference'] = $numericReference;
        }

        $alphaReference = $this->sanitizeAlphanumericSenderReference($input['alphanumericSenderReference'] ?? null);
        if ($alphaReference !== null) {
            $routing['alphanumericSenderReference'] = $alphaReference;
        }

        if ($network !== '' && $hasNetworkOverride) {
            $routing['network'] = $network;
        } elseif (!$hasNetworkOverride && $defaultNetworkCode !== '') {
            $routing['network'] = $defaultNetworkCode;
        }

        $serviceType = $this->toString($input['serviceType'] ?? $this->config->getDefaultServiceType() ?? '');
        if ($serviceType !== '') {
            $routing['serviceType'] = $serviceType;
        }

        $senderParcelType = $this->toString($input['senderParcelType'] ?? '');
        if ($senderParcelType !== '') {
            $routing['senderParcelType'] = $senderParcelType;
        }

        $quantityToBeInvoiced = $this->normalizeFloat($input['quantityToBeInvoiced'] ?? 0);
        if ($quantityToBeInvoiced > 0) {
            $routing['quantityToBeInvoiced'] = $quantityToBeInvoiced;
        }

        if (isset($input['dimensionLengthCM'], $input['dimensionDepthCM'], $input['dimensionHeightCM'])) {
            $routing['parcelDimensions'] = [
                'lengthCM' => $this->normalizeFloat($input['dimensionLengthCM']),
                'depthCM' => $this->normalizeFloat($input['dimensionDepthCM']),
                'heightCM' => $this->normalizeFloat($input['dimensionHeightCM']),
            ];
        }

        if (isset($input['volumetricWeightKG'])) {
            $routing['volumetricWeightKG'] = $this->normalizeFloat($input['volumetricWeightKG']);
        }

        if (isset($input['cashOnDeliveryAmount'])) {
            $routing['cashOnDelivery'] = $this->toString($input['cashOnDeliveryAmount']);
        }

        if (isset($input['isCODMandatory'])) {
            $routing['isCODMandatory'] = $this->toString($input['isCODMandatory']);
        }

        if (isset($input['codCurrency'])) {
            $routing['codCurrency'] = $this->toString($input['codCurrency']);
        }

        if (isset($input['codPaymentType'])) {
            $routing['codPaymentType'] = $this->toString($input['codPaymentType']);
        }

        if (isset($input['insuranceAmount'])) {
            $insuranceAmount = $this->normalizeFloat($input['insuranceAmount']);
            if ($insuranceAmount > 0) {
                $routing['insuranceAmount'] = $insuranceAmount;
                $routing['insuranceAmountCurrency'] = $this->toString($input['insuranceAmountCurrency'] ?? 'EUR');
            }
        }

        if (isset($input['payerType'])) {
            $routing['payerType'] = $this->toString($input['payerType']);
        }

        return $routing;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function extractConsigneeData(array $input): array
    {
        $country = $input['consigneeCountryAbbreviationISOAlpha2'] ?? $this->config->getDefaultCountryIsoAlpha2() ?? 'IT';

        return [
            'consigneeCompanyName' => $this->toString($input['consigneeCompanyName'] ?? ''),
            'consigneeAddress' => $this->toString($input['consigneeAddress'] ?? ''),
            'consigneeZIPCode' => $this->toString($input['consigneeZIPCode'] ?? ''),
            'consigneeCity' => $this->toString($input['consigneeCity'] ?? ''),
            'consigneeProvinceAbbreviation' => $this->toString($input['consigneeProvinceAbbreviation'] ?? ''),
            'consigneeCountryAbbreviationISOAlpha2' => $this->toString($country),
            'consigneeClosingShift1_DayOfTheWeek' => $input['consigneeClosingShift1_DayOfTheWeek'] ?? '',
            'consigneeClosingShift1_PeriodOfTheDay' => $input['consigneeClosingShift1_PeriodOfTheDay'] ?? '',
            'consigneeClosingShift2_DayOfTheWeek' => $input['consigneeClosingShift2_DayOfTheWeek'] ?? '',
            'consigneeClosingShift2_PeriodOfTheDay' => $input['consigneeClosingShift2_PeriodOfTheDay'] ?? '',
            'consigneeContactName' => $input['consigneeContactName'] ?? '',
            'consigneeTelephone' => $input['consigneeTelephone'] ?? '',
            'consigneeEMail' => $input['consigneeEMail'] ?? null,
            'consigneeMobilePhoneNumber' => $input['consigneeMobilePhoneNumber'] ?? '',
            'isAlertRequired' => (int) ($input['isAlertRequired'] ?? 0),
            'consigneeVATNumber' => $input['consigneeVATNumber'] ?? '',
            'consigneeVATNumberCountryISOAlpha2' => $input['consigneeVATNumberCountryISOAlpha2'] ?? '',
            'consigneeItalianFiscalCode' => $input['consigneeItalianFiscalCode'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>|null
     */
    private function buildActualSenderData(array $input): ?array
    {
        $actualSender = $input['actualSender'] ?? [];
        if (!is_array($actualSender)) {
            $actualSender = [];
        }

        $serviceCode = strtoupper($this->toString($input['brtServiceCode'] ?? $this->config->getDefaultBrtServiceCode() ?? ''));
        $actualSenderRequired = $this->requiresActualSenderData($serviceCode);

        $fields = [
            'actualSenderName',
            'actualSenderCity',
            'actualSenderAddress',
            'actualSenderZIPCode',
            'actualSenderProvince',
            'actualSenderCountry',
            'actualSenderEmail',
            'actualSenderMobilePhoneNumber',
            'actualSenderPudoId',
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $actualSender) || $actualSender[$field] === null || $actualSender[$field] === '') {
                if (isset($input[$field]) && $input[$field] !== '') {
                    $actualSender[$field] = $input[$field];
                }
            }
        }

        $normalized = [
            'actualSenderName' => $this->toString($actualSender['actualSenderName'] ?? ''),
            'actualSenderCity' => $this->toString($actualSender['actualSenderCity'] ?? ''),
            'actualSenderAddress' => $this->toString($actualSender['actualSenderAddress'] ?? ''),
            'actualSenderZIPCode' => $this->toString($actualSender['actualSenderZIPCode'] ?? ''),
            'actualSenderProvince' => $this->toString($actualSender['actualSenderProvince'] ?? ''),
            'actualSenderCountry' => $this->toString($actualSender['actualSenderCountry'] ?? ''),
            'actualSenderEmail' => $this->toString($actualSender['actualSenderEmail'] ?? ''),
            'actualSenderMobilePhoneNumber' => $this->toString($actualSender['actualSenderMobilePhoneNumber'] ?? ''),
            'actualSenderPudoId' => $this->toString($actualSender['actualSenderPudoId'] ?? ''),
        ];

        if ($actualSenderRequired) {
            if ($normalized['actualSenderName'] === '') {
                foreach (['actualSenderName', 'consigneeCompanyName', 'originalSenderCompanyName'] as $source) {
                    if (isset($input[$source])) {
                        $candidate = $this->toString($input[$source]);
                        if ($candidate !== '') {
                            $normalized['actualSenderName'] = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($normalized['actualSenderAddress'] === '') {
                foreach (['actualSenderAddress', 'consigneeAddress'] as $source) {
                    if (isset($input[$source])) {
                        $candidate = $this->toString($input[$source]);
                        if ($candidate !== '') {
                            $normalized['actualSenderAddress'] = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($normalized['actualSenderCity'] === '') {
                foreach (['actualSenderCity', 'consigneeCity'] as $source) {
                    if (isset($input[$source])) {
                        $candidate = $this->toString($input[$source]);
                        if ($candidate !== '') {
                            $normalized['actualSenderCity'] = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($normalized['actualSenderZIPCode'] === '') {
                foreach (['actualSenderZIPCode', 'consigneeZIPCode'] as $source) {
                    if (isset($input[$source])) {
                        $candidate = $this->toString($input[$source]);
                        if ($candidate !== '') {
                            $normalized['actualSenderZIPCode'] = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($normalized['actualSenderProvince'] === '') {
                foreach (['actualSenderProvince', 'consigneeProvinceAbbreviation'] as $source) {
                    if (isset($input[$source])) {
                        $candidate = $this->toString($input[$source]);
                        if ($candidate !== '') {
                            $normalized['actualSenderProvince'] = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($normalized['actualSenderCountry'] === '') {
                $candidate = $this->toString($input['consigneeCountryAbbreviationISOAlpha2'] ?? '');
                if ($candidate !== '') {
                    $normalized['actualSenderCountry'] = $candidate;
                }
            }

            if ($normalized['actualSenderEmail'] === '') {
                foreach (['actualSenderEmail', 'consigneeEMail'] as $source) {
                    if (isset($input[$source])) {
                        $candidate = $this->toString($input[$source]);
                        if ($candidate !== '') {
                            $normalized['actualSenderEmail'] = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($normalized['actualSenderMobilePhoneNumber'] === '') {
                foreach (['actualSenderMobilePhoneNumber', 'consigneeMobilePhoneNumber'] as $source) {
                    if (isset($input[$source])) {
                        $candidate = $this->toString($input[$source]);
                        if ($candidate !== '') {
                            $normalized['actualSenderMobilePhoneNumber'] = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($normalized['actualSenderPudoId'] === '') {
                $candidate = $this->toString($input['actualSenderPudoId'] ?? $input['pudoId'] ?? '');
                if ($candidate !== '') {
                    $normalized['actualSenderPudoId'] = $candidate;
                }
            }
        }

        $hasData = array_filter($normalized, static fn ($value) => $value !== '');
        if ($hasData === []) {
            if ($actualSenderRequired) {
                throw new BrtException('Il servizio BRT selezionato richiede il mittente reale (nome negozio e contatti).');
            }
            return null;
        }

        if ($normalized['actualSenderCountry'] === '') {
            $defaultCountry = $this->config->getDefaultCountryIsoAlpha2();
            if ($defaultCountry !== null && $defaultCountry !== '') {
                $normalized['actualSenderCountry'] = $defaultCountry;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private function buildLabelParameters(array $input): ?array
    {
        $parameters = $input['labelParameters'] ?? $input;
        if (!is_array($parameters)) {
            $parameters = [];
        }

        $defaults = [
            'outputType' => $parameters['outputType'] ?? $this->config->getLabelOutputType(),
            'offsetX' => $parameters['offsetX'] ?? $this->config->getLabelOffsetX(),
            'offsetY' => $parameters['offsetY'] ?? $this->config->getLabelOffsetY(),
            'isBorderRequired' => $parameters['isBorderRequired'] ?? ($this->config->isLabelBorderEnabled() ? 1 : null),
            'isLogoRequired' => $parameters['isLogoRequired'] ?? ($this->config->isLabelLogoEnabled() ? 1 : null),
            'isBarcodeControlRowRequired' => $parameters['isBarcodeControlRowRequired'] ?? ($this->config->isLabelBarcodeRowEnabled() ? 1 : null),
        ];

        $filtered = [];
        foreach ($defaults as $key => $value) {
            if ($value !== null && $value !== '') {
                $filtered[$key] = $value;
            }
        }

        return $filtered !== [] ? $filtered : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertExecutionSuccess(array $data): void
    {
        if (!isset($data['executionMessage']) || !is_array($data['executionMessage'])) {
            return;
        }

        $message = $data['executionMessage'];
        $code = isset($message['code']) ? (int) $message['code'] : 0;
        if ($code < 0) {
            $codeDesc = isset($message['codeDesc']) ? (string) $message['codeDesc'] : '';
            $details = isset($message['message']) ? (string) $message['message'] : '';
            $errorMessage = $codeDesc !== '' ? $codeDesc : 'Errore nell\'esecuzione del servizio BRT.';
            if ($details !== '') {
                $errorMessage .= ' ' . $details;
            }
            throw new BrtException($errorMessage);
        }
    }

    /**
     * @param mixed $body
     */
    private function extractErrorMessage($body): ?string
    {
        if (is_string($body)) {
            $trimmed = trim($body);
            return $trimmed !== '' ? $this->truncateMessage($trimmed) : null;
        }

        if (!is_array($body)) {
            return null;
        }

        if (isset($body['executionMessage']) && is_array($body['executionMessage'])) {
            $message = $body['executionMessage'];
            $parts = [];
            if (!empty($message['codeDesc'])) {
                $parts[] = (string) $message['codeDesc'];
            }
            if (!empty($message['message'])) {
                $parts[] = (string) $message['message'];
            }
            return $parts ? implode(' - ', $parts) : null;
        }

        foreach (['createResponse', 'confirmResponse', 'deleteResponse'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $response = $body[$key];
                if (isset($response['executionMessage']) && is_array($response['executionMessage'])) {
                    $message = $response['executionMessage'];
                    $parts = [];
                    if (!empty($message['codeDesc'])) {
                        $parts[] = (string) $message['codeDesc'];
                    }
                    if (!empty($message['message'])) {
                        $parts[] = (string) $message['message'];
                    }
                    return $parts ? implode(' - ', $parts) : null;
                }
            }
        }

        if (isset($body['errors']) && is_array($body['errors'])) {
            $parts = [];
            foreach ($body['errors'] as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $parts[] = (string) $error['message'];
                } elseif (is_string($error)) {
                    $parts[] = $error;
                }
            }
            if ($parts) {
                return $this->truncateMessage(implode(' | ', $parts));
            }
        }

        if (isset($body['message'])) {
            return $this->truncateMessage((string) $body['message']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolveLabelRequired(array $input): int
    {
        if (isset($input['isLabelRequired'])) {
            return (int) ((bool) $input['isLabelRequired']);
        }

        return $this->config->isLabelRequiredByDefault() ? 1 : 0;
    }

    private function requiresActualSenderData(string $serviceCode): bool
    {
        if ($serviceCode === '') {
            return false;
        }

        return in_array($serviceCode, ['B13', 'B14', 'B15'], true);
    }

    private function toString($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param mixed $value
     */
    private function sanitizeNetworkCode($value): string
    {
        $normalized = strtoupper($this->toString($value));
        if ($normalized === '') {
            return '';
        }

        if (isset(self::NETWORK_ALIAS_BY_INPUT[$normalized])) {
            $alias = self::NETWORK_ALIAS_BY_INPUT[$normalized];
            if (isset(self::NETWORK_CODE_BY_ALIAS[$alias])) {
                return self::NETWORK_CODE_BY_ALIAS[$alias];
            }
        }

        if (strlen($normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function normalizeNetworkAlias($value): ?string
    {
        $normalized = strtoupper($this->toString($value));
        if ($normalized === '') {
            return null;
        }

        if (isset(self::NETWORK_ALIAS_BY_INPUT[$normalized])) {
            return self::NETWORK_ALIAS_BY_INPUT[$normalized];
        }

        if (isset(self::NETWORK_CODE_BY_ALIAS[$normalized])) {
            return $normalized;
        }

        return $normalized;
    }

    /**
     * @param mixed $reference
     */
    private function sanitizeAlphanumericSenderReference($reference): ?string
    {
        $value = $this->toString($reference);
        if ($value === '') {
            return null;
        }

        if (strlen($value) > 15) {
            $value = substr($value, 0, 15);
        }

        return $value;
    }
    private function normalizeFloat($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    private function truncateMessage(string $message, int $length = 400): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (strlen($trimmed) <= $length) {
            return $trimmed;
        }

        return substr($trimmed, 0, max(0, $length - 1)) . '…';
    }
}
