<?php

namespace App\Services\ManagementPlans;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlanActivity;

final readonly class PlanActivityLinker
{
    public function resolve(Flock $flock, ?string $activityPublicId, string $expectedType): ?FlockPlanActivity
    {
        if ($activityPublicId === null) {
            return null;
        }
        $activity = FlockPlanActivity::query()->with('revision.plan')
            ->where('public_id', $activityPublicId)->where('type', $expectedType)->first();
        if ($activity === null
            || $activity->revision->plan->flock_id !== $flock->id
            || $activity->revision->number !== $activity->revision->plan->current_revision) {
            throw new LotsConflict('La actividad prevista no pertenece al plan vigente del lote o no corresponde al manejo.');
        }

        return $activity;
    }
}
