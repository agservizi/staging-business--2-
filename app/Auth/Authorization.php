<?php
declare(strict_types=1);

namespace App\Auth;

final class Authorization
{
    private const ROLE_CAPABILITIES = [
        'Admin' => [
            'settings.view',
            'settings.manage',
            'users.manage',
            'backup.create',
            'clients.manage',
            'email.marketing.manage',
            'email.marketing.view',
            'services.manage',
            'tickets.manage',
            'reports.view',
        ],
        'Manager' => [
            'settings.view',
            'users.manage',
            'clients.manage',
            'email.marketing.manage',
            'email.marketing.view',
            'services.manage',
            'tickets.manage',
            'reports.view',
        ],
        'Operatore' => [
            'clients.view',
            'clients.manage',
            'email.marketing.view',
            'services.manage',
            'tickets.manage',
            'reports.view',
        ],
        'Patronato' => [
            'services.manage',
        ],
        'Cliente' => [
            'clients.view_self',
            'tickets.create',
            'tickets.view_self',
        ],
    ];

    public static function roleCan(string $role, string $capability): bool
    {
        $roleCaps = self::ROLE_CAPABILITIES[$role] ?? [];
        return in_array($capability, $roleCaps, true);
    }

    public static function roleAllows(string $role, string ...$capabilities): bool
    {
        if (!$capabilities) {
            return true;
        }

        foreach ($capabilities as $capability) {
            if (self::roleCan($role, $capability)) {
                return true;
            }
        }

        return false;
    }

    public static function roleExists(string $role): bool
    {
        return array_key_exists($role, self::ROLE_CAPABILITIES);
    }
}
