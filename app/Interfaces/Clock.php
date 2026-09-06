<?php

namespace App\Interfaces;

use Carbon\CarbonImmutable;

interface Clock
{
    public function now(): CarbonImmutable;
}
