<?php

namespace App\Queries\Lots;

use App\Models\Lots\Breed;
use App\Models\Lots\MortalityCategory;
use App\Models\Lots\WeighingReferenceSettings;
use App\Services\Lots\BreedWeighingReference;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListLotsCatalogQuery
{
    public function __construct(private BreedWeighingReference $references) {}

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

        $settings = $model === Breed::class ? WeighingReferenceSettings::query()->first() : null;

        return $query->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1)
            ->withQueryString()->through(fn (Breed|MortalityCategory $record): array => $record instanceof Breed
                ? $this->references->catalog($record, $settings)
                : $record->only(['id', 'name', 'status', 'version']));
    }
}
