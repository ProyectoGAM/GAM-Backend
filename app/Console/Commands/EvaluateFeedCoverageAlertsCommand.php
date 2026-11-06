<?php

namespace App\Console\Commands;

use App\Actions\Inventory\EvaluateFeedCoverageAlertsAction;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateFeedCoverageAlertsCommand extends Command
{
    protected $signature = 'alimentacion:evaluar-cobertura';

    protected $description = 'Evalúa la cobertura de ración y sincroniza los avisos de Planes de manejo';

    public function handle(EvaluateFeedCoverageAlertsAction $action): int
    {
        $failed = false;

        foreach (ProductionUnit::query()->lazyById(100) as $unit) {
            try {
                $action->execute($unit->id);
            } catch (Throwable $exception) {
                report($exception);
                $this->error('No se pudo evaluar la cobertura de la unidad productiva '.$unit->id.'.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
