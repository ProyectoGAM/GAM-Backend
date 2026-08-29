<?php

namespace App\Models\Inventory;

use App\Models\User;
use App\Modules\Inventory\Domain\Enums\StockReservationStatus;
use Database\Factories\Inventory\StockReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => StockReservationStatus::class];
    }

    /** @return HasMany<StockReservationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockReservationLine::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'stock_reservation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
