<?php

namespace App\Http\Resources\ManagementPlans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FeedSupplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->public_id, 'product' => $this->product_name_snapshot, 'quantity' => $this->quantity,
            'unit' => $this->unit, 'occurred_at' => $this->occurred_at, 'status' => $this->status, 'notes' => $this->notes];
    }
}
