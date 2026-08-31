<?php

namespace App\Models\SuppliersAndCatalogs;

use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use Database\Factories\SuppliersAndCatalogs\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** @property BaseUnit $base_unit */
#[Fillable(['sku', 'name', 'kind', 'base_unit', 'stock_tracked', 'status'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'base_unit' => BaseUnit::class,
            'stock_tracked' => 'boolean',
            'status' => ProductStatus::class,
        ];
    }

    /** Normaliza el nombre para mantener la unicidad sin alterar el valor mostrado. */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'name' => trim($value),
                'normalized_name' => Str::lower(trim($value)),
            ],
        );
    }

    /** Normaliza el SKU sin cambiar su representación pública. */
    protected function sku(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => trim($value));
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<InventoryMovementLine, $this> */
    public function movementLines(): HasMany
    {
        return $this->hasMany(InventoryMovementLine::class);
    }
}
