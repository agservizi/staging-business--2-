<?php
declare(strict_types=1);

namespace App\Demo;

final class DemoMode
{
    private const DEFAULT_MODE = 'production';

    public static function current(): string
    {
        $value = getenv('APP_MODE');
        if ($value === false || $value === null || trim($value) === '') {
            return self::DEFAULT_MODE;
        }

        return strtolower(trim($value));
    }

    public static function isDemo(): bool
    {
        return self::current() === 'demo';
    }
}