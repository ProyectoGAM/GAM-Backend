<?php

namespace App\DTO\SuppliersAndCatalogs;

final readonly class SupplierData
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
    ) {}
}
