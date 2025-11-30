<?php
declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        abstract class TestCase
        {
            public function assertSame($expected, $actual, string $message = ''): void {}
            public function assertArrayHasKey($key, $array, string $message = ''): void {}
        }
    }
}

namespace Tests\Modules\Brt {

use PHPUnit\Framework\TestCase;

use function brt_prefill_orm_form_data_from_shipment;

final class OrmPrefillIrelandTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
            define('CORESUITE_BRT_BOOTSTRAP', true);
        }
        require_once __DIR__ . '/../../../modules/servizi/brt/functions.php';
    }

    /**
     * @return array<string, string>
     */
    private function ormDefaults(): array
    {
        return [
            'collection_date' => '',
            'collection_time' => '',
            'parcel_count' => '',
            'weight_kg' => '',
            'good_description' => '',
            'payer_type' => '',
            'service_code' => '',
            'customer_account_number' => '',
            'requester_customer_number' => '',
            'sender_customer_number' => '',
            'sender_company_name' => '',
            'sender_address' => '',
            'sender_zip' => '',
            'sender_city' => '',
            'sender_state' => '',
            'sender_country' => '',
            'sender_contact_person' => '',
            'sender_contact_phone' => '',
            'receiver_company_name' => '',
            'receiver_address' => '',
            'receiver_zip' => '',
            'receiver_city' => '',
            'receiver_state' => '',
            'receiver_country' => '',
            'alerts_email' => '',
            'alerts_sms' => '',
            'notes' => '',
            'parcel_lines' => '',
            'opening_hour_1_from' => '',
            'opening_hour_1_to' => '',
            'opening_hour_2_from' => '',
            'opening_hour_2_to' => '',
            'request_ref' => '',
            'source_shipment_id' => '',
        ];
    }

    public function testIrishZipDefaultsToEireWhenMissing(): void
    {
        $defaults = $this->ormDefaults();
        $shipment = [
            'id' => 42,
            'created_at' => '2024-04-01 08:00:00',
            'number_of_parcels' => 2,
            'weight_kg' => 5.2,
            'numeric_sender_reference' => 9001,
            'sender_customer_code' => 'CST123',
            'consignee_name' => 'Acme Ireland',
            'consignee_address' => '1 River Street',
            'consignee_zip' => '',
            'consignee_city' => 'Dublin',
            'consignee_province' => 'D',
            'consignee_country' => 'IE',
        ];

        $prefilled = brt_prefill_orm_form_data_from_shipment($defaults, $shipment);

        $this->assertArrayHasKey('receiver_zip', $prefilled);
        $this->assertSame('EIRE', $prefilled['receiver_zip']);
        $this->assertSame('IE', $prefilled['receiver_country']);
    }

    public function testIrishZipIsUppercasedWhenPresent(): void
    {
        $defaults = $this->ormDefaults();
        $shipment = [
            'id' => 99,
            'created_at' => '2024-04-02 10:30:00',
            'number_of_parcels' => 1,
            'weight_kg' => 1.1,
            'numeric_sender_reference' => 8123,
            'sender_customer_code' => 'CST456',
            'consignee_name' => 'Beta Logistics',
            'consignee_address' => '12 Harbor Road',
            'consignee_zip' => 'd02x285',
            'consignee_city' => 'Dublin',
            'consignee_province' => 'D',
            'consignee_country' => 'ie',
        ];

        $prefilled = brt_prefill_orm_form_data_from_shipment($defaults, $shipment);

        $this->assertSame('D02X285', $prefilled['receiver_zip']);
        $this->assertSame('IE', $prefilled['receiver_country']);
    }
}

}
