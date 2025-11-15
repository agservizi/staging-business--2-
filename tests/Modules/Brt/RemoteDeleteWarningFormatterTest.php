<?php
declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        abstract class TestCase
        {
            public function assertSame($expected, $actual, string $message = ''): void {}
            public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void {}
        }
    }
}

namespace Tests\Modules\Brt {

use PHPUnit\Framework\TestCase;

use function brt_normalize_remote_warning;

final class RemoteDeleteWarningFormatterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
            define('CORESUITE_BRT_BOOTSTRAP', true);
        }

        require_once __DIR__ . '/../../../modules/servizi/brt/functions.php';
    }

    public function testJsonPayloadIsExtracted(): void
    {
        $message = brt_normalize_remote_warning('{"message":"Errore remoto"}');

        $this->assertSame('Errore remoto', $message);
    }

    public function testHtmlNoiseIsStripped(): void
    {
        $raw = '<style>.foo{}</style><script>alert(1)</script><div>Problema &amp; dettagli</div>';
        $message = brt_normalize_remote_warning($raw);

        $this->assertSame('Problema & dettagli', $message);
    }

    public function testFallsBackForUnstructuredBody(): void
    {
        $message = brt_normalize_remote_warning('body{background:#fff;}');

        $this->assertSame('Risposta non interpretata dal webservice BRT.', $message);
    }
}

}
