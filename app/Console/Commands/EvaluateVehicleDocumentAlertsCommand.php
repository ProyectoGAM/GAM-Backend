<?php

namespace App\Console\Commands;

use App\Actions\Vehicles\EvaluateVehicleDocumentAlertsAction;
use Illuminate\Console\Command;

final class EvaluateVehicleDocumentAlertsCommand extends Command
{
    protected $signature = 'vehiculos:evaluar-documentos';

    protected $description = 'Evalúa el vencimiento de los documentos de vehículos';

    public function handle(EvaluateVehicleDocumentAlertsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
