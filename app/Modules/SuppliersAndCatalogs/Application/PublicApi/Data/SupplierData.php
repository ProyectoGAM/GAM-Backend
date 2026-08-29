<?php

namespace App\Modules\SuppliersAndCatalogs\Application\PublicApi\Data;

final readonly class SupplierData
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
    ) {}
}
