<?php

namespace App\Modules\Inventory\Application\PublicApi\Queries;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListEggStockTransactionsQuery
{
    public function execute(ProductionUnit $unit, array $filters): LengthAwarePaginator
    {
        $query = EggStockTransaction::query()->where('production_unit_id', $unit->getKey())->with('revisions');
        foreach (['status', 'type'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
        }
        if (isset($filters['date_to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['date_to'])->addDay()->startOfDay());
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 50, ['*'], 'pagina', $filters['page'] ?? 1)->withQueryString();
    }
}
