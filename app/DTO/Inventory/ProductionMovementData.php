<?php

namespace App\DTO\Inventory;

final readonly class ProductionMovementData
{
    public function __construct(public int $id, public string $operationId) {}
}
