<?php

namespace Database\Factories\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->unique()->words(2, true),
            'kind' => ProductKind::RawMaterial,
            'base_unit' => BaseUnit::Kilogram,
            'stock_tracked' => true,
            'status' => ProductStatus::Active,
        ];
    }
}
