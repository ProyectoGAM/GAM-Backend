<?php

namespace App\Console\Commands;

use App\Actions\ManagementPlans\EvaluateScheduledActivityAlertsAction;
use App\Enums\ManagementPlans\ScheduledActivityType;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateHealthAlertsCommand extends Command
{
    protected $signature = 'manejo:evaluar-sanidad {--solo-vacunas : Evalúa únicamente las vacunaciones}';

    protected $description = 'Evalúa las aplicaciones sanitarias programadas y sincroniza los avisos de Planes de manejo';

    public function handle(EvaluateScheduledActivityAlertsAction $action): int
    {
        $types = $this->option('solo-vacunas') ? [ScheduledActivityType::Vaccination] : [ScheduledActivityType::Vaccination, ScheduledActivityType::Medication];
        $failed = false;

        foreach (ProductionUnit::query()->lazyById(100) as $unit) {
            foreach ($types as $type) {
                try {
                    $action->execute($unit->id, $type);
                } catch (Throwable $exception) {
                    report($exception);
                    $label = $type === ScheduledActivityType::Vaccination ? 'las vacunas' : 'las medicaciones';
                    $this->error("No se pudieron evaluar {$label} de la unidad productiva {$unit->id}.");
                    $failed = true;
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
