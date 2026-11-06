<?php

namespace App\Console\Commands;

use App\Actions\Vehicles\EvaluateVehicleMaintenanceAlertsAction;
use Illuminate\Console\Command;

final class EvaluateVehicleMaintenanceAlertsCommand extends Command
{
    protected $signature = 'vehiculos:evaluar-intervenciones';

    protected $description = 'Evalúa los planes preventivos y las intervenciones pendientes de vehículos';

    public function handle(EvaluateVehicleMaintenanceAlertsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
