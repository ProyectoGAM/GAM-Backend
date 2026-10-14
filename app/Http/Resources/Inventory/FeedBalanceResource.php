<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\FeedBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeedBalance */
final class FeedBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'receta_id' => $this->feed_recipe_id, 'nombre_receta' => $this->recipe->name,
            'estado_receta' => $this->recipe->status->value, 'up_id' => $this->production_unit_id,
            'nombre_up' => $this->productionUnit->name, 'saldo_g' => (string) $this->quantity_grams,
        ];
    }
}
