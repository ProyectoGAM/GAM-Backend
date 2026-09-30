<?php

namespace App\Queries\Lots;

use App\Models\Lots\Weighing;
use App\Services\Lots\WeighingPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListWeighingsQuery
{
    public function __construct(private WeighingPresenter $presenter) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = Weighing::query()->with(['flock', 'measurements']);
        if (isset($filters['flock_id'])) {
            $query->whereHas('flock', fn ($flockQuery) => $flockQuery->where('public_id', $filters['flock_id']));
        }
        if (isset($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }
        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['date_from'], config('lots.timezone'))->startOfDay()->utc());
        }
        if (isset($filters['date_to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['date_to'], config('lots.timezone'))->addDay()->startOfDay()->utc());
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1)
            ->withQueryString()
            ->through(fn (Weighing $weighing): array => $this->presenter->weighing($weighing));
    }
}
