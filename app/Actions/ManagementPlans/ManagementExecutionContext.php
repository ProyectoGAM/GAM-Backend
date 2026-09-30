<?php

namespace App\Actions\ManagementPlans;

use App\Models\Lots\Flock;
use Carbon\CarbonImmutable;

final readonly class ManagementExecutionContext
{
    public function __construct(
        public Flock $flock,
        public CarbonImmutable $occurredAt,
        public int $responsibleUserId,
        public string $responsibleNameSnapshot,
        public ?string $planActivityPublicId,
        public ?string $planActivityTitleSnapshot,
    ) {}
}
