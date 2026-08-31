<?php

namespace App\Shared;

use Carbon\CarbonImmutable;

final readonly class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(now())->utc()->startOfSecond();
    }
}
