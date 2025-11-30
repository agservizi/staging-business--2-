<?php
declare(strict_types=1);

namespace PHPUnit\Framework;

if (!class_exists(Assert::class, false)) {
    class Assert
    {
        public static function assertIsArray(mixed $actual, string $message = ''): void {}
        public static function assertArrayHasKey(mixed $key, array $array, string $message = ''): void {}
        public static function assertEmpty(mixed $actual, string $message = ''): void {}
        public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void {}
        public static function assertCount(int $expectedCount, \Countable|iterable $haystack, string $message = ''): void {}
        public static function assertNotNull(mixed $actual, string $message = ''): void {}
        public static function assertNull(mixed $actual, string $message = ''): void {}
        public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void {}
        public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void {}
        public static function assertNotEmpty(mixed $actual, string $message = ''): void {}
        public static function assertFalse(bool $condition, string $message = ''): void {}
        public static function assertNotFalse(mixed $actual, string $message = ''): void {}
        public static function assertTrue(bool $condition, string $message = ''): void {}
    }
}

if (!class_exists(TestCase::class, false)) {
    abstract class TestCase extends Assert
    {
        public function expectException(string $exception): void {}
        public function expectExceptionMessage(string $message): void {}
    }
}
