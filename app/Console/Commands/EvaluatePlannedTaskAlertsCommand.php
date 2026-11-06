<?php

namespace App\Console\Commands;

use App\Actions\ManagementPlans\EvaluateScheduledActivityAlertsAction;
use App\Enums\ManagementPlans\ScheduledActivityType;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluatePlannedTaskAlertsCommand extends Command
{
    protected $signature = 'manejo:evaluar-tareas';

    protected $description = 'Evalúa las tareas pendientes del Plan de Manejo y sincroniza sus avisos';

    public function handle(EvaluateScheduledActivityAlertsAction $action): int
    {
        $types = [
            ScheduledActivityType::BeakTrimming, ScheduledActivityType::ManualPractice,
            ScheduledActivityType::FlockMovement, ScheduledActivityType::EggCollection, ScheduledActivityType::Mortality,
        ];
        $failed = false;

        foreach (ProductionUnit::query()->lazyById(100) as $unit) {
            foreach ($types as $type) {
                try {
                    $action->execute($unit->id, $type);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->error("No se pudo evaluar la actividad {$type->value} de la unidad productiva {$unit->id}.");
                    $failed = true;
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
