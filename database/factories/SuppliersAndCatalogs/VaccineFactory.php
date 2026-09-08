<?php

namespace Database\Factories\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vaccine>
 */
class VaccineFactory extends Factory
{
    protected $model = Vaccine::class;

    /**
     * Define el estado predeterminado del modelo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->vaccine(),
            'supplier_id' => Supplier::factory(),
            'description' => fake()->sentence(),
            'details' => null,
            'supplier_name_snapshot' => fn (array $attributes): string => Supplier::query()->findOrFail((int) $attributes['supplier_id'])->name,
            'created_by' => User::factory(),
            'created_by_name' => fn (array $attributes): string => User::query()->findOrFail((int) $attributes['created_by'])->name,
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
