<?php

namespace App\Console\Commands;

use App\Actions\Inventory\EvaluateManualFeedingReviewAlertsAction;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateManualFeedingReviewAlertsCommand extends Command
{
    protected $signature = 'alimentacion:evaluar-revision-manual';

    protected $description = 'Evalúa las raciones manuales pendientes de confirmar y sincroniza sus avisos';

    public function handle(EvaluateManualFeedingReviewAlertsAction $action): int
    {
        $failed = false;

        foreach (ProductionUnit::query()->lazyById(100) as $unit) {
            try {
                $action->execute($unit->id);
            } catch (Throwable $exception) {
                report($exception);
                $this->error("No se pudo evaluar la ración manual de la unidad productiva {$unit->id}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
