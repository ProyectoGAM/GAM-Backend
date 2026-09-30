<?php

namespace App\Services;

use App\Interfaces\Clock;
use Carbon\CarbonImmutable;

final readonly class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(now())->utc()->startOfSecond();
    }
}
