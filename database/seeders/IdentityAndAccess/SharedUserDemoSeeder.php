<?php

namespace Database\Seeders\IdentityAndAccess;

use App\Actions\IdentityAndAccess\RegisterUserAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SharedUserDemoSeeder extends Seeder
{
    private const PIN = '1234';

    private const USERS = [
        'granja el ombú' => ['locality' => 'las piedras', 'names' => ['Ana Duarte (demo)', 'Mateo Silva (demo)']],
        'granja santa clara' => ['locality' => 'libertad', 'names' => ['Lucía Méndez (demo)', 'Bruno Castro (demo)']],
    ];

    public function run(RegisterUserAction $registerUser, AccessControl $access): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function () use ($registerUser, $access): void {
            $admin = $access->lockActor(User::query()->role('admin')->orderBy('id')->firstOrFail());
            $role = Role::findByName('demo_poultry_house_operator', 'web');

            foreach (self::USERS as $unitName => $definition) {
                $unit = ProductionUnit::query()->where('normalized_name', $unitName)
                    ->whereRelation('locality', 'normalized_name', $definition['locality'])
                    ->where('status', ProductionUnitStatus::Active)->sole();

                foreach ($definition['names'] as $name) {
                    if (User::withTrashed()->where('name', $name)->exists()) {
                        continue;
                    }

                    $registerUser->execute([
                        'name' => $name,
                        'pin' => self::PIN,
                        'up_id' => $unit->id,
                        'rol_id' => $role->id,
                    ], $admin, source: 'seeder');
                }
            }
        }, 3);
    }
}
