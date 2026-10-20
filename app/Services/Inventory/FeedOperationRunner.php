<?php

namespace App\Services\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use App\ValueObjects\Inventory\FeedStockGrams;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class FeedOperationRunner
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Serializa reintentos del mismo actor y mantiene todos los efectos en una transacción.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  Closure(string): FeedStockOperation  $mutation
     */
    public function run(string $kind, string $key, array $payload, User $actor, Closure $mutation): FeedStockOperation
    {
        if (! Str::isUuid($key)) {
            throw new FeedRecipeConflict('El encabezado Idempotency-Key debe ser un UUID válido.');
        }
        $key = Str::lower($key);
        $hash = hash('sha256', json_encode([$kind, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($kind, $key, $hash, $actor, $mutation): FeedStockOperation {
            if (User::query()->whereKey($actor->getKey())->lockForUpdate()->first() === null) {
                throw new AuthorizationException;
            }
            $existing = FeedStockOperation::query()->where('created_by', $actor->getKey())->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new FeedRecipeConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $existing->load('cancellation');
            }
            $operation = $mutation((string) Str::uuid());
            $operation->forceFill([
                'kind' => $kind, 'created_by' => $actor->getKey(), 'idempotency_key' => $key, 'request_hash' => $hash,
            ])->save();

            return $operation->load('cancellation');
        }, 3);
    }

    public function recipe(int $id, bool $requireActive = true): FeedRecipe
    {
        $recipe = FeedRecipe::query()->whereKey($id)->sharedLock()->firstOrFail();
        if ($requireActive && $recipe->status !== FeedRecipeStatus::Active) {
            throw new FeedRecipeConflict('La receta debe estar activa para esta operación.');
        }

        return $recipe;
    }

    public function balance(int $recipeId, int $unitId): FeedBalance
    {
        FeedBalance::query()->insertOrIgnore([
            'feed_recipe_id' => $recipeId, 'production_unit_id' => $unitId, 'quantity_grams' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return FeedBalance::query()->where('feed_recipe_id', $recipeId)->where('production_unit_id', $unitId)->lockForUpdate()->firstOrFail();
    }

    public function grams(mixed $value, bool $positive = false, bool $allowNegative = true): int
    {
        try {
            return FeedStockGrams::parse($value, $positive, $allowNegative);
        } catch (InvalidArgumentException $exception) {
            throw new FeedRecipeConflict($exception->getMessage(), previous: $exception);
        }
    }

    /** @return array{mode:'sumar'|'restar'|'establecer', value:int, reason:string} */
    public function adjustment(string $mode, mixed $value, string $reason, bool $ingredient = false): array
    {
        if (! in_array($mode, ['sumar', 'restar', 'establecer'], true) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new FeedRecipeConflict('Debes indicar una acción válida y un motivo de hasta 2000 caracteres.');
        }

        return ['mode' => $mode, 'value' => $this->grams($value, $mode !== 'establecer', ! $ingredient), 'reason' => trim($reason)];
    }

    /** @param array<string, mixed> $attributes */
    public function operation(string $kind, string $key, User $actor, string $operationId, array $attributes): FeedStockOperation
    {
        $operation = new FeedStockOperation;
        $operation->forceFill([
            ...$attributes, 'kind' => $kind, 'operation_id' => $operationId, 'idempotency_key' => Str::lower($key),
            'created_by' => $actor->getKey(), 'request_hash' => '', 'occurred_at' => now(),
        ]);

        return $operation;
    }

    /** La auditoría del Action se escribe después de materializar la operación y antes del commit. */
    public function audit(FeedStockOperation $operation, User $actor, string $description, string $source): void
    {
        $distribution = $operation->snapshot['distribution'] ?? [];
        $ingredientChanges = static fn (string $attribute): array => array_map(static fn (array $line): array => [
            'product_id' => $line['product_id'], 'stock_location_id' => $line['stock_location_id'],
            'quantity_grams' => $line[$attribute],
        ], $distribution);
        $this->auditRecorder->record(AuditEntryData::forSubject(
            subject: $operation, actor: $actor, logName: 'inventory', event: 'feed_'.$operation->kind.'_recorded',
            description: $description, operationId: $operation->operation_id, source: $source,
            upId: $operation->production_unit_id,
            properties: [
                'subject_snapshot' => [
                    'kind' => $operation->kind, 'recipe_id' => $operation->feed_recipe_id,
                    'production_unit_id' => $operation->production_unit_id, 'poultry_house_id' => $operation->poultry_house_id,
                    'product_id' => $operation->product_id, 'quantity_grams' => (string) $operation->quantity_grams,
                    'factor' => $operation->factor === null ? null : (string) $operation->factor,
                    'snapshot' => $operation->snapshot, 'reason' => $operation->reason,
                    'inventory_movement_id' => $operation->inventory_movement_id, 'cancels_operation_id' => $operation->cancels_operation_id,
                    'occurred_at' => $operation->occurred_at->toIso8601String(),
                ],
                'result' => 'success',
            ],
            attributeChanges: [
                'old' => ['quantity_grams' => $operation->balance_before, 'ingredients' => $ingredientChanges('balance_before')],
                'new' => ['quantity_grams' => $operation->balance_after, 'ingredients' => $ingredientChanges('balance_after')],
            ],
        ));
    }
}
