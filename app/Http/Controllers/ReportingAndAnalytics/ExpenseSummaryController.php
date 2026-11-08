<?php

namespace App\Http\Controllers\ReportingAndAnalytics;

use App\Http\Requests\ReportingAndAnalytics\ExpenseSummaryRequest;
use App\Models\User;
use App\Queries\ReportingAndAnalytics\GetExpenseSummaryQuery;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use Illuminate\Http\JsonResponse;

final readonly class ExpenseSummaryController
{
    public function __invoke(ExpenseSummaryRequest $request, GetExpenseSummaryQuery $query, ProductionUnitAccess $access): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $query->execute(
            $actor,
            $request->string('desde')->toString(),
            $request->string('hasta')->toString(),
            $request->integer('up_id') ?: $access->unitId(),
        )]);
    }
}
