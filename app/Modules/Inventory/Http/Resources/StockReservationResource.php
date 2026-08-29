<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Inventory\StockReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockReservation */
final class StockReservationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'status' => $this->status->value,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'lines' => StockReservationLineResource::collection($this->whenLoaded('lines')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
