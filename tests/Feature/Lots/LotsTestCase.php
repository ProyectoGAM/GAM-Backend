<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
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

    /** @param array<string, mixed> $payload */
    protected function command(string $method, string $path, array $payload, ?string $key = null): TestResponse
    {
        return $this->json($method, '/api/v1'.$path, $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
