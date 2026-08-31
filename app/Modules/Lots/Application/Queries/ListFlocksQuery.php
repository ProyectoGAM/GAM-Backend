<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Lots\Flock;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListFlocksQuery
{
    public function __construct(private LotsSnapshots $snapshots) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = Flock::query();
        foreach (['poultry_house_id', 'production_unit_id', 'breed_id', 'supplier_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['search'])) {
            $query->where('code', 'ilike', '%'.$filters['search'].'%');
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('entry_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('entry_date', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'pagina', $filters['page'] ?? 1)
            ->withQueryString()->through(fn (Flock $flock): array => $this->snapshots->flock($flock));
    }
}
