<?php

namespace App\Modules\Lots\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggCollectionLossResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['id'] ?? null,
            'id_operacion' => $data['operation_id'] ?? null,
            'tipo' => $data['type'] ?? 'loss',
            'tipo_referencia' => $data['reference_type'] ?? null,
            'referencia_id' => $data['reference_id'] ?? null,
            'motivo' => $data['reason'] ?? null,
            'ocurrido_en' => $data['occurred_at'] ?? null,
            'creado_por' => $data['created_by'] ?? null,
            'revierte_movimiento_id' => $data['reverses_movement_id'] ?? null,
            'lineas' => array_map(static fn (array $line): array => [
                'producto_id' => $line['product_id'] ?? null,
                'ubicacion_stock_id' => $line['stock_location_id'] ?? null,
                'cantidad' => abs((int) ($line['on_hand_delta'] ?? $line['quantity'] ?? 0)),
            ], $data['lines'] ?? []),
        ];
    }
}
