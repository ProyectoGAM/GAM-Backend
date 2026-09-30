<?php

namespace App\Http\Resources\ManagementPlans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MedicineStockResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'medicine' => ['id' => $this['id'], 'name' => $this['name']],
            'quantity' => $this['quantity'],
            'version' => $this['version'],
            'is_negative' => $this['is_negative'],
            'stock_warning' => $this['stock_warning'],
        ];
    }
}
