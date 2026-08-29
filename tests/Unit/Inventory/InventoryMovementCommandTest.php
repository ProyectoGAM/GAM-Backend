<?php

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use PHPUnit\Framework\TestCase;

final class InventoryMovementCommandTest extends TestCase
{
    public function test_hash_is_stable_when_lines_arrive_in_a_different_order(): void
    {
        $first = new InventoryMovementCommand(
            type: InventoryMovementType::Receipt,
            operationId: '00000000-0000-0000-0000-000000000001',
            lines: [
                ['product_id' => 2, 'stock_location_id' => 4, 'on_hand_delta' => '1.000000', 'reserved_delta' => '0'],
                ['product_id' => 1, 'stock_location_id' => 3, 'on_hand_delta' => '2.000000', 'reserved_delta' => '0'],
            ],
        );
        $second = new InventoryMovementCommand(
            type: InventoryMovementType::Receipt,
            operationId: '00000000-0000-0000-0000-000000000001',
            lines: array_reverse($first->lines),
        );

        $this->assertSame($first->requestHash(), $second->requestHash());
    }
}
