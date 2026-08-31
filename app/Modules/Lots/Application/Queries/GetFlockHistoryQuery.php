<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class GetFlockHistoryQuery
{
    public function __construct(private LotsSnapshots $snapshots) {}

    /** @param array<string, mixed> $filters */
    public function execute(Flock $flock, array $filters): LengthAwarePaginator
    {
        $query = FlockMovement::query()->with(['sourceFlock', 'destinationFlock', 'reversedMovement'])
            ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id));
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['date_from'], config('lots.timezone'))->startOfDay()->toIso8601String());
        }
        if (isset($filters['date_to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['date_to'], config('lots.timezone'))->addDay()->startOfDay()->toIso8601String());
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 50, ['*'], 'pagina', $filters['page'] ?? 1)
            ->withQueryString()->through(fn (FlockMovement $movement): array => $this->snapshots->movement($movement));
    }
}
