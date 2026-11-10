<?php

namespace App\Queries\Lots;

use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\Services\Lots\LotsSnapshots;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListMortalityQuery
{
    public function __construct(private LotsSnapshots $snapshots) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters, ?Flock $flock = null): LengthAwarePaginator
    {
        $query = MortalityRecord::query()->with('flock')->forReportingPeriod(
            $filters['date_from'] ?? null, $filters['date_to'] ?? null, $filters['production_unit_id'] ?? null,
        );
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

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1)
            ->withQueryString()->through(fn (MortalityRecord $record): array => $this->snapshots->mortality($record, $record->flock));
    }
}
