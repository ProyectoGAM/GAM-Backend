<?php

namespace App\Services\Vehicles;

use App\Models\Vehicles\VehicleMaintenance;
use App\Models\Vehicles\VehicleMaintenancePlan;
use App\Models\Vehicles\VehicleMeterReading;
use Carbon\CarbonImmutable;

final class VehicleMaintenanceSchedule
{
    public function status(VehicleMaintenancePlan $plan, ?VehicleMeterReading $odometer, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today(VehicleMaintenance::TIMEZONE);
        $dueDate = $plan->next_due_at?->toDateString();
        $hasKmLimit = $plan->next_due_odometer_km !== null;
        if ($dueDate === null && ! $hasKmLimit) {
            return 'insufficient_data';
        }
        $dueByDate = $dueDate !== null && $dueDate <= $today->toDateString();
        $odometerEpochMatches = $odometer !== null && ($plan->next_due_odometer_epoch ?? 0) === $odometer->counter_epoch;
        $dueByKm = $hasKmLimit && $odometerEpochMatches && (float) $odometer->value >= (float) $plan->next_due_odometer_km;
        if ($dueByDate || $dueByKm) {
            return 'overdue';
        }
        $dueSoonByDate = $dueDate !== null && $dueDate <= $today->addDays(30)->toDateString();
        $dueSoonByKm = $hasKmLimit && $odometerEpochMatches && ((float) $plan->next_due_odometer_km - (float) $odometer->value) <= 500;
        if ($dueSoonByDate || $dueSoonByKm) {
            return 'upcoming';
        }

        return $hasKmLimit && ! $odometerEpochMatches ? 'insufficient_data' : 'current';
    }
}
