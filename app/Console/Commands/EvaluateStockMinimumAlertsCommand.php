<?php

namespace App\Console\Commands;

use App\Actions\Inventory\EvaluateStockMinimumAlertsAction;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateStockMinimumAlertsCommand extends Command
{
    protected $signature = 'inventario:evaluar-minimos';

    protected $description = 'Evalúa y sincroniza las alertas de stock mínimo por ubicación';

    public function handle(EvaluateStockMinimumAlertsAction $action): int
    {
        $failed = false;
        $unitIds = ProductionUnit::query()->orderBy('id')->pluck('id')->prepend(null);

        foreach ($unitIds as $unitId) {
            try {
                $action->execute($unitId);
            } catch (Throwable $exception) {
                report($exception);
                $scope = $unitId === null ? 'las ubicaciones sin unidad productiva' : "la unidad productiva {$unitId}";
                $this->error("No se pudo evaluar el stock mínimo de {$scope}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
