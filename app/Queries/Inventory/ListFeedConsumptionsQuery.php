<?php

namespace App\Queries\Inventory;

use App\Models\Inventory\FeedConsumption;
use App\Models\Lots\Flock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListFeedConsumptionsQuery
{
    /** @param array<string, mixed> $filters @return array{consumptions:LengthAwarePaginator<int, FeedConsumption>, accumulated_grams:string} */
    public function execute(Flock $flock, array $filters): array
    {
        $query = FeedConsumption::query()->where('flock_id', $flock->id)
            ->when(isset($filters['desde']), fn ($query) => $query->where('consumption_date', '>=', $filters['desde']))
            ->when(isset($filters['hasta']), fn ($query) => $query->where('consumption_date', '<=', $filters['hasta']));
        $total = (clone $query)->selectRaw('COALESCE(SUM(COALESCE((SELECT quantity_grams FROM feed_consumption_corrections WHERE feed_consumption_id = feed_consumptions.id ORDER BY id DESC LIMIT 1), quantity_grams)), 0)::text AS total')->value('total');
        $paginator = $query->with('latestCorrection')->orderByDesc('consumption_date')->orderByDesc('id')->paginate((int) ($filters['por_pagina'] ?? 25));

        return ['consumptions' => $paginator, 'accumulated_grams' => (string) $total];
    }
}
