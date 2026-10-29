<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\Inventory\InventoryMovement;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\Inventory\StockLocationLocks;
use App\Services\Inventory\StockProductCompatibility;
use App\Services\Lots\LotsAuthorization;
use App\ValueObjects\Inventory\InventoryQuantity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class TransferStockAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement, private StockLocationLocks $locationLocks, private StockProductCompatibility $compatibility) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, User $actor): InventoryMovement
    {
        return DB::transaction(function () use ($attributes, $actor): InventoryMovement {
            $currentActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            LotsAuthorization::ensure($currentActor, 'inventory.move');

            return $this->executeWithinTransaction($attributes, $currentActor);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function executeWithinTransaction(array $attributes, User $actor): InventoryMovement
    {
        $productIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['product_id'],
            $attributes['lines'],
        )));
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $locationIds = [];
        foreach ($attributes['lines'] as $line) {
            $locationIds[] = (int) $line['from_stock_location_id'];
            $locationIds[] = (int) $line['to_stock_location_id'];
        }
        $locations = $this->locationLocks->lock($locationIds);
        $isReplay = InventoryMovement::query()->where('operation_id', $attributes['idempotency_key'])->exists();
        $lines = [];
        foreach ($attributes['lines'] as $line) {
            $product = $products->get((int) $line['product_id']);
            if ($product === null) {
                throw new InventoryConflict('El producto indicado no existe.');
            }
            foreach ([(int) $line['from_stock_location_id'], (int) $line['to_stock_location_id']] as $locationId) {
                if ($isReplay) {
                    continue;
                }
                $location = $locations->get($locationId);
                $this->compatibility->assertCompatible($product, $location);
                if (! $this->compatibility->isOperational($location)) {
                    throw new InventoryConflict('Una de las ubicaciones indicadas no está activa.');
                }
            }
            $inputUnit = null;
            if (array_key_exists('unit', $line) && $line['unit'] !== null) {
                $inputUnit = BaseUnit::tryFrom((string) $line['unit']);
                if ($inputUnit === null) {
                    throw new InventoryConflict('La unidad de entrada no es válida.');
                }
            }

            try {
                $quantity = InventoryQuantity::fromInput((string) $line['quantity'], $inputUnit, $product->base_unit, false)->toString();
            } catch (\InvalidArgumentException $exception) {
                throw new InventoryConflict($exception->getMessage(), previous: $exception);
            }
            $unit = $product->base_unit->value;
            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'stock_location_id' => (int) $line['from_stock_location_id'],
                'on_hand_delta' => '-'.ltrim($quantity, '+'),
                'unit' => $unit,
            ];
            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'stock_location_id' => (int) $line['to_stock_location_id'],
                'on_hand_delta' => $quantity,
                'unit' => $unit,
            ];
        }

        $lines = $this->mergeLines($lines);

        return $this->recordMovement->execute(new InventoryMovementCommand(
            type: InventoryMovementType::Transfer,
            lines: $lines,
            operationId: (string) $attributes['idempotency_key'],
            reason: $attributes['reason'] ?? null,
            occurredAt: $attributes['occurred_at'] ?? null,
        ), $actor, requiredPermission: 'inventory.move');
    }

    /** @param list<array{product_id:int, stock_location_id:int, on_hand_delta:string}> $lines */
    private function mergeLines(array $lines): array
    {
        $merged = [];
        foreach ($lines as $line) {
            $key = $line['product_id'].':'.$line['stock_location_id'];
            if (! isset($merged[$key])) {
                $merged[$key] = $line;

                continue;
            }
            $merged[$key]['on_hand_delta'] = (string) BigDecimal::of($merged[$key]['on_hand_delta'])->plus($line['on_hand_delta']);
        }

        return array_values(array_filter($merged, static fn (array $line): bool => ! BigDecimal::of($line['on_hand_delta'])->isZero()));
    }
}
