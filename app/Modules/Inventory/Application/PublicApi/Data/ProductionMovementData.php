<?php

namespace App\Modules\Inventory\Application\PublicApi\Data;

final readonly class ProductionMovementData
{
    public function __construct(public int $id, public string $operationId) {}
}
