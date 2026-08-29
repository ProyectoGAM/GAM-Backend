<?php

namespace App\Modules\SuppliersAndCatalogs\Application\PublicApi\Data;

use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;

final readonly class ProductData
{
    public function __construct(
        public int $id,
        public string $name,
        public ProductKind $kind,
        public BaseUnit $baseUnit,
        public bool $stockTracked,
    ) {}
}
