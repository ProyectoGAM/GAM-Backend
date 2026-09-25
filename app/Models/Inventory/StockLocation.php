<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\StockLocationStatus;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use Database\Factories\Inventory\StockLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int|null $production_unit_id
 * @property int|null $poultry_house_id
 * @property string $name
 * @property StockLocationStatus $status
 * @property bool $system_managed
 */
#[Fillable(['production_unit_id', 'name', 'status', 'system_managed'])]
class StockLocation extends Model
{
    /** @use HasFactory<StockLocationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => StockLocationStatus::class, 'system_managed' => 'boolean'];
    }

    /** Normaliza el nombre para mantener la unicidad a nivel de empresa. */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'name' => trim($value),
                'normalized_name' => Str::lower(trim($value)),
            ],
        );
    }

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
    }

    /** @return BelongsTo<PoultryHouse, $this> */
    public function poultryHouse(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class);
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
