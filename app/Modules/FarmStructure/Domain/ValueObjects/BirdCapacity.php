<?php

namespace App\Modules\FarmStructure\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class BirdCapacity
{
    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('La capacidad de aves debe ser mayor que cero.');
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function supportsOccupancy(int $occupancy): bool
    {
        return $occupancy >= 0 && $occupancy <= $this->value;
    }

    public function availableFor(int $occupancy): int
    {
        if (! $this->supportsOccupancy($occupancy)) {
            throw new InvalidArgumentException('La ocupación debe estar entre cero y la capacidad de aves.');
        }

        return $this->value - $occupancy;
    }
}
