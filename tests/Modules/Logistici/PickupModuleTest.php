<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @method void assertNotNull(mixed $actual, string $message = '')
 * @method void assertSame(mixed $expected, mixed $actual, string $message = '')
 * @method void assertNotEmpty(mixed $actual, string $message = '')
 * @method void assertIsArray(mixed $actual, string $message = '')
 * @method void assertArrayHasKey(mixed $key, iterable $array, string $message = '')
 * @method void assertTrue(bool $condition, string $message = '')
 * @method void assertNotFalse(mixed $actual, string $message = '')
 * @method void assertFileExists(string $filename, string $message = '')
 */
final class PickupModuleTest extends TestCase
{
    private const QR_PLACEHOLDER_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    public static function setUpBeforeClass(): void
    {
        if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
            define('CORESUITE_PICKUP_BOOTSTRAP', true);
        }

        require_once __DIR__ . '/../../../modules/servizi/logistici/functions.php';
    }

    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['DB_DATABASE'] = ':memory:';
        putenv('APP_URL=http://localhost');
        putenv('PICKUP_QR_PLACEHOLDER_BASE64=' . self::QR_PLACEHOLDER_BASE64);
        putenv('WHATSAPP_API_URL=');
        putenv('WHATSAPP_API_TOKEN=');

        $_SESSION = [];

        pickup_db(true);
        ensure_pickup_tables();
    }

    public function testAddPackagePersistsData(): void
    {
        $packageId = add_package([
            'customer_name' => 'Mario Rossi',
            'customer_phone' => '+390811234567',
            'customer_email' => 'mario.rossi@example.com',
            'tracking' => 'TRK-001',
            'status' => 'in_giacenza',
            'courier_id' => $this->getDefaultCourierId(),
            'pickup_location_id' => $this->getDefaultLocationId(),
            'notes' => 'Test package',
        ]);

    $package = get_package_details($packageId);
    $this->assertNotNull($package);
    $this->assertSame('in_giacenza', $package['status']);
    $this->assertSame('TRK-001', $package['tracking']);

    $history = get_package_history($packageId);
    $this->assertNotEmpty($history, 'Storico pacco assente dopo la creazione.');
    }

    public function testOtpFlowMarksPackageAsPicked(): void
    {
        $packageId = add_package([
            'customer_name' => 'Laura Bianchi',
            'customer_phone' => '+390821234567',
            'customer_email' => 'laura.bianchi@example.com',
            'tracking' => 'TRK-OTP',
            'status' => 'in_giacenza',
            'pickup_location_id' => $this->getDefaultLocationId(),
        ]);

        $otp = generate_pickup_otp($packageId, [
            'length' => 6,
            'valid_minutes' => 10,
        ]);

    $this->assertIsArray($otp);
    $this->assertArrayHasKey('code', $otp);

    $result = confirm_pickup_with_otp($packageId, $otp['code']);
    $this->assertSame('ritirato', $result['status']['status']);

    $package = get_package_details($packageId);
    $this->assertSame('ritirato', $package['status']);
    }

    public function testLinkCustomerReportToPickup(): void
    {
        $pdo = pickup_db();

        $pdo->prepare('INSERT INTO pickup_customers (email, phone, name, status, created_at, updated_at) VALUES (:email, :phone, :name, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
            ->execute([
                ':email' => 'cliente@example.com',
                ':phone' => '+390831234567',
                ':name' => 'Cliente Portale',
                ':status' => 'active',
            ]);
        $customerId = (int) $pdo->lastInsertId();

        $packageId = add_package([
            'customer_name' => 'Cliente Portale',
            'customer_phone' => '+390831234567',
            'customer_email' => 'cliente@example.com',
            'tracking' => 'TRK-PORTAL',
            'status' => 'in_giacenza',
            'pickup_location_id' => $this->getDefaultLocationId(),
        ]);

        $pdo->prepare('INSERT INTO pickup_customer_reports (customer_id, tracking_code, notes, status, created_at, updated_at) VALUES (:customer_id, :tracking, :notes, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
            ->execute([
                ':customer_id' => $customerId,
                ':tracking' => 'TRK-PORTAL',
                ':notes' => 'Segnalazione di test',
                ':status' => 'reported',
            ]);
        $reportId = (int) $pdo->lastInsertId();

    $linked = link_customer_report_to_pickup($reportId, $packageId, 'confirmed');
    $this->assertTrue($linked);

    $report = get_customer_report($reportId);
    $this->assertNotNull($report);
    $this->assertSame($packageId, (int) $report['pickup_id']);
    $this->assertSame('confirmed', $report['status']);
    }

    public function testGenerateCheckinQrCreatesImage(): void
    {
        $relativePath = generate_qr_checkin();
        $this->assertNotEmpty($relativePath, 'Percorso QR vuoto.');

        $absolutePath = pickup_root_path() . '/' . ltrim($relativePath, '/');
        $this->assertFileExists($absolutePath, 'File QR non trovato.');

        $contents = file_get_contents($absolutePath);
        $this->assertNotFalse($contents, 'Impossibile leggere il QR generato.');

        if (function_exists('getimagesizefromstring')) {
            $this->assertNotFalse(getimagesizefromstring($contents), 'Il QR generato non è un immagine valida.');
        }

        @unlink($absolutePath);
    }

    public function testCheckStorageExpirationSendsWarningAndMarksExpired(): void
    {
        $pdo = pickup_db();

        $warningPackageId = add_package([
            'customer_name' => 'Cliente Giacenza',
            'customer_phone' => '+390841234567',
            'customer_email' => 'ag.servizi16@gmail.com',
            'tracking' => 'TRK-WARN',
            'status' => 'in_giacenza',
            'pickup_location_id' => $this->getDefaultLocationId(),
        ]);

        $expiredPackageId = add_package([
            'customer_name' => 'Cliente Scaduto',
            'customer_phone' => '+390841234568',
            'customer_email' => 'scaduto@example.com',
            'tracking' => 'TRK-EXP',
            'status' => 'in_giacenza',
            'pickup_location_id' => $this->getDefaultLocationId(),
        ]);

        $pdo->prepare("UPDATE pickup_packages SET updated_at = DATETIME('now','-2 day'), created_at = DATETIME('now','-2 day') WHERE id = :id")
            ->execute([':id' => $warningPackageId]);
        $pdo->prepare("UPDATE pickup_packages SET updated_at = DATETIME('now','-5 day'), created_at = DATETIME('now','-5 day') WHERE id = :id")
            ->execute([':id' => $expiredPackageId]);

    $result = check_storage_expiration(3, ['warning_days' => 1]);

    $this->assertSame(2, $result['processed']);
    $this->assertSame(1, $result['warned']);
    $this->assertSame(1, $result['expired']);

    $expiredPackage = get_package_details($expiredPackageId);
    $this->assertSame('in_giacenza_scaduto', $expiredPackage['status']);

    $warningHistory = get_package_history($warningPackageId);
    $warningEvents = array_filter($warningHistory, static fn(array $entry): bool => ($entry['event_type'] ?? '') === 'notify_storage_warning');
    $this->assertNotEmpty($warningEvents, 'Avviso di giacenza non registrato.');

    $warningPackage = get_package_details($warningPackageId);
    $this->assertSame('in_giacenza', $warningPackage['status']);
    }

    private function getDefaultLocationId(): int
    {
    $pdo = pickup_db();
    $value = $pdo->query('SELECT id FROM pickup_locations LIMIT 1')->fetchColumn();
    $this->assertNotFalse($value, 'Nessun punto ritiro iniziale disponibile.');
        return (int) $value;
    }

    private function getDefaultCourierId(): ?int
    {
        $pdo = pickup_db();
        $value = $pdo->query('SELECT id FROM pickup_couriers LIMIT 1')->fetchColumn();
        return $value === false ? null : (int) $value;
    }
}
