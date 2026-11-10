<?php

namespace Database\Factories;

use App\Enums\IdentityAndAccess\AccessMethod;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\PinHasher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Asigna un rol vacío independiente para no compartir permisos entre fixtures.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $role = Role::findOrCreate('test-user-'.$user->id, 'web');
            $user->syncRoles([$role]);
        });
    }

    /** Indica que el correo electrónico todavía no fue verificado. */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Cuenta sin correo, con un PIN de prueba y una única UP. */
    public function devicePin(?ProductionUnit $unit = null, string $pin = '0007'): static
    {
        return $this->state(fn (): array => [
            'access_method' => AccessMethod::DevicePin,
            'email' => null, 'email_verified_at' => null, 'password' => null,
            'production_unit_id' => $unit !== null ? $unit->id : ProductionUnit::factory(),
            'pin_hash' => app(PinHasher::class)->hash($pin),
            'pin_enabled' => true,
        ]);
    }
}
