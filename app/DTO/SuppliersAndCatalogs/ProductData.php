<?php

namespace App\DTO\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;

final readonly class ProductData
{
    public function __construct(
        public int $id,
        public string $name,
        public ProductKind $kind,
        public BaseUnit $baseUnit,
        public bool $stockTracked,
        public ?string $systemKey = null,
    ) {}
}
