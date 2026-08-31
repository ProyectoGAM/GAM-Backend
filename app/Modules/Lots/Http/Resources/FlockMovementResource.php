<?php

namespace App\Modules\Lots\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['public_id'] ?? null,
            'id_operacion' => $data['operation_id'] ?? null,
            'tipo' => $data['type'] ?? null,
            'cantidad' => $data['quantity'] ?? null,
            'lote_origen_id' => $data['source_flock_id'] ?? null,
            'lote_destino_id' => $data['destination_flock_id'] ?? null,
            'movimiento_revertido_id' => $data['reverses_movement_id'] ?? null,
            'galpon_origen_id' => $data['source_poultry_house_id'] ?? null,
            'galpon_destino_id' => $data['destination_poultry_house_id'] ?? null,
            'ocurrido_en' => $data['occurred_at'] ?? null,
            'registrado_en' => $data['created_at'] ?? null,
            'motivo' => $data['reason'] ?? null,
            'registrado_por' => $data['created_by'] ?? null,
            'antes' => (object) array_map(fn (array $flock): array => (new FlockResource($flock))->resolve($request), $data['before']),
            'despues' => (object) array_map(fn (array $flock): array => (new FlockResource($flock))->resolve($request), $data['after']),
        ];
    }
}
