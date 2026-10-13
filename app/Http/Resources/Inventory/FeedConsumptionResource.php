<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\FeedConsumption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeedConsumption */
class FeedConsumptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $effective = $this->resource->effective();

        return [
            'id' => $this->id, 'fecha' => $this->consumption_date->toDateString(),
            'up_id' => $this->production_unit_id, 'modo' => $this->mode === 'manual' ? 'manual' : 'automatico',
            'edad_dias' => $this->age_days, 'poblacion' => $this->population,
            'receta_id' => $effective['recipe_id'], 'cantidad_g' => (string) $effective['quantity_grams'],
            'original' => ['receta_id' => $this->feed_recipe_id, 'cantidad_g' => (string) $this->quantity_grams],
            'gramos_por_ave' => (string) $this->grams_per_bird, 'etapa' => $this->stage_snapshot,
            'censo' => $this->census_snapshot, 'operation_id' => $this->operation_id,
            'created_at' => $this->created_at->toIso8601String(),
            'rectificaciones' => $this->whenLoaded('corrections', fn (): array => $this->corrections->map(fn ($correction): array => [
                'id' => $correction->id, 'receta_id' => $correction->feed_recipe_id,
                'cantidad_g' => (string) $correction->quantity_grams, 'motivo' => $correction->reason,
                'anterior' => $correction->before_snapshot, 'operation_id' => $correction->operation_id,
                'created_by' => $correction->created_by, 'created_at' => $correction->created_at->toIso8601String(),
            ])->all()),
            'movimientos' => $this->whenLoaded('movements', fn (): array => $this->movements->map(fn ($movement): array => [
                'id' => $movement->id, 'receta_id' => $movement->feed_recipe_id,
                'delta_g' => (string) $movement->delta_grams, 'saldo_anterior_g' => (string) $movement->balance_before,
                'saldo_posterior_g' => (string) $movement->balance_after, 'operation_id' => $movement->operation_id,
                'rectificacion_id' => $movement->feed_consumption_correction_id,
            ])->all()),
        ];
    }
}
