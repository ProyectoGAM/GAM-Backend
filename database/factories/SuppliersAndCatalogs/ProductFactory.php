<?php

namespace Database\Factories\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->unique()->words(2, true),
            'kind' => ProductKind::Supply,
            'base_unit' => BaseUnit::Kilogram,
            'stock_tracked' => true,
            'status' => ProductStatus::Active,
        ];
    }

    public function vaccine(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductKind::Vaccine,
            'base_unit' => BaseUnit::Dose,
            'stock_tracked' => true,
            'status' => ProductStatus::Active,
        ]);
    }

    public function rawMaterial(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductKind::RawMaterial,
            'base_unit' => BaseUnit::Gram,
            'stock_tracked' => true,
            'status' => ProductStatus::Active,
        ]);
    }
}
