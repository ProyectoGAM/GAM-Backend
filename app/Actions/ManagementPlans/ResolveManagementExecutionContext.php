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

        if (in_array($activityType, ['medication', 'vaccination'], true)) {
            if (isset($attributes['quantity']) || isset($attributes['stock_consumption'])) {
                throw new LotsConflict('Las aplicaciones sanitarias se registran sin cantidad aplicada ni consumo de stock.');
            }
            $reason = trim($attributes['reason'] ?? '');
            if ($activity === null && $reason === '') {
                throw new LotsConflict('Debes indicar un motivo cuando la aplicación no está prevista en el plan de manejo.');
            }
            if (mb_strlen($reason) > 500) {
                throw new LotsConflict('El motivo no puede superar los 500 caracteres.');
            }
            if ($activity?->catalog_id !== null) {
                $catalogType = $activityType === 'medication' ? 'medicine' : 'vaccine';
                if ($activity->catalog_type !== $catalogType
                    || ($activity->catalog_snapshot['id'] ?? null) !== ($attributes[$catalogType.'_id'] ?? null)) {
                    throw new LotsConflict('El medicamento o la vacuna no coincide con la actividad prevista en el plan de manejo.');
                }
            }
        }

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
