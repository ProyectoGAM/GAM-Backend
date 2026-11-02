<?php

namespace Database\Seeders;

use App\Models\IdentityAndAccess\Role;
use App\Services\IdentityAndAccess\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class IdentityPermissionSeeder extends Seeder
{
    public function run(PermissionCatalog $catalog): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () use ($catalog): void {
            foreach (array_keys($catalog->permissions()) as $name) {
                Permission::findOrCreate($name, 'web');
            }
            $admin = Role::findOrCreate('admin', 'web');
            $admin->forceFill(['label' => 'Administrador'])->save();
            $admin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
            $defaults = [
                'delivery' => ['label' => 'Repartidor', 'permissions' => [
                    'can_login_native', 'can_login_web', 'can_start_deliveries', 'can_view_own_deliveries',
                    'can_update_own_deliveries', 'can_publish_delivery_location', 'can_record_delivery_fuel',
                ]],
                'employee' => ['label' => 'Empleado', 'permissions' => ['can_login_shared']],
            ];
            foreach ($defaults as $name => $data) {
                $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['label' => $data['label']]);
                if ($role->wasRecentlyCreated || $role->label === null) {
                    $role->forceFill(['label' => $data['label']])->save();
                    $role->syncPermissions($data['permissions']);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
