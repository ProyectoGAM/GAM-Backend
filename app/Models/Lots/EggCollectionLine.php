<?php

namespace App\Models\Lots;

use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use Database\Factories\Lots\EggCollectionLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clasificación inventariable de una recolección de huevos.
 *
 * @property int $id
 * @property int $egg_collection_id
 * @property int $product_id
 * @property int $stock_location_id
 * @property int $quantity
 */
#[Fillable(['egg_collection_id', 'product_id', 'stock_location_id', 'quantity'])]
class EggCollectionLine extends Model
{
    /** @use HasFactory<EggCollectionLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<EggCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(EggCollection::class, 'egg_collection_id');
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
