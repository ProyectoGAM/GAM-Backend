<?php

namespace App\Services\Vehicles;

use App\Models\Vehicles\Vehicle;
use Illuminate\Support\Carbon;

final class VehicleAvailability
{
    /** @return array{availability: string, reason: string|null, warnings: list<string>} */
    public function describe(Vehicle $vehicle): array
    {
        $activeUsage = $vehicle->usages()->whereNull('ended_at')->exists();
        $immobilizingMaintenance = $vehicle->maintenances()->where('status', 'in_progress')->where('immobilizes_vehicle', true)->exists();
        $warnings = [];
        $expiredDocuments = $vehicle->documents()->whereNotNull('expires_at')->whereDate('expires_at', '<', Carbon::today())->count();
        $soonDocuments = $vehicle->documents()->whereNotNull('expires_at')->whereBetween('expires_at', [Carbon::today(), Carbon::today()->addDays(30)])->count();
        if ($expiredDocuments > 0) {
            $warnings[] = 'Hay documentación vencida.';
        } elseif ($soonDocuments > 0) {
            $warnings[] = 'Hay documentación próxima a vencer.';
        }
        $odometer = $vehicle->readings()->effective()->where('meter_type', 'odometer')->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $plans = $vehicle->maintenancePlans()->where('active', true)->get();
        $schedule = app(VehicleMaintenanceSchedule::class);
        foreach ($plans as $plan) {
            $status = $schedule->status($plan, $odometer);
            if ($status === 'overdue') {
                $warnings[] = 'Mantenimiento vencido: '.$plan->task;
            } elseif ($status === 'upcoming') {
                $warnings[] = 'Mantenimiento próximo: '.$plan->task;
            } elseif ($status === 'insufficient_data') {
                $warnings[] = 'Falta una lectura de tablero para evaluar: '.$plan->task;
            }
        }

        if ($vehicle->status !== 'active') {
            return ['availability' => 'not_available', 'reason' => $vehicle->status_reason ?? 'El vehículo no está activo.', 'warnings' => array_values(array_unique($warnings))];
        }
        if ($activeUsage) {
            return ['availability' => 'in_use', 'reason' => 'El vehículo tiene un uso abierto.', 'warnings' => array_values(array_unique($warnings))];
        }
        if ($immobilizingMaintenance) {
            return ['availability' => 'not_available', 'reason' => 'El vehículo está inmovilizado por mantenimiento.', 'warnings' => array_values(array_unique($warnings))];
        }

        return ['availability' => 'available', 'reason' => null, 'warnings' => array_values(array_unique($warnings))];
    }
}
