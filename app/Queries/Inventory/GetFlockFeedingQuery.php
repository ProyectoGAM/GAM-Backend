<?php

namespace App\Queries\Inventory;

use App\Interfaces\Clock;
use App\Models\Lots\Flock;
use App\Services\Inventory\FlockFeedingResolver;
use Illuminate\Support\Facades\DB;

final readonly class GetFlockFeedingQuery
{
    public function __construct(private FlockFeedingResolver $resolver, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function execute(Flock $flock): array
    {
        return DB::transaction(function () use ($flock): array {
            $locked = Flock::query()->whereKey($flock->id)->sharedLock()->firstOrFail();
            $date = $this->clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE)->startOfDay();
            $resolved = $this->resolver->resolve($locked, $date);

            return [
                'id' => $resolved['configuration']->id, 'lote_id' => $locked->public_id,
                'modo' => $resolved['configuration']->mode === 'manual' ? 'manual' : 'automatico',
                'receta_id' => $resolved['recipe_id'], 'cantidad_diaria_g' => (string) $resolved['quantity_grams'],
                'cantidad_manual_g' => $resolved['configuration']->daily_quantity_grams === null ? null : (string) $resolved['configuration']->daily_quantity_grams,
                'gramos_por_ave' => (string) $resolved['stage']['grams_per_bird'],
                'poblacion' => $resolved['census']['population'], 'edad_dias' => $resolved['age'],
                'fecha_vigencia' => $resolved['configuration']->effective_date->toDateString(),
                'etapa' => $resolved['stage'], 'finalizado' => $locked->status->value === 'finished',
            ];
        });
    }
}
