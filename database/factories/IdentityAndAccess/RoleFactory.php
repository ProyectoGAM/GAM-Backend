<?php

namespace Database\Factories\IdentityAndAccess;

use App\Models\IdentityAndAccess\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    /** @return array{name: string, guard_name: string, label: string, description: string|null} */
    public function definition(): array
    {
        return [
            'name' => 'custom_'.fake()->unique()->uuid(), 'guard_name' => 'web',
            'label' => 'Rol de prueba', 'description' => null,
        ];
    }
}
