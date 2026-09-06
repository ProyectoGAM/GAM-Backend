<?php

namespace App\Models\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Models\Geography\Locality;
use App\Models\Inventory\InventoryMovement;
use Database\Factories\SuppliersAndCatalogs\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property SupplierStatus $status
 */
#[Fillable(['locality_id', 'name', 'address', 'status'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => SupplierStatus::class];
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

    /** @return BelongsTo<Locality, $this> */
    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
