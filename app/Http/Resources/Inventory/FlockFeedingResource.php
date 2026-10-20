<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\FlockFeedingChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlockFeedingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof FlockFeedingChange) {
            return $this->resource;
        }
        $change = $this->resource;

        return [
            'id' => $change->id, 'modo' => $change->mode === 'manual' ? 'manual' : 'automatico',
            'receta_id' => $change->feed_recipe_id, 'cantidad_manual_g' => $change->daily_quantity_grams === null ? null : (string) $change->daily_quantity_grams,
            'gramos_por_ave' => (string) $change->grams_per_bird, 'fecha_vigencia' => $change->effective_date->toDateString(),
            'etapa' => $change->stage_snapshot, 'etapas_copiadas' => $change->stages,
            'revision_plan_id' => $change->flock_plan_revision_id, 'operation_id' => $change->operation_id,
            'created_by' => $change->created_by, 'created_at' => $change->created_at->toIso8601String(),
        ];
    }
}
