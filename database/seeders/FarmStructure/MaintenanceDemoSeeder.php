<?php

namespace Database\Seeders\FarmStructure;

use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\CreateMaintenanceAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class MaintenanceDemoSeeder extends Seeder
{
    public function run(CreateMaintenanceAction $create): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $definitions = [
            [
                'key' => '00000000-0000-4000-8000-000000000101',
                'unit' => 'Granja El Ombú', 'house' => 'Galpón Norte', 'days_ago' => 45,
                'description' => 'Revisión de bebederos y limpieza de cañerías.', 'amount' => '1850.00',
            ],
            [
                'key' => '00000000-0000-4000-8000-000000000102',
                'unit' => 'Granja El Ombú', 'house' => 'Galpón Norte', 'days_ago' => 15,
                'description' => 'Reparación del sistema de ventilación.', 'amount' => '3250.50',
            ],
            [
                'key' => '00000000-0000-4000-8000-000000000103',
                'unit' => 'Granja El Ombú', 'house' => 'Galpón Sur', 'days_ago' => 10,
                'description' => 'Reparación del tablero de iluminación.', 'amount' => '2480.00',
            ],
            [
                'key' => '00000000-0000-4000-8000-000000000104',
                'unit' => 'Granja Santa Clara', 'house' => 'Galpón Ponedoras', 'days_ago' => 20,
                'description' => 'Mantenimiento de nidos y ajuste de cintas.', 'amount' => '900.75',
            ],
        ];

        foreach ($definitions as $definition) {
            /** Una recarga no debe cambiar fechas ni sobrescribir correcciones del histórico demo. */
            if (Maintenance::query()->where('created_by', $actor->id)->where('idempotency_key', $definition['key'])->exists()) {
                continue;
            }

            $house = PoultryHouse::query()
                ->where('normalized_name', Str::lower($definition['house']))
                ->whereHas('productionUnit', fn (Builder $query): Builder => $query->where('normalized_name', Str::lower($definition['unit'])))
                ->sole();

            $create->execute($house, [
                'maintenance_date' => now()->subDays($definition['days_ago'])->toDateString(),
                'description' => $definition['description'],
                'cost_amount' => $definition['amount'],
                'cost_currency' => 'UYU',
                'responsible_user_id' => $actor->id,
                'idempotency_key' => $definition['key'],
            ], $actor, source: 'seeder');
        }
    }
}
