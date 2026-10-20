<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\FeedStockOperation;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeedStockOperation */
final class FeedStockOperationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot;
        $ingredient = $this->kind === 'ingredient_adjustment';
        $quantity = fn (string $value): string => (string) BigDecimal::of($value)->toScale($ingredient ? 6 : 0);

        return [
            'id' => $this->id, 'operacion_id' => $this->operation_id,
            'tipo' => match ($this->kind) {
                'manufacture' => 'elaboracion', 'cancellation' => 'cancelacion',
                'ingredient_adjustment' => 'ajuste_ingrediente', 'feed_adjustment' => 'ajuste_alimento',
            },
            'receta_id' => $this->feed_recipe_id, 'up_id' => $this->production_unit_id,
            'planta_id' => $this->poultry_house_id, 'producto_id' => $this->product_id,
            'cantidad_g' => (string) $this->quantity_grams,
            'factor' => $this->factor === null ? null : (string) $this->factor,
            'accion' => $this->adjustment_mode, 'motivo' => $this->reason,
            'saldo_anterior_g' => $quantity($this->balance_before), 'saldo_resultante_g' => $quantity($this->balance_after),
            'delta_g' => $quantity($this->delta_grams), 'movimiento_id' => $this->inventory_movement_id,
            'cancela_id' => $this->cancels_operation_id, 'actor_id' => $this->created_by,
            'registrado_en' => $this->occurred_at->toIso8601String(),
            'nombre_receta' => $snapshot['recipe_name'] ?? null, 'nombre_planta' => $snapshot['plant_name'] ?? null,
            'nombre_producto' => $snapshot['product_name'] ?? null, 'sku' => $snapshot['sku'] ?? null,
            'rendimiento_g' => $snapshot['yield_grams'] ?? null,
            'ingredientes' => array_map(fn (array $item): array => [
                'producto_id' => $item['product_id'], 'nombre' => $item['name'], 'sku' => $item['sku'],
                'cantidad_base_g' => $item['base_quantity_grams'], 'cantidad_requerida_g' => $item['required_quantity_grams'],
            ], $snapshot['ingredients'] ?? []),
            'distribucion' => array_map(fn (array $item): array => [
                'producto_id' => $item['product_id'], 'planta_id' => $item['poultry_house_id'],
                'nombre_planta' => $item['plant_name'], 'ubicacion_id' => $item['stock_location_id'],
                'cantidad_g' => $item['quantity_grams'], 'linea_movimiento_id' => $item['movement_line_id'],
                'saldo_anterior_g' => $item['balance_before'], 'saldo_resultante_g' => $item['balance_after'],
            ], $snapshot['distribution'] ?? []),
            'estado' => $this->kind === 'manufacture' ? ($this->cancellation === null ? 'elaborada' : 'cancelada') : null,
            'cancelacion' => $this->whenLoaded('cancellation', fn () => $this->cancellation === null ? null : new self($this->cancellation)),
        ];
    }
}
