<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\RecordManualEggStockAction;
use App\Http\Requests\Inventory\ListEggDonationUnitsRequest;
use App\Http\Requests\Inventory\StoreEggDonationRequest;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Http\JsonResponse;

final readonly class EggDonationController
{
    public function units(ListEggDonationUnitsRequest $request): JsonResponse
    {
        $query = ProductionUnit::query()->where('status', 'active');
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->validated('search').'%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(50, ['id', 'name']);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(StoreEggDonationRequest $request, ProductionUnit $productionUnit, RecordManualEggStockAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($productionUnit, $request->attributesForAction(), $request->actor(), -1, 'donation')], 201);
    }
}
