<?php

namespace App\Actions\ManagementPlans;

use App\Models\ManagementPlans\MedicineStockBalance;
use App\Models\SuppliersAndCatalogs\Medicine;

final readonly class GetMedicineStockAction
{
    /** @return array{id: string, name: string, quantity: int, version: int|null, is_negative: bool, stock_warning: array{code: string, message: string}|null} */
    public function execute(Medicine $medicine): array
    {
        $balance = MedicineStockBalance::query()->where('medicine_id', $medicine->getKey())->first();
        $quantity = $balance === null ? 0 : (int) $balance->on_hand_quantity;

        return [
            'id' => $medicine->public_id,
            'name' => $medicine->name,
            'quantity' => $quantity,
            'version' => $balance === null ? null : (int) $balance->version,
            'is_negative' => $quantity < 0,
            'stock_warning' => $quantity < 0 ? [
                'code' => 'MEDICINE_STOCK_NEGATIVE',
                'message' => 'El saldo interno de '.$medicine->name.' es de '.$quantity.' unidades.',
            ] : null,
        ];
    }
}
