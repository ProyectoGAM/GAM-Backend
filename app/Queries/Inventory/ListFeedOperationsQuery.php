<?php

namespace App\Queries\Inventory;

use App\Models\Inventory\FeedStockOperation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListFeedOperationsQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, FeedStockOperation>
     */
    public function execute(array $filters, bool $adjustments = false): LengthAwarePaginator
    {
        $query = FeedStockOperation::query()->with('cancellation')->whereIn('kind',
            $adjustments ? ['ingredient_adjustment', 'feed_adjustment'] : ['manufacture']);
        foreach (['receta_id' => 'feed_recipe_id', 'up_id' => 'production_unit_id', 'planta_id' => 'poultry_house_id', 'producto_id' => 'product_id'] as $public => $column) {
            if (isset($filters[$public])) {
                $query->where($column, $filters[$public]);
            }
        }
        if (isset($filters['estado'])) {
            $filters['estado'] === 'cancelada' ? $query->whereHas('cancellation') : $query->whereDoesntHave('cancellation');
        }
        if (isset($filters['tipo'])) {
            $query->where('kind', $filters['tipo'] === 'ingrediente' ? 'ingredient_adjustment' : 'feed_adjustment');
        }

        return $query->orderByDesc('id')->paginate((int) ($filters['por_pagina'] ?? 25))->withQueryString();
    }
}
