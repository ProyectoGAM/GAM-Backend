<?php

namespace App\Actions\Vehicles;

use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\Notifications\NotificationUrgency;
use App\Interfaces\Clock;
use App\Models\Vehicles\VehicleMaintenance;
use App\Models\Vehicles\VehicleMaintenancePlan;
use App\Services\Vehicles\VehicleMaintenanceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateVehicleMaintenanceAlertsAction
{
    private const int NOTICE_DAYS = 3;

    public function __construct(
        private SynchronizeOperationalAlertsAction $synchronize,
        private Clock $clock,
        private VehicleMaintenanceSchedule $schedule,
    ) {}

    public function execute(): void
    {
        DB::transaction(function (): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['vehicle-maintenance-alerts']);
            $evaluatedAt = $this->clock->now()->setTimezone(VehicleMaintenance::TIMEZONE);
            $today = $evaluatedAt->startOfDay();
            [$interventions, $interventionByPlan] = $this->pendingInterventions($today);
            $plans = [];
            $activePlans = VehicleMaintenancePlan::query()->where('active', true)
                ->whereHas('vehicle', fn ($vehicle) => $vehicle->where('status', '!=', 'retired'))
                ->with(['vehicle:id,public_id,plate', 'vehicle.latestEffectiveOdometer']);

            foreach ($activePlans->lazyById(100) as $plan) {
                $vehicle = $plan->vehicle;
                $urgency = match ($this->schedule->status($plan, $vehicle->latestEffectiveOdometer, $today)) {
                    'overdue' => NotificationUrgency::High,
                    'upcoming' => NotificationUrgency::Low,
                    default => null,
                };
                if ($urgency === null) {
                    continue;
                }

                $interventionKey = $interventionByPlan[$plan->id] ?? null;
                if ($interventionKey !== null) {
                    if ($urgency->rank() > $interventions[$interventionKey]['urgency']->rank()) {
                        $interventions[$interventionKey]['urgency'] = $urgency;
                        $interventions[$interventionKey]['title'] = 'Intervención con un plan preventivo vencido';
                        $interventions[$interventionKey]['message'] .= ' Tiene asociado un plan preventivo vencido.';
                    }

                    continue;
                }

                $overdue = $urgency === NotificationUrgency::High;
                $state = $overdue ? 'alcanzó un límite de mantenimiento' : 'se acerca a un límite de mantenimiento';
                $plans['vehicle-maintenance-plan:'.$plan->public_id] = [
                    'entity_id' => $plan->public_id,
                    'target_path' => '/administracion/vehiculos/'.$vehicle->public_id,
                    'urgency' => $urgency,
                    'title' => $overdue ? 'Plan preventivo vencido' : 'Plan preventivo próximo',
                    'message' => "El plan «{$plan->task}» del vehículo {$vehicle->plate} {$state}.",
                ];
            }

            $this->synchronize->execute(null, 'vehicles', 'vehicle_maintenance_due', 'can_view_vehicle_maintenance', 'vehicle_maintenance', $evaluatedAt, $interventions);
            $this->synchronize->execute(null, 'vehicles', 'vehicle_maintenance_plan_due', 'can_view_vehicle_maintenance', 'vehicle_maintenance_plan', $evaluatedAt, $plans);
        }, 3);
    }

    /** @return array{array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string, message: string}>, array<int, string>} */
    private function pendingInterventions(CarbonImmutable $today): array
    {
        $maintenances = VehicleMaintenance::query()
            ->whereIn('status', ['planned', 'in_progress'])
            ->where('planned_at', '<', $today->addDays(self::NOTICE_DAYS + 1)->toIso8601String())
            ->with(['vehicle:id,public_id,plate', 'items:id,maintenance_id,plan_id']);
        $pending = [];
        $interventionByPlan = [];

        foreach ($maintenances->lazyById(100) as $maintenance) {
            $vehicle = $maintenance->vehicle;
            $plannedAt = $maintenance->planned_at->setTimezone(VehicleMaintenance::TIMEZONE);
            $overdue = $plannedAt->lt($today);
            $date = $plannedAt->format('d/m/Y');
            $key = 'vehicle-maintenance:'.$maintenance->public_id;
            $pending[$key] = [
                'entity_id' => $maintenance->public_id,
                'target_path' => '/administracion/vehiculos/'.$vehicle->public_id,
                'urgency' => $overdue ? NotificationUrgency::High : NotificationUrgency::Low,
                'title' => $overdue ? 'Intervención de vehículo vencida' : 'Intervención de vehículo prevista',
                'message' => "La intervención «{$maintenance->description}» del vehículo {$vehicle->plate}, prevista para el {$date}, sigue pendiente.",
            ];

            foreach ($maintenance->items as $item) {
                if ($item->plan_id !== null) {
                    $interventionByPlan[$item->plan_id] ??= $key;
                }
            }
        }

        return [$pending, $interventionByPlan];
    }
}
