<?php
declare(strict_types=1);

if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
    http_response_code(403);
    exit('Accesso non autorizzato.');
}

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtCustomsDocumentService;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtManifestGenerator;
use App\Services\Brt\BrtManifestService;
use App\Services\Brt\BrtShipmentService;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../../includes/helpers.php';

const BRT_UPLOAD_BASE = __DIR__ . '/../../../uploads/brt';
const BRT_LABEL_DIRECTORY = BRT_UPLOAD_BASE . '/labels';
const BRT_MANIFEST_DIRECTORY = BRT_UPLOAD_BASE . '/manifests';
const BRT_CUSTOMS_DIRECTORY = BRT_UPLOAD_BASE . '/customs';
const BRT_BACKUP_BASE = __DIR__ . '/../../../backups/brt';
const BRT_MANIFEST_BACKUP_DIRECTORY = BRT_BACKUP_BASE . '/manifests';
const BRT_MANIFEST_OFFICIAL_BACKUP_DIRECTORY = BRT_BACKUP_BASE . '/manifests-official';
const BRT_FILE_RETENTION_DAYS = 120;
const BRT_LOG_LEVELS = ['info', 'warning', 'error'];

function ensure_brt_tables(): void
{
    $missingTables = brt_missing_tables();
    if ($missingTables !== []) {
        throw new RuntimeException(
            'Tabelle BRT mancanti: ' . implode(', ', $missingTables) . '. Esegui le migrazioni in database/migrations per completare la configurazione.'
        );
    }
}

/**
 * @return array<int, string>
 */
function brt_required_tables(): array
{
    return [
        'brt_shipments',
        'brt_manifests',
        'brt_orm_requests',
        'brt_saved_recipients',
        'brt_customs_documents',
        'brt_logs',
    ];
}

/**
 * @return array<int, string>
 */
function brt_missing_tables(): array
{
    $pdo = brt_db();
    $missing = [];

    foreach (brt_required_tables() as $table) {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        if ($stmt === false || $stmt->fetchColumn() === false) {
            $missing[] = $table;
        }
    }

    return $missing;
}

function brt_log_event(string $level, string $message, array $context = []): void
{
    $normalizedLevel = strtolower(trim($level));
    if (!in_array($normalizedLevel, BRT_LOG_LEVELS, true)) {
        $normalizedLevel = 'info';
    }

    $trimmedMessage = trim($message);
    if ($trimmedMessage === '') {
        return;
    }

    $user = $context['user'] ?? null;
    if ($user === null && function_exists('current_user_display_name') && session_status() === PHP_SESSION_ACTIVE) {
        $user = current_user_display_name();
        if ($user !== '') {
            $context['user'] = $user;
        }
    }

    $payload = $context === [] ? null : encode_json_pretty($context);
    $createdBy = is_string($user) && $user !== '' ? $user : null;

    $shortMessage = mb_strimwidth($trimmedMessage, 0, 255, mb_strlen($trimmedMessage, 'UTF-8') > 255 ? '...' : '', 'UTF-8');

    try {
        $pdo = brt_db();
        $stmt = $pdo->prepare('INSERT INTO brt_logs (level, message, context, created_by) VALUES (:level, :message, :context, :created_by)');
        $stmt->execute([
            ':level' => $normalizedLevel,
            ':message' => $shortMessage,
            ':context' => $payload,
            ':created_by' => $createdBy,
        ]);
    } catch (Throwable $exception) {
        error_log('BRT log failure: ' . $exception->getMessage());
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function brt_get_logs(int $limit = 200, ?string $level = null): array
{
    $limit = max(1, min(500, $limit));
    $normalizedLevel = null;
    if ($level !== null && $level !== '') {
        $candidate = strtolower(trim($level));
        if (in_array($candidate, BRT_LOG_LEVELS, true)) {
            $normalizedLevel = $candidate;
        }
    }

    try {
        $pdo = brt_db();
        $sql = 'SELECT id, level, message, context, created_by, created_at FROM brt_logs';
        $params = [];
        if ($normalizedLevel !== null) {
            $sql .= ' WHERE level = :level';
            $params[':level'] = $normalizedLevel;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $exception) {
        error_log('BRT log fetch failure: ' . $exception->getMessage());
        return [];
    }

    foreach ($rows as &$row) {
        $decoded = null;
        if (isset($row['context']) && $row['context'] !== null && $row['context'] !== '') {
            $contextData = json_decode((string) $row['context'], true);
            if (is_array($contextData)) {
                $decoded = $contextData;
            }
        }
        $row['context'] = $decoded ?? [];
    }
    unset($row);

    return $rows;
}

/**
 * @param array<string, mixed> $createData
 * @param array<string, mixed> $response
 * @param array<string, mixed> $metadata
 */
function brt_store_shipment(array $createData, array $response, array $metadata = []): int
{
    $pdo = brt_db();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO brt_shipments (
            sender_customer_code,
            numeric_sender_reference,
            alphanumeric_sender_reference,
            departure_depot,
            arrival_depot,
            arrival_terminal,
            parcel_number_from,
            parcel_number_to,
            parcel_id,
            tracking_by_parcel_id,
            number_of_parcels,
            weight_kg,
            volume_m3,
            consignee_name,
            consignee_address,
            consignee_zip,
            consignee_city,
            consignee_province,
            consignee_country,
            consignee_phone,
            consignee_email,
            status,
            execution_code,
            execution_code_description,
            execution_message,
            request_payload,
            response_payload
        ) VALUES (
            :sender_customer_code,
            :numeric_sender_reference,
            :alphanumeric_sender_reference,
            :departure_depot,
            :arrival_depot,
            :arrival_terminal,
            :parcel_number_from,
            :parcel_number_to,
            :parcel_id,
            :tracking_by_parcel_id,
            :number_of_parcels,
            :weight_kg,
            :volume_m3,
            :consignee_name,
            :consignee_address,
            :consignee_zip,
            :consignee_city,
            :consignee_province,
            :consignee_country,
            :consignee_phone,
            :consignee_email,
            :status,
            :execution_code,
            :execution_code_description,
            :execution_message,
            :request_payload,
            :response_payload
        )');

    $parcelId = (string) ($response['labels']['label'][0]['parcelID'] ?? '');
    $trackingByParcelId = (string) ($response['labels']['label'][0]['trackingByParcelID'] ?? '');
    $trackingByParcelId = (string) ($response['labels']['label'][0]['trackingByParcelID'] ?? '');
    $trackingByParcelId = (string) ($response['labels']['label'][0]['trackingByParcelID'] ?? '');
    $trackingByParcelId = (string) ($response['labels']['label'][0]['trackingByParcelID'] ?? '');
        $executionMessage = $response['executionMessage'] ?? [];
        $executionCode = (int) ($executionMessage['code'] ?? 0);
        $executionDesc = (string) ($executionMessage['codeDesc'] ?? '');
        $executionDetail = (string) ($executionMessage['message'] ?? '');

        $stmt->execute([
            ':sender_customer_code' => (string) ($createData['senderCustomerCode'] ?? ''),
            ':numeric_sender_reference' => (int) ($createData['numericSenderReference'] ?? 0),
            ':alphanumeric_sender_reference' => (string) ($createData['alphanumericSenderReference'] ?? ''),
            ':departure_depot' => (string) ($createData['departureDepot'] ?? ''),
            ':arrival_depot' => (string) ($response['arrivalDepot'] ?? null),
            ':arrival_terminal' => (string) ($response['arrivalTerminal'] ?? null),
            ':parcel_number_from' => (string) ($response['parcelNumberFrom'] ?? null),
            ':parcel_number_to' => (string) ($response['parcelNumberTo'] ?? null),
            ':parcel_id' => $parcelId !== '' ? $parcelId : null,
            ':tracking_by_parcel_id' => $trackingByParcelId !== '' ? $trackingByParcelId : null,
            ':number_of_parcels' => (int) ($response['numberOfParcels'] ?? $createData['numberOfParcels'] ?? 1),
            ':weight_kg' => (float) ($response['weightKG'] ?? $createData['weightKG'] ?? 0),
            ':volume_m3' => (float) ($response['volumeM3'] ?? $createData['volumeM3'] ?? 0),
            ':consignee_name' => (string) ($response['consigneeCompanyName'] ?? $createData['consigneeCompanyName'] ?? ''),
            ':consignee_address' => (string) ($response['consigneeAddress'] ?? $createData['consigneeAddress'] ?? ''),
            ':consignee_zip' => (string) ($response['consigneeZIPCode'] ?? $createData['consigneeZIPCode'] ?? ''),
            ':consignee_city' => (string) ($response['consigneeCity'] ?? $createData['consigneeCity'] ?? ''),
            ':consignee_province' => (string) ($response['consigneeProvinceAbbreviation'] ?? $createData['consigneeProvinceAbbreviation'] ?? null),
            ':consignee_country' => (string) ($response['consigneeCountryAbbreviationBRT'] ?? $createData['consigneeCountryAbbreviationISOAlpha2'] ?? null),
            ':consignee_phone' => (string) ($createData['consigneeMobilePhoneNumber'] ?? null),
            ':consignee_email' => (string) ($createData['consigneeEMail'] ?? null),
            ':status' => $executionCode === 0 ? 'created' : 'warning',
            ':execution_code' => $executionCode,
            ':execution_code_description' => $executionDesc !== '' ? $executionDesc : null,
            ':execution_message' => $executionDetail !== '' ? $executionDetail : null,
            ':request_payload' => brt_serialize_request_payload($createData, $metadata),
            ':response_payload' => encode_json_pretty($response),
        ]);

        $shipmentId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $shipmentId;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $createData
 * @param array<string, mixed> $response
 * @param array<string, mixed> $metadata
 * @param array<string, mixed> $options
 */
function brt_update_shipment_record(int $shipmentId, array $createData, array $response, array $metadata = [], array $options = []): void
{
    $pdo = brt_db();

    $parcelId = (string) ($response['labels']['label'][0]['parcelID'] ?? '');
    $trackingByParcelId = (string) ($response['labels']['label'][0]['trackingByParcelID'] ?? '');
    $executionMessage = $response['executionMessage'] ?? [];
    $executionCode = (int) ($executionMessage['code'] ?? 0);
    $executionDesc = (string) ($executionMessage['codeDesc'] ?? '');
    $executionDetail = (string) ($executionMessage['message'] ?? '');

    $preserveLabel = (bool) ($options['preserve_label'] ?? false);
    $preserveTracking = (bool) ($options['preserve_tracking'] ?? false);
    $preserveConfirmation = (bool) ($options['preserve_confirmation'] ?? false);

    $stmt = $pdo->prepare('UPDATE brt_shipments SET
        sender_customer_code = :sender_customer_code,
        numeric_sender_reference = :numeric_sender_reference,
        alphanumeric_sender_reference = :alphanumeric_sender_reference,
        departure_depot = :departure_depot,
        arrival_depot = :arrival_depot,
        arrival_terminal = :arrival_terminal,
        parcel_number_from = :parcel_number_from,
        parcel_number_to = :parcel_number_to,
        parcel_id = :parcel_id,
        tracking_by_parcel_id = :tracking_by_parcel_id,
        number_of_parcels = :number_of_parcels,
        weight_kg = :weight_kg,
        volume_m3 = :volume_m3,
        consignee_name = :consignee_name,
        consignee_address = :consignee_address,
        consignee_zip = :consignee_zip,
        consignee_city = :consignee_city,
        consignee_province = :consignee_province,
        consignee_country = :consignee_country,
        consignee_phone = :consignee_phone,
        consignee_email = :consignee_email,
        status = :status,
        execution_code = :execution_code,
        execution_code_description = :execution_code_description,
        execution_message = :execution_message,
        label_path = CASE WHEN :preserve_label = 1 THEN label_path ELSE NULL END,
        request_payload = :request_payload,
        response_payload = :response_payload,
        confirmed_at = CASE WHEN :preserve_confirmation = 1 THEN confirmed_at ELSE NULL END,
        deleted_at = NULL,
        manifest_id = NULL,
        manifest_generated_at = NULL,
        last_tracking_payload = CASE WHEN :preserve_tracking = 1 THEN last_tracking_payload ELSE NULL END,
        last_tracking_at = CASE WHEN :preserve_tracking = 1 THEN last_tracking_at ELSE NULL END
    WHERE id = :id');

    $stmt->execute([
        ':sender_customer_code' => (string) ($createData['senderCustomerCode'] ?? ''),
        ':numeric_sender_reference' => (int) ($createData['numericSenderReference'] ?? 0),
        ':alphanumeric_sender_reference' => (string) ($createData['alphanumericSenderReference'] ?? ''),
        ':departure_depot' => (string) ($createData['departureDepot'] ?? ''),
        ':arrival_depot' => (string) ($response['arrivalDepot'] ?? null),
        ':arrival_terminal' => (string) ($response['arrivalTerminal'] ?? null),
        ':parcel_number_from' => (string) ($response['parcelNumberFrom'] ?? null),
        ':parcel_number_to' => (string) ($response['parcelNumberTo'] ?? null),
        ':parcel_id' => $parcelId !== '' ? $parcelId : null,
        ':tracking_by_parcel_id' => $trackingByParcelId !== '' ? $trackingByParcelId : null,
        ':number_of_parcels' => (int) ($response['numberOfParcels'] ?? $createData['numberOfParcels'] ?? 1),
        ':weight_kg' => (float) ($response['weightKG'] ?? $createData['weightKG'] ?? 0),
        ':volume_m3' => (float) ($response['volumeM3'] ?? $createData['volumeM3'] ?? 0),
        ':consignee_name' => (string) ($response['consigneeCompanyName'] ?? $createData['consigneeCompanyName'] ?? ''),
        ':consignee_address' => (string) ($response['consigneeAddress'] ?? $createData['consigneeAddress'] ?? ''),
        ':consignee_zip' => (string) ($response['consigneeZIPCode'] ?? $createData['consigneeZIPCode'] ?? ''),
        ':consignee_city' => (string) ($response['consigneeCity'] ?? $createData['consigneeCity'] ?? ''),
        ':consignee_province' => (string) ($response['consigneeProvinceAbbreviation'] ?? $createData['consigneeProvinceAbbreviation'] ?? null),
        ':consignee_country' => (string) ($response['consigneeCountryAbbreviationBRT'] ?? $createData['consigneeCountryAbbreviationISOAlpha2'] ?? null),
        ':consignee_phone' => (string) ($createData['consigneeMobilePhoneNumber'] ?? null),
        ':consignee_email' => (string) ($createData['consigneeEMail'] ?? null),
        ':status' => $executionCode === 0 ? 'created' : 'warning',
        ':execution_code' => $executionCode,
        ':execution_code_description' => $executionDesc !== '' ? $executionDesc : null,
        ':execution_message' => $executionDetail !== '' ? $executionDetail : null,
        ':request_payload' => brt_serialize_request_payload($createData, $metadata),
        ':response_payload' => encode_json_pretty($response),
        ':id' => $shipmentId,
        ':preserve_label' => $preserveLabel ? 1 : 0,
        ':preserve_tracking' => $preserveTracking ? 1 : 0,
        ':preserve_confirmation' => $preserveConfirmation ? 1 : 0,
    ]);
}

function brt_remove_shipment(int $shipmentId): void
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT label_path FROM brt_shipments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $shipmentId]);
    $row = $stmt->fetch();

    if ($row && !empty($row['label_path'])) {
        $path = project_root_path() . '/' . ltrim((string) $row['label_path'], '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    brt_delete_customs_document($shipmentId);

    $delete = $pdo->prepare('DELETE FROM brt_shipments WHERE id = :id');
    $delete->execute([':id' => $shipmentId]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function brt_get_saved_recipients(): array
{
    $pdo = brt_db();
    $stmt = $pdo->query('SELECT id, label, company_name, address, zip, city, province, country, contact_name, phone, mobile, email, pudo_id, pudo_description FROM brt_saved_recipients ORDER BY label ASC');
    $rows = $stmt->fetchAll();
    return $rows ?: [];
}

function brt_get_saved_recipient(int $recipientId): ?array
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT id, label, company_name, address, zip, city, province, country, contact_name, phone, mobile, email, pudo_id, pudo_description FROM brt_saved_recipients WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $recipientId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @param array<string, mixed> $recipient
 */
function brt_store_saved_recipient(array $recipient): int
{
    $pdo = brt_db();

    $label = trim((string) ($recipient['label'] ?? ''));
    $companyName = trim((string) ($recipient['company_name'] ?? ''));
    $address = trim((string) ($recipient['address'] ?? ''));
    $zip = trim((string) ($recipient['zip'] ?? ''));
    $city = trim((string) ($recipient['city'] ?? ''));
    $country = strtoupper(trim((string) ($recipient['country'] ?? 'IT')));

    if ($country === 'IE') {
        if ($zip === '') {
            $zip = 'EIRE';
        } else {
            $zip = strtoupper($zip);
        }
    }

    if ($label === '') {
        $label = $companyName;
    }

    if ($label === '' || $companyName === '' || $address === '' || $zip === '' || $city === '') {
        throw new InvalidArgumentException('Dati destinatario incompleti per il salvataggio.');
    }

    if (function_exists('mb_strlen')) {
        if (mb_strlen($label) > 120) {
            $label = mb_substr($label, 0, 120);
        }
    } elseif (strlen($label) > 120) {
        $label = substr($label, 0, 120);
    }

    $payload = [
        ':label' => $label,
        ':company_name' => $companyName,
        ':address' => $address,
        ':zip' => $zip,
        ':city' => $city,
        ':province' => trim((string) ($recipient['province'] ?? '')) ?: null,
        ':country' => $country,
        ':contact_name' => trim((string) ($recipient['contact_name'] ?? '')) ?: null,
        ':phone' => trim((string) ($recipient['phone'] ?? '')) ?: null,
        ':mobile' => trim((string) ($recipient['mobile'] ?? '')) ?: null,
        ':email' => trim((string) ($recipient['email'] ?? '')) ?: null,
        ':pudo_id' => trim((string) ($recipient['pudo_id'] ?? '')) ?: null,
        ':pudo_description' => trim((string) ($recipient['pudo_description'] ?? '')) ?: null,
    ];

    $stmt = $pdo->prepare('SELECT id FROM brt_saved_recipients WHERE label = :label LIMIT 1');
    $stmt->execute([':label' => $label]);
    $existingId = $stmt->fetchColumn();

    if ($existingId !== false) {
        $payload[':id'] = (int) $existingId;
        $update = $pdo->prepare('UPDATE brt_saved_recipients SET company_name = :company_name, address = :address, zip = :zip, city = :city, province = :province, country = :country, contact_name = :contact_name, phone = :phone, mobile = :mobile, email = :email, pudo_id = :pudo_id, pudo_description = :pudo_description WHERE id = :id');
        $update->execute($payload);
        return (int) $existingId;
    }

    $insert = $pdo->prepare('INSERT INTO brt_saved_recipients (label, company_name, address, zip, city, province, country, contact_name, phone, mobile, email, pudo_id, pudo_description) VALUES (:label, :company_name, :address, :zip, :city, :province, :country, :contact_name, :phone, :mobile, :email, :pudo_id, :pudo_description)');
    $insert->execute($payload);
    return (int) $pdo->lastInsertId();
}

/**
 * @return array{request: array<string, mixed>, meta: array<string, mixed>}
 */
function brt_decode_request_payload(?string $payload): array
{
    if ($payload === null || $payload === '') {
        return ['request' => [], 'meta' => []];
    }

    try {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return ['request' => [], 'meta' => []];
    }

    if (!is_array($decoded)) {
        return ['request' => [], 'meta' => []];
    }

    if (array_key_exists('request', $decoded) && is_array($decoded['request'])) {
        $meta = [];
        if (array_key_exists('meta', $decoded) && is_array($decoded['meta'])) {
            $meta = $decoded['meta'];
        }

    /** @var array<string, mixed> $request */
    $request = $decoded['request'];
    return ['request' => $request, 'meta' => $meta];
    }

    /** @var array<string, mixed> $request */
    $request = $decoded;
    return ['request' => $request, 'meta' => []];
}

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed> $metadata
 */
function brt_serialize_request_payload(array $request, array $metadata = []): string
{
    if ($metadata === []) {
        return encode_json_pretty($request);
    }

    return encode_json_pretty([
        'request' => $request,
        'meta' => $metadata,
    ]);
}

/**
 * @param array<string, mixed> $confirmResponse
 * @param array<string, mixed> $options
 */
function brt_mark_shipment_confirmed(int $shipmentId, array $confirmResponse, array $options = []): void
{
    $pdo = brt_db();
    $execution = $confirmResponse['executionMessage'] ?? [];
    $preserveConfirmedAt = (bool) ($options['preserve_confirmed_at'] ?? false);

    $stmt = $pdo->prepare('UPDATE brt_shipments SET
        status = :status,
        execution_code = :code,
        execution_code_description = :description,
        execution_message = :message,
        confirmed_at = CASE WHEN :preserve_confirmed_at = 1 THEN confirmed_at ELSE NOW() END,
        response_payload = :payload
    WHERE id = :id');

    $stmt->execute([
        ':status' => 'confirmed',
        ':code' => (int) ($execution['code'] ?? 0),
        ':description' => (string) ($execution['codeDesc'] ?? null),
        ':message' => (string) ($execution['message'] ?? null),
        ':payload' => encode_json_pretty($confirmResponse),
        ':id' => $shipmentId,
        ':preserve_confirmed_at' => $preserveConfirmedAt ? 1 : 0,
    ]);

    brt_ensure_financial_entry($shipmentId);
}

/**
 * @return array{reference:string,numeric_reference:string}
 */
function brt_build_financial_reference(array $shipment, int $shipmentId): array
{
    $senderCodeRaw = strtoupper((string) ($shipment['sender_customer_code'] ?? 'BRT'));
    $senderCodeSanitized = preg_replace('/[^A-Z0-9]/', '', $senderCodeRaw);
    $senderCode = is_string($senderCodeSanitized) && $senderCodeSanitized !== '' ? $senderCodeSanitized : 'BRT';

    $numericReferenceRaw = (string) ($shipment['numeric_sender_reference'] ?? '');
    $numericReferenceSanitized = preg_replace('/[^0-9]/', '', $numericReferenceRaw);
    $numericReference = is_string($numericReferenceSanitized) && $numericReferenceSanitized !== ''
        ? $numericReferenceSanitized
        : (string) $shipmentId;

    $reference = sprintf('BRT-%s-%s', $senderCode, $numericReference);
    if (function_exists('mb_strlen')) {
        if (mb_strlen($reference, 'UTF-8') > 80) {
            $reference = mb_substr($reference, 0, 80, 'UTF-8');
        }
    } elseif (strlen($reference) > 80) {
        $reference = substr($reference, 0, 80);
    }

    return [
        'reference' => $reference,
        'numeric_reference' => $numericReference,
    ];
}

function brt_ensure_financial_entry(int $shipmentId, bool $force = false): void
{
    try {
        $shipment = brt_get_shipment($shipmentId);
        if ($shipment === null) {
            return;
        }

        $status = strtolower((string) ($shipment['status'] ?? ''));
        if (!$force && $status !== 'confirmed') {
            return;
        }

        $decoded = brt_decode_request_payload($shipment['request_payload'] ?? null);
        $meta = $decoded['meta'] ?? [];
        if (!is_array($meta)) {
            return;
        }

        $pricingMeta = $meta['portal_pricing'] ?? null;
        if (!is_array($pricingMeta)) {
            return;
        }

        $matchedTier = $pricingMeta['matched_tier'] ?? null;
        if (!is_array($matchedTier)) {
            return;
        }

        $priceValue = $matchedTier['price'] ?? null;
        if (!is_numeric($priceValue)) {
            return;
        }

        $amount = round((float) $priceValue, 2);
        if ($amount <= 0) {
            return;
        }

        $currency = strtoupper((string) ($pricingMeta['currency'] ?? 'EUR'));
        if ($currency !== 'EUR' && $currency !== '') {
            return;
        }

        $pdo = brt_db();

        $referenceData = brt_build_financial_reference($shipment, $shipmentId);
        $reference = $referenceData['reference'];
        $numericReference = $referenceData['numeric_reference'];

        $checkStmt = $pdo->prepare('SELECT id FROM entrate_uscite WHERE riferimento = :reference LIMIT 1');
        $checkStmt->execute([':reference' => $reference]);
        if ($checkStmt->fetchColumn()) {
            return;
        }

        $amountString = number_format($amount, 2, '.', '');

        $consigneeName = trim((string) ($shipment['consignee_name'] ?? ''));
        $destinationCity = trim((string) ($shipment['consignee_city'] ?? ''));
        $descriptionParts = ['Spedizione BRT', '#' . $numericReference];
        if ($consigneeName !== '') {
            $descriptionParts[] = $consigneeName;
        } elseif ($destinationCity !== '') {
            $descriptionParts[] = $destinationCity;
        }

        $description = implode(' ', array_filter($descriptionParts));
        if (function_exists('mb_strlen')) {
            if (mb_strlen($description, 'UTF-8') > 180) {
                $description = mb_substr($description, 0, 180, 'UTF-8');
            }
        } elseif (strlen($description) > 180) {
            $description = substr($description, 0, 180);
        }

        $noteSegments = ['Movimento generato automaticamente dalla spedizione BRT.'];
        if (!empty($matchedTier['label']) && is_string($matchedTier['label'])) {
            $noteSegments[] = 'Scaglione: ' . $matchedTier['label'];
        }

        if (isset($pricingMeta['evaluation']) && is_array($pricingMeta['evaluation'])) {
            $evaluation = $pricingMeta['evaluation'];
            $metrics = [];
            if (isset($evaluation['weight_kg']) && is_numeric($evaluation['weight_kg'])) {
                $metrics[] = 'Peso ' . number_format((float) $evaluation['weight_kg'], 3, ',', '.') . ' kg';
            }
            if (isset($evaluation['volume_m3']) && is_numeric($evaluation['volume_m3'])) {
                $metrics[] = 'Volume ' . number_format((float) $evaluation['volume_m3'], 3, ',', '.') . ' m³';
            }
            if ($metrics !== []) {
                $noteSegments[] = implode(' · ', $metrics);
            }
        }

        $note = implode(' ', $noteSegments);
        if ($note === '') {
            $note = null;
        }

        $today = date('Y-m-d');

        $insert = $pdo->prepare('INSERT INTO entrate_uscite (
            cliente_id,
            descrizione,
            riferimento,
            metodo,
            stato,
            tipo_movimento,
            importo,
            quantita,
            prezzo_unitario,
            data_scadenza,
            data_pagamento,
            note,
            allegato_path,
            allegato_hash,
            created_at,
            updated_at
        ) VALUES (
            NULL,
            :descrizione,
            :riferimento,
            :metodo,
            :stato,
            :tipo_movimento,
            :importo,
            :quantita,
            :prezzo_unitario,
            NULL,
            :data_pagamento,
            :note,
            NULL,
            NULL,
            NOW(),
            NOW()
        )');

        $insert->execute([
            ':descrizione' => $description,
            ':riferimento' => $reference,
            ':metodo' => 'Bonifico',
            ':stato' => 'In lavorazione',
            ':tipo_movimento' => 'Entrata',
            ':importo' => $amountString,
            ':quantita' => 1,
            ':prezzo_unitario' => $amountString,
            ':data_pagamento' => $today,
            ':note' => $note,
        ]);

        $entryId = (int) $pdo->lastInsertId();

        brt_log_event('info', 'Entrata/uscita registrata per spedizione BRT', [
            'shipment_id' => $shipmentId,
            'entrate_uscite_id' => $entryId,
            'riferimento' => $reference,
            'importo' => $amountString,
        ]);
    } catch (Throwable $exception) {
        brt_log_event('warning', 'Registrazione automatica entrata non riuscita', [
            'shipment_id' => $shipmentId,
            'error' => $exception->getMessage(),
        ]);
    }
}

function brt_remove_financial_entry(int $shipmentId): void
{
    try {
        $shipment = brt_get_shipment($shipmentId);
        if ($shipment === null) {
            return;
        }

        $referenceData = brt_build_financial_reference($shipment, $shipmentId);
        $reference = $referenceData['reference'];

        $pdo = brt_db();
        $delete = $pdo->prepare('DELETE FROM entrate_uscite WHERE riferimento = :reference LIMIT 1');
        $delete->execute([':reference' => $reference]);

        if ($delete->rowCount() > 0) {
            brt_log_event('info', 'Entrata rimossa per annullamento spedizione BRT', [
                'shipment_id' => $shipmentId,
                'riferimento' => $reference,
            ]);
        }
    } catch (Throwable $exception) {
        brt_log_event('warning', 'Rimozione entrata automatica non riuscita', [
            'shipment_id' => $shipmentId,
            'error' => $exception->getMessage(),
        ]);
    }
}

/**
 * @param array<string, mixed> $deleteResponse
 */
function brt_mark_shipment_deleted(int $shipmentId, array $deleteResponse): void
{
    $pdo = brt_db();
    $execution = $deleteResponse['executionMessage'] ?? [];
    $stmt = $pdo->prepare('UPDATE brt_shipments SET status = :status, execution_code = :code, execution_code_description = :description, execution_message = :message, deleted_at = NOW(), response_payload = :payload WHERE id = :id');
    $stmt->execute([
        ':status' => 'cancelled',
        ':code' => (int) ($execution['code'] ?? 0),
        ':description' => (string) ($execution['codeDesc'] ?? null),
        ':message' => (string) ($execution['message'] ?? null),
        ':payload' => encode_json_pretty($deleteResponse),
        ':id' => $shipmentId,
    ]);

    brt_remove_financial_entry($shipmentId);
}

function brt_attach_label(int $shipmentId, array $label): ?string
{
    if (!isset($label['stream'])) {
        return null;
    }
    $stream = (string) $label['stream'];
    if ($stream === '') {
        return null;
    }

    $parcelId = (string) ($label['parcelID'] ?? 'label');
    $trackingByParcelId = (string) ($label['trackingByParcelID'] ?? '');
    $path = brt_store_label_stream($parcelId, $stream);

    $pdo = brt_db();
    $stmt = $pdo->prepare('UPDATE brt_shipments SET label_path = :path, parcel_id = COALESCE(parcel_id, :parcel_id), tracking_by_parcel_id = COALESCE(tracking_by_parcel_id, :tracking_by_parcel_id) WHERE id = :id');
    $stmt->execute([
        ':path' => $path,
        ':parcel_id' => $parcelId !== '' ? $parcelId : null,
        ':tracking_by_parcel_id' => $trackingByParcelId !== '' ? $trackingByParcelId : null,
        ':id' => $shipmentId,
    ]);

    return $path;
}

function brt_store_label_stream(string $parcelId, string $stream): string
{
    brt_ensure_directories();

    $cleanedParcel = preg_replace('/[^A-Za-z0-9_-]/', '_', $parcelId);
    if ($cleanedParcel === null || $cleanedParcel === '') {
        $cleanedParcel = 'parcel';
    }

    $timestamp = date('Ymd_His');
    $filename = $cleanedParcel . '_' . $timestamp . '.pdf';
    $relativePath = 'uploads/brt/labels/' . $filename;
    $absolutePath = rtrim(project_root_path(), '/') . '/' . ltrim($relativePath, '/');

    $decoded = base64_decode($stream, true);
    if ($decoded === false) {
        throw new BrtException('Impossibile decodificare l\'etichetta restituita da BRT.');
    }

    $bytes = @file_put_contents($absolutePath, $decoded, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Impossibile salvare l\'etichetta BRT sul filesystem. Verifica i permessi della cartella uploads/brt/labels.');
    }

    brt_cleanup_old_artifacts();

    return $relativePath;
}

function brt_delete_label_file(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    $absolutePath = rtrim(project_root_path(), '/') . '/' . ltrim($relativePath, '/');
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function brt_normalize_remote_warning(?string $message): string
{
    $originalPayload = (string) ($message ?? '');
    $cleanMessage = trim($originalPayload);

    if ($cleanMessage === '') {
        return 'Risposta non interpretata dal webservice BRT.';
    }

    $titleText = null;
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $originalPayload, $match)) {
        $titleCandidate = trim(strip_tags($match[1] ?? ''));
        if ($titleCandidate !== '') {
            $titleText = preg_replace('/\s+/', ' ', $titleCandidate);
        }
    }

    $originalForFallback = $cleanMessage;

    $cleanMessage = preg_replace('~<style[^>]*>.*?(</style>|$)~is', ' ', $cleanMessage);
    $cleanMessage = preg_replace('~<script[^>]*>.*?(</script>|$)~is', ' ', $cleanMessage);
    $cleanMessage = preg_replace('/<!DOCTYPE[^>]*>/i', ' ', $cleanMessage ?? '');
    $cleanMessage = preg_replace('/<!--.*?(-->|$)/s', ' ', $cleanMessage ?? '');
    $cleanMessage = html_entity_decode($cleanMessage ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cleanMessage = strip_tags($cleanMessage ?? '');

    $jsonPayload = json_decode($cleanMessage, true);
    if (is_array($jsonPayload)) {
        $jsonMessage = $jsonPayload['message'] ?? $jsonPayload['error'] ?? null;
        if (is_string($jsonMessage) && trim($jsonMessage) !== '') {
            $cleanMessage = $jsonMessage;
        } else {
            $flattened = array_filter(array_map(
                static fn ($value) => is_scalar($value) ? (string) $value : null,
                $jsonPayload
            ));
            $cleanMessage = implode(' — ', $flattened);
        }
    }

    $cleanMessage = preg_replace('/\s+/', ' ', $cleanMessage ?? '');
    $cleanMessage = trim((string) $cleanMessage);

    if ($cleanMessage === '') {
        $fallback = trim(strip_tags($originalPayload ?: $originalForFallback));
        if ($fallback !== '') {
            $cleanMessage = preg_replace('/\s+/', ' ', $fallback);
        }
    }

    $looksLikeCss = preg_match('/^(?:[.#A-Za-z0-9_\-]+\s*\{[^}]*\}\s*)+$/', $cleanMessage) === 1;
    if ($looksLikeCss || stripos($cleanMessage, 'font-family:') !== false || stripos($cleanMessage, 'background-color:') !== false) {
        if ($titleText !== null) {
            $cleanMessage = $titleText;
        } else {
            $cleanMessage = '';
        }
    }

    if ($cleanMessage === '' || str_starts_with($cleanMessage, '{')) {
        return 'Risposta non interpretata dal webservice BRT.';
    }

    if (strlen($cleanMessage) > 220) {
        $cleanMessage = rtrim(substr($cleanMessage, 0, 217)) . '...';
    }

    return $cleanMessage;
}

function brt_is_remote_already_confirmed_message(?string $message): bool
{
    $normalized = strtoupper(trim((string) $message));
    if ($normalized === '') {
        return false;
    }

    $needles = [
        'SHIPMENT HAS ALREADY BEEN CONFIRMED',
        'SHIPMENT ALREADY CONFIRMED',
        'ALREADY BEEN CONFIRMED',
        'SPEDIZIONE GIA CONFERMATA',
        'SPEDIZIONE GIA\' CONFERMATA',
    ];

    foreach ($needles as $needle) {
        if ($needle !== '' && strpos($normalized, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function brt_mark_shipment_confirmed_from_remote_status(int $shipmentId, string $message): void
{
    $confirmResponse = [
        'executionMessage' => [
            'code' => 0,
            'codeDesc' => 'ALREADY CONFIRMED REMOTAMENTE',
            'message' => $message,
        ],
    ];

    brt_mark_shipment_confirmed($shipmentId, $confirmResponse);
}
/**
 * @return array<int, string>
 */
function brt_customs_required_countries(): array
{
    return ['CH'];
}

/**
 * @return array<string, string>
 */
function brt_customs_categories(): array
{
    return [
        'general' => 'Generico',
        'food' => 'Alimentare',
        'technology' => 'Tecnologia',
        'fashion' => 'Moda e tessile',
    ];
}

/**
 * @return array<string, string>
 */
function brt_customs_default_form_data(): array
{
    return [
        'enabled' => '0',
        'category' => 'general',
        'goods_description' => '',
        'goods_value' => '',
        'goods_currency' => 'EUR',
        'goods_origin_country' => 'IT',
        'hs_code' => '',
        'incoterm' => 'DAP',
        'sender_vat' => '',
        'sender_eori' => '',
        'receiver_vat' => '',
        'receiver_eori' => '',
        'additional_notes' => '',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, string>
 */
function brt_normalize_customs_form_input(array $input): array
{
    $defaults = brt_customs_default_form_data();
    $normalized = $defaults;

    foreach ($defaults as $key => $default) {
        $value = $input[$key] ?? $default;
        if (is_array($value)) {
            $value = '';
        }
        $normalized[$key] = trim((string) $value);
    }

    return $normalized;
}

/**
 * @param array<string, string> $form
 * @return array{errors: array<int, string>, payload: ?array<string, mixed>}
 */
function brt_validate_customs_form(array $form, bool $isRequired): array
{
    $errors = [];

    if (!$isRequired) {
        return ['errors' => [], 'payload' => null];
    }

    $categories = brt_customs_categories();
    $category = array_key_exists($form['category'], $categories) ? $form['category'] : 'general';

    $description = $form['goods_description'];
    if ($description === '') {
        $errors[] = 'Inserisci una descrizione merce dettagliata per la dogana.';
    }

    $valueInput = str_replace([' ', ','], ['', '.'], $form['goods_value']);
    $value = is_numeric($valueInput) ? (float) $valueInput : 0.0;
    if ($value <= 0) {
        $errors[] = 'Inserisci un valore merce in CHF/EUR maggiore di zero.';
    }

    $currency = strtoupper($form['goods_currency']);
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors[] = 'Il codice valuta deve essere composto da 3 lettere (es. EUR).';
    }

    $origin = strtoupper($form['goods_origin_country']);
    if (!preg_match('/^[A-Z]{2}$/', $origin)) {
        $errors[] = 'Il paese di origine deve essere indicato con il codice ISO-2 (es. IT).';
    }

    $hsCode = strtoupper(str_replace([' ', '-'], '', $form['hs_code']));
    if (!preg_match('/^[A-Z0-9]{4,12}$/', $hsCode)) {
        $errors[] = 'Inserisci un codice HS valido (4-12 caratteri alfanumerici).';
    }

    $incoterm = strtoupper($form['incoterm']);
    $allowedIncoterms = ['DAP', 'DDP', 'EXW', 'CIP', 'CPT', 'FCA', 'FOB'];
    if (!in_array($incoterm, $allowedIncoterms, true)) {
        $errors[] = 'Seleziona un Incoterm supportato (DAP, DDP, EXW, CIP, CPT, FCA o FOB).';
    }

    $senderVat = $form['sender_vat'];
    $senderEori = strtoupper($form['sender_eori']);
    if ($senderVat === '' && $senderEori === '') {
        $errors[] = 'Indica almeno Partita IVA o codice EORI del mittente.';
    }

    $payload = null;
    if ($errors === []) {
        $payload = [
            'enabled' => true,
            'category' => $category,
            'goods_description' => $description,
            'goods_value' => $value,
            'goods_value_number' => $value,
            'goods_currency' => $currency,
            'goods_origin_country' => $origin,
            'hs_code' => $hsCode,
            'incoterm' => $incoterm,
            'sender_vat' => $senderVat,
            'sender_eori' => $senderEori,
            'receiver_vat' => $form['receiver_vat'],
            'receiver_eori' => strtoupper($form['receiver_eori']),
            'additional_notes' => $form['additional_notes'],
        ];
    }

    return ['errors' => $errors, 'payload' => $payload];
}

function brt_get_customs_document(int $shipmentId): ?array
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT * FROM brt_customs_documents WHERE shipment_id = :shipment_id LIMIT 1');
    $stmt->execute([':shipment_id' => $shipmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function brt_upsert_customs_document(int $shipmentId, array $data): void
{
    $pdo = brt_db();

    $sql = 'INSERT INTO brt_customs_documents (
            shipment_id,
            status,
            commodity_category,
            goods_description,
            goods_value,
            goods_currency,
            goods_origin_country,
            hs_code,
            incoterm,
            sender_vat,
            sender_eori,
            receiver_vat,
            receiver_eori,
            additional_notes,
            data_payload,
            invoice_path,
            declaration_path,
            generated_at,
            last_error
        ) VALUES (
            :shipment_id,
            :status,
            :commodity_category,
            :goods_description,
            :goods_value,
            :goods_currency,
            :goods_origin_country,
            :hs_code,
            :incoterm,
            :sender_vat,
            :sender_eori,
            :receiver_vat,
            :receiver_eori,
            :additional_notes,
            :data_payload,
            :invoice_path,
            :declaration_path,
            :generated_at,
            :last_error
        ) ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            commodity_category = VALUES(commodity_category),
            goods_description = VALUES(goods_description),
            goods_value = VALUES(goods_value),
            goods_currency = VALUES(goods_currency),
            goods_origin_country = VALUES(goods_origin_country),
            hs_code = VALUES(hs_code),
            incoterm = VALUES(incoterm),
            sender_vat = VALUES(sender_vat),
            sender_eori = VALUES(sender_eori),
            receiver_vat = VALUES(receiver_vat),
            receiver_eori = VALUES(receiver_eori),
            additional_notes = VALUES(additional_notes),
            data_payload = VALUES(data_payload),
            invoice_path = VALUES(invoice_path),
            declaration_path = VALUES(declaration_path),
            generated_at = VALUES(generated_at),
            last_error = VALUES(last_error),
            updated_at = CURRENT_TIMESTAMP';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':shipment_id' => $shipmentId,
        ':status' => (string) ($data['status'] ?? 'pending'),
        ':commodity_category' => $data['commodity_category'] ?? null,
        ':goods_description' => (string) ($data['goods_description'] ?? ''),
        ':goods_value' => (float) ($data['goods_value'] ?? 0),
        ':goods_currency' => strtoupper((string) ($data['goods_currency'] ?? 'EUR')),
        ':goods_origin_country' => strtoupper((string) ($data['goods_origin_country'] ?? 'IT')),
        ':hs_code' => strtoupper((string) ($data['hs_code'] ?? '')),
        ':incoterm' => strtoupper((string) ($data['incoterm'] ?? '')),
        ':sender_vat' => $data['sender_vat'] !== '' ? (string) $data['sender_vat'] : null,
        ':sender_eori' => $data['sender_eori'] !== '' ? strtoupper((string) $data['sender_eori']) : null,
        ':receiver_vat' => $data['receiver_vat'] !== '' ? (string) $data['receiver_vat'] : null,
        ':receiver_eori' => $data['receiver_eori'] !== '' ? strtoupper((string) $data['receiver_eori']) : null,
        ':additional_notes' => $data['additional_notes'] !== '' ? (string) $data['additional_notes'] : null,
        ':data_payload' => (string) ($data['data_payload'] ?? ''),
        ':invoice_path' => $data['invoice_path'] ?? null,
        ':declaration_path' => $data['declaration_path'] ?? null,
        ':generated_at' => $data['generated_at'] ?? null,
        ':last_error' => $data['last_error'] ?? null,
    ]);
}

function brt_delete_customs_document(int $shipmentId, bool $deleteFiles = true): void
{
    $existing = brt_get_customs_document($shipmentId);
    if ($existing !== null && $deleteFiles) {
        brt_customs_delete_files($existing['invoice_path'] ?? null, $existing['declaration_path'] ?? null);
    }

    $pdo = brt_db();
    $stmt = $pdo->prepare('DELETE FROM brt_customs_documents WHERE shipment_id = :shipment_id');
    $stmt->execute([':shipment_id' => $shipmentId]);
}

function brt_customs_delete_files(?string ...$paths): void
{
    $base = rtrim(project_root_path(), '/') . '/';
    foreach ($paths as $path) {
        if ($path === null || $path === '') {
            continue;
        }
        $absolute = $base . ltrim((string) $path, '/');
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}

/**
 * @param array<string, mixed> $shipment
 * @param array<string, mixed>|null $customsPayload
 * @return array{status: string, message?: string}
 */
function brt_sync_customs_documents(int $shipmentId, array $shipment, ?array $customsPayload): array
{
    $destination = strtoupper((string) ($shipment['consignee_country'] ?? ''));
    $required = in_array($destination, brt_customs_required_countries(), true);

    if (!$required || $customsPayload === null) {
        brt_delete_customs_document($shipmentId, true);
        return ['status' => 'skipped'];
    }

    brt_ensure_directories();

    $existing = brt_get_customs_document($shipmentId);
    $outputDirectory = BRT_CUSTOMS_DIRECTORY . '/' . $shipmentId;
    if (!is_dir($outputDirectory)) {
        if (!mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Impossibile creare la cartella per i documenti doganali.');
        }
    }

    $relativeBase = 'uploads/brt/customs/' . $shipmentId . '/';
    $service = new BrtCustomsDocumentService(__DIR__ . '/customs/templates', $outputDirectory);

    $payloadJson = encode_json_pretty($customsPayload);

    try {
        $result = $service->generate($shipment, $customsPayload);

        $invoicePath = $relativeBase . ltrim((string) $result['invoice'], '/');
        $declarationPath = $relativeBase . ltrim((string) $result['declaration'], '/');

        if ($existing !== null) {
            brt_customs_delete_files($existing['invoice_path'] ?? null, $existing['declaration_path'] ?? null);
        }

        brt_upsert_customs_document($shipmentId, [
            'status' => 'generated',
            'commodity_category' => $customsPayload['category'] ?? null,
            'goods_description' => $customsPayload['goods_description'] ?? '',
            'goods_value' => $customsPayload['goods_value'] ?? 0,
            'goods_currency' => $customsPayload['goods_currency'] ?? 'EUR',
            'goods_origin_country' => $customsPayload['goods_origin_country'] ?? 'IT',
            'hs_code' => $customsPayload['hs_code'] ?? '',
            'incoterm' => $customsPayload['incoterm'] ?? '',
            'sender_vat' => $customsPayload['sender_vat'] ?? '',
            'sender_eori' => $customsPayload['sender_eori'] ?? '',
            'receiver_vat' => $customsPayload['receiver_vat'] ?? '',
            'receiver_eori' => $customsPayload['receiver_eori'] ?? '',
            'additional_notes' => $customsPayload['additional_notes'] ?? '',
            'data_payload' => $payloadJson,
            'invoice_path' => $invoicePath,
            'declaration_path' => $declarationPath,
            'generated_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ]);

        brt_cleanup_old_artifacts();

        return ['status' => 'generated'];
    } catch (Throwable $exception) {
        brt_upsert_customs_document($shipmentId, [
            'status' => 'error',
            'commodity_category' => $customsPayload['category'] ?? null,
            'goods_description' => $customsPayload['goods_description'] ?? '',
            'goods_value' => $customsPayload['goods_value'] ?? 0,
            'goods_currency' => $customsPayload['goods_currency'] ?? 'EUR',
            'goods_origin_country' => $customsPayload['goods_origin_country'] ?? 'IT',
            'hs_code' => $customsPayload['hs_code'] ?? '',
            'incoterm' => $customsPayload['incoterm'] ?? '',
            'sender_vat' => $customsPayload['sender_vat'] ?? '',
            'sender_eori' => $customsPayload['sender_eori'] ?? '',
            'receiver_vat' => $customsPayload['receiver_vat'] ?? '',
            'receiver_eori' => $customsPayload['receiver_eori'] ?? '',
            'additional_notes' => $customsPayload['additional_notes'] ?? '',
            'data_payload' => $payloadJson,
            'invoice_path' => $existing['invoice_path'] ?? null,
            'declaration_path' => $existing['declaration_path'] ?? null,
            'generated_at' => $existing['generated_at'] ?? null,
            'last_error' => $exception->getMessage(),
        ]);

        return ['status' => 'error', 'message' => $exception->getMessage()];
    }
}

function brt_ensure_directories(): void
{
    foreach ([BRT_UPLOAD_BASE, BRT_LABEL_DIRECTORY, BRT_MANIFEST_DIRECTORY, BRT_CUSTOMS_DIRECTORY] as $directory) {
        if (is_dir($directory)) {
            continue;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossibile creare la cartella BRT: ' . $directory);
        }
    }
}

function brt_cleanup_old_artifacts(?int $retentionDays = null): void
{
    $days = $retentionDays ?? BRT_FILE_RETENTION_DAYS;
    if ($days <= 0) {
        return;
    }

    $threshold = (new DateTimeImmutable(sprintf('-%d days', $days)))->getTimestamp();

    foreach ([BRT_LABEL_DIRECTORY, BRT_MANIFEST_DIRECTORY, BRT_CUSTOMS_DIRECTORY] as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $rootRealPath = realpath($directory) ?: null;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isFile()) {
                if ($item->getMTime() < $threshold) {
                    if (!@unlink($path)) {
                        error_log('BRT cleanup: impossibile eliminare il file ' . $path);
                        brt_log_event('warning', 'Pulizia BRT: eliminazione file non riuscita', [
                            'path' => $path,
                        ]);
                    }
                }
                continue;
            }

            if (!$item->isDir()) {
                continue;
            }

            if ($rootRealPath !== null && realpath($path) === $rootRealPath) {
                continue;
            }

            try {
                $inner = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
                if ($inner->valid()) {
                    unset($inner);
                    continue;
                }
                unset($inner);
            } catch (UnexpectedValueException $exception) {
                error_log('BRT cleanup: lettura cartella non riuscita per ' . $path . ' - ' . $exception->getMessage());
                brt_log_event('warning', 'Pulizia BRT: lettura cartella non riuscita', [
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }

            if (!@rmdir($path)) {
                error_log('BRT cleanup: impossibile eliminare la cartella ' . $path);
                brt_log_event('warning', 'Pulizia BRT: eliminazione cartella non riuscita', [
                    'path' => $path,
                ]);
            }
        }
    }
}

function brt_backup_manifest_document(?string $absolutePath, ?string $relativePath = null): void
{
    if ($absolutePath === null || $absolutePath === '' || !is_file($absolutePath)) {
        return;
    }

    $filename = $relativePath !== null && $relativePath !== ''
        ? basename(str_replace('\\', '/', $relativePath))
        : basename($absolutePath);

    brt_copy_to_backup($absolutePath, BRT_MANIFEST_BACKUP_DIRECTORY, $filename, 'manifest');
}

function brt_backup_official_manifest_document(?string $relativePath): void
{
    $trimmed = $relativePath !== null ? trim($relativePath) : '';
    if ($trimmed === '') {
        return;
    }

    $absolute = public_path($trimmed);
    if (!is_file($absolute)) {
        return;
    }

    $filename = basename(str_replace('\\', '/', $trimmed));
    brt_copy_to_backup($absolute, BRT_MANIFEST_OFFICIAL_BACKUP_DIRECTORY, $filename, 'manifest_official');
}

function brt_copy_to_backup(string $source, string $directory, string $filename, string $type): void
{
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        brt_log_event('warning', 'Impossibile creare la cartella backup BRT', [
            'directory' => $directory,
            'type' => $type,
        ]);
        return;
    }

    $destination = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (@copy($source, $destination)) {
        return;
    }

    $fallback = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . $filename;
    if (@copy($source, $fallback)) {
        return;
    }

    brt_log_event('warning', 'Copia backup borderò non riuscita', [
        'type' => $type,
        'source' => $source,
        'destination' => $destination,
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function brt_get_shipments(array $filters = []): array
{
    $pdo = brt_db();
    $where = [];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'status = :status';
        $params[':status'] = $filters['status'];
    }

    if (!empty($filters['search'])) {
        $where[] = '(parcel_id LIKE :search OR consignee_name LIKE :search OR alphanumeric_sender_reference LIKE :search)';
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    $sql = 'SELECT s.*, m.reference AS manifest_reference, m.pdf_path AS manifest_pdf_path, m.generated_at AS manifest_generated_at, '
        . 'm.official_number AS manifest_official_number, m.official_url AS manifest_official_url, m.official_pdf_path AS manifest_official_pdf_path, '
        . 'c.status AS customs_status, c.invoice_path AS customs_invoice_path, c.declaration_path AS customs_declaration_path, c.last_error AS customs_last_error '
        . 'FROM brt_shipments s '
        . 'LEFT JOIN brt_manifests m ON m.id = s.manifest_id '
        . 'LEFT JOIN brt_customs_documents c ON c.shipment_id = s.id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function brt_get_shipments_by_manifest(int $manifestId): array
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT * FROM brt_shipments WHERE manifest_id = :id ORDER BY id ASC');
    $stmt->execute([':id' => $manifestId]);
    return $stmt->fetchAll() ?: [];
}

function brt_get_shipment(int $shipmentId): ?array
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT s.*, m.reference AS manifest_reference, m.pdf_path AS manifest_pdf_path, m.generated_at AS manifest_generated_at, '
        . 'm.official_number AS manifest_official_number, m.official_url AS manifest_official_url, m.official_pdf_path AS manifest_official_pdf_path, '
        . 'c.status AS customs_status, c.invoice_path AS customs_invoice_path, c.declaration_path AS customs_declaration_path, c.last_error AS customs_last_error, '
        . 'c.goods_description AS customs_goods_description, c.goods_value AS customs_goods_value, c.goods_currency AS customs_goods_currency, '
        . 'c.hs_code AS customs_hs_code, c.incoterm AS customs_incoterm, c.generated_at AS customs_generated_at '
        . 'FROM brt_shipments s '
        . 'LEFT JOIN brt_manifests m ON m.id = s.manifest_id '
        . 'LEFT JOIN brt_customs_documents c ON c.shipment_id = s.id '
        . 'WHERE s.id = :id LIMIT 1');
    $stmt->execute([':id' => $shipmentId]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function brt_get_shipment_by_reference(string $senderCustomerCode, int $numericReference): ?array
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT s.*, m.reference AS manifest_reference, m.pdf_path AS manifest_pdf_path, m.generated_at AS manifest_generated_at, '
        . 'm.official_number AS manifest_official_number, m.official_url AS manifest_official_url, m.official_pdf_path AS manifest_official_pdf_path, '
        . 'c.status AS customs_status, c.invoice_path AS customs_invoice_path, c.declaration_path AS customs_declaration_path, c.last_error AS customs_last_error '
        . 'FROM brt_shipments s '
        . 'LEFT JOIN brt_manifests m ON m.id = s.manifest_id '
        . 'LEFT JOIN brt_customs_documents c ON c.shipment_id = s.id '
        . 'WHERE s.sender_customer_code = :code AND s.numeric_sender_reference = :reference LIMIT 1');
    $stmt->execute([
        ':code' => $senderCustomerCode,
        ':reference' => $numericReference,
    ]);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * @param array<int, string> $statuses
 * @return array<int, array<string, mixed>>
 */
function brt_get_shipments_pending_tracking(array $statuses, \DateTimeImmutable $staleBefore, ?\DateTimeImmutable $notOlderThan, int $limit): array
{
    $normalizedStatuses = [];
    foreach ($statuses as $status) {
        $value = strtolower(trim((string) $status));
        if ($value === '') {
            continue;
        }
        $normalizedStatuses[$value] = $value;
    }

    if ($normalizedStatuses === []) {
        return [];
    }

    $limit = max(1, min(100, $limit));

    $placeholders = [];
    $params = [
        ':stale_before' => $staleBefore->format('Y-m-d H:i:s'),
    ];

    $index = 0;
    foreach ($normalizedStatuses as $status => $stored) {
        $placeholder = ':status_' . $index++;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $stored;
    }

    $sql = 'SELECT id, parcel_id, tracking_by_parcel_id, sender_customer_code, numeric_sender_reference, alphanumeric_sender_reference, status, last_tracking_at, confirmed_at, created_at '
        . 'FROM brt_shipments '
        . 'WHERE (tracking_by_parcel_id IS NOT NULL OR parcel_id IS NOT NULL) '
        . 'AND deleted_at IS NULL '
        . 'AND status IN (' . implode(', ', $placeholders) . ') '
        . 'AND (last_tracking_at IS NULL OR last_tracking_at <= :stale_before)';

    if ($notOlderThan !== null) {
        $sql .= ' AND COALESCE(confirmed_at, created_at) >= :not_older_than';
        $params[':not_older_than'] = $notOlderThan->format('Y-m-d H:i:s');
    }

    $sql .= ' ORDER BY (last_tracking_at IS NULL) DESC, last_tracking_at ASC, created_at ASC LIMIT ' . $limit;

    $pdo = brt_db();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function brt_next_numeric_reference(string $senderCustomerCode, int $minimum = 0): int
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT MAX(numeric_sender_reference) FROM brt_shipments WHERE sender_customer_code = :code');
    $stmt->execute([':code' => $senderCustomerCode]);
    $max = $stmt->fetchColumn();
    $value = $max !== false ? (int) $max : 0;
    if ($minimum > $value) {
        $value = $minimum;
    }
    return $value + 1;
}

function encode_json_pretty(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
}

function brt_truncate_string(string $value, int $length): string
{
    if ($length <= 0) {
        return '';
    }

    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen')) {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return trim(mb_substr($value, 0, $length));
    }

    if (strlen($value) <= $length) {
        return $value;
    }

    return trim(substr($value, 0, $length));
}

/**
 * @param array<string, string> $defaults
 * @param array<string, mixed> $shipment
 * @return array<string, string>
 */
function brt_prefill_orm_form_data_from_shipment(array $defaults, array $shipment, ?BrtConfig $config = null): array
{
    $prefilled = $defaults;

    $today = date('Y-m-d');
    $createdAt = (string) ($shipment['created_at'] ?? '');
    $createdDate = null;
    if ($createdAt !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $createdAt, $matches)) {
        $createdDate = $matches[1];
    }
    $collectionDate = $today;
    if ($createdDate !== null && $createdDate > $today) {
        $collectionDate = $createdDate;
    }
    $prefilled['collection_date'] = $collectionDate;

    $parcels = (int) ($shipment['number_of_parcels'] ?? 0);
    if ($parcels <= 0) {
        $parcels = 1;
    }
    $prefilled['parcel_count'] = (string) $parcels;

    $weightKg = (float) ($shipment['weight_kg'] ?? 0);
    if ($weightKg > 0) {
        $prefilled['weight_kg'] = rtrim(rtrim(number_format($weightKg, 2, '.', ''), '0'), '.');
        if ($prefilled['weight_kg'] === '') {
            $prefilled['weight_kg'] = '0';
        }
    }

    $shipmentId = (int) ($shipment['id'] ?? 0);
    $numericReference = trim((string) ($shipment['numeric_sender_reference'] ?? ''));
    $consigneeName = trim((string) ($shipment['consignee_name'] ?? ''));

    $descriptionParts = [];
    if ($consigneeName !== '') {
        $descriptionParts[] = 'Spedizione ' . $consigneeName;
    }
    if ($numericReference !== '') {
        $descriptionParts[] = 'rif ' . $numericReference;
    }
    if ($descriptionParts === []) {
        $descriptionParts[] = 'Spedizione BRT';
    }
    $prefilled['good_description'] = brt_truncate_string(implode(' - ', $descriptionParts), 120);

    if (trim($prefilled['notes']) === '') {
        $noteParts = ['Ritiro spedizione #' . ($shipmentId > 0 ? (string) $shipmentId : '?')];
        if ($numericReference !== '') {
            $noteParts[] = 'ref ' . $numericReference;
        }
        if ($consigneeName !== '') {
            $noteParts[] = 'dest. ' . $consigneeName;
        }
        $prefilled['notes'] = brt_truncate_string(implode(' - ', $noteParts), 200);
    }

    $customerCode = trim((string) ($shipment['sender_customer_code'] ?? ''));
    if ($customerCode !== '') {
        $prefilled['customer_account_number'] = $customerCode;
        if (trim($prefilled['requester_customer_number']) === '' || $prefilled['requester_customer_number'] === $defaults['requester_customer_number']) {
            $prefilled['requester_customer_number'] = $customerCode;
        }
        if (trim($prefilled['sender_customer_number']) === '') {
            $prefilled['sender_customer_number'] = $customerCode;
        }
    }

    $defaultCountry = $config?->getDefaultCountryIsoAlpha2() ?? 'IT';
    $prefilled['receiver_company_name'] = $consigneeName;
    $prefilled['receiver_address'] = trim((string) ($shipment['consignee_address'] ?? ''));
    $receiverZip = trim((string) ($shipment['consignee_zip'] ?? ''));
    $prefilled['receiver_city'] = trim((string) ($shipment['consignee_city'] ?? ''));
    $prefilled['receiver_state'] = trim((string) ($shipment['consignee_province'] ?? ''));
    $receiverCountry = strtoupper(trim((string) ($shipment['consignee_country'] ?? '')));
    if ($receiverCountry === '') {
        $receiverCountry = strtoupper($defaultCountry);
    }

    if ($receiverCountry === 'IE') {
        if ($receiverZip === '') {
            $receiverZip = 'EIRE';
        } else {
            $receiverZip = strtoupper($receiverZip);
        }
    }

    $prefilled['receiver_zip'] = $receiverZip;
    $prefilled['receiver_country'] = $receiverCountry;

    $prefilled['source_shipment_id'] = $shipmentId > 0 ? (string) $shipmentId : '';
    if ($numericReference !== '') {
        $prefilled['request_ref'] = brt_truncate_string('SHIP-' . $numericReference, 35);
    } elseif ($shipmentId > 0) {
        $prefilled['request_ref'] = brt_truncate_string('SHIP-' . $shipmentId, 35);
    }

    return $prefilled;
}

/**
 * @param array<mixed> $orders
 * @return array{collection_date: ?string, parcels: ?int, weight: ?float, payer_type: ?string}
 */
function brt_extract_orm_summary_from_orders(array $orders): array
{
    $candidate = null;

    if (isset($orders[0]) && is_array($orders[0])) {
        $candidate = $orders[0];
    } elseif (isset($orders['requestInfos']) || isset($orders['brtSpec'])) {
        $candidate = $orders;
    }

    $collectionDate = null;
    $parcels = null;
    $weight = null;
    $payerType = null;

    if (is_array($candidate)) {
        $requestInfos = $candidate['requestInfos'] ?? null;
        if (is_array($requestInfos)) {
            $collectionDate = $requestInfos['collectionDate'] ?? null;
            $parcelValue = $requestInfos['parcelCount'] ?? null;
            if ($parcelValue !== null && $parcelValue !== '') {
                $parcels = (int) $parcelValue;
            }
        }

        $brtSpec = $candidate['brtSpec'] ?? null;
        if (is_array($brtSpec)) {
            $weightValue = $brtSpec['weightKG'] ?? null;
            if ($weightValue !== null && $weightValue !== '') {
                $weight = (float) $weightValue;
            }
            $payerType = $brtSpec['payerType'] ?? null;
        }
    }

    return [
        'collection_date' => is_string($collectionDate) && $collectionDate !== '' ? $collectionDate : null,
        'parcels' => $parcels !== null ? (int) $parcels : null,
        'weight' => $weight !== null ? (float) $weight : null,
        'payer_type' => is_string($payerType) && $payerType !== '' ? $payerType : null,
    ];
}

/**
 * @param array<string, mixed> $routingResponse
 * @return array{amount: float, currency: string, label: string, breakdown: array<string, float>}|null
 */
function brt_extract_routing_quote_summary(array $routingResponse): ?array
{
    $quotes = [];

    $walker = static function (array $node) use (&$walker, &$quotes): void {
        $amounts = [];
        $currency = null;

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $walker($value);
                continue;
            }

            if (is_numeric($value) && is_string($key) && preg_match('/(amount|price|cost)$/i', $key)) {
                $amounts[strtolower($key)] = (float) $value;
            }

            if ($currency === null && is_string($key) && preg_match('/currency/i', $key) && is_string($value)) {
                $currency = strtoupper(trim($value));
            }
        }

        if ($amounts !== []) {
            $quotes[] = [
                'amounts' => $amounts,
                'currency' => $currency,
            ];
        }
    };

    $walker($routingResponse);

    if ($quotes === []) {
        return null;
    }

    $priority = ['customeramount', 'customerprice', 'totalamount', 'grossamount', 'netamount', 'amount', 'price', 'listprice'];
    foreach ($quotes as $quote) {
        foreach ($priority as $targetKey) {
            foreach ($quote['amounts'] as $name => $value) {
                if ($name === $targetKey) {
                    return [
                        'amount' => $value,
                        'currency' => $quote['currency'] ?? 'EUR',
                        'label' => $name,
                        'breakdown' => $quote['amounts'],
                    ];
                }
            }
        }
    }

    $first = $quotes[0];
    $firstAmounts = $first['amounts'];
    $label = array_key_first($firstAmounts) ?? 'amount';
    $amount = $firstAmounts[$label] ?? 0.0;

    return [
        'amount' => (float) $amount,
        'currency' => $first['currency'] ?? 'EUR',
        'label' => (string) $label,
        'breakdown' => $firstAmounts,
    ];
}

function brt_normalize_orm_status(?string $status): ?string
{
    if ($status === null) {
        return null;
    }

    $trimmed = trim($status);
    if ($trimmed === '') {
        return null;
    }

    return strtolower($trimmed);
}

/**
 * @param array<mixed> $payload
 */
function brt_extract_orm_remote_status(array $payload): ?string
{
    $candidates = [
        $payload['status'] ?? null,
        $payload['ormStatus'] ?? null,
        $payload['state'] ?? null,
        $payload['result']['status'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate)) {
            $normalized = brt_normalize_orm_status($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }
    }

    return null;
}

/**
 * @param array<int, array<string, mixed>> $response
 */
function brt_store_orm_response(array $orders, array $response, ?array $formData = null): int
{
    $pdo = brt_db();
    $pdo->beginTransaction();
    try {
        $reservationNumber = null;
        $status = 'pending';
        $errors = null;
        $remoteStatus = 'pending';

        if (isset($response[0]['ormReservationNumber'])) {
            $reservationNumber = (string) $response[0]['ormReservationNumber'];
            $status = 'confirmed';
            $remoteStatus = $status;
        }

        if (isset($response[0]['errors']) && is_array($response[0]['errors']) && $response[0]['errors'] !== []) {
            $status = 'error';
            $errors = encode_json_pretty((array) $response[0]['errors']);
            $remoteStatus = 'error';
        }

        $summary = brt_extract_orm_summary_from_orders($orders);
        $collectionDate = $summary['collection_date'];
        $parcels = $summary['parcels'];
        $weight = $summary['weight'];
        $payerType = $summary['payer_type'];
        $lastRequestBody = $orders;

        $stmt = $pdo->prepare('INSERT INTO brt_orm_requests (
            reservation_number,
            status,
            remote_status,
            collection_date,
            payer_type,
            parcels,
            weight_kg,
            request_payload,
            response_payload,
            last_request_payload,
            last_response_payload,
            remote_payload,
            form_payload,
            errors_payload,
            last_synced_at
        ) VALUES (
            :reservation_number,
            :status,
            :remote_status,
            :collection_date,
            :payer_type,
            :parcels,
            :weight,
            :request_payload,
            :response_payload,
            :last_request_payload,
            :last_response_payload,
            :remote_payload,
            :form_payload,
            :errors,
            :last_synced_at
        )');

        $stmt->execute([
            ':reservation_number' => $reservationNumber,
            ':status' => $status,
            ':remote_status' => $remoteStatus,
            ':collection_date' => $collectionDate,
            ':payer_type' => $payerType,
            ':parcels' => $parcels !== null ? (int) $parcels : null,
            ':weight' => $weight !== null ? (float) $weight : null,
            ':request_payload' => encode_json_pretty($orders),
            ':response_payload' => encode_json_pretty($response),
            ':last_request_payload' => encode_json_pretty($lastRequestBody),
            ':last_response_payload' => encode_json_pretty($response),
            ':remote_payload' => null,
            ':form_payload' => $formData !== null ? encode_json_pretty($formData) : null,
            ':errors' => $errors,
            ':last_synced_at' => null,
        ]);

        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function brt_get_recent_orm_requests(): array
{
    $pdo = brt_db();
    $stmt = $pdo->query('SELECT * FROM brt_orm_requests ORDER BY created_at DESC LIMIT 50');
    return $stmt->fetchAll() ?: [];
}

function brt_update_tracking(int $shipmentId, array $tracking): void
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('UPDATE brt_shipments SET last_tracking_payload = :payload, last_tracking_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':payload' => encode_json_pretty($tracking),
        ':id' => $shipmentId,
    ]);
}

function brt_get_orm_request(int $requestId): ?array
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT * FROM brt_orm_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $requestId]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function brt_get_orm_request_by_reservation(string $reservationNumber): ?array
{
    $reservationNumber = trim($reservationNumber);
    if ($reservationNumber === '') {
        return null;
    }

    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT * FROM brt_orm_requests WHERE reservation_number = :number LIMIT 1');
    $stmt->execute([':number' => $reservationNumber]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function brt_mark_orm_cancelled(int $requestId, bool $success, ?array $response = null): void
{
    $pdo = brt_db();
    $status = $success ? 'cancelled' : 'cancel_failed';
    $payload = $response !== null ? encode_json_pretty($response) : null;
    $errors = $success ? null : $payload;

    $stmt = $pdo->prepare(
        'UPDATE brt_orm_requests SET '
        . 'status = :status, '
        . 'remote_status = :remote_status, '
        . 'response_payload = COALESCE(:payload, response_payload), '
        . 'last_response_payload = COALESCE(:payload, last_response_payload), '
        . 'remote_payload = COALESCE(:payload, remote_payload), '
        . 'errors_payload = CASE WHEN :errors IS NULL THEN errors_payload ELSE :errors END, '
        . 'last_synced_at = NOW(), '
        . 'updated_at = NOW() '
        . 'WHERE id = :id'
    );

    $stmt->execute([
        ':status' => $status,
        ':remote_status' => $status,
        ':payload' => $payload,
        ':errors' => $errors,
        ':id' => $requestId,
    ]);
}

function brt_mark_orm_error(int $requestId, string $message, ?array $response = null): void
{
    $pdo = brt_db();
    $payload = $response !== null ? encode_json_pretty($response) : null;
    $errorsData = [
        'message' => $message,
    ];

    if ($response !== null) {
        $errorsData['response'] = $response;
    }

    $stmt = $pdo->prepare(
        'UPDATE brt_orm_requests SET '
        . 'status = :status, '
        . 'remote_status = :remote_status, '
        . 'response_payload = COALESCE(:payload, response_payload), '
        . 'last_response_payload = COALESCE(:payload, last_response_payload), '
        . 'remote_payload = COALESCE(:payload, remote_payload), '
        . 'errors_payload = :errors, '
        . 'last_synced_at = NOW(), '
        . 'updated_at = NOW() '
        . 'WHERE id = :id'
    );

    $stmt->execute([
        ':status' => 'error',
        ':remote_status' => 'error',
        ':payload' => $payload,
        ':errors' => encode_json_pretty($errorsData),
        ':id' => $requestId,
    ]);
}

function brt_mark_orm_synced(int $requestId, array $remotePayload): void
{
    $pdo = brt_db();
    $summary = brt_extract_orm_summary_from_orders($remotePayload);
    $remoteStatus = brt_extract_orm_remote_status($remotePayload) ?? 'synced';

    $stmt = $pdo->prepare(
        'UPDATE brt_orm_requests SET '
        . 'status = :status, '
        . 'remote_status = :remote_status, '
        . 'remote_payload = :remote_payload, '
        . 'collection_date = COALESCE(:collection_date, collection_date), '
        . 'parcels = COALESCE(:parcels, parcels), '
        . 'weight_kg = COALESCE(:weight, weight_kg), '
        . 'payer_type = COALESCE(:payer_type, payer_type), '
        . 'last_synced_at = NOW(), '
        . 'updated_at = NOW() '
        . 'WHERE id = :id'
    );

    $stmt->execute([
        ':status' => $remoteStatus,
        ':remote_status' => $remoteStatus,
        ':remote_payload' => encode_json_pretty($remotePayload),
        ':collection_date' => $summary['collection_date'],
        ':parcels' => $summary['parcels'],
        ':weight' => $summary['weight'],
        ':payer_type' => $summary['payer_type'],
        ':id' => $requestId,
    ]);
}

function brt_mark_orm_updated(int $requestId, array $requestBody, array $responseBody, ?array $formData = null, ?array $remotePayload = null): void
{
    $pdo = brt_db();
    $summary = brt_extract_orm_summary_from_orders($requestBody);
    $status = brt_extract_orm_remote_status($remotePayload ?? $responseBody) ?? 'updated';

    $remotePayloadJson = $remotePayload !== null ? encode_json_pretty($remotePayload) : null;
    $remoteSynced = $remotePayload !== null ? 1 : 0;

    $stmt = $pdo->prepare(
        'UPDATE brt_orm_requests SET '
        . 'status = :status, '
        . 'remote_status = :remote_status, '
        . 'collection_date = COALESCE(:collection_date, collection_date), '
        . 'parcels = COALESCE(:parcels, parcels), '
        . 'weight_kg = COALESCE(:weight, weight_kg), '
        . 'payer_type = COALESCE(:payer_type, payer_type), '
        . 'last_request_payload = :last_request_payload, '
        . 'last_response_payload = :last_response_payload, '
        . 'response_payload = :last_response_payload, '
        . 'form_payload = COALESCE(:form_payload, form_payload), '
        . 'remote_payload = COALESCE(:remote_payload, remote_payload), '
        . 'last_synced_at = CASE WHEN :remote_synced = 1 THEN NOW() ELSE last_synced_at END, '
        . 'updated_at = NOW() '
        . 'WHERE id = :id'
    );

    $stmt->execute([
        ':status' => $status,
        ':remote_status' => $status,
        ':collection_date' => $summary['collection_date'],
        ':parcels' => $summary['parcels'],
        ':weight' => $summary['weight'],
        ':payer_type' => $summary['payer_type'],
        ':last_request_payload' => encode_json_pretty($requestBody),
        ':last_response_payload' => encode_json_pretty($responseBody),
        ':form_payload' => $formData !== null ? encode_json_pretty($formData) : null,
        ':remote_payload' => $remotePayloadJson,
        ':remote_synced' => $remoteSynced,
        ':id' => $requestId,
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function brt_get_shipments_pending_manifest(): array
{
    $pdo = brt_db();
    $stmt = $pdo->query(
        "SELECT * FROM brt_shipments " .
        "WHERE manifest_id IS NULL AND deleted_at IS NULL AND status IN ('confirmed', 'warning', 'created') " .
        'ORDER BY created_at ASC'
    );

    return $stmt->fetchAll() ?: [];
}

/**
 * @param array<int, int> $shipmentIds
 * @return array<int, array<string, mixed>>
 */
function brt_get_shipments_for_manifest(array $shipmentIds): array
{
    $filteredIds = array_values(array_filter(array_map(static fn ($value) => (int) $value, $shipmentIds), static fn ($value) => $value > 0));
    if ($filteredIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($filteredIds), '?'));

    $pdo = brt_db();
    $sql = "SELECT * FROM brt_shipments WHERE id IN (" . $placeholders . ") "
        . "AND manifest_id IS NULL AND deleted_at IS NULL AND status IN ('confirmed', 'warning', 'created') "
        . 'ORDER BY created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($filteredIds);

    return $stmt->fetchAll() ?: [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function brt_get_recent_manifests(): array
{
    $pdo = brt_db();
    $stmt = $pdo->query('SELECT * FROM brt_manifests ORDER BY generated_at DESC LIMIT 20');
    return $stmt->fetchAll() ?: [];
}

function brt_get_manifest(int $manifestId): ?array
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('SELECT * FROM brt_manifests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $manifestId]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function brt_update_manifest_pdf_path(int $manifestId, string $relativePath): void
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('UPDATE brt_manifests SET pdf_path = :path WHERE id = :id');
    $stmt->execute([
        ':path' => $relativePath,
        ':id' => $manifestId,
    ]);
}

/**
 * @param array<string, mixed> $manifest
 * @return array{relative_path: string, absolute_path: string}|null
 */
function brt_ensure_manifest_pdf(array $manifest, ?BrtConfig $config = null): ?array
{
    $relative = isset($manifest['pdf_path']) ? (string) $manifest['pdf_path'] : '';
    if ($relative !== '') {
        $absolute = public_path($relative);
        if (is_file($absolute)) {
            return ['relative_path' => $relative, 'absolute_path' => $absolute];
        }
    }

    $manifestId = (int) ($manifest['id'] ?? 0);
    if ($manifestId <= 0) {
        return null;
    }

    $shipments = brt_get_shipments_by_manifest($manifestId);
    if ($shipments === []) {
        return null;
    }

    $config = $config ?? new BrtConfig();
    $context = [
        'senderCustomerCode' => $config->getSenderCustomerCode(),
        'departureDepot' => $config->getDepartureDepot(),
    ];

    $timestamp = null;
    if (!empty($manifest['generated_at'])) {
        try {
            $timestamp = new DateTimeImmutable((string) $manifest['generated_at']);
        } catch (Throwable $exception) {
            $timestamp = null;
        }
    }

    $filename = null;
    if ($relative !== '') {
        $filename = basename(str_replace('\\', '/', $relative));
    } elseif ($timestamp !== null) {
        $filename = sprintf('bordero_brt_%s.pdf', $timestamp->format('Ymd_His'));
    }

    try {
        $generator = new BrtManifestGenerator();
        $paths = $generator->generate($shipments, $context, $timestamp, $filename);
        brt_update_manifest_pdf_path($manifestId, $paths['relative_path']);
        brt_backup_manifest_document($paths['absolute_path'] ?? null, $paths['relative_path'] ?? null);
        return $paths;
    } catch (Throwable $exception) {
        brt_log_event('error', 'Rigenerazione borderò non riuscita: ' . $exception->getMessage(), [
            'manifest_id' => $manifestId,
            'reference' => $manifest['reference'] ?? null,
        ]);
        return null;
    }
}

/**
 * @throws Throwable
 */
function brt_generate_pending_manifest(?BrtConfig $config = null): ?array
{
    $shipments = brt_get_shipments_pending_manifest();
    if ($shipments === []) {
        return null;
    }

    $config = $config ?? new BrtConfig();
    $generator = new BrtManifestGenerator();
    $manifestService = $config->isManifestEnabled() ? new BrtManifestService($config) : null;
    $manifestService = $config->isManifestEnabled() ? new BrtManifestService($config) : null;

    $context = [
        'senderCustomerCode' => $config->getSenderCustomerCode(),
        'departureDepot' => $config->getDepartureDepot(),
    ];

    $manifestData = $generator->generate($shipments, $context);
    brt_backup_manifest_document($manifestData['absolute_path'] ?? null, $manifestData['relative_path'] ?? null);

    $totals = brt_calculate_manifest_totals($shipments);

    $pdo = brt_db();
    $pdo->beginTransaction();

    $officialPdfRelativePath = null;

    try {
        $officialData = [];
        if ($manifestService !== null) {
            try {
                $officialData = $manifestService->generateOfficialManifest($shipments, [
                    'departureDepot' => $context['departureDepot'] ?? null,
                    'pickupDate' => $manifestData['generated_at']->format('Y-m-d'),
                ]);
                $officialPdfRelativePath = $officialData['pdf_path'] ?? null;
                brt_backup_official_manifest_document($officialPdfRelativePath);
            } catch (BrtException $exception) {
                $cleanMessage = brt_normalize_remote_warning($exception->getMessage());
                brt_log_event('warning', 'Bordero ufficiale non generato: ' . $cleanMessage, [
                    'error' => $cleanMessage,
                    'shipments' => array_column($shipments, 'id'),
                    'context' => $context,
                ]);
            }
        }

        $manifestId = brt_store_manifest_record([
            'reference' => $manifestData['reference'],
            'generated_at' => $manifestData['generated_at'],
            'shipments_count' => $totals['shipments'],
            'total_parcels' => $totals['parcels'],
            'total_weight_kg' => $totals['weight'],
            'total_volume_m3' => $totals['volume'],
            'pdf_path' => $manifestData['relative_path'],
            'official_number' => $officialData['manifest_number'] ?? null,
            'official_url' => $officialData['document_url'] ?? null,
            'official_pdf_path' => $officialData['pdf_path'] ?? null,
        ]);

        brt_assign_shipments_to_manifest($manifestId, array_column($shipments, 'id'), $manifestData['generated_at']);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        if (isset($manifestData['absolute_path']) && file_exists($manifestData['absolute_path'])) {
            unlink($manifestData['absolute_path']);
        }
        if ($officialPdfRelativePath !== null) {
            $officialAbsolute = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $officialPdfRelativePath);
            if (file_exists($officialAbsolute)) {
                unlink($officialAbsolute);
            }
        }
        throw $exception;
    }

    brt_cleanup_old_artifacts();

    return brt_get_manifest($manifestId);
}

/**
 * @param array<int, int> $shipmentIds
 */
function brt_generate_manifest_for_shipments(array $shipmentIds, ?BrtConfig $config = null): ?array
{
    $filteredIds = array_values(array_filter(array_map(static fn ($value) => (int) $value, $shipmentIds), static fn ($value) => $value > 0));
    if ($filteredIds === []) {
        return null;
    }

    $shipments = brt_get_shipments_for_manifest($filteredIds);
    if ($shipments === []) {
        throw new BrtException('Nessuna delle spedizioni selezionate è idonea per il borderò. Verifica che siano confermate e non già assegnate.');
    }

    if (count($shipments) !== count($filteredIds)) {
        throw new BrtException('Alcune spedizioni selezionate non sono idonee. Verifica lo stato o l\'eventuale assegnazione a un borderò.');
    }

    $config = $config ?? new BrtConfig();
    
    // Conferma automaticamente le spedizioni "created" prima di creare il borderò
    $shipmentService = new BrtShipmentService($config);
    foreach ($shipments as &$shipment) {
        if ($shipment['status'] === 'created') {
            try {
                $confirmInput = [
                    'senderCustomerCode' => $shipment['sender_customer_code'],
                    'numericSenderReference' => $shipment['numeric_sender_reference'],
                    'alphanumericSenderReference' => $shipment['alphanumeric_sender_reference']
                ];
                
                $confirmResponse = $shipmentService->confirmShipment($confirmInput);
                
                // Aggiorna il record della spedizione con i dati di conferma
                $originalPayload = brt_decode_request_payload($shipment['request_payload'] ?? null);
                $originalRequest = $originalPayload['request'] ?? [];
                if (!is_array($originalRequest)) {
                    $originalRequest = [];
                }
                $originalMetadata = $originalPayload['meta'] ?? [];
                if (!is_array($originalMetadata)) {
                    $originalMetadata = [];
                }
                brt_update_shipment_record(
                    $shipment['id'],
                    $originalRequest,
                    $confirmResponse,
                    $originalMetadata,
                    ['preserve_label' => false, 'preserve_tracking' => true, 'preserve_confirmation' => false]
                );

                brt_mark_shipment_confirmed((int) $shipment['id'], $confirmResponse);
                
                // Aggiorna i dati locali per il manifest
                $shipment['status'] = 'confirmed';
                
                brt_log_event('info', 'Spedizione confermata automaticamente durante creazione borderò', [
                    'shipment_id' => $shipment['id'],
                    'numeric_reference' => $shipment['numeric_sender_reference']
                ]);
                
            } catch (BrtException $exception) {
                $cleanMessage = brt_normalize_remote_warning($exception->getMessage());
                if (brt_is_remote_already_confirmed_message($cleanMessage)) {
                    brt_mark_shipment_confirmed_from_remote_status((int) $shipment['id'], $cleanMessage);
                    $shipment['status'] = 'confirmed';
                    brt_log_event('info', 'Spedizione già confermata da BRT rilevata durante creazione borderò', [
                        'shipment_id' => $shipment['id'],
                        'numeric_reference' => $shipment['numeric_sender_reference'],
                        'message' => $cleanMessage,
                    ]);
                } else {
                    brt_log_event('warning', 'Impossibile confermare automaticamente la spedizione durante creazione borderò: ' . $cleanMessage, [
                        'shipment_id' => $shipment['id'],
                        'numeric_reference' => $shipment['numeric_sender_reference']
                    ]);
                    // Se la conferma fallisce, manteniamo comunque la spedizione nel manifest se è in stato created
                    continue;
                }
            }
        }
    }
    unset($shipment);

    $generator = new BrtManifestGenerator();
    $manifestService = $config->isManifestEnabled() ? new BrtManifestService($config) : null;

    $context = [
        'senderCustomerCode' => $config->getSenderCustomerCode(),
        'departureDepot' => $config->getDepartureDepot(),
    ];

    $manifestData = $generator->generate($shipments, $context);
    brt_backup_manifest_document($manifestData['absolute_path'] ?? null, $manifestData['relative_path'] ?? null);
    $totals = brt_calculate_manifest_totals($shipments);

    $pdo = brt_db();
    $pdo->beginTransaction();

    $officialPdfRelativePath = null;

    try {
        $officialData = [];
        if ($manifestService !== null) {
            try {
                $officialData = $manifestService->generateOfficialManifest($shipments, [
                    'departureDepot' => $context['departureDepot'] ?? null,
                    'pickupDate' => $manifestData['generated_at']->format('Y-m-d'),
                ]);
                $officialPdfRelativePath = $officialData['pdf_path'] ?? null;
                brt_backup_official_manifest_document($officialPdfRelativePath);
            } catch (BrtException $exception) {
                $cleanMessage = brt_normalize_remote_warning($exception->getMessage());
                brt_log_event('warning', 'Bordero ufficiale non generato: ' . $cleanMessage, [
                    'error' => $cleanMessage,
                    'shipments' => array_column($shipments, 'id'),
                    'context' => $context,
                ]);
            }
        }

        $manifestId = brt_store_manifest_record([
            'reference' => $manifestData['reference'],
            'generated_at' => $manifestData['generated_at'],
            'shipments_count' => $totals['shipments'],
            'total_parcels' => $totals['parcels'],
            'total_weight_kg' => $totals['weight'],
            'total_volume_m3' => $totals['volume'],
            'pdf_path' => $manifestData['relative_path'],
            'official_number' => $officialData['manifest_number'] ?? null,
            'official_url' => $officialData['document_url'] ?? null,
            'official_pdf_path' => $officialData['pdf_path'] ?? null,
        ]);

        brt_assign_shipments_to_manifest($manifestId, array_column($shipments, 'id'), $manifestData['generated_at']);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        if (isset($manifestData['absolute_path']) && file_exists($manifestData['absolute_path'])) {
            unlink($manifestData['absolute_path']);
        }
        if ($officialPdfRelativePath !== null) {
            $officialAbsolute = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $officialPdfRelativePath);
            if (file_exists($officialAbsolute)) {
                unlink($officialAbsolute);
            }
        }
        throw $exception;
    }

    brt_cleanup_old_artifacts();

    return brt_get_manifest($manifestId);
}

/**
 * @param array<int, array<string, mixed>> $shipments
 * @return array{shipments: int, parcels: int, weight: float, volume: float}
 */
function brt_calculate_manifest_totals(array $shipments): array
{
    $parcels = 0;
    $weight = 0.0;
    $volume = 0.0;

    foreach ($shipments as $shipment) {
        $parcels += (int) ($shipment['number_of_parcels'] ?? 0);
        $weight += (float) ($shipment['weight_kg'] ?? 0);
        $volume += (float) ($shipment['volume_m3'] ?? 0);
    }

    return [
        'shipments' => count($shipments),
        'parcels' => $parcels,
        'weight' => $weight,
        'volume' => $volume,
    ];
}

/**
 * @param array{
 *     reference: string,
 *     generated_at: DateTimeInterface,
 *     shipments_count: int,
 *     total_parcels: int,
 *     total_weight_kg: float,
 *     total_volume_m3: float,
 *     pdf_path: string,
 *     official_number?: ?string,
 *     official_url?: ?string,
 *     official_pdf_path?: ?string
 * } $manifest
 */
function brt_store_manifest_record(array $manifest): int
{
    $pdo = brt_db();
    $stmt = $pdo->prepare('INSERT INTO brt_manifests (
        reference,
        official_number,
        official_url,
        official_pdf_path,
        generated_at,
        shipments_count,
        total_parcels,
        total_weight_kg,
        total_volume_m3,
        pdf_path
    ) VALUES (
        :reference,
        :official_number,
        :official_url,
        :official_pdf_path,
        :generated_at,
        :shipments_count,
        :total_parcels,
        :total_weight_kg,
        :total_volume_m3,
        :pdf_path
    )');

    $stmt->execute([
        ':reference' => $manifest['reference'],
        ':official_number' => $manifest['official_number'] ?? null,
        ':official_url' => $manifest['official_url'] ?? null,
        ':official_pdf_path' => $manifest['official_pdf_path'] ?? null,
        ':generated_at' => $manifest['generated_at']->format('Y-m-d H:i:s'),
        ':shipments_count' => $manifest['shipments_count'],
        ':total_parcels' => $manifest['total_parcels'],
        ':total_weight_kg' => $manifest['total_weight_kg'],
        ':total_volume_m3' => $manifest['total_volume_m3'],
        ':pdf_path' => $manifest['pdf_path'],
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<int, int> $shipmentIds
 */
function brt_assign_shipments_to_manifest(int $manifestId, array $shipmentIds, DateTimeInterface $generatedAt): void
{
    if ($shipmentIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($shipmentIds), '?'));
    $sql = 'UPDATE brt_shipments SET manifest_id = ?, manifest_generated_at = ? WHERE id IN (' . $placeholders . ')';

    $params = array_merge(
        [$manifestId, $generatedAt->format('Y-m-d H:i:s')],
        array_map(static fn ($id) => (int) $id, $shipmentIds)
    );

    $pdo = brt_db();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function brt_translate_status(string $status): string
{
    $normalized = strtolower(trim($status));
    $map = [
        'created' => 'Creata',
        'confirmed' => 'Confermata',
        'warning' => 'Con avvisi',
        'cancelled' => 'Annullata',
    ];

    if ($normalized === '') {
        return '';
    }

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    return ucfirst($normalized);
}

function brt_translate_execution_message(?string $message): string
{
    if ($message === null) {
        return '';
    }

    $trimmed = trim($message);
    if ($trimmed === '') {
        return '';
    }

    $map = [
        'brt has received shipment information properly' => 'BRT ha ricevuto correttamente le informazioni della spedizione.',
        'shipment correctly confirmed' => 'Spedizione confermata correttamente.',
        'shipment correctly deleted' => 'Spedizione annullata correttamente.',
    ];

    $key = strtolower($trimmed);
    return $map[$key] ?? $trimmed;
}

