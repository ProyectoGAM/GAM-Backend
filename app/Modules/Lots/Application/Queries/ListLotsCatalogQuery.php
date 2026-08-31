<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Lots\Breed;
use App\Models\Lots\MortalityCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListLotsCatalogQuery
{
    /**
     * @param  class-string<Breed>|class-string<MortalityCategory>  $model
     * @param  array<string, mixed>  $filters
     */
    public function execute(string $model, array $filters): LengthAwarePaginator
    {
        $query = $model::query();
        if (isset($filters['search'])) {
            $query->where('name', 'ilike', '%'.$filters['search'].'%');
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 50, ['*'], 'pagina', $filters['page'] ?? 1)
            ->withQueryString()->through(fn (Breed|MortalityCategory $record): array => $record->only(['id', 'name', 'status', 'version']));
    }
}
