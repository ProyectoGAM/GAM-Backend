<?php

namespace App\Events\Lots;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class WeighingReferenceSettingsSaved implements ShouldDispatchAfterCommit
{
    public function __construct(public string $operationId, public int $actorId) {}
}
