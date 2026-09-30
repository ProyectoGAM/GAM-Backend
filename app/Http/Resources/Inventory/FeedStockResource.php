<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\SuppliersAndCatalogs\ProductResource;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FeedStockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if (! is_array($this->resource)) {
            return [];
        }

        if (array_key_exists('items', $this->resource)) {
            return [
                'scope' => $this->resource['scope'],
                'scope_id' => $this->resource['scope_id'],
                'items' => array_map(static fn (array $item): array => [
                    'product_id' => (int) $item['product_id'],
                    'product' => ProductResource::make($item['product']),
                    'total_g' => (string) $item['total_g'],
                    'is_negative' => (bool) $item['is_negative'],
                    'details' => $item['details'],
                ], $this->resource['items']),
            ];
        }

        /** @var array{product:Product, movement:InventoryMovement, stock:StockBalance} $result */
        $result = $this->resource;

        return [
            'product' => ProductResource::make($result['product']),
            'movement' => InventoryMovementResource::make($result['movement']),
            'stock' => StockBalanceResource::make($result['stock']),
        ];
    }
}
