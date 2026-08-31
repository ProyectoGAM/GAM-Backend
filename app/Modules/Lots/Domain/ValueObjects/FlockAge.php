<?php

namespace App\Modules\Lots\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class FlockAge
{
    private function __construct(public int $days, public int $week) {}

    public static function on(string $entryDate, CarbonImmutable $now, string $timezone): self
    {
        $entry = CarbonImmutable::createFromFormat('!Y-m-d', $entryDate, $timezone);
        if ($entry === null || $entry->format('Y-m-d') !== $entryDate) {
            throw new InvalidArgumentException('La fecha de ingreso no es válida.');
        }
        $days = (int) $entry->diffInDays($now->setTimezone($timezone)->startOfDay(), false);
        if ($days < 0) {
            throw new InvalidArgumentException('La fecha de ingreso no puede ser futura.');
        }

        return new self($days, intdiv($days, 7) + 1);
    }
}
