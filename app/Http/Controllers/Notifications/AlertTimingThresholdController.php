<?php

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\SetAlertTimingThresholdsAction;
use App\Enums\Notifications\AlertTimingType;
use App\Http\Requests\Notifications\ShowAlertTimingThresholdsRequest;
use App\Http\Requests\Notifications\UpdateAlertTimingThresholdsRequest;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Http\JsonResponse;

final class AlertTimingThresholdController
{
    public function show(ShowAlertTimingThresholdsRequest $request, ProductionUnit $productionUnit, AlertTimingType $alertType): JsonResponse
    {
        return $this->response($productionUnit, $alertType);
    }

    public function update(
        UpdateAlertTimingThresholdsRequest $request,
        ProductionUnit $productionUnit,
        AlertTimingType $alertType,
        SetAlertTimingThresholdsAction $action,
    ): JsonResponse {
        return $this->response($action->execute($productionUnit->id, $alertType, $request->thresholds(), $request->actor()), $alertType);
    }

    private function response(ProductionUnit $unit, AlertTimingType $type): JsonResponse
    {
        return response()->json(['data' => [
            'unidad_productiva_id' => $unit->id,
            'tipo' => $type->value,
            ...$type->thresholds($unit)->toArray(),
        ]]);
    }
}
