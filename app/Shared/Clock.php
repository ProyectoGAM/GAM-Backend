<?php

namespace App\Shared;

use Carbon\CarbonImmutable;

interface Clock
{
    public function now(): CarbonImmutable;
}
