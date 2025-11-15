<?php
declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        abstract class TestCase
        {
            public function assertSame($expected, $actual, string $message = ''): void {}
            public function assertIsArray($actual, string $message = ''): void {}
            public function assertContains($needle, $haystack, string $message = ''): void {}
        }
    }
}

namespace Tests\Modules\Brt {

use PHPUnit\Framework\TestCase;

use function brt_extract_routing_quote_summary;

final class RoutingQuoteSummaryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
            define('CORESUITE_BRT_BOOTSTRAP', true);
        }
        require_once __DIR__ . '/../../../modules/servizi/brt/functions.php';
    }

    public function testExtractSummaryPrefersCustomerAmount(): void
    {
        $response = [
            'pricing' => [
                'offer' => [
                    'customerAmount' => 12.34,
                    'customerPrice' => 11.50,
                    'taxAmount' => 2.34,
                    'currency' => 'eur',
                ],
            ],
        ];

        $summary = brt_extract_routing_quote_summary($response);

        $this->assertIsArray($summary);
        $this->assertSame(12.34, $summary['amount']);
        $this->assertSame('EUR', $summary['currency']);
        $this->assertSame('customeramount', $summary['label']);
        $this->assertContains('customeramount', array_keys($summary['breakdown']));
        $this->assertContains('taxamount', array_keys($summary['breakdown']));
    }

    public function testExtractSummaryRecognisesListPrice(): void
    {
        $response = [
            'costing' => [
                'base' => [
                    'listPrice' => 45.5,
                    'fuelCost' => 5.5,
                    'currencyCode' => 'EUR',
                ],
            ],
        ];

        $summary = brt_extract_routing_quote_summary($response);

        $this->assertIsArray($summary);
        $this->assertSame(45.5, $summary['amount']);
        $this->assertSame('EUR', $summary['currency']);
        $this->assertSame('listprice', $summary['label']);
        $this->assertContains('fuelcost', array_keys($summary['breakdown']));
    }

    public function testExtractSummaryFallsBackToFirstAmount(): void
    {
        $response = [
            'details' => [
                'charges' => [
                    'miscCost' => 9.75,
                    'handlingCost' => 1.25,
                    'currency' => 'eur',
                ],
            ],
        ];

        $summary = brt_extract_routing_quote_summary($response);

        $this->assertIsArray($summary);
        $this->assertSame(9.75, $summary['amount']);
        $this->assertSame('EUR', $summary['currency']);
        $this->assertSame('misccost', $summary['label']);
        $this->assertContains('handlingcost', array_keys($summary['breakdown']));
    }

    public function testExtractSummaryReturnsNullWhenAmountsMissing(): void
    {
        $response = [
            'routingResponse' => [
                'message' => 'No pricing available',
                'currency' => 'EUR',
            ],
        ];

        $summary = brt_extract_routing_quote_summary($response);

        $this->assertSame(null, $summary);
    }
}

}
