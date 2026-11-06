<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\SetFeedCoverageThresholdsAction;
use App\Http\Requests\Inventory\ShowFeedCoverageThresholdsRequest;
use App\Http\Requests\Inventory\UpdateFeedCoverageThresholdsRequest;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Http\JsonResponse;

final class FeedCoverageThresholdController
{
    public function show(ShowFeedCoverageThresholdsRequest $request, ProductionUnit $productionUnit): JsonResponse
    {
        return $this->response($productionUnit);
    }

    public function update(
        UpdateFeedCoverageThresholdsRequest $request,
        ProductionUnit $productionUnit,
        SetFeedCoverageThresholdsAction $action,
    ): JsonResponse {
        return $this->response($action->execute(
            $productionUnit->id,
            (int) $request->validated('dias_aviso'),
            (int) $request->validated('dias_urgencia'),
            $request->actor(),
        ));
    }

    private function response(ProductionUnit $unit): JsonResponse
    {
        return response()->json(['data' => [
            'unidad_productiva_id' => $unit->id,
            'dias_aviso' => $unit->feed_coverage_warning_days,
            'dias_urgencia' => $unit->feed_coverage_urgent_days,
        ]]);
    }
}
