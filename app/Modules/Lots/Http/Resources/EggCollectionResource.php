<?php

namespace App\Modules\Lots\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggCollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['public_id'] ?? null,
            'lote_id' => $data['flock_id'] ?? null,
            'galpon_id' => $data['poultry_house_id'] ?? null,
            'unidad_productiva_id' => $data['production_unit_id'] ?? null,
            'cantidad_recolectada' => $data['collected_quantity'] ?? null,
            'cantidad_descartada' => $data['discarded_quantity'] ?? null,
            'motivo_descarte' => $data['discard_reason'] ?? null,
            'cantidad_utilizable' => $data['usable_quantity'] ?? null,
            'cantidad_perdida_posterior' => $data['post_loss_quantity'] ?? null,
            'cantidad_neta' => $data['net_quantity'] ?? null,
            'lineas' => array_map(static fn (array $line): array => [
                'producto_id' => $line['product_id'] ?? null,
                'ubicacion_stock_id' => $line['stock_location_id'] ?? null,
                'cantidad' => $line['quantity'] ?? null,
            ], $data['lines'] ?? []),
            'producto_id' => $data['product_id'] ?? null,
            'ubicacion_stock_id' => $data['stock_location_id'] ?? null,
            'movimiento_inventario_id' => $data['inventory_movement_id'] ?? null,
            'cantidad' => $data['quantity'] ?? null,
            'ocurrido_en' => $data['occurred_at'] ?? null,
            'observaciones' => $data['notes'] ?? null,
            'estado' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
            'registrado_por' => $data['created_by'] ?? null,
        ];
    }
}
