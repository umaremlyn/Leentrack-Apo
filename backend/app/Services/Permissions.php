<?php
declare(strict_types=1);

namespace LeenTrack\Services;

/** Server-side permission matrix (docs/07 §2). Roles come ONLY from business_members. */
final class Permissions
{
    private const ALL_MEMBERS = ['sales.create', 'sales.read', 'customers.read', 'customers.create', 'products.read', 'business.read', 'locations.read', 'groups.read', 'loyalty.read'];
    private const MANAGER_EXTRA = [
        'sales.edit', 'sales.void', 'customers.update', 'customers.delete', 'groups.write',
        'products.write', 'stock.adjust', 'categories.write', 'purchases.manage', 'suppliers.manage',
        'expenses.manage', 'reports.read', 'products.cost.read', 'profit.read', 'dashboard.read', 'credit.repay', 'locations.manage', 'staff.read',
    ];
    private const OWNER_EXTRA = ['staff.manage', 'billing.manage', 'branding.write', 'loyalty.write', 'business.update', 'business.delete'];

    /** @return list<string> */
    public static function for(string $role): array
    {
        return match ($role) {
            'owner' => array_merge(self::ALL_MEMBERS, self::MANAGER_EXTRA, self::OWNER_EXTRA),
            'manager' => array_merge(self::ALL_MEMBERS, self::MANAGER_EXTRA),
            'cashier' => self::ALL_MEMBERS,
            default => [],
        };
    }

    public static function can(string $role, string $permission): bool
    {
        return in_array($permission, self::for($role), true);
    }
}
