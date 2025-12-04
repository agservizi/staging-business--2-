<?php

declare(strict_types=1);

namespace Modules\Onlyoffice;

use RuntimeException;
use ZipArchive;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const DOCUMENT_SERVER_URL = 'https://docspace-50jyxi.onlyoffice.com';
const DOCUMENT_SERVER_SECRET = 'k6ZkDq3vF2x9tJrLp0YcA7wHqS1mV8eR';
const DOCUMENT_SERVER_USE_JWT = true;

const ENCRYPTED_STORAGE_PATH = __DIR__ . '/../../storage/onlyoffice';
const ENCRYPTED_FILES_DIR = ENCRYPTED_STORAGE_PATH . '/files';
const FILE_INDEX_PATH = ENCRYPTED_STORAGE_PATH . '/files.json';
const MAX_UPLOAD_SIZE = 20 * 1024 * 1024; // 20 MB
const ALLOWED_EXTENSIONS = ['docx', 'xlsx', 'pptx'];

const DEFAULT_ENCRYPTION_KEY = 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI='; // "12345678901234567890123456789012"

function ensureStoragePaths(): void
{
    if (!is_dir(ENCRYPTED_STORAGE_PATH) && !mkdir(ENCRYPTED_STORAGE_PATH, 0775, true) && !is_dir(ENCRYPTED_STORAGE_PATH)) {
        throw new RuntimeException('Cannot create base storage directory');
    }

    if (!is_dir(ENCRYPTED_FILES_DIR) && !mkdir(ENCRYPTED_FILES_DIR, 0775, true) && !is_dir(ENCRYPTED_FILES_DIR)) {
        throw new RuntimeException('Cannot create encrypted storage directory');
    }

    if (!file_exists(FILE_INDEX_PATH)) {
        file_put_contents(FILE_INDEX_PATH, json_encode(['files' => []], JSON_PRETTY_PRINT));
    }
}

function loadFileIndex(): array
{
    ensureStoragePaths();
    $json = file_get_contents(FILE_INDEX_PATH);
    $data = json_decode($json ?: '{"files":[]}', true) ?: ['files' => []];
    return $data['files'];
}

function persistFileIndex(array $files): void
{
    ensureStoragePaths();
    $payload = ['files' => $files];
    file_put_contents(FILE_INDEX_PATH, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function listDocuments(): array
{
    return array_values(loadFileIndex());
}

function getFileInfo(string $id): array
{
    $index = loadFileIndex();
    if (!isset($index[$id])) {
        throw new RuntimeException('File not found');
    }

    $file = $index[$id];
    $file['storagePath'] = ENCRYPTED_FILES_DIR . '/' . $file['storageName'];

    if (!file_exists($file['storagePath'])) {
        throw new RuntimeException('Missing binary payload for file');
    }

    return $file;
}

function getFileUrl(string $id): string
{
    $base = rtrim(getBaseUrl(), '/');
    return $base . '/modules/onlyoffice/filemanager.php?action=download&id=' . rawurlencode($id);
}

function getCallbackUrl(string $id): string
{
    $base = rtrim(getBaseUrl(), '/');
    return $base . '/modules/onlyoffice/callback.php?id=' . rawurlencode($id);
}

function getBaseUrl(): string
{
    if (!empty($_SERVER['ONLYOFFICE_BASE_URL'])) {
        return $_SERVER['ONLYOFFICE_BASE_URL'];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

    $segments = explode('/modules/', $scriptDir, 2);
    $basePath = $segments[0] ?? '';

    return rtrim($scheme . '://' . $host . $basePath, '/');
}

function requireUser(): array
{
    if (!isset($_SESSION['user'])) {
        // Fallback demo user; replace with your auth system
        $_SESSION['user'] = [
            'id' => 1,
            'name' => 'Demo Admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ];
    }

    return $_SESSION['user'];
}

function userCanEdit(array $user): bool
{
  return in_array($user['role'] ?? 'user', ['admin', 'operator', 'manager', 'support'], true);
}

function resolvePermissions(array $user): array
{
    $role = $user['role'] ?? 'user';

    return [
      'edit' => in_array($role, ['admin', 'operator', 'manager', 'support'], true),
      'comment' => in_array($role, ['admin', 'operator', 'manager', 'support'], true),
        'download' => true,
        'print' => true,
      'fillForms' => in_array($role, ['admin', 'operator', 'manager', 'support'], true),
      'review' => in_array($role, ['admin', 'manager'], true),
    ];
}

function documentTypeByExtension(string $extension): string
{
    return match (strtolower($extension)) {
        'xlsx' => 'cell',
        'pptx' => 'slide',
        default => 'word',
    };
}

function buildDocumentConfig(array $file, array $user): array
{
    $permissions = resolvePermissions($user);
    $config = [
        'document' => [
            'title' => $file['name'],
            'url' => getFileUrl($file['id']),
            'fileType' => $file['extension'],
            'key' => hash('sha256', $file['id'] . '::' . ($file['updatedAt'] ?? '')),
            'permissions' => $permissions,
            'info' => [
                'owner' => $file['ownerName'] ?? 'Unknown',
                'uploaded' => date(DATE_ATOM, $file['createdAt']),
            ],
        ],
        'documentType' => documentTypeByExtension($file['extension']),
        'editorConfig' => [
            'callbackUrl' => getCallbackUrl($file['id']),
            'mode' => $permissions['edit'] ? 'edit' : 'view',
            'lang' => 'it',
            'user' => [
                'id' => (string) $user['id'],
                'name' => $user['name'] ?? 'Utente',
                'group' => $user['role'] ?? 'user',
            ],
            'customization' => [
                'forcesave' => true,
                'compactToolbar' => false,
            ],
        ],
        'height' => '100%',
        'width' => '100%',
    ];

    if (DOCUMENT_SERVER_USE_JWT && DOCUMENT_SERVER_SECRET !== '') {
        $config['token'] = generateJwt($config);
    }

    return $config;
}

function saveCallback(string $id, array $payload): array
{
    $status = (int) ($payload['status'] ?? 0);

    if (!in_array($status, [2, 3, 6, 7], true)) {
        return ['error' => 0];
    }

    $file = getFileInfo($id);
    $url = $payload['url'] ?? '';

    if ($url === '') {
        throw new RuntimeException('Missing document URL inside callback payload');
    }

    $binary = downloadRemoteFile($url);
    writeEncryptedBinary($file['storagePath'], $binary);

    $files = loadFileIndex();
    $files[$id]['updatedAt'] = time();
    $files[$id]['size'] = strlen($binary);
    $files[$id]['checksum'] = sha1($binary);
    persistFileIndex($files);

    return ['error' => 0];
}

function downloadRemoteFile(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $binary = @file_get_contents($url, false, $context);

    if ($binary === false) {
        throw new RuntimeException('Unable to download updated document');
    }

    return $binary;
}

function writeEncryptedBinary(string $path, string $plain): void
{
    $payload = encryptPayload($plain);

    if (file_put_contents($path, $payload) === false) {
        throw new RuntimeException('Unable to write encrypted file');
    }
}

function readDecryptedBinary(string $path): string
{
    $cipher = file_get_contents($path);

    if ($cipher === false) {
        throw new RuntimeException('Unable to read encrypted file');
    }

    return decryptPayload($cipher);
}

function encryptPayload(string $plain): string
{
    $key = getEncryptionKey();
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed');
    }

    return $iv . $ciphertext;
}

function decryptPayload(string $payload): string
{
    $key = getEncryptionKey();
    $iv = substr($payload, 0, 16);
    $ciphertext = substr($payload, 16);

    $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($plain === false) {
        throw new RuntimeException('Decryption failed');
    }

    return $plain;
}

function getEncryptionKey(): string
{
    $value = getenv('ONLYOFFICE_ENCRYPTION_KEY') ?: DEFAULT_ENCRYPTION_KEY;

    if (str_starts_with($value, 'base64:')) {
        $decoded = base64_decode(substr($value, 7));
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 encryption key');
        }
      if (strlen($decoded) !== 32) {
        throw new RuntimeException('The encryption key must be exactly 32 bytes for AES-256-CBC');
      }
      return $decoded;
    }

    return hash('sha256', $value, true);
}

function generateJwt(array $payload): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
        base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
        base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), DOCUMENT_SERVER_SECRET, true);
    $segments[] = base64UrlEncode($signature);

    return implode('.', $segments);
}

function validateIncomingJwt(?string $token): array
{
    if (!$token) {
        return [];
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        throw new RuntimeException('Malformed JWT token');
    }

    [$header64, $payload64, $signature64] = $parts;
    $signature = base64UrlDecode($signature64);
    $expected = hash_hmac('sha256', $header64 . '.' . $payload64, DOCUMENT_SERVER_SECRET, true);

    if (!hash_equals($expected, $signature)) {
        throw new RuntimeException('Invalid JWT signature');
    }

    $payloadJson = base64UrlDecode($payload64);
    $payload = json_decode($payloadJson, true);

    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JWT payload');
    }

    return $payload;
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function createDocumentFromTemplate(string $extension, string $title, array $user): array
{
    if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
        throw new RuntimeException('Unsupported file type requested');
    }

    $binary = match ($extension) {
        'xlsx' => buildXlsxTemplate($title),
        'pptx' => buildPptxTemplate($title),
        default => buildDocxTemplate($title),
    };

  return persistBinaryDocument($extension, ensureFileTitle($title, $extension), $binary, $user);
}

function ensureFileTitle(string $title, string $extension): string
{
  $clean = trim(basename($title)) ?: 'Nuovo documento';
    return str_ends_with(strtolower($clean), '.' . $extension) ? $clean : $clean . '.' . $extension;
}

function persistBinaryDocument(string $extension, string $displayName, string $binary, array $user): array
{
  if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
    throw new RuntimeException('Unsupported file type');
  }

  ensureStoragePaths();

  $id = generateId();
  $storageName = $id . '.' . $extension;
  $storagePath = ENCRYPTED_FILES_DIR . '/' . $storageName;

  writeEncryptedBinary($storagePath, $binary);

  $files = loadFileIndex();
  $files[$id] = [
    'id' => $id,
    'name' => ensureFileTitle($displayName, $extension),
    'extension' => $extension,
    'storageName' => $storageName,
    'ownerId' => $user['id'] ?? 0,
    'ownerName' => $user['name'] ?? 'System',
    'createdAt' => time(),
    'updatedAt' => time(),
    'size' => strlen($binary),
    'checksum' => sha1($binary),
  ];

  persistFileIndex($files);

  return $files[$id];
}

function generateId(): string
{
    return bin2hex(random_bytes(8));
}

function buildDocxTemplate(string $title): string
{
  ensureZipExtensionLoaded();
  $tmp = tempnam(sys_get_temp_dir(), 'docx');
  $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to initialize DOCX template');
    }

    $zip->addFromString('[Content_Types].xml', docxContentTypes());
    $zip->addFromString('_rels/.rels', docxRels());
    $zip->addFromString('docProps/app.xml', docxAppProps());
    $zip->addFromString('docProps/core.xml', docxCoreProps($title));
    $zip->addFromString('word/document.xml', docxDocumentXml());
    $zip->addFromString('word/styles.xml', docxStylesXml());
    $zip->addFromString('word/_rels/document.xml.rels', docxDocumentRels());

    $zip->close();
    $binary = file_get_contents($tmp) ?: '';
    unlink($tmp);

    return $binary;
}

function docxContentTypes(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>
XML;
}

function docxRels(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
}

function docxAppProps(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Coresuite</Application>
  <DocSecurity>0</DocSecurity>
  <ScaleCrop>false</ScaleCrop>
  <HeadingPairs>
    <vt:vector size="2" baseType="variant">
      <vt:variant>
        <vt:lpstr>Title</vt:lpstr>
      </vt:variant>
      <vt:variant>
        <vt:i4>1</vt:i4>
      </vt:variant>
    </vt:vector>
  </HeadingPairs>
  <TitlesOfParts>
    <vt:vector size="1" baseType="lpstr">
      <vt:lpstr>Documento</vt:lpstr>
    </vt:vector>
  </TitlesOfParts>
</Properties>
XML;
}

function docxCoreProps(string $title): string
{
    $safeTitle = htmlspecialchars($title, ENT_XML1);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>Coresuite</dc:creator>
  <cp:lastModifiedBy>Coresuite</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">$now</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">$now</dcterms:modified>
  <dc:title>$safeTitle</dc:title>
</cp:coreProperties>
XML;
}

function docxDocumentXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p>
      <w:r>
        <w:t xml:space="preserve">Modello ONLYOFFICE pronto all'uso.</w:t>
      </w:r>
    </w:p>
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/>
    </w:sectPr>
  </w:body>
</w:document>
XML;
}

function docxStylesXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:rsid w:val="00000000"/>
    <w:pPr/>
    <w:rPr/>
  </w:style>
</w:styles>
XML;
}

function docxDocumentRels(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
}

function buildXlsxTemplate(string $title): string
{
  ensureZipExtensionLoaded();
  $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
  $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to initialize XLSX template');
    }

    $zip->addFromString('[Content_Types].xml', xlsxContentTypes());
    $zip->addFromString('_rels/.rels', xlsxRels());
    $zip->addFromString('docProps/app.xml', xlsxAppProps());
    $zip->addFromString('docProps/core.xml', xlsxCoreProps($title));
    $zip->addFromString('xl/workbook.xml', xlsxWorkbook());
    $zip->addFromString('xl/_rels/workbook.xml.rels', xlsxWorkbookRels());
    $zip->addFromString('xl/worksheets/sheet1.xml', xlsxSheet());
    $zip->addFromString('xl/styles.xml', xlsxStyles());
    $zip->addFromString('xl/sharedStrings.xml', xlsxSharedStrings());

    $zip->close();
    $binary = file_get_contents($tmp) ?: '';
    unlink($tmp);

    return $binary;
}

function xlsxContentTypes(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
}

function xlsxRels(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
}

function xlsxAppProps(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Coresuite</Application>
  <DocSecurity>0</DocSecurity>
  <ScaleCrop>false</ScaleCrop>
  <HeadingPairs>
    <vt:vector size="2" baseType="variant">
      <vt:variant>
        <vt:lpstr>Worksheets</vt:lpstr>
      </vt:variant>
      <vt:variant>
        <vt:i4>1</vt:i4>
      </vt:variant>
    </vt:vector>
  </HeadingPairs>
  <TitlesOfParts>
    <vt:vector size="1" baseType="lpstr">
      <vt:lpstr>Sheet1</vt:lpstr>
    </vt:vector>
  </TitlesOfParts>
</Properties>
XML;
}

function xlsxCoreProps(string $title): string
{
    $safeTitle = htmlspecialchars($title, ENT_XML1);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>Coresuite</dc:creator>
  <cp:lastModifiedBy>Coresuite</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">$now</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">$now</dcterms:modified>
  <dc:title>$safeTitle</dc:title>
</cp:coreProperties>
XML;
}

function xlsxWorkbook(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Foglio1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
}

function xlsxWorkbookRels(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
}

function xlsxSheet(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheetData>
    <row r="1">
      <c r="A1" t="s">
        <v>0</v>
      </c>
    </row>
  </sheetData>
</worksheet>
XML;
}

function xlsxSharedStrings(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">
  <si>
    <t>Template XLSX pronto all'uso</t>
  </si>
</sst>
XML;
}

function xlsxStyles(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1">
    <font>
      <sz val="11"/>
      <color theme="1"/>
      <name val="Calibri"/>
      <family val="2"/>
      <scheme val="minor"/>
    </font>
  </fonts>
  <fills count="1">
    <fill>
      <patternFill patternType="none"/>
    </fill>
  </fills>
  <borders count="1">
    <border>
      <left/><right/><top/><bottom/><diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>
XML;
}

function buildPptxTemplate(string $title): string
{
  ensureZipExtensionLoaded();
  $tmp = tempnam(sys_get_temp_dir(), 'pptx');
  $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to initialize PPTX template');
    }

    $zip->addFromString('[Content_Types].xml', pptxContentTypes());
    $zip->addFromString('_rels/.rels', pptxRels());
    $zip->addFromString('docProps/app.xml', pptxAppProps());
    $zip->addFromString('docProps/core.xml', pptxCoreProps($title));
    $zip->addFromString('ppt/presentation.xml', pptxPresentation());
    $zip->addFromString('ppt/_rels/presentation.xml.rels', pptxPresentationRels());
    $zip->addFromString('ppt/slides/slide1.xml', pptxSlide());

    $zip->close();
    $binary = file_get_contents($tmp) ?: '';
    unlink($tmp);

    return $binary;
}

function pptxContentTypes(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>
  <Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
}

function pptxRels(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
}

function pptxAppProps(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Coresuite</Application>
  <PresentationFormat>On-screen</PresentationFormat>
  <Slides>1</Slides>
</Properties>
XML;
}

function pptxCoreProps(string $title): string
{
    $safeTitle = htmlspecialchars($title, ENT_XML1);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>Coresuite</dc:creator>
  <cp:lastModifiedBy>Coresuite</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">$now</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">$now</dcterms:modified>
  <dc:title>$safeTitle</dc:title>
</cp:coreProperties>
XML;
}

function pptxPresentation(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
  <p:sldIdLst>
    <p:sldId id="256" r:id="rId1"/>
  </p:sldIdLst>
  <p:sldSz cx="9144000" cy="6858000" type="screen4x3"/>
  <p:notesSz cx="6858000" cy="9144000"/>
</p:presentation>
XML;
}

function pptxPresentationRels(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
</Relationships>
XML;
}

function pptxSlide(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
  <p:cSld>
    <p:spTree>
      <p:nvGrpSpPr>
        <p:cNvPr id="1" name=""/>
        <p:cNvGrpSpPr/>
        <p:nvPr/>
      </p:nvGrpSpPr>
      <p:grpSpPr/>
      <p:sp>
        <p:nvSpPr>
          <p:cNvPr id="2" name="Titolo"/>
          <p:cNvSpPr/>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr/>
        <p:txBody>
          <a:bodyPr/>
          <a:lstStyle/>
          <a:p>
            <a:r>
              <a:t>Template PPTX pronto</a:t>
            </a:r>
            <a:endParaRPr lang="it-IT" dirty="0" smtClean="0"/>
          </a:p>
        </p:txBody>
      </p:sp>
    </p:spTree>
  </p:cSld>
</p:sld>
XML;
}

function ensureZipExtensionLoaded(): void
{
  if (!extension_loaded('zip')) {
    throw new RuntimeException('PHP Zip extension is required to generate templates');
  }
}

function mimeType(string $extension): string
{
    return match (strtolower($extension)) {
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        default => 'application/octet-stream',
    };
}
