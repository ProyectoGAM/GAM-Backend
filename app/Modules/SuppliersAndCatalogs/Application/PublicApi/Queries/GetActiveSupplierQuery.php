<?php

namespace App\Modules\SuppliersAndCatalogs\Application\PublicApi\Queries;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Modules\SuppliersAndCatalogs\Application\PublicApi\Data\SupplierData;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;

final readonly class GetActiveSupplierQuery
{
    public function execute(int $supplierId): ?SupplierData
    {
        $supplier = Supplier::query()
            ->whereKey($supplierId)
            ->where('status', SupplierStatus::Active->value)
            ->first();

        if ($supplier === null) {
            return null;
        }

        return new SupplierData(
            id: (int) $supplier->getKey(),
            name: $supplier->name,
            active: true,
        );
    }
}
