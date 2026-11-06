<?php

namespace App\Console\Commands;

use App\Actions\Lots\EvaluatePoultryHouseCapacityAlertsAction;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluatePoultryHouseCapacityAlertsCommand extends Command
{
    protected $signature = 'galpones:evaluar-capacidad';

    protected $description = 'Evalúa y sincroniza las alertas por exceso de capacidad de los galpones';

    public function handle(EvaluatePoultryHouseCapacityAlertsAction $action): int
    {
        $failed = false;

        foreach (ProductionUnit::query()->select('id')->lazyById(100) as $unit) {
            try {
                $action->execute($unit->id);
            } catch (Throwable $exception) {
                report($exception);
                $this->error("No se pudo evaluar la capacidad de los galpones de la unidad productiva {$unit->id}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
