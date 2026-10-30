<?php

namespace App\Actions\ManagementPlans;

use App\Exceptions\Lots\LotsConflict;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class AdjustMedicineStockAction
{
    /** @param array{quantity_delta: int, reason: string, idempotency_key: string} $attributes */
    public function execute(Medicine $medicine, array $attributes, User $actor, string $source = 'api'): never
    {
        LotsAuthorization::ensure($actor, 'management-plans.stock.manage');

        throw new LotsConflict('El control de stock de medicamentos está retirado. Registra las aplicaciones sin cantidad ni ajustes de saldo.');
    }
}
