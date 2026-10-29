<?php

namespace App\Console\Commands;

use App\Actions\Inventory\RecordDailyFeedConsumptionAction;
use App\Interfaces\Clock;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Flock;
use App\Services\Inventory\FlockFeedingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RecordDailyFeedConsumptionCommand extends Command
{
    protected $signature = 'alimentacion:consumir {--lote= : Identificador público del lote}';

    protected $description = 'Registra y recupera los consumos diarios pendientes hasta ayer en America/Montevideo';

    public function handle(RecordDailyFeedConsumptionAction $action, Clock $clock): int
    {
        $yesterday = $clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE)->startOfDay()->subDay();
        $failures = 0;
        $recorded = 0;
        $query = Flock::query()->whereIn('id', FlockFeedingChange::query()->select('flock_id'));
        if ($this->option('lote') !== null) {
            $query->where('public_id', $this->option('lote'));
        }
        foreach ($query->lazyById(100) as $flock) {
            $first = FlockFeedingChange::query()->where('flock_id', $flock->id)->min('effective_date');
            $start = CarbonImmutable::parse($first, FlockFeedingResolver::TIMEZONE)->startOfDay();
            $end = $flock->finalized_at === null ? $yesterday : $yesterday->min($flock->finalized_at->setTimezone(FlockFeedingResolver::TIMEZONE)->startOfDay());
            $existing = FeedConsumption::query()->where('flock_id', $flock->id)->pluck('consumption_date')
                ->mapWithKeys(fn ($date): array => [CarbonImmutable::parse($date)->toDateString() => true])->all();
            for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                if (isset($existing[$date->toDateString()])) {
                    continue;
                }
                try {
                    if ($action->execute($flock->id, $date->toDateString()) !== null) {
                        $recorded++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $this->error('No se pudo registrar el consumo del lote '.$flock->public_id.' del '.$date->toDateString().'.');
                    $failures++;
                    break;
                }
            }
        }
        $this->info('Consumos registrados: '.$recorded.'. Lotes con fallas: '.$failures.'.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
