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
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('admin', 'web')->syncPermissions(
            Permission::query()->whereIn('name', $permissions)->get(),
        );
        Role::findOrCreate('delivery', 'web')->syncPermissions([
            Permission::findByName('identity.personal.login', 'web'),
        ]);
        Role::findOrCreate('employee', 'web')->syncPermissions([
            Permission::findByName('identity.shared.login', 'web'),
        ]);
    }
}
