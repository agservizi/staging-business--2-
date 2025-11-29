<?php
declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        abstract class TestCase
        {
            public function assertTrue($condition, string $message = ''): void {}
            public function assertFalse($condition, string $message = ''): void {}
        }
    }
}

namespace Tests\Modules\Brt {

use PHPUnit\Framework\TestCase;

use function brt_is_remote_already_confirmed_message;

final class RemoteConfirmStatusFormatterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
            define('CORESUITE_BRT_BOOTSTRAP', true);
        }

        require_once __DIR__ . '/../../../modules/servizi/brt/functions.php';
    }

    public function testDetectsAlreadyConfirmedMessage(): void
    {
        $message = 'SHIPMENT NOT CONFIRMABLE Shipment has already been confirmed';
        $this->assertTrue(brt_is_remote_already_confirmed_message($message));
    }

    public function testIgnoresDifferentWarnings(): void
    {
        $message = 'PUDO ID not found';
        $this->assertFalse(brt_is_remote_already_confirmed_message($message));
    }
}

}
