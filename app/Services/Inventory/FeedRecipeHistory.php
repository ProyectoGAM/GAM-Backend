<?php

namespace App\Services\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\User;

final readonly class FeedRecipeHistory
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @return array<string, mixed> */
    public function snapshot(FeedRecipe $recipe): array
    {
        $recipe->loadMissing('ingredients.product');

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'notes' => $recipe->notes,
            'yield_grams' => (string) $recipe->yield_grams,
            'status' => $recipe->status->value,
            'ingredients' => $recipe->ingredients->map(fn (FeedRecipeIngredient $ingredient): array => [
                'product_id' => $ingredient->product_id,
                'sku' => $ingredient->product->sku,
                'name' => $ingredient->product->name,
                'quantity_grams' => (string) $ingredient->quantity_grams,
                'base_unit' => $ingredient->product->base_unit->value,
            ])->all(),
        ];
    }

    /**
     * El Action invoca esta escritura dentro de su misma transacción.
     *
     * @param  array<string, mixed>  $before
     */
    public function record(FeedRecipe $recipe, User $actor, string $event, string $description, array $before, string $operationId, string $source): void
    {
        $after = $this->snapshot($recipe);
        $this->auditRecorder->record(AuditEntryData::forSubject(
            subject: $recipe,
            actor: $actor,
            logName: 'inventory',
            event: $event,
            description: $description,
            properties: ['subject_snapshot' => $after, 'result' => 'success'],
            attributeChanges: ['old' => $before, 'new' => $after],
            operationId: $operationId,
            source: $source,
        ));
    }
}
