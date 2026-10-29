<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{name: string, email: string, password: string|null} $adminConfig */
        $adminConfig = config('auth.admin');

        if (! is_string($adminConfig['password']) || trim($adminConfig['password']) === '') {
            throw new RuntimeException('ADMIN_PASSWORD debe configurarse antes de crear el administrador.');
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

        $this->call(IdentityPermissionSeeder::class);
        $role = Role::findByName('admin', 'web');
        $admin->syncRoles([$role]);
    }
}
