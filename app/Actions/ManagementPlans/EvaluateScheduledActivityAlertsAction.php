<?php

namespace App\Actions\ManagementPlans;

use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\ManagementPlans\ScheduledActivityType;
use App\Enums\Notifications\AlertTimingType;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Services\ManagementPlans\ScheduledPlanActivities;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateScheduledActivityAlertsAction
{
    public function __construct(
        private ScheduledPlanActivities $activities,
        private SynchronizeOperationalAlertsAction $synchronize,
        private Clock $clock,
    ) {}

    public function execute(int $unitId, ScheduledActivityType $type): void
    {
        DB::transaction(function () use ($unitId, $type): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$type->value.'-alerts:'.$unitId]);
            $unit = ProductionUnit::query()->findOrFail($unitId);
            $evaluatedAt = $this->clock->now()->setTimezone((string) config('lots.timezone'));
            $today = $evaluatedAt->startOfDay();
            $alertType = AlertTimingType::from($type->value.'_due');
            $thresholds = $alertType->thresholds($unit);
            $pending = [];

            if ($unit->status === ProductionUnitStatus::Active) {
                $through = $today->subDays($thresholds->firstDay());
                foreach ($this->activities->pending($type, $unitId, $evaluatedAt, $through) as $key => $occurrence) {
                    $days = (int) $occurrence['due']->diffInDays($today, false);
                    $urgency = $occurrence['invalidated'] ? NotificationUrgency::High : $thresholds->urgency($days);
                    if ($urgency !== null) {
                        $pending[$key] = [
                            'entity_id' => $occurrence['entity_id'],
                            'target_path' => '/administracion/manejo-lotes/planes/lotes/'.$occurrence['flock_id'],
                            'urgency' => $urgency,
                            'title' => $type->title(),
                            'message' => $this->message($type, $occurrence['activity'], $occurrence['flock'], $occurrence['due']->format('d/m/Y')),
                        ];
                    }
                }
            }

            $this->synchronize->execute($unitId, OperationalAlertType::from($alertType->value), 'can_view_management_plans', 'flock_plan_activity', $evaluatedAt, $pending);
        }, 3);
    }

    private function message(ScheduledActivityType $type, string $activity, string $flock, string $due): string
    {
        $label = match ($type) {
            ScheduledActivityType::Vaccination => 'La vacuna',
            ScheduledActivityType::Medication => 'La medicación',
            ScheduledActivityType::BeakTrimming => 'El despique',
            default => 'La tarea',
        };

        return "{$label} «{$activity}» del lote «{$flock}» sigue pendiente. Fecha prevista: {$due}.";
    }
}
