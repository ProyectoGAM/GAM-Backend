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
            Permission::query()->whereIn('name', $permissions)->get(),
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
