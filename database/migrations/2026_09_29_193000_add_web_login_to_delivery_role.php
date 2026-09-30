<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissionId = DB::table('permissions')
            ->where('name', 'identity.web.login')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'identity.web.login',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = DB::table('roles')
            ->where('name', 'delivery')
            ->where('guard_name', 'web')
            ->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'delivery',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('role_has_permissions')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'identity.web.login')
            ->where('guard_name', 'web')
            ->value('id');
        $roleId = DB::table('roles')
            ->where('name', 'delivery')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId !== null && $roleId !== null) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->delete();
        }
    }
};
