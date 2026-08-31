<?php

namespace App\Modules\Lots\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

abstract readonly class LotsEvent implements ShouldDispatchAfterCommit
{
    /** @param list<string> $flockIds */
    public function __construct(public string $operationId, public array $flockIds, public int $actorId) {}
}
