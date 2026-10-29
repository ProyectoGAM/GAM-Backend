<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class IdentityPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'admin.dashboard.view',
            'audit.view',
            'customers.view',
            'customers.manage',
            'customer-accounts.view',
            'customer-payments.record',
            'customer-accounts.adjust',
            'customer-accounts.reverse',
            'flocks.view',
            'flocks.manage',
            'flocks.redistribute',
            'flocks.finalize',
            'management-plans.view',
            'management-plans.manage',
            'management-plans.execute',
            'management-plans.stock.manage',
            'breeds.view',
            'breeds.manage',
            'mortality.view',
            'mortality.manage',
            'egg-collections.view',
            'egg-collections.manage',
            'egg-stock.view',
            'egg-stock.move',
            'egg-stock.adjust',
            'weighings.view',
            'weighings.manage',
            'weighing-settings.manage',
            'geography.view',
            'geography.manage',
            'production-units.view',
            'production-units.manage',
            'poultry-houses.view',
            'poultry-houses.manage',
            'suppliers.view',
            'suppliers.manage',
            'products.view',
            'products.manage',
            'inventory.view',
            'inventory.move',
            'inventory.adjust',
            'inventory.manage',
            'reports.view',
            'reports.export',
            'reports.presets.manage',
            'reports.share',
            'feed-recipes.view',
            'feed-recipes.manage',
            'identity.personal.login',
            'identity.web.login',
            'identity.shared.login',
            'identity.users.manage',
            'identity.pins.manage',
            'identity.shared-devices.manage',
            'identity.sessions.manage',
            'delivery.start',
            'delivery.view-own',
            'delivery.update-own',
            'delivery.location.publish',
            'delivery.monitor',
            'delivery.history',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('admin', 'web')->syncPermissions(
            Permission::query()->where('guard_name', 'web')->get(),
        );
        Role::findOrCreate('delivery', 'web')->syncPermissions([
            Permission::findByName('identity.personal.login', 'web'),
            Permission::findByName('identity.web.login', 'web'),
            Permission::findByName('delivery.start', 'web'),
            Permission::findByName('delivery.view-own', 'web'),
            Permission::findByName('delivery.update-own', 'web'),
            Permission::findByName('delivery.location.publish', 'web'),
        ]);
        Role::findOrCreate('employee', 'web')->syncPermissions([
            Permission::findByName('identity.shared.login', 'web'),
        ]);
    }
}
