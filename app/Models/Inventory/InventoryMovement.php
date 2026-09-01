<?php

namespace App\Models\Inventory;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use Carbon\Carbon;
use Database\Factories\Inventory\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property InventoryMovementType $type
 * @property Carbon $occurred_at
 */
class InventoryMovement extends Model
{
    /** Los movimientos de producción deben conservar su zona al persistirse en PostgreSQL. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return HasMany<InventoryMovementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryMovementLine::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'reverses_movement_id');
    }

    /** @return HasOne<InventoryMovement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(InventoryMovement::class, 'reverses_movement_id');
    }
}
