<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeedRecipe */
final class FeedRecipeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'nombre' => $this->name,
            'nota' => $this->notes,
            'rendimiento_g' => (string) $this->yield_grams,
            'estado' => $this->status->value,
            'ingredientes' => $this->whenLoaded('ingredients', fn (): array => $this->ingredients->map(fn (FeedRecipeIngredient $ingredient): array => [
                'producto_id' => $ingredient->product_id,
                'nombre' => $ingredient->product->name,
                'sku' => $ingredient->product->sku,
                'cantidad_g' => (string) $ingredient->quantity_grams,
            ])->all()),
            'creado_en' => $this->created_at,
            'actualizado_en' => $this->updated_at,
        ];
    }
}
