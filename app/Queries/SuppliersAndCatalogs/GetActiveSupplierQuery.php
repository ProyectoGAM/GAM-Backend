<?php

namespace App\Queries\SuppliersAndCatalogs;

use App\DTO\SuppliersAndCatalogs\SupplierData;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Models\SuppliersAndCatalogs\Supplier;

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
