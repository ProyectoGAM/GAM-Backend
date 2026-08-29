<?php

namespace Tests\Unit\FarmStructure;

use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use PHPUnit\Framework\TestCase;

final class PoultryHouseStatusTest extends TestCase
{
    public function test_allows_operational_house_to_enter_maintenance(): void
    {
        $this->assertTrue(
            PoultryHouseStatus::Operational->canTransitionTo(PoultryHouseStatus::Maintenance),
        );
    }

    public function test_rejects_duplicate_status_transition(): void
    {
        $this->assertFalse(
            PoultryHouseStatus::Maintenance->canTransitionTo(PoultryHouseStatus::Maintenance),
        );
    }

    public function test_inactive_house_can_only_return_to_operational_status(): void
    {
        $this->assertTrue(
            PoultryHouseStatus::Inactive->canTransitionTo(PoultryHouseStatus::Operational),
        );
        $this->assertFalse(
            PoultryHouseStatus::Inactive->canTransitionTo(PoultryHouseStatus::Maintenance),
        );
    }
}
