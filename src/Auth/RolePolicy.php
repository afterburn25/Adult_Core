<?php

declare(strict_types=1);

namespace AdultCore\Auth;

/** Explicit, deny-by-default role permissions. Routes must check permissions server-side. */
final class RolePolicy
{
    private const PERMISSIONS = [
        'owner' => ['admin.access', 'admin.dashboard.view', 'site.settings.manage', 'modules.manage', 'members.manage', 'content.moderate'],
        'admin' => ['admin.access', 'admin.dashboard.view', 'site.settings.manage', 'modules.manage', 'members.manage', 'content.moderate'],
        'moderator' => ['admin.access', 'content.moderate'],
        'member' => [],
    ];

    public static function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS[$role] ?? [], true);
    }
}
