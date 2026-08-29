<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{name: string, email: string, password: string|null} $adminConfig */
        $adminConfig = config('auth.admin');

        if (! is_string($adminConfig['password']) || trim($adminConfig['password']) === '') {
            throw new RuntimeException('ADMIN_PASSWORD must be configured before seeding the administrator.');
        }

        $admin = User::withTrashed()->firstOrNew([
            'email' => $adminConfig['email'],
        ]);

        if ($admin->trashed()) {
            $admin->restore();
        }

        $admin->forceFill([
            'name' => $adminConfig['name'],
            'email' => $adminConfig['email'],
            'password' => $adminConfig['password'],
            'email_verified_at' => now(),
        ])->save();

        $permission = Permission::findOrCreate('admin.dashboard.view', 'web');
        $role = Role::findOrCreate('admin', 'web');
        $role->syncPermissions([$permission]);
        $admin->syncRoles([$role]);
    }
}
