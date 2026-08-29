<?php

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Models\Inventory\InventoryMovement;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InventoryIdempotentReplay extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly InventoryMovement $movement)
    {
        parent::__construct('La operación ya fue procesada.');
    }
}
