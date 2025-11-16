<?php
declare(strict_types=1);

use App\Services\SettingsService;
use DateTimeImmutable;
use DateTimeZone;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;

const CAF_PATRONATO_MODULE_LOG = 'Servizi/CAFPatronato';
const CAF_PATRONATO_UPLOAD_DIR = 'assets/uploads/caf-patronato';
const CAF_PATRONATO_ENCRYPTION_SUFFIX = '.cafenc';
const CAF_PATRONATO_ENCRYPTION_HEADER = 'CAFENC1';
const CAF_PATRONATO_MAX_UPLOAD_SIZE = 12_582_912; // 12 MB

function caf_patronato_generate_standard_filename(?string $servizio, ?string $nominativo): ?string
{
    $servizio = trim((string) $servizio);
    $nominativo = trim((string) $nominativo);

    if ($servizio === '' || $nominativo === '') {
        return null;
    }

    $year = null;
    if (preg_match('/\b(20\d{2})\b/', $servizio, $match) === 1) {
        $year = $match[1];
        $servizio = trim(str_replace($match[0], '', $servizio));
    }

    $normalizedService = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $servizio));
    $normalizedService = trim($normalizedService, '_');
    if ($normalizedService === '') {
        $normalizedService = 'DOCUMENTO';
    }

    if ($year === null) {
        $year = date('Y');
    }

    $nameParts = preg_split('/\s+/', $nominativo) ?: [];
    $normalizedName = '';
    foreach ($nameParts as $part) {
        $cleanPart = preg_replace('/[^A-Za-z0-9]/', '', $part);
        if ($cleanPart === '') {
            continue;
        }
        $normalizedName .= ucfirst(strtolower($cleanPart));
    }

    if ($normalizedName === '') {
        $normalizedName = 'Assistito';
    }

    $filename = $normalizedService . '_' . $year . '_' . $normalizedName . '.pdf';

    return preg_replace('/[^A-Za-z0-9._-]/', '', $filename) ?: null;
}

function caf_patronato_get_encryption_key(bool $required = true): ?string
{
    static $keyCache;
    if ($keyCache === null) {
        $raw = env('CAF_PATRONATO_ENCRYPTION_KEY') ?: '';
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            $keyCache = false;
        } elseif (preg_match('/^[A-Fa-f0-9]{64}$/', $raw) === 1) {
            $decoded = hex2bin($raw);
            $keyCache = $decoded !== false && strlen($decoded) === 32 ? $decoded : false;
        } else {
            $decoded = base64_decode($raw, true);
            $keyCache = $decoded !== false && strlen($decoded) === 32 ? $decoded : false;
        }
    }

    if ($keyCache === false) {
        if ($required) {
            throw new RuntimeException('Chiave di cifratura non configurata. Imposta CAF_PATRONATO_ENCRYPTION_KEY.');
        }
        return null;
    }

    return $keyCache;
}

function caf_patronato_encrypt_uploaded_file(string $sourcePath, string $destinationPath): void
{
    if ($sourcePath === '' || !is_file($sourcePath)) {
        throw new RuntimeException('File sorgente per la cifratura non valido.');
    }

    $contents = file_get_contents($sourcePath);
    if ($contents === false) {
        throw new RuntimeException('Impossibile leggere il file sorgente per la cifratura.');
    }

    caf_patronato_encrypt_contents_to_path($contents, $destinationPath);
}

function caf_patronato_encrypt_contents_to_path(string $plainContents, string $destinationPath): void
{
    $key = caf_patronato_get_encryption_key();
    $cipher = 'aes-256-gcm';
    $ivLength = openssl_cipher_iv_length($cipher);
    if (!is_int($ivLength) || $ivLength <= 0) {
        throw new RuntimeException('Cipher di cifratura non valido.');
    }

    $iv = random_bytes($ivLength);
    $tag = '';
    $ciphertext = openssl_encrypt($plainContents, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('Cifratura allegato non riuscita.');
    }

    $payload = [
        'v' => 1,
        'alg' => $cipher,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($ciphertext),
    ];

    $encoded = CAF_PATRONATO_ENCRYPTION_HEADER . "\n" . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($destinationPath, $encoded) === false) {
        throw new RuntimeException('Scrittura allegato cifrato non riuscita.');
    }
}

function caf_patronato_decrypt_file(string $absolutePath): string
{
    if (!is_file($absolutePath)) {
        throw new RuntimeException('Allegato non trovato.');
    }

    $contents = file_get_contents($absolutePath);
    if ($contents === false) {
        throw new RuntimeException('Impossibile leggere l\'allegato.');
    }

    if (strncmp($contents, CAF_PATRONATO_ENCRYPTION_HEADER, strlen(CAF_PATRONATO_ENCRYPTION_HEADER)) !== 0) {
        return $contents;
    }

    $parts = explode("\n", $contents, 2);
    if (count($parts) !== 2) {
        throw new RuntimeException('Formato allegato cifrato non riconosciuto.');
    }

    $meta = json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($meta) || !isset($meta['data'], $meta['iv'], $meta['tag'], $meta['alg'])) {
        throw new RuntimeException('Metadati di cifratura mancanti.');
    }

    $ciphertext = base64_decode((string) $meta['data'], true);
    $iv = base64_decode((string) $meta['iv'], true);
    $tag = base64_decode((string) $meta['tag'], true);
    $cipher = (string) $meta['alg'];

    if ($ciphertext === false || $iv === false || $tag === false || $cipher === '') {
        throw new RuntimeException('Metadati di cifratura non validi.');
    }

    $key = caf_patronato_get_encryption_key();
    $plain = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('Decifratura allegato non riuscita.');
    }

    return $plain;
}

function caf_patronato_build_download_url(string $source, int $id): string
{
    $normalizedSource = 'document';
    return base_url('modules/servizi/caf-patronato/download.php?source=' . $normalizedSource . '&id=' . $id);
}

/**
 * @return array<int, array{key:string,label:string,prefix:string}>
 */
function caf_patronato_type_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = SettingsService::defaultCafPatronatoTypes();

    global $pdo;
    if ($pdo instanceof PDO) {
        try {
            $root = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__, 3);
            $service = new SettingsService($pdo, $root);
            $config = $service->getCafPatronatoTypes();
            if ($config) {
                $cache = $config;
            }
        } catch (Throwable $exception) {
            error_log('CAF/Patronato type config fallback: ' . $exception->getMessage());
        }
    }

    if (!$cache) {
        $cache = caf_patronato_prepare_service_map(SettingsService::defaultCafPatronatoServices());
    }

    return $cache;
}

/**
 * @return array<string, array{key:string,label:string,prefix:string}>
 */
function caf_patronato_type_map(): array
{
    $map = [];
    foreach (caf_patronato_type_config() as $entry) {
        $key = strtoupper((string) ($entry['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $map[$key] = [
            'key' => $key,
            'label' => (string) ($entry['label'] ?? $key),
            'prefix' => strtoupper((string) ($entry['prefix'] ?? substr($key, 0, 3))),
        ];
    }

    return $map;
}

function caf_patronato_type_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $options = [];
    foreach (caf_patronato_type_config() as $entry) {
        $key = strtoupper((string) ($entry['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $options[$key] = (string) ($entry['label'] ?? $key);
    }

    if (!$options) {
        $defaults = SettingsService::defaultCafPatronatoTypes();
        foreach ($defaults as $entry) {
            $key = strtoupper((string) ($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options[$key] = (string) ($entry['label'] ?? $key);
        }
    }

    return $options;
}

function caf_patronato_type_label(string $key): string
{
    $map = caf_patronato_type_options();
    $upper = strtoupper($key);
    return $map[$upper] ?? $upper;
}

/**
 * @return array<string, array<int, array{name:string,price:float|null}>>
 */
function caf_patronato_service_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = caf_patronato_prepare_service_map(SettingsService::defaultCafPatronatoServices());

    global $pdo;
    if ($pdo instanceof PDO) {
        try {
            $root = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__, 3);
            $service = new SettingsService($pdo, $root);
            $config = $service->getCafPatronatoServices();
            if ($config) {
                $cache = caf_patronato_prepare_service_map($config);
            }
        } catch (Throwable $exception) {
            error_log('CAF/Patronato services config fallback: ' . $exception->getMessage());
        }
    }

    return $cache;
}

/**
 * @param array<string,mixed> $payload
 * @return array<string, array<int, array{name:string,price:float|null}>>
 */
function caf_patronato_prepare_service_map($payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    $normalized = [];
    foreach ($payload as $typeKey => $entries) {
        $key = strtoupper((string) $typeKey);
        if ($key === '') {
            continue;
        }
        if (!is_array($entries)) {
            $entries = [$entries];
        }

        $seen = [];
        $prepared = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $name = (string) ($entry['name'] ?? ($entry['label'] ?? ($entry['value'] ?? '')));
                $price = $entry['price'] ?? null;
            } elseif (is_string($entry)) {
                $name = $entry;
                $price = null;
            } else {
                continue;
            }

            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $hash = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $prepared[] = [
                'name' => mb_substr($name, 0, 120),
                'price' => is_numeric($price) ? round((float) $price, 2) : null,
            ];
        }

        if ($prepared) {
            usort($prepared, static function (array $a, array $b): int {
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
        }

        $normalized[$key] = $prepared;
    }

    return $normalized;
}

function caf_patronato_service_options(?string $typeKey = null): array
{
    $config = caf_patronato_service_config();
    $extractNames = static function ($list): array {
        $names = [];
        if (!is_array($list)) {
            return $names;
        }
        foreach ($list as $entry) {
            if (is_array($entry)) {
                $name = (string) ($entry['name'] ?? '');
            } elseif (is_string($entry)) {
                $name = $entry;
            } else {
                continue;
            }
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    };

    if ($typeKey !== null) {
        $normalizedKey = strtoupper($typeKey);
        return $extractNames($config[$normalizedKey] ?? []);
    }

    $merged = [];
    foreach ($config as $list) {
        foreach ($extractNames($list) as $name) {
            $merged[$name] = $name;
        }
    }

    if (!$merged) {
        foreach (SettingsService::defaultCafPatronatoServices() as $list) {
            foreach ($extractNames($list) as $name) {
                $merged[$name] = $name;
            }
        }
    }

    $values = array_values($merged);
    sort($values, SORT_FLAG_CASE | SORT_STRING);

    return $values;
}

/**
 * @return array<int, array{value:string,label:string,category:string}>
 */
function caf_patronato_status_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = SettingsService::defaultCafPatronatoStatuses();

    global $pdo;
    if ($pdo instanceof PDO) {
        try {
            $root = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__, 3);
            $service = new SettingsService($pdo, $root);
            $config = $service->getCafPatronatoStatuses();
            if ($config) {
                $cache = $config;
            }
        } catch (Throwable $exception) {
            error_log('CAF/Patronato status config fallback: ' . $exception->getMessage());
        }
    }

    return $cache;
}

function caf_patronato_status_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $options = [];
    foreach (caf_patronato_status_config() as $entry) {
        $value = trim((string) ($entry['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($entry['label'] ?? $value));
        $options[$value] = $label !== '' ? $label : $value;
    }

    if (!$options) {
        $defaults = SettingsService::defaultCafPatronatoStatuses();
        $options = array_column($defaults, 'label', 'value');
    }

    return $options;
}

function caf_patronato_status_label(string $value): string
{
    $options = caf_patronato_status_options();
    return $options[$value] ?? $value;
}

function caf_patronato_status_badge_class(string $status): string
{
    $category = caf_patronato_status_category($status);

    return match ($category) {
        'pending' => 'bg-warning text-dark',
        'in_progress' => 'bg-info text-dark',
        'waiting' => 'bg-primary text-dark',
        'completed' => 'bg-success',
        'archived' => 'bg-primary',
        'cancelled' => 'bg-secondary',
        default => 'bg-secondary',
    };
}

function caf_patronato_status_category(string $status): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (caf_patronato_status_config() as $entry) {
            $value = trim((string) ($entry['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $category = strtolower(trim((string) ($entry['category'] ?? '')));
            if (!isset(SettingsService::CAF_PATRONATO_STATUS_CATEGORIES[$category])) {
                $category = 'pending';
            }
            $map[mb_strtolower($value, 'UTF-8')] = $category;
        }
    }

    $normalized = mb_strtolower(trim($status), 'UTF-8');

    return $map[$normalized] ?? 'pending';
}

function caf_patronato_priority_options(): array
{
    return [
        0 => 'Normale',
        1 => 'Alta',
        2 => 'Urgente',
    ];
}

function caf_patronato_priority_label(?int $priority): string
{
    $options = caf_patronato_priority_options();
    return $options[$priority ?? 0] ?? $options[0];
}

function caf_patronato_notification_recipient(): string
{
    $recipient = env('CAF_PATRONATO_NOTIFICATION_EMAIL', 'cafpatronato@newprojectmobile.it');
    $trimmed = is_string($recipient) ? trim($recipient) : '';
    return $trimmed !== '' ? $trimmed : 'cafpatronato@newprojectmobile.it';
}

function caf_patronato_allowed_mime_types(): array
{
    return [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
    ];
}

/**
 * @param array<string,mixed> $formData
 * @param array<string,mixed> $context
 * @return array{path:string,size:int}
 */
function caf_patronato_generate_autocertification_pdf(array $formData, array $context = []): array
{
    if (!class_exists(Mpdf::class)) {
        throw new RuntimeException('Libreria mPDF non disponibile. Esegui composer install dal server.');
    }

    $timezoneId = (string) ($context['timezone'] ?? (function_exists('date_default_timezone_get') ? date_default_timezone_get() : 'UTC'));
    try {
        $timezone = new DateTimeZone($timezoneId ?: 'UTC');
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    $generatedAt = $context['generated_at'] ?? null;
    if (!$generatedAt instanceof DateTimeImmutable) {
        $generatedAt = new DateTimeImmutable('now', $timezone);
    } else {
        $generatedAt = $generatedAt->setTimezone($timezone);
    }

    $operatorLabel = trim((string) ($context['operator_label'] ?? 'Operatore incaricato'));
    if ($operatorLabel === '') {
        $operatorLabel = 'Operatore incaricato';
    }

    $operatorRole = trim((string) ($context['operator_role'] ?? ''));
    $requestIp = trim((string) ($context['request_ip'] ?? ''));

    $companyName = trim((string) ($context['company_name'] ?? (function_exists('env') ? (env('APP_NAME', 'Coresuite Business') ?: 'Coresuite Business') : 'Coresuite Business')));
    if ($companyName === '') {
        $companyName = 'Coresuite Business';
    }
    $companyNameHtml = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');

    $summaryRows = [
        'Tipologia pratica' => $formData['tipo_pratica'] ?? '',
        'Servizio richiesto' => $formData['servizio'] ?? '',
        'Nominativo richiedente' => $formData['nominativo'] ?? '',
        'Codice fiscale' => $formData['codice_fiscale'] ?? '',
        'Telefono' => $formData['telefono'] ?? '',
        'Email' => $formData['email'] ?? '',
        'Cliente collegato' => $context['cliente_label'] ?? '',
        'Notifica al team' => (($formData['send_notification'] ?? '1') === '1') ? 'Richiesta' : 'Non richiesta',
    ];

    $rowsHtml = '';
    foreach ($summaryRows as $label => $value) {
        $rowsHtml .= '<tr><th>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th><td>' . caf_patronato_format_autocertification_value($value) . '</td></tr>';
    }

    $nominativo = caf_patronato_format_autocertification_value($formData['nominativo'] ?? '');
    $codiceFiscale = caf_patronato_format_autocertification_value($formData['codice_fiscale'] ?? '');
    $servizio = caf_patronato_format_autocertification_value($formData['servizio'] ?? '');
    $intro = 'Il/La sottoscritto/a <strong>' . $nominativo . '</strong> (CF: ' . $codiceFiscale . '), richiede l\'attivazione della seguente pratica: <strong>' . $servizio . '</strong> e dichiara che le informazioni fornite sono veritiere.';

    $operatorMeta = trim($operatorLabel . ($operatorRole ? ' (' . $operatorRole . ')' : ''));
    $operatorMetaHtml = htmlspecialchars($operatorMeta, ENT_QUOTES, 'UTF-8');
    $operatorLabelHtml = htmlspecialchars($operatorLabel, ENT_QUOTES, 'UTF-8');

    $consentList = '<ul>
            <li>Autorizzo ' . $companyNameHtml . ' al trattamento dei miei dati personali ai sensi del Regolamento (UE) 2016/679 (GDPR) esclusivamente per la gestione della pratica richiesta.</li>
            <li>Dichiaro di essere stato informato sulle modalit&agrave; di conservazione dei documenti e sulla possibilit&agrave; di revocare i consensi prestati.</li>
            <li>Richiedo di essere contattato ai recapiti indicati per eventuali aggiornamenti o integrazioni.</li>
        </ul>';

    $metaInfo = '<p class="meta">Documento generato il ' . $generatedAt->format('d/m/Y H:i') . ' — Operatore: ' . $operatorMetaHtml;
    if ($requestIp !== '') {
        $metaInfo .= ' — IP richiedente: ' . htmlspecialchars($requestIp, ENT_QUOTES, 'UTF-8');
    }
    $metaInfo .= '</p>';

    $html = <<<HTML
<style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #1f2937; }
    h1 { font-size: 18pt; margin-bottom: 6px; color: #111827; }
    p { line-height: 1.5; }
    table.summary { width: 100%; border-collapse: collapse; margin-top: 14px; }
    table.summary th { text-align: left; width: 30%; background: #f3f4f6; padding: 7px 9px; font-weight: 600; font-size: 10.5pt; }
    table.summary td { padding: 7px 9px; border-left: 1px solid #e5e7eb; font-size: 10.5pt; }
    table.summary tr + tr th,
    table.summary tr + tr td { border-top: 1px solid #e5e7eb; }
    .consents { margin-top: 12px; }
    .signature { margin-top: 40px; display: flex; justify-content: space-between; gap: 30px; }
    .signature div { flex: 1; text-align: center; font-size: 10pt; }
    .signature div .line { border-top: 1px solid #111827; margin-top: 50px; padding-top: 6px; }
    .meta { font-size: 9pt; color: #4b5563; margin-top: 10px; }
</style>
<h1>Autocertificazione richiesta pratica CAF &amp; Patronato</h1>
<p>Struttura responsabile: <strong>{$companyNameHtml}</strong></p>
{$metaInfo}
<p>{$intro}</p>
<table class="summary">
    {$rowsHtml}
</table>
<div class="consents">
    <p>Il richiedente dichiara inoltre:</p>
    {$consentList}
</div>
<div class="signature">
    <div>
        <span>Firma del richiedente</span>
        <div class="line">{$nominativo}</div>
    </div>
    <div>
        <span>Firma operatore incaricato</span>
        <div class="line">{$operatorLabelHtml}</div>
    </div>
</div>
HTML;

    $tempBase = tempnam(sys_get_temp_dir(), 'caf_auto_');
    if ($tempBase === false) {
        throw new RuntimeException('Impossibile creare un file temporaneo per l\'autocertificazione.');
    }

    $pdfPath = $tempBase . '.pdf';
    @unlink($tempBase);

    try {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 20,
            'margin_bottom' => 20,
            'tempDir' => sys_get_temp_dir(),
            'default_font' => 'DejaVu Sans',
        ]);
        $mpdf->SetTitle('Autocertificazione richiesta pratica');
        $mpdf->SetAuthor($companyName);
        $mpdf->WriteHTML($html);
        $mpdf->Output($pdfPath, Destination::FILE);
    } catch (MpdfException $exception) {
        throw new RuntimeException('Generazione del PDF non riuscita: ' . $exception->getMessage(), 0, $exception);
    }

    if (!is_file($pdfPath)) {
        throw new RuntimeException('Il file PDF dell\'autocertificazione non &egrave; stato creato.');
    }

    $size = @filesize($pdfPath);
    if ($size === false) {
        $size = 0;
    }

    return ['path' => $pdfPath, 'size' => (int) $size];
}

/**
 * @param array<string,mixed> $formData
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function caf_patronato_create_autocertification_attachment(array $formData, array $context = []): array
{
    $pdf = caf_patronato_generate_autocertification_pdf($formData, $context);

    $parts = [];
    if (!empty($formData['nominativo'])) {
        $parts[] = caf_patronato_slugify_identifier((string) $formData['nominativo']);
    }
    if (!empty($formData['codice_fiscale'])) {
        $parts[] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper((string) $formData['codice_fiscale']));
    }

    $suffix = $parts ? implode('_', $parts) : date('Ymd_His');
    $rawFilename = 'Autocertificazione_' . $suffix . '.pdf';
    if (function_exists('sanitize_filename')) {
        $rawFilename = sanitize_filename($rawFilename);
    } else {
        $rawFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $rawFilename) ?: ('Autocertificazione_' . date('Ymd_His') . '.pdf');
    }

    return [
        'name' => $rawFilename,
        'tmp_name' => $pdf['path'],
        'size' => $pdf['size'],
        'mime' => 'application/pdf',
        'cleanup' => true,
    ];
}

function caf_patronato_format_autocertification_value($value): string
{
    $string = trim((string) $value);
    if ($string === '') {
        return '—';
    }

    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function caf_patronato_slugify_identifier(string $value): string
{
    $normalized = $value;
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($converted !== false) {
            $normalized = $converted;
        }
    }

    $normalized = strtoupper((string) $normalized);
    $normalized = preg_replace('/[^A-Z0-9]+/', '-', $normalized ?? '');
    $normalized = trim((string) $normalized, '-');

    if ($normalized === '') {
        return 'PRATICA';
    }

    return $normalized;
}

function caf_patronato_normalize_uploads(?array $files): array
{
    if (!$files || !is_array($files)) {
        return [];
    }

    $normalized = [];
    if (is_array($files['name'] ?? null)) {
        foreach ($files['name'] as $index => $name) {
            $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $normalized[] = [
                'name' => (string) $name,
                'type' => (string) ($files['type'][$index] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
                'error' => (int) $error,
                'size' => (int) ($files['size'][$index] ?? 0),
            ];
        }

        return $normalized;
    }

    $error = (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    $normalized[] = [
        'name' => (string) ($files['name'] ?? ''),
        'type' => (string) ($files['type'] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
        'error' => $error,
        'size' => (int) ($files['size'] ?? 0),
    ];

    return $normalized;
}

function caf_patronato_detect_mime(string $filePath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($filePath);
            return $detected ?: 'application/octet-stream';
        }
        return 'application/octet-stream';
    }

    $mime = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
    finfo_close($finfo);

    return $mime;
}

function caf_patronato_fetch_pratiche(PDO $pdo): array
{
    $sql = 'SELECT cp.*, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale AS cliente_ragione_sociale,
                u.username AS created_by_username, ua.username AS updated_by_username,
                (SELECT COUNT(*) FROM caf_patronato_allegati ca WHERE ca.pratica_id = cp.id) AS attachments_count
            FROM caf_patronato_pratiche cp
            LEFT JOIN clienti c ON cp.cliente_id = c.id
            LEFT JOIN users u ON cp.created_by = u.id
            LEFT JOIN users ua ON cp.updated_by = ua.id
            ORDER BY cp.created_at DESC';

    $stmt = $pdo->query($sql);
    if (!$stmt) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function caf_patronato_fetch_pratica(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $sql = 'SELECT cp.*, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale AS cliente_ragione_sociale,
                u.username AS created_by_username, ua.username AS updated_by_username
            FROM caf_patronato_pratiche cp
            LEFT JOIN clienti c ON cp.cliente_id = c.id
            LEFT JOIN users u ON cp.created_by = u.id
            LEFT JOIN users ua ON cp.updated_by = ua.id
            WHERE cp.id = :id
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->execute([':id' => $id]);
    $pratica = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pratica === false) {
        return null;
    }

    $attachmentsStmt = $pdo->prepare('SELECT ca.*, u.username AS created_by_username
        FROM caf_patronato_allegati ca
        LEFT JOIN users u ON ca.created_by = u.id
        WHERE ca.pratica_id = :id
        ORDER BY ca.created_at DESC');
    if ($attachmentsStmt) {
        $attachmentsStmt->execute([':id' => $id]);
        $rows = $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pratica['attachments'] = array_map(static function (array $row): array {
            $row['download_url'] = caf_patronato_build_download_url('document', (int) $row['id']);
            return $row;
        }, $rows);
    } else {
        $pratica['attachments'] = [];
    }

    try {
        $logStmt = $pdo->prepare('SELECT la.id, la.azione, la.dettagli, la.created_at, u.username
            FROM log_attivita la
            LEFT JOIN users u ON la.user_id = u.id
            WHERE la.modulo = :modulo AND la.dettagli LIKE :pattern
            ORDER BY la.created_at DESC
            LIMIT 50');
        if ($logStmt) {
            $logStmt->execute([
                ':modulo' => CAF_PATRONATO_MODULE_LOG,
                ':pattern' => 'Pratica #' . $id . '%',
            ]);
            $pratica['activity_log'] = $logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $pratica['activity_log'] = [];
        }
    } catch (Throwable) {
        $pratica['activity_log'] = [];
    }

    return $pratica;
}

function caf_patronato_format_cliente(array $pratica): string
{
    $ragione = trim((string) ($pratica['cliente_ragione_sociale'] ?? ''));
    $nome = trim((string) ($pratica['cliente_nome'] ?? ''));
    $cognome = trim((string) ($pratica['cliente_cognome'] ?? ''));

    if ($ragione !== '') {
        return $ragione;
    }

    $fullName = trim($nome . ' ' . $cognome);
    if ($fullName !== '') {
        return $fullName;
    }

    $nominativo = trim((string) ($pratica['nominativo'] ?? ''));
    if ($nominativo !== '') {
        return $nominativo;
    }

    $id = (int) ($pratica['cliente_id'] ?? 0);
    return $id > 0 ? 'Cliente #' . $id : 'N/D';
}

function caf_patronato_build_code(int $praticaId, string $tipoPratica, ?string $createdAt): string
{
    if ($praticaId <= 0) {
        return 'CAF-' . date('Y') . '-00000';
    }

    $key = strtoupper($tipoPratica);
    $typeMap = caf_patronato_type_map();
    $rawPrefix = $typeMap[$key]['prefix'] ?? substr($key, 0, 3);
    $rawPrefix = strtoupper((string) $rawPrefix);
    $cleanPrefix = preg_replace('/[^A-Z0-9]/', '', $rawPrefix ?? '') ?? '';
    if ($cleanPrefix === '') {
        $cleanPrefix = preg_replace('/[^A-Z0-9]/', '', substr($key, 0, 3)) ?? '';
    }
    if ($cleanPrefix === '') {
        $cleanPrefix = 'CAF';
    }
    $prefix = strtoupper(substr($cleanPrefix, 0, 6));
    $year = date('Y');
    if ($createdAt) {
        try {
            $date = new DateTimeImmutable($createdAt);
            $year = $date->format('Y');
        } catch (Throwable) {
            $year = date('Y');
        }
    }

    return sprintf('%s-%s-%05d', $prefix, $year, $praticaId);
}

function caf_patronato_assign_code(PDO $pdo, array $pratica): ?string
{
    $id = (int) ($pratica['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $existing = trim((string) ($pratica['pratica_code'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    $tipo = (string) ($pratica['tipo_pratica'] ?? 'CAF');
    $code = caf_patronato_build_code($id, $tipo, (string) ($pratica['created_at'] ?? ''));

    try {
        $stmt = $pdo->prepare('UPDATE caf_patronato_pratiche SET pratica_code = :code WHERE id = :id AND (pratica_code IS NULL OR pratica_code = \'\')');
        $stmt->execute([
            ':code' => $code,
            ':id' => $id,
        ]);
    } catch (Throwable $exception) {
        error_log('CAF/Patronato assign code failed: ' . $exception->getMessage());
        return null;
    }

    return $code;
}

function caf_patronato_log_action(PDO $pdo, string $action, string $details): void
{
    try {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $stmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
            VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $stmt->execute([
            ':user_id' => $userId ?: null,
            ':modulo' => CAF_PATRONATO_MODULE_LOG,
            ':azione' => $action,
            ':dettagli' => $details,
        ]);
    } catch (Throwable $exception) {
        error_log('CAF/Patronato log action failed: ' . $exception->getMessage());
    }
}

function caf_patronato_send_notification(array $pratica, bool $isUpdate = false): bool
{
    $recipient = caf_patronato_notification_recipient();
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = $isUpdate ? 'Aggiornamento pratica CAF/Patronato ' : 'Nuova pratica CAF/Patronato ';
    $subject .= $pratica['pratica_code'] ?? caf_patronato_build_code((int) ($pratica['id'] ?? 0), (string) ($pratica['tipo_pratica'] ?? 'CAF'), (string) ($pratica['created_at'] ?? ''));

    $detailsRows = [
        'Codice pratica' => (string) ($pratica['pratica_code'] ?? ''),
        'Tipologia' => caf_patronato_type_label((string) ($pratica['tipo_pratica'] ?? '')),
        'Servizio' => (string) ($pratica['servizio'] ?? ''),
        'Stato' => caf_patronato_status_label((string) ($pratica['stato'] ?? '')),
        'Cliente/Nominativo' => caf_patronato_format_cliente($pratica),
        'Codice fiscale' => (string) ($pratica['codice_fiscale'] ?? ''),
        'Telefono' => (string) ($pratica['telefono'] ?? ''),
        'Email' => (string) ($pratica['email'] ?? ''),
        'Scadenza' => isset($pratica['scadenza_at']) && $pratica['scadenza_at'] !== null ? format_datetime_locale($pratica['scadenza_at']) : 'N/D',
        'Priorità' => caf_patronato_priority_label(isset($pratica['priorita']) ? (int) $pratica['priorita'] : 0),
    ];

    $rowsHtml = '';
    foreach ($detailsRows as $label => $value) {
        $rowsHtml .= '<tr><th align="left" style="padding:6px 12px;background:#f8f9fc;width:200px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
        $rowsHtml .= '<td style="padding:6px 12px;">' . htmlspecialchars($value !== '' ? $value : 'N/D', ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    $note = trim((string) ($pratica['note_interne'] ?? ''));
    $noteHtml = $note !== '' ? '<p style="margin:12px 0 0;"><strong>Note interne:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' : '';

    $body = '<p style="margin:0 0 12px;">' . ($isUpdate ? 'La pratica è stata aggiornata.' : 'È stata registrata una nuova pratica.') . '</p>';
    $body .= '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">' . $rowsHtml . '</table>';
    $body .= $noteHtml;

    if (!function_exists('render_mail_template') || !function_exists('send_system_mail')) {
        return false;
    }

    $htmlBody = render_mail_template('Pratica CAF & Patronato', $body);
    return send_system_mail($recipient, $subject, $htmlBody);
}

function caf_patronato_sync_legacy_pratica(PDO $pdo, array $payload, int $cafPraticaId, ?string $praticaCode, array $attachments, int $userId): ?int
{
    if ($cafPraticaId <= 0) {
        return null;
    }

    $adminId = $userId > 0 ? $userId : null;
    if ($adminId === null) {
        $fallback = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
        if ($fallback !== false) {
            $adminId = (int) $fallback->fetchColumn() ?: null;
        }
        if ($adminId === null) {
            return null;
        }
    }

    $categoriaRaw = trim((string) ($payload['tipo_pratica'] ?? 'CAF'));
    $categoria = strcasecmp($categoriaRaw, 'Patronato') === 0 ? 'Patronato' : 'CAF';

    $typeId = caf_patronato_ensure_legacy_type($pdo, $categoria);
    $statusInfo = caf_patronato_map_status_to_legacy((string) ($payload['stato'] ?? ''));
    $statusCode = caf_patronato_ensure_legacy_status($pdo, $statusInfo);

    $nominativo = trim((string) ($payload['nominativo'] ?? ''));
    $servizio = trim((string) ($payload['servizio'] ?? ''));
    $titleParts = array_filter([$nominativo, $servizio !== '' ? $servizio : ($categoria === 'Patronato' ? 'Patronato' : 'CAF')]);
    $titolo = trim(implode(' - ', $titleParts));
    if ($titolo === '') {
        $titolo = $categoria === 'Patronato' ? 'Pratica Patronato' : 'Pratica CAF';
    }
    if (mb_strlen($titolo) > 200) {
        $titolo = mb_substr($titolo, 0, 200);
    }

    $descriptionLines = [];
    if ($servizio !== '') {
        $descriptionLines[] = 'Servizio richiesto: ' . $servizio;
    }
    $telefono = trim((string) ($payload['telefono'] ?? ''));
    if ($telefono !== '') {
        $descriptionLines[] = 'Telefono: ' . $telefono;
    }
    $email = trim((string) ($payload['email'] ?? ''));
    if ($email !== '') {
        $descriptionLines[] = 'Email: ' . $email;
    }
    $cf = trim((string) ($payload['codice_fiscale'] ?? ''));
    if ($cf !== '') {
        $descriptionLines[] = 'Codice fiscale: ' . $cf;
    }
    $descriptionLines[] = 'Pratica originata dal modulo CAF & Patronato.';
    $descrizione = implode("\n", $descriptionLines);

    $note = trim((string) ($payload['note_interne'] ?? ''));

    $clienteId = isset($payload['cliente_id']) ? (int) $payload['cliente_id'] : 0;
    if ($clienteId <= 0) {
        $clienteId = null;
    }

    $metadata = [
        'caf_patronato_pratica_id' => $cafPraticaId,
        'caf_patronato_code' => $praticaCode,
        'nominativo' => $nominativo,
        'servizio' => $servizio,
        'telefono' => $telefono,
        'email' => $email,
        'codice_fiscale' => $cf,
    ];
    $metadata = array_filter($metadata, static fn($value) => $value !== null && $value !== '');

    try {
        $metadataJson = $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
        $attachmentsJson = $attachments ? json_encode($attachments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode([], JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new RuntimeException('Serializzazione metadati pratiche legacy fallita: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
    }

    $scadenza = null;
    if (!empty($payload['scadenza'])) {
        $candidate = (string) $payload['scadenza'];
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $candidate) ?: DateTimeImmutable::createFromFormat('d/m/Y', $candidate);
        if ($date) {
            $scadenza = $date->format('Y-m-d');
        }
    }

    $legacyStmt = $pdo->prepare('INSERT INTO pratiche (
            titolo,
            descrizione,
            tipo_pratica,
            categoria,
            stato,
            data_creazione,
            data_aggiornamento,
            id_admin,
            id_utente_caf_patronato,
            allegati,
            note,
            metadati,
            scadenza,
            cliente_id
        ) VALUES (
            :titolo,
            :descrizione,
            :tipo_pratica,
            :categoria,
            :stato,
            NOW(),
            NOW(),
            :id_admin,
            NULL,
            :allegati,
            :note,
            :metadati,
            :scadenza,
            :cliente_id
        )');
    if ($legacyStmt === false) {
        throw new RuntimeException('Preparazione inserimento pratiche legacy fallita.');
    }

    $legacyStmt->execute([
        ':titolo' => $titolo,
        ':descrizione' => $descrizione,
        ':tipo_pratica' => $typeId,
        ':categoria' => $categoria,
        ':stato' => $statusCode,
        ':id_admin' => $adminId,
        ':allegati' => $attachmentsJson,
        ':note' => $note !== '' ? $note : null,
        ':metadati' => $metadataJson,
        ':scadenza' => $scadenza,
        ':cliente_id' => $clienteId,
    ]);

    $legacyId = (int) $pdo->lastInsertId();
    if ($legacyId <= 0) {
        return null;
    }

    if ($attachments) {
        $documentStmt = $pdo->prepare('INSERT INTO pratiche_documenti (
                pratica_id,
                file_name,
                file_path,
                mime_type,
                file_size,
                uploaded_by,
                uploaded_operatore_id,
                created_at
            ) VALUES (
                :pratica_id,
                :file_name,
                :file_path,
                :mime_type,
                :file_size,
                :uploaded_by,
                NULL,
                NOW()
            )');
        if ($documentStmt === false) {
            throw new RuntimeException('Preparazione inserimento documenti legacy fallita.');
        }

        foreach ($attachments as $attachment) {
            $documentStmt->execute([
                ':pratica_id' => $legacyId,
                ':file_name' => (string) ($attachment['file_name'] ?? ''),
                ':file_path' => (string) ($attachment['file_path'] ?? ''),
                ':mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
                ':file_size' => (int) ($attachment['file_size'] ?? 0),
                ':uploaded_by' => $adminId,
            ]);
        }
    }

    $eventStmt = $pdo->prepare('INSERT INTO pratiche_eventi (
            pratica_id,
            evento,
            messaggio,
            payload,
            creato_da,
            creato_operatore_id,
            created_at
        ) VALUES (
            :pratica_id,
            :evento,
            :messaggio,
            :payload,
            :creato_da,
            NULL,
            NOW()
        )');
    if ($eventStmt !== false) {
        try {
            $payloadData = json_encode([
                'caf_pratica_id' => $cafPraticaId,
                'caf_pratica_code' => $praticaCode,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $eventStmt->execute([
                ':pratica_id' => $legacyId,
                ':evento' => 'creazione',
                ':messaggio' => 'Pratica registrata dal modulo CAF & Patronato.',
                ':payload' => $payloadData,
                ':creato_da' => $adminId,
            ]);
        } catch (Throwable $exception) {
            error_log('CAF/Patronato legacy event log failed: ' . $exception->getMessage());
        }
    }

    return $legacyId;
}

function caf_patronato_ensure_legacy_type(PDO $pdo, string $categoria): int
{
    $categoria = strcasecmp($categoria, 'Patronato') === 0 ? 'Patronato' : 'CAF';
    $name = $categoria === 'Patronato' ? 'Patronato - Legacy' : 'CAF - Legacy';

    $select = $pdo->prepare('SELECT id FROM tipologie_pratiche WHERE nome = :nome AND categoria = :categoria LIMIT 1');
    if ($select === false) {
        throw new RuntimeException('Preparazione ricerca tipologia legacy fallita.');
    }
    $select->execute([
        ':nome' => $name,
        ':categoria' => $categoria,
    ]);
    $existing = $select->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }

    $insert = $pdo->prepare('INSERT INTO tipologie_pratiche (nome, categoria, campi_personalizzati, created_at, updated_at)
        VALUES (:nome, :categoria, NULL, NOW(), NOW())');
    if ($insert === false) {
        throw new RuntimeException('Preparazione creazione tipologia legacy fallita.');
    }

    try {
        $insert->execute([
            ':nome' => $name,
            ':categoria' => $categoria,
        ]);
    } catch (Throwable $exception) {
        $select->execute([
            ':nome' => $name,
            ':categoria' => $categoria,
        ]);
        $existing = $select->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }
        throw new RuntimeException('Impossibile creare tipologia legacy: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
    }

    return (int) $pdo->lastInsertId();
}

function caf_patronato_map_status_to_legacy(string $status): array
{
    $normalized = strtoupper(trim($status));
    $map = [
        'DA LAVORARE' => ['code' => 'in_lavorazione', 'label' => 'In lavorazione', 'color' => 'primary', 'ordering' => 20],
        'IN LAVORAZIONE' => ['code' => 'in_lavorazione', 'label' => 'In lavorazione', 'color' => 'primary', 'ordering' => 20],
        'IN ATTESA DOCUMENTI' => ['code' => 'sospesa', 'label' => 'In attesa', 'color' => 'warning', 'ordering' => 30],
        'COMPLETATA' => ['code' => 'completata', 'label' => 'Completata', 'color' => 'success', 'ordering' => 40],
        'CHIUSA' => ['code' => 'archiviata', 'label' => 'Archiviata', 'color' => 'secondary', 'ordering' => 90],
        'ANNULLATA' => ['code' => 'annullata', 'label' => 'Annullata', 'color' => 'danger', 'ordering' => 95],
    ];

    return $map[$normalized] ?? ['code' => 'in_lavorazione', 'label' => 'In lavorazione', 'color' => 'primary', 'ordering' => 20];
}

function caf_patronato_ensure_legacy_status(PDO $pdo, array $status): string
{
    $code = (string) ($status['code'] ?? 'in_lavorazione');
    $label = (string) ($status['label'] ?? 'In lavorazione');
    $color = (string) ($status['color'] ?? 'primary');
    $ordering = (int) ($status['ordering'] ?? 20);

    $select = $pdo->prepare('SELECT codice FROM pratiche_stati WHERE codice = :codice LIMIT 1');
    if ($select === false) {
        throw new RuntimeException('Preparazione ricerca stato legacy fallita.');
    }
    $select->execute([':codice' => $code]);
    if ($select->fetchColumn()) {
        return $code;
    }

    $insert = $pdo->prepare('INSERT INTO pratiche_stati (codice, nome, colore, ordering, created_at, updated_at)
        VALUES (:codice, :nome, :colore, :ordering, NOW(), NOW())');
    if ($insert === false) {
        throw new RuntimeException('Preparazione creazione stato legacy fallita.');
    }

    try {
        $insert->execute([
            ':codice' => $code,
            ':nome' => $label,
            ':colore' => $color,
            ':ordering' => $ordering,
        ]);
    } catch (Throwable $exception) {
        $select->execute([':codice' => $code]);
        if ($select->fetchColumn()) {
            return $code;
        }
        throw new RuntimeException('Impossibile creare stato legacy: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
    }

    return $code;
}
