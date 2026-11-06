<?php

namespace App\Console\Commands;

use App\Actions\Lots\EvaluateDailyEggCollectionAlertsAction;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateDailyEggCollectionAlertsCommand extends Command
{
    protected $signature = 'lotes:evaluar-recolecciones-diarias';

    protected $description = 'Evalúa los lotes sin recolección diaria de huevos y sincroniza sus alertas';

    public function handle(EvaluateDailyEggCollectionAlertsAction $action): int
    {
        $failed = false;

        foreach (ProductionUnit::query()->lazyById(100) as $unit) {
            try {
                $action->execute($unit->id);
            } catch (Throwable $exception) {
                report($exception);
                $this->error("No se pudieron evaluar las recolecciones de huevos de la unidad productiva {$unit->id}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
