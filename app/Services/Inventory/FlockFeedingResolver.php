<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

final readonly class FlockFeedingResolver
{
    public const TIMEZONE = 'America/Montevideo';

    public function age(string $baseline, CarbonImmutable $date): int
    {
        $interval = CarbonImmutable::parse($baseline, self::TIMEZONE)->startOfDay()->diff($date->setTimezone(self::TIMEZONE)->startOfDay());

        return max(1, ($interval->invert === 1 ? -$interval->days : $interval->days) + 1);
    }

    public function configuration(Flock $flock, CarbonImmutable $date): FlockFeedingChange
    {
        return FlockFeedingChange::query()->where('flock_id', $flock->id)
            ->where('effective_date', '<=', $date->toDateString())
            ->orderByDesc('effective_date')->orderByDesc('id')->firstOrFail();
    }

    /** @param list<array<string, mixed>> $stages @return array<string, mixed> */
    public function anchor(array $stages, int $age, CarbonImmutable $date, ?array $previous = null): array
    {
        if ($stages === [] || (int) $stages[0]['start_day'] !== 1) {
            throw new LotsConflict('El plan debe tener exactamente una alimentación inicial en día 1 o semana 1.');
        }
        $selected = $previous ?? $stages[0];
        $recipes = $this->lockRecipes($stages);
        $history = $this->recipeHistory(array_keys($recipes));
        foreach ($stages as $stage) {
            if ($stage['start_day'] <= $age && $this->available((int) $stage['recipe_id'], $date, $recipes, $history)) {
                $selected = $stage;
            }
        }

        return $selected;
    }

    /** @return array<string, mixed> */
    public function stage(FlockFeedingChange $configuration, CarbonImmutable $date): array
    {
        $selected = $configuration->stage_snapshot;
        if ($configuration->mode === 'manual') {
            return $selected;
        }
        $recipes = $this->lockRecipes($configuration->stages);
        $history = $this->recipeHistory(array_keys($recipes));
        $age = $this->age($configuration->baseline_date->toDateString(), $date);
        foreach ($configuration->stages as $stage) {
            if ($stage['start_day'] <= $configuration->anchor_day || $stage['start_day'] > $age) {
                continue;
            }
            $transitionDate = CarbonImmutable::parse($configuration->baseline_date->toDateString(), self::TIMEZONE)
                ->addDays($stage['start_day'] - 1);
            if ($this->available((int) $stage['recipe_id'], $transitionDate, $recipes, $history)) {
                $selected = $stage;
            }
        }

        return $selected;
    }

    /**
     * Usa la historia cargada en bloque para recuperar disponibilidad sin consultas por etapa.
     *
     * @param  array<int,string>  $recipes
     * @param  array<int,list<array{at:int,status:string,before:?string}>>  $history
     */
    private function available(int $recipeId, CarbonImmutable $date, array $recipes, array $history): bool
    {
        $events = $history[$recipeId] ?? [];
        $status = $events[0]['before'] ?? $recipes[$recipeId];
        $cutoff = $date->getTimestamp();
        foreach ($events as $event) {
            if ($event['at'] > $cutoff) {
                break;
            }
            $status = $event['status'];
        }

        return $status === FeedRecipeStatus::Active->value;
    }

    /** @param list<array<string, mixed>> $stages @return array<int,string> */
    private function lockRecipes(array $stages): array
    {
        return FeedRecipe::query()->whereIn('id', array_column($stages, 'recipe_id'))->orderBy('id')->sharedLock()->get()
            ->mapWithKeys(fn (FeedRecipe $recipe): array => [$recipe->id => $recipe->status->value])->all();
    }

    /** @param list<int> $recipeIds @return array<int,list<array{at:int,status:string,before:?string}>> */
    private function recipeHistory(array $recipeIds): array
    {
        $history = [];
        $events = Activity::query()->where('subject_type', (new FeedRecipe)->getMorphClass())
            ->whereIn('subject_id', $recipeIds)->whereIn('event', ['feed_recipe_created', 'feed_recipe_status_changed'])
            ->orderBy('created_at')->orderBy('id')->get(['subject_id', 'created_at', 'properties', 'attribute_changes']);
        foreach ($events as $event) {
            $changes = $event->getAttribute('attribute_changes');
            if (is_string($changes)) {
                $changes = json_decode($changes, true, flags: JSON_THROW_ON_ERROR);
            }
            $history[(int) $event->subject_id][] = [
                'at' => $event->created_at->getTimestamp(),
                'status' => $event->properties->get('subject_snapshot')['status'],
                'before' => $changes['old']['status'] ?? null,
            ];
        }

        return $history;
    }

    /**
     * Las instantáneas de movimientos son inmutables. El egreso administrativo al finalizar no resta aves del día final.
     *
     * @return array{population:int, initial_quantity:int, movements:list<array{id:int, delta:int}>, finished:bool}
     */
    public function census(Flock $flock, CarbonImmutable $date): array
    {
        $population = $flock->initial_quantity;
        $lines = [];
        $lastState = null;
        $lastDate = null;
        $movements = FlockMovement::query()
            ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id))
            ->where('occurred_at', '<', $date->addDay()->startOfDay()->utc()->toIso8601String())
            ->orderBy('occurred_at')->orderBy('id')->get();
        foreach ($movements as $movement) {
            $before = $movement->before[$flock->public_id] ?? null;
            $after = $movement->after[$flock->public_id] ?? null;
            if ($after === null) {
                continue;
            }
            $lastState = $after['status'] ?? $lastState;
            $lastDate = $movement->occurred_at->setTimezone(self::TIMEZONE)->toDateString();
            if ($before === null || $movement->type === 'departure') {
                continue;
            }
            $delta = (int) $after['current_quantity'] - (int) $before['current_quantity'];
            $population += $delta;
            $lines[] = ['id' => $movement->id, 'delta' => $delta];
        }

        return [
            'population' => $population, 'initial_quantity' => $flock->initial_quantity, 'movements' => $lines,
            'finished' => $lastState === 'finished' && $lastDate < $date->toDateString(),
        ];
    }

    /** @return array{configuration:FlockFeedingChange, stage:array<string,mixed>, census:array<string,mixed>, age:int, recipe_id:int, quantity_grams:int|string} */
    public function resolve(Flock $flock, CarbonImmutable $date): array
    {
        $configuration = $this->configuration($flock, $date);
        $stage = $this->stage($configuration, $date);
        $census = $this->census($flock, $date);
        $quantity = $configuration->mode === 'manual'
            ? $configuration->daily_quantity_grams
            : (string) BigInteger::of($stage['grams_per_bird'])->multipliedBy(max(0, $census['population']));

        return [
            'configuration' => $configuration, 'stage' => $stage, 'census' => $census,
            'age' => $this->age($configuration->baseline_date->toDateString(), $date),
            'recipe_id' => $configuration->mode === 'manual' ? $configuration->feed_recipe_id : (int) $stage['recipe_id'],
            'quantity_grams' => $quantity,
        ];
    }
}
