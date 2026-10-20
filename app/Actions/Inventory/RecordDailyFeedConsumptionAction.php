<?php

namespace App\Actions\Inventory;

use App\Enums\Lots\FlockStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Inventory\FeedConsumption;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Services\Inventory\FeedConsumptionStock;
use App\Services\Inventory\FlockFeedingHistory;
use App\Services\Inventory\FlockFeedingResolver;
use App\Services\Lots\FlockActivityJournal;
use App\ValueObjects\Inventory\FeedStockGrams;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RecordDailyFeedConsumptionAction
{
    public function __construct(
        private FlockFeedingResolver $resolver, private FeedConsumptionStock $stock,
        private FlockFeedingHistory $history, private FlockActivityJournal $journal, private Clock $clock,
    ) {}

    public function execute(int $flockId, string $date): ?FeedConsumption
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, FlockFeedingResolver::TIMEZONE);
        if ($day === null || $day->toDateString() !== $date || $day->greaterThanOrEqualTo($this->clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE)->startOfDay())) {
            throw new LotsConflict('El consumo requiere una fecha válida anterior a hoy en America/Montevideo.');
        }

        return DB::transaction(function () use ($flockId, $day, $date): ?FeedConsumption {
            $flock = Flock::query()->whereKey($flockId)->lockForUpdate()->firstOrFail();
            $existing = FeedConsumption::query()->where('flock_id', $flockId)->where('consumption_date', $date)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($date < $flock->established_at->setTimezone(FlockFeedingResolver::TIMEZONE)->toDateString()
                || ($flock->status === FlockStatus::Finished && $flock->finalized_at !== null && $date > $flock->finalized_at->setTimezone(FlockFeedingResolver::TIMEZONE)->toDateString())) {
                return null;
            }
            try {
                $resolved = $this->resolver->resolve($flock, $day);
                $quantity = FeedStockGrams::parse($resolved['quantity_grams'], allowNegative: false);
            } catch (InvalidArgumentException $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }
            if ($resolved['census']['finished']) {
                return null;
            }
            $operationId = (string) Str::uuid();
            $consumption = new FeedConsumption;
            $consumption->forceFill([
                'flock_id' => $flock->id, 'flock_feeding_change_id' => $resolved['configuration']->id,
                'production_unit_id' => $flock->production_unit_id, 'feed_recipe_id' => $resolved['recipe_id'],
                'consumption_date' => $date, 'mode' => $resolved['configuration']->mode,
                'age_days' => $resolved['age'], 'population' => $resolved['census']['population'],
                'grams_per_bird' => $resolved['stage']['grams_per_bird'], 'quantity_grams' => $quantity,
                'stage_snapshot' => $resolved['stage'], 'census_snapshot' => $resolved['census'], 'operation_id' => $operationId,
            ])->save();
            $movements = $this->stock->apply($consumption, [$consumption->feed_recipe_id => -$consumption->quantity_grams], $operationId);
            $this->history->record($consumption, $flock, null, 'feed_consumption_recorded', $operationId, [],
                [...$consumption->only(['consumption_date', 'mode', 'age_days', 'population', 'feed_recipe_id', 'quantity_grams', 'stage_snapshot', 'census_snapshot']), 'movements' => $movements], 'scheduler');
            $operation = new FlockOperation;
            $operation->forceFill([
                'operation_id' => $operationId, 'created_by' => null, 'idempotency_key' => $operationId,
                'command' => 'flock.feeding.daily', 'request_hash' => hash('sha256', $flock->id.'|'.$date),
                'result' => ['consumption_id' => $consumption->id, 'automatic_process' => true],
            ])->save();
            $this->journal->record($flock, $operationId, 'feed_consumption', 'flock.feeding.daily');

            return $consumption;
        }, 3);
    }
}
