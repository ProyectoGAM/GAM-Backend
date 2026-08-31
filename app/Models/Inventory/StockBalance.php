<?php

namespace App\Models\Inventory;

use App\Models\SuppliersAndCatalogs\Product;
use Database\Factories\Inventory\StockBalanceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    /** @use HasFactory<StockBalanceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'on_hand_quantity' => 'decimal:6',
            'minimum_quantity' => 'decimal:6',
        ];
    }

    /** El stock disponible es el saldo único proyectado. */
    protected function availableQuantity(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => (string) ($attributes['on_hand_quantity'] ?? '0.000000'),
        );
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
