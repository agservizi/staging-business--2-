<?php
declare(strict_types=1);

namespace Tests\Modules\CAFPatronato;

use PHPUnit\Framework\TestCase;

final class StatusMappingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('caf_patronato_map_status_to_legacy')) {
            require_once dirname(__DIR__, 3) . '/modules/servizi/caf-patronato/functions.php';
        }
    }

    public function testMapStatusCategoryToLegacyCode(): void
    {
        $completed = caf_patronato_map_status_to_legacy('Completata');
        self::assertSame('completata', $completed['code']);

        $waiting = caf_patronato_map_status_to_legacy('In attesa documenti');
        self::assertSame('sospesa', $waiting['code']);

        $pending = caf_patronato_map_status_to_legacy('Da lavorare');
        self::assertSame('in_lavorazione', $pending['code']);
    }
}
