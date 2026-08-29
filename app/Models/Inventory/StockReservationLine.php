<?php

namespace App\Models\Inventory;

use App\Models\SuppliersAndCatalogs\Product;
use Database\Factories\Inventory\StockReservationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservationLine extends Model
{
    /** @use HasFactory<StockReservationLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reserved_quantity' => 'decimal:6',
            'released_quantity' => 'decimal:6',
            'consumed_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<StockReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
