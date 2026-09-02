<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockTransaction;
use App\Modules\Inventory\Application\PublicApi\Actions\CancelEggStockTransactionAction;
use App\Modules\Inventory\Application\PublicApi\Actions\CorrectEggStockTransactionAction;
use App\Modules\Inventory\Application\PublicApi\Actions\RecordManualEggStockAction;
use App\Modules\Inventory\Application\PublicApi\Queries\GetEggStockBalanceQuery;
use App\Modules\Inventory\Application\PublicApi\Queries\GetEggStockTransactionQuery;
use App\Modules\Inventory\Application\PublicApi\Queries\ListEggStockTransactionsQuery;
use App\Modules\Inventory\Http\Requests\CancelEggStockTransactionRequest;
use App\Modules\Inventory\Http\Requests\CorrectEggStockTransactionRequest;
use App\Modules\Inventory\Http\Requests\ListEggStockTransactionsRequest;
use App\Modules\Inventory\Http\Requests\StoreEggStockIssueRequest;
use App\Modules\Inventory\Http\Requests\StoreEggStockReceiptRequest;
use App\Modules\Inventory\Http\Requests\ViewEggStockRequest;
use App\Modules\Inventory\Http\Resources\EggStockTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class EggStockController
{
    public function balance(ViewEggStockRequest $request, ProductionUnit $unidadProductiva, GetEggStockBalanceQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute($unidadProductiva)]);
    }

    public function index(ListEggStockTransactionsRequest $request, ProductionUnit $unidadProductiva, ListEggStockTransactionsQuery $query): AnonymousResourceCollection
    {
        return EggStockTransactionResource::collection($query->execute($unidadProductiva, $request->attributesForAction()));
    }

    public function show(ViewEggStockRequest $request, EggStockTransaction $movimiento, GetEggStockTransactionQuery $query): EggStockTransactionResource
    {
        return new EggStockTransactionResource($query->execute($movimiento));
    }

    public function receipt(StoreEggStockReceiptRequest $request, ProductionUnit $unidadProductiva, RecordManualEggStockAction $action): JsonResponse
    {
        $result = $action->execute($unidadProductiva, $request->attributesForAction(), $request->actor());

        return response()->json(['data' => $result], 201);
    }

    public function issue(StoreEggStockIssueRequest $request, ProductionUnit $unidadProductiva, RecordManualEggStockAction $action): JsonResponse
    {
        $data = $request->attributesForAction();
        $result = $action->execute($unidadProductiva, $data, $request->actor(), -1, (string) $data['type']);

        return response()->json(['data' => $result], 201);
    }

    public function update(CorrectEggStockTransactionRequest $request, EggStockTransaction $movimiento, CorrectEggStockTransactionAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($movimiento, $request->attributesForAction(), $request->actor())]);
    }

    public function cancel(CancelEggStockTransactionRequest $request, EggStockTransaction $movimiento, CancelEggStockTransactionAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($movimiento, $request->attributesForAction(), $request->actor())]);
    }
}
