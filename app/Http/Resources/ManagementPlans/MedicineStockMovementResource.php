<?php

namespace App\Http\Resources\ManagementPlans;

use App\Models\ManagementPlans\MedicineStockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MedicineStockMovement */
final class MedicineStockMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'medicine' => [
                'id' => $this->medicine_public_id_snapshot,
                'name_at_movement' => $this->medicine_name_snapshot,
            ],
            'movement_type' => $this->movement_type,
            'quantity_delta' => (int) $this->quantity_delta,
            'balance_after' => (int) $this->balance_after,
            'is_negative' => (int) $this->balance_after < 0,
            'stock_warning' => (int) $this->balance_after < 0 ? [
                'code' => 'MEDICINE_STOCK_NEGATIVE',
                'message' => 'El saldo interno de '.$this->medicine_name_snapshot.' quedó en '.(int) $this->balance_after.' unidades.',
            ] : null,
            'reason' => $this->reason,
            'operation_id' => $this->operation_id,
            'created_at' => $this->created_at,
        ];
    }
}
