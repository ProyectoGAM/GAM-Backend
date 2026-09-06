<?php

namespace Database\Factories\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Medicine> */
class MedicineFactory extends Factory
{
    protected $model = Medicine::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'supplier_id' => Supplier::factory(),
            'supplier_name_snapshot' => fn (array $attributes): string => Supplier::query()->findOrFail((int) $attributes['supplier_id'])->name,
            'created_by' => User::factory(),
            'created_by_name' => fn (array $attributes): string => User::query()->findOrFail((int) $attributes['created_by'])->name,
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
