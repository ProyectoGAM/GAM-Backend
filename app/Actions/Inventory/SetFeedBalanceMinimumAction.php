<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\User;
use App\Services\Inventory\FeedBalanceEvaluator;
use App\Services\Inventory\FeedOperationRunner;
use App\ValueObjects\Inventory\FeedStockGrams;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SetFeedBalanceMinimumAction
{
    public function __construct(
        private FeedOperationRunner $operations,
        private AuditRecorder $auditRecorder,
        private FeedBalanceEvaluator $evaluator,
    ) {}

    public function execute(int $recipeId, int $unitId, mixed $minimum, User $actor): FeedBalance
    {
        try {
            $minimum = $minimum === null ? null : FeedStockGrams::parse($minimum);
        } catch (InvalidArgumentException $exception) {
            throw new FeedRecipeConflict($exception->getMessage(), previous: $exception);
        }
        if ($minimum !== null && $minimum < 0) {
            throw new FeedRecipeConflict('El mínimo no puede ser negativo.');
        }

        return DB::transaction(function () use ($recipeId, $unitId, $minimum, $actor): FeedBalance {
            $recipe = $this->operations->recipe($recipeId, false);
            $unit = ProductionUnit::query()->whereKey($unitId)->sharedLock()->firstOrFail();
            $balance = $this->operations->balance($recipeId, $unitId);
            $before = $balance->minimum_quantity_grams;
            $balance->forceFill(['minimum_quantity_grams' => $minimum])->save();
            $operationId = (string) Str::uuid();
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $balance,
                actor: $actor,
                logName: 'inventory',
                event: 'feed_balance_minimum_changed',
                description: 'Mínimo de alimento configurado por receta y unidad productiva',
                operationId: $operationId,
                upId: $unitId,
                source: 'api',
                properties: ['result' => 'success', 'subject_snapshot' => [
                    'feed_recipe_id' => $recipeId, 'recipe_name' => $recipe->name,
                    'production_unit_id' => $unitId, 'production_unit_name' => $unit->name,
                    'quantity_grams' => (string) $balance->quantity_grams,
                    'minimum_quantity_grams' => $minimum === null ? null : (string) $minimum,
                ]],
                attributeChanges: [
                    'old' => ['minimum_quantity_grams' => $before === null ? null : (string) $before],
                    'new' => ['minimum_quantity_grams' => $minimum === null ? null : (string) $minimum],
                ],
            ));
            $this->evaluator->evaluate($balance, 'minimum_changed', $operationId);

            return $balance->setRelation('recipe', $recipe)->setRelation('productionUnit', $unit);
        }, 3);
    }
}
