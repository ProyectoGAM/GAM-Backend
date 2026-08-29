<?php

namespace Tests\Unit\FarmStructure;

use App\Modules\FarmStructure\Domain\ValueObjects\BirdCapacity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BirdCapacityTest extends TestCase
{
    public function test_returns_available_capacity_for_valid_occupancy(): void
    {
        $capacity = BirdCapacity::fromInt(100);

        $this->assertSame(100, $capacity->value());
        $this->assertTrue($capacity->supportsOccupancy(75));
        $this->assertSame(25, $capacity->availableFor(75));
    }

    public function test_rejects_capacity_that_is_not_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La capacidad de aves debe ser mayor que cero.');

        BirdCapacity::fromInt(0);
    }

    public function test_rejects_occupancy_higher_than_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La ocupación debe estar entre cero y la capacidad de aves.');

        BirdCapacity::fromInt(100)->availableFor(101);
    }
}
