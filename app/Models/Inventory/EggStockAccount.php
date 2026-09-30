<?php

namespace App\Models\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EggStockAccount extends Model
{
    protected $table = 'egg_stock_accounts';

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
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

    /** @return HasMany<EggStockTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(EggStockTransaction::class);
    }
}
