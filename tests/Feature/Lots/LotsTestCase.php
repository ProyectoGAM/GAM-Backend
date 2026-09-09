<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

abstract class LotsTestCase extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    protected function signIn(array $permissions = ['flocks.view', 'flocks.manage', 'flocks.redistribute', 'flocks.finalize', 'mortality.view', 'mortality.manage', 'egg-collections.view', 'egg-collections.manage', 'breeds.view', 'breeds.manage', 'mortality-categories.view', 'mortality-categories.manage']): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }

    protected function flock(int $quantity = 100, ?Breed $breed = null, ?PoultryHouse $house = null): Flock
    {
        return Flock::factory()->create([
            'initial_quantity' => $quantity, 'current_quantity' => $quantity,
            'breed_id' => $breed?->id ?? Breed::factory(),
            'poultry_house_id' => $house?->id ?? PoultryHouse::factory(),
        ]);
    }

    protected function flockWithHistory(int $quantity = 100, ?Breed $breed = null, ?PoultryHouse $house = null): Flock
    {
        // Preparación: crea la admisión histórica que la proyección de pesajes consulta.
        $flock = $this->flock($quantity, $breed, $house);
        FlockMovement::factory()->create([
            'destination_flock_id' => $flock->id,
            'quantity' => $quantity,
            'occurred_at' => $flock->established_at,
            'created_by' => auth()->id(),
            'after' => [$flock->public_id => [
                'public_id' => $flock->public_id,
                'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id,
                'current_quantity' => $quantity,
                'entry_date' => $flock->entry_date->format('Y-m-d'),
            ]],
        ]);

        return $flock;
    }

    /** @param array<string, mixed> $payload */
    protected function command(string $method, string $path, array $payload, ?string $key = null): TestResponse
    {
        return $this->json($method, '/api/v1'.$path, $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
