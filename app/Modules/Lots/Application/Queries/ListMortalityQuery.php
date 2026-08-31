<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListMortalityQuery
{
    public function __construct(private LotsSnapshots $snapshots) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters, ?Flock $flock = null): LengthAwarePaginator
    {
        $query = MortalityRecord::query()->with('flock');
        if ($flock !== null) {
            $query->where('flock_id', $flock->id);
        }
        if (isset($filters['flock_id'])) {
            $query->whereHas('flock', fn ($query) => $query->where('public_id', $filters['flock_id']));
        }
        foreach (['poultry_house_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['date_from'], config('lots.timezone'))->startOfDay()->toIso8601String());
        }
        if (isset($filters['date_to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['date_to'], config('lots.timezone'))->addDay()->startOfDay()->toIso8601String());
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 50, ['*'], 'pagina', $filters['page'] ?? 1)
            ->withQueryString()->through(fn (MortalityRecord $record): array => $this->snapshots->mortality($record, $record->flock));
    }
}
