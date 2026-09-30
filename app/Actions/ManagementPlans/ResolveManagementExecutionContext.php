<?php

namespace App\Actions\ManagementPlans;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\User;
use App\Services\Lots\FlockState;
use App\Services\ManagementPlans\PlanActivityLinker;

final readonly class ResolveManagementExecutionContext
{
    public function __construct(private FlockState $flockState, private PlanActivityLinker $activityLinker) {}

    /** @param array<string, mixed> $attributes */
    public function execute(Flock $flock, User $actor, array $attributes, string $activityType): ManagementExecutionContext
    {
        $lockedFlock = $this->flockState->lock([$flock->public_id])->get($flock->public_id);
        $this->flockState->open($lockedFlock);
        $occurredAt = $this->flockState->time($lockedFlock, $attributes['occurred_at']);
        $responsibleId = (int) ($attributes['responsible_user_id'] ?? $actor->getKey());
        $responsible = User::query()->whereKey($responsibleId)->sharedLock()->first();
        if ($responsible === null) {
            throw new LotsConflict('La persona responsable debe ser un usuario activo.');
        }

        $activityPublicId = $attributes['plan_activity_id'] ?? null;
        $activity = $this->activityLinker->resolve($lockedFlock, $activityPublicId, $activityType);

        return new ManagementExecutionContext(
            flock: $lockedFlock,
            occurredAt: $occurredAt,
            responsibleUserId: $responsible->getKey(),
            responsibleNameSnapshot: $responsible->name,
            planActivityPublicId: $activityPublicId,
            planActivityTitleSnapshot: $activity?->title,
        );
    }
}
