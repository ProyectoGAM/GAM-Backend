<?php

namespace App\Models\Lots;

use App\Models\Inventory\InventoryMovement;
use Carbon\CarbonImmutable;
use Database\Factories\Lots\EggCollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $flock_id
 * @property int $poultry_house_id
 * @property int $production_unit_id
 * @property int|null $product_id
 * @property int|null $stock_location_id
 * @property int|null $inventory_movement_id
 * @property int $collected_quantity
 * @property int|null $quantity
 * @property int $discarded_quantity
 * @property string|null $discard_reason
 * @property int $version
 * @property string $status
 * @property string|null $notes
 * @property CarbonImmutable $occurred_at
 * @property int $created_by
 * @property-read Flock $flock
 */
class EggCollection extends Model
{
    /** Conserva el instante aunque PostgreSQL use una zona horaria distinta de UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<EggCollectionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'collected_quantity' => 'integer',
            'quantity' => 'integer',
            'discarded_quantity' => 'integer',
            'version' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return HasMany<EggCollectionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EggCollectionLine::class);
    }

    /**
     * Movimientos de pérdida vinculados por el identificador público de la colección.
     * Las reversiones se excluyen al consultar los movimientos activos.
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryLosses(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'reference_id', 'public_id')
            ->where('reference_type', 'egg_collection')
            ->where('type', 'loss')
            ->whereDoesntHave('reversal');
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'reference_id', 'public_id')
            ->where('reference_type', 'egg_collection');
    }
}
