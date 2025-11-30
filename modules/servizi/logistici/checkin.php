<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    define('CORESUITE_PICKUP_BOOTSTRAP', true);
}

require_once __DIR__ . '/functions.php';
ensure_pickup_tables();

$tracking = clean_input($_POST['tracking'] ?? '', 120);
$codeRaw = trim((string) ($_POST['code'] ?? ''));
$codeLower = strtolower($codeRaw);

if ($tracking === '' && $codeRaw === '') {
    add_flash('danger', 'Inserisci il tracking o il codice per procedere al ritiro.');
    header('Location: index.php');
    exit;
}

$package = null;
$packageId = null;

try {
    if ($tracking !== '') {
        $package = api_get_package($tracking);
        $packageId = (int) ($package['id'] ?? 0);
    }
} catch (Throwable $exception) {
    $package = null;
    $packageId = null;
}

if (!$packageId) {
    $looksLikeQr = $codeLower !== '' && (
        str_contains($codeLower, 'http') ||
        str_contains($codeLower, 'modules/servizi/logistici') ||
        str_contains($codeLower, 'qr_')
    );

    $extractedId = $looksLikeQr ? pickup_extract_package_id_from_code($codeRaw) : null;
    if ($extractedId) {
        $packageId = $extractedId;
        $package = get_package_details($packageId);
    }
}

if (!$packageId || !$package) {
    add_flash('danger', 'Pacco non trovato per i dati forniti.');
    header('Location: index.php');
    exit;
}

try {
    $normalizedCode = preg_replace('/\s+/', '', $codeRaw) ?? '';
    $normalizedCode = trim($normalizedCode);
    if ($normalizedCode !== '' && preg_match('/^[0-9]{4,10}$/', $normalizedCode)) {
        confirm_pickup_with_otp($packageId, $normalizedCode);
        add_flash('success', 'Ritiro confermato tramite codice OTP.');
    } else {
        $looksLikeQr = $codeLower !== '' && (
            str_contains($codeLower, 'http')
            || str_contains($codeLower, 'modules/servizi/logistici')
            || str_contains($codeLower, 'qr_')
        );

        if (!$looksLikeQr) {
            throw new InvalidArgumentException('Codice inserito non valido. Usa il QR o il codice OTP ricevuto.');
        }

        $qrPackageId = pickup_extract_package_id_from_code($codeRaw);
        if (!$qrPackageId || $qrPackageId !== $packageId) {
            throw new InvalidArgumentException('Codice QR non riconosciuto.');
        }
        confirm_pickup_with_qr($packageId);
        add_flash('success', 'Ritiro confermato tramite QR code.');
    }

    header('Location: view.php?id=' . $packageId);
    exit;
} catch (Throwable $exception) {
    add_flash('danger', $exception->getMessage());
    header('Location: index.php');
    exit;
}
