<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FeedRecipeCalculationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'receta_id' => $this->resource['recipe']->id,
            'rendimiento_g' => (string) $this->resource['recipe']->yield_grams,
            'cantidad_g' => (string) $this->resource['requested_grams'],
            'factor' => (string) $this->resource['factor'],
            'ingredientes' => array_map(static fn (array $ingredient): array => [
                'producto_id' => $ingredient['product_id'],
                'nombre' => $ingredient['name'],
                'cantidad_base_g' => (string) $ingredient['base_grams'],
                'cantidad_requerida_g' => (string) $ingredient['required_grams'],
            ], $this->resource['ingredients']),
        ];
    }
}
