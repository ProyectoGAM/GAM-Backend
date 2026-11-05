<?php

namespace Database\Seeders\IdentityAndAccess;

use App\Actions\IdentityAndAccess\CreateRoleAction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class RoleDemoSeeder extends Seeder
{
    /** @var array<string, array{nombre: string, descripcion: string, permisos: list<string>}> */
    private const ROLES = [
        'customer_management' => [
            'nombre' => 'Gestión de clientes',
            'descripcion' => 'Alta y edición de fichas de clientes desde el navegador.',
            'permisos' => ['can_login_web', 'can_view_customers', 'can_manage_customers', 'can_view_geography'],
        ],
        'collections' => [
            'nombre' => 'Cobranzas',
            'descripcion' => 'Consulta de clientes y sus cuentas corrientes, con registro de pagos.',
            'permisos' => ['can_login_web', 'can_view_customers', 'can_view_customer_accounts', 'can_record_customer_payments'],
        ],
        'inventory_viewer' => [
            'nombre' => 'Consulta de inventario',
            'descripcion' => 'Consulta de existencias de insumos, huevos y alimento desde el navegador.',
            'permisos' => [
                'can_login_web', 'can_view_production_units', 'can_view_products', 'can_view_inventory',
                'can_view_egg_stock', 'can_view_feed_stock',
            ],
        ],
        'poultry_house_operator' => [
            'nombre' => 'Operación de galpón',
            'descripcion' => 'Registro de recolecciones, mortalidad y pesajes desde un dispositivo compartido de la UP.',
            'permisos' => [
                'can_login_shared', 'can_view_production_units', 'can_view_poultry_houses', 'can_view_flocks',
                'can_view_egg_collections', 'can_manage_egg_collections', 'can_view_mortality', 'can_manage_mortality',
                'can_view_weighings', 'can_manage_weighings',
            ],
        ],
    ];

    public function run(CreateRoleAction $createRole): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $admin = User::query()->role('admin')->orderBy('id')->first();
        if ($admin === null) {
            return;
        }

        DB::transaction(function () use ($createRole, $admin): void {
            foreach (self::ROLES as $key => $role) {
                $createRole->execute($admin, $role, demoKey: $key);
            }
        }, 3);
    }
}
