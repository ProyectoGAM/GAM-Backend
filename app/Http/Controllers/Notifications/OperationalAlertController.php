<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Requests\Notifications\ListOperationalAlertsRequest;
use App\Interfaces\Clock;
use App\Models\Notifications\OperationalAlert;
use App\Models\User;
use App\Queries\Notifications\ListOperationalAlertsQuery;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

final readonly class OperationalAlertController
{
    public function index(ListOperationalAlertsRequest $request, ListOperationalAlertsQuery $query, ProductionUnitAccess $access): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();
        $alerts = $query->execute(
            $actor,
            $request->integer('up_id') ?: $access->unitId(),
            $request->validated('urgencia'),
            $request->integer('per_page', 20),
        );

        return JsonResource::collection($alerts->through(fn (OperationalAlert $alert): array => [
            'id' => $alert->public_id,
            'tipo' => $alert->type->value,
            'modulo' => $alert->type->module(),
            'up_id' => $alert->production_unit_id,
            'urgencia' => $alert->urgency->value,
            'titulo' => $alert->title,
            'ocurrida_en' => $alert->occurred_at->toIso8601String(),
            'entidad' => ['tipo' => $alert->entity_type, 'id' => $alert->entity_id, 'ruta' => $alert->target_path],
        ]));
    }

    public function summary(ListOperationalAlertsRequest $request, ListOperationalAlertsQuery $query, ProductionUnitAccess $access, Clock $clock): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $unitId = $request->integer('up_id') ?: $access->unitId();
        $counts = $query->counts($actor, $unitId, $request->validated('urgencia'));

        return response()->json(['data' => [
            'up_id' => $unitId,
            'consultado_en' => $clock->now()->toIso8601String(),
            'total' => array_sum($counts),
            'por_urgencia' => $counts,
        ]]);
    }
}
