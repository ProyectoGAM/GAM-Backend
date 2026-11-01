<?php

namespace App\Services\Vehicles;

use App\Models\Vehicles\VehicleMaintenancePlan;
use App\Models\Vehicles\VehicleMeterReading;
use Illuminate\Support\Carbon;

final class VehicleMaintenanceSchedule
{
    public function status(VehicleMaintenancePlan $plan, ?VehicleMeterReading $odometer): string
    {
        $hasDateLimit = $plan->next_due_at !== null;
        $hasKmLimit = $plan->next_due_odometer_km !== null;
        if (! $hasDateLimit && ! $hasKmLimit) {
            return 'insufficient_data';
        }
        $dueByDate = $hasDateLimit && $plan->next_due_at->lte(Carbon::today());
        $odometerEpochMatches = $odometer !== null && ($plan->next_due_odometer_epoch ?? 0) === $odometer->counter_epoch;
        $dueByKm = $hasKmLimit && $odometerEpochMatches && (float) $odometer->value >= (float) $plan->next_due_odometer_km;
        if ($dueByDate || $dueByKm) {
            return 'overdue';
        }
        $odometerEpochChanged = $hasKmLimit && $odometer !== null && ! $odometerEpochMatches;
        if ($hasKmLimit && ($odometer === null || $odometerEpochChanged)) {
            return 'insufficient_data';
        }
        $dueSoonByDate = $hasDateLimit && $plan->next_due_at->lte(Carbon::today()->addDays(30));
        $dueSoonByKm = $hasKmLimit && $odometer !== null && ((float) $plan->next_due_odometer_km - (float) $odometer->value) <= 500;
        return $dueSoonByDate || $dueSoonByKm ? 'upcoming' : 'current';
    }
}
