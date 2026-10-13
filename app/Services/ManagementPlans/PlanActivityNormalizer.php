<?php

namespace App\Services\ManagementPlans;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Inventory\FeedRecipe;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\ValueObjects\Inventory\FeedStockGrams;

final readonly class PlanActivityNormalizer
{
    /** @param array<string, mixed> $activity @return array<string, mixed> */
    public function normalize(array $activity, int $sortOrder): array
    {
        $type = $activity['type'];
        $timing = $activity['timing_kind'];
        $startDay = isset($activity['start_day']) ? (int) $activity['start_day'] : null;
        $endDay = isset($activity['end_day']) ? (int) $activity['end_day'] : null;
        $startWeek = isset($activity['start_week']) ? (int) $activity['start_week'] : null;
        $endWeek = isset($activity['end_week']) ? (int) $activity['end_week'] : null;
        $interval = isset($activity['interval_days']) ? (int) $activity['interval_days'] : null;

        if (($timing === 'day' && ($startDay === null || $endDay !== null || $startWeek !== null || $endWeek !== null || $interval !== null))
            || ($timing === 'week' && ($startWeek === null || $startDay !== null || $endDay !== null || $endWeek !== null || $interval !== null))
            || ($timing === 'week_range' && ($startWeek === null || $startDay !== null || $endDay !== null || $interval !== null || ($endWeek !== null && $endWeek < $startWeek)))
            || ($timing === 'day_recurrence' && ($startDay === null || $interval === null || $interval < 1 || $startWeek !== null || ($endDay !== null && $endWeek !== null) || ($endDay !== null && $endDay < $startDay) || ($endWeek !== null && $endWeek * 7 < $startDay) || ($endDay === null && $endWeek === null)))
            || ($timing === 'unscheduled' && ($startDay !== null || $endDay !== null || $startWeek !== null || $endWeek !== null || $interval !== null))) {
            throw new LotsConflict('El momento previsto de la actividad no es consistente.');
        }

        if ($type === 'ration_change' && ! in_array($timing, ['day', 'week_range', 'week'], true)) {
            throw new LotsConflict('La alimentación requiere un día, una semana o un tramo de semanas.');
        }
        if (($activity['conditional'] ?? false) && empty($activity['condition'])) {
            throw new LotsConflict('Indica la condición de la actividad condicional.');
        }

        [$catalogType, $catalogId, $catalogSnapshot] = $this->catalog($type, $activity['catalog_ref'] ?? null);
        $grams = null;
        if ($type === 'ration_change') {
            if ($catalogId === null || ($activity['conditional'] ?? false)) {
                throw new LotsConflict('Cada etapa de alimentación requiere una receta y no puede ser condicional.');
            }
            try {
                $grams = FeedStockGrams::parse($activity['grams_per_bird'] ?? null, positive: true);
            } catch (\InvalidArgumentException $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }
        } elseif (isset($activity['grams_per_bird'])) {
            throw new LotsConflict('La estimación de gramos por ave sólo corresponde a la alimentación.');
        }

        return [
            'sort_order' => $sortOrder, 'type' => $type, 'title' => trim($activity['title']),
            'timing_kind' => $timing, 'start_day' => $startDay, 'end_day' => $endDay,
            'start_week' => $startWeek, 'end_week' => $endWeek, 'interval_days' => $interval,
            'conditional' => (bool) ($activity['conditional'] ?? false),
            'condition' => $activity['condition'] ?? null, 'notes' => $activity['notes'] ?? null,
            'catalog_type' => $catalogType, 'catalog_id' => $catalogId,
            'catalog_snapshot' => $catalogSnapshot,
            'feed_recipe_id' => $type === 'ration_change' ? $catalogId : null,
            'grams_per_bird' => $grams,
        ];
    }

    /** @return array{0: ?string, 1: ?int, 2: ?array<string, mixed>} */
    private function catalog(string $type, mixed $reference): array
    {
        if ($reference === null || $reference === '') {
            return [null, null, null];
        }
        if ($type === 'vaccination') {
            $vaccine = Vaccine::query()->with('product')->where('public_id', $reference)->first();
            if ($vaccine === null || $vaccine->product?->status !== ProductStatus::Active) {
                throw new LotsConflict('La vacuna referida no está disponible.');
            }

            return ['vaccine', $vaccine->id, ['id' => $vaccine->public_id, 'name' => $vaccine->product->name, 'description' => $vaccine->description]];
        }
        if ($type === 'medication') {
            $medicine = Medicine::query()->where('public_id', $reference)->first();
            if ($medicine === null) {
                throw new LotsConflict('El medicamento referido no existe.');
            }

            return ['medicine', $medicine->id, ['id' => $medicine->public_id, 'name' => $medicine->name, 'description' => $medicine->description]];
        }
        if ($type === 'ration_change' && ctype_digit((string) $reference)) {
            $recipe = FeedRecipe::query()->whereKey((int) $reference)->sharedLock()->first();
            if ($recipe === null || $recipe->status !== FeedRecipeStatus::Active) {
                throw new LotsConflict('La receta referida no está disponible.');
            }

            return ['feed_recipe', $recipe->id, ['id' => $recipe->id, 'name' => $recipe->name, 'status' => $recipe->status->value]];
        }

        throw new LotsConflict('La referencia de catálogo no corresponde al tipo de actividad.');
    }

    /** @param list<array<string, mixed>> $activities */
    public function validateInitialFeeding(array $activities): void
    {
        $initial = 0;
        $days = [];
        foreach ($activities as $activity) {
            if ($activity['type'] !== 'ration_change') {
                continue;
            }
            $day = isset($activity['start_day']) ? (int) $activity['start_day'] : (((int) ($activity['start_week'] ?? 0) - 1) * 7 + 1);
            if ($day < 1 || isset($days[$day])) {
                throw new LotsConflict('Sólo puede haber una etapa de alimentación por día de inicio.');
            }
            $days[$day] = true;
            if ($day === 1) {
                $initial++;
            }
        }
        if ($initial !== 1) {
            throw new LotsConflict('El plan debe tener exactamente una alimentación inicial en día 1 o semana 1.');
        }
    }
}
