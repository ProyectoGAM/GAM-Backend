<?php

namespace App\Console\Commands;

use App\Actions\Inventory\EvaluateEggStockAlertsAction;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateEggStockAlertsCommand extends Command
{
    protected $signature = 'inventario:evaluar-stock-huevos';

    protected $description = 'Evalúa el stock negativo de huevos por unidad productiva';

    public function handle(EvaluateEggStockAlertsAction $action): int
    {
        $failed = false;

        foreach (ProductionUnit::query()->select('id')->lazyById(100) as $unit) {
            try {
                $action->execute($unit->id);
            } catch (Throwable $exception) {
                report($exception);
                $this->error("No se pudo evaluar el stock de huevos de la unidad productiva {$unit->id}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
