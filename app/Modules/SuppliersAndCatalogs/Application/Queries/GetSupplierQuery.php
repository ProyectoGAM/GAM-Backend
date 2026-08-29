<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Queries;

use App\Models\SuppliersAndCatalogs\Supplier;

final readonly class GetSupplierQuery
{
    public function execute(int $supplierId): Supplier
    {
        return Supplier::query()
            ->with('locality.department')
            ->whereKey($supplierId)
            ->firstOrFail();
    }
}
