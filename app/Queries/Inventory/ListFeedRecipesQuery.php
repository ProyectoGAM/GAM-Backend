<?php

namespace App\Queries\Inventory;

use App\Models\Inventory\FeedRecipe;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListFeedRecipesQuery
{
    /**
     * @param  array{buscar?: string, estado?: string, por_pagina?: int, page?: int}  $filters
     * @return LengthAwarePaginator<int, FeedRecipe>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = FeedRecipe::query()->with('ingredients.product');
        if (isset($filters['estado'])) {
            $query->where('status', $filters['estado']);
        }
        if (isset($filters['buscar'])) {
            $query->where('name', 'ilike', '%'.$filters['buscar'].'%');
        }

        return $query->orderByDesc('id')->paginate((int) ($filters['por_pagina'] ?? 25))->withQueryString();
    }
}
