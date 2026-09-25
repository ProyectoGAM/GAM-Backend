<?php

namespace App\Actions\Inventory;

use App\Actions\SuppliersAndCatalogs\CreateProductAction;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Inventory\StockLocationStatus;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\ValueObjects\Inventory\InventoryQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateFeedIngredientAction
{
    public function __construct(
        private CreateProductAction $createProduct,
        private CreateFeedStockLocationAction $createFeedStockLocation,
        private RecordInventoryMovementAction $recordMovement,
    ) {}

    /**
     * @param  array{sku:string, nombre:string, cantidad:string, unidad?:string, proveedor_id?:int|null, idempotency_key:string}  $attributes
     * @return array{product:Product, movement:InventoryMovement, stock:StockBalance}
     */
    public function execute(PoultryHouse $house, array $attributes, User $actor): array
    {
        if ($house->type !== PoultryHouseType::Feed) {
            throw new InventoryConflict('El galpón indicado no es una planta de ración.');
        }

        $inputUnit = BaseUnit::tryFrom((string) ($attributes['unidad'] ?? BaseUnit::Gram->value));
        if (! in_array($inputUnit, [BaseUnit::Gram, BaseUnit::Kilogram], true)) {
            throw new InventoryConflict('La unidad de entrada debe ser g o kg.');
        }

        try {
            $quantity = InventoryQuantity::fromInput(
                (string) $attributes['cantidad'],
                $inputUnit,
                BaseUnit::Gram,
                false,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InventoryConflict($exception->getMessage(), previous: $exception);
        }

        $supplierId = array_key_exists('proveedor_id', $attributes) && $attributes['proveedor_id'] !== null
            ? (int) $attributes['proveedor_id']
            : null;
        $sku = trim((string) $attributes['sku']);
        $name = trim((string) $attributes['nombre']);
        $movementType = $supplierId === null ? InventoryMovementType::OpeningBalance : InventoryMovementType::Receipt;
        $payload = [
            'type' => $movementType->value,
            'feed_ingredient' => [
                'poultry_house_id' => (int) $house->getKey(),
                'sku' => $sku,
                'nombre' => $name,
                'quantity_g' => $quantity->toString(),
                'supplier_id' => $supplierId,
            ],
        ];
        $operationId = (string) $attributes['idempotency_key'];
        $expectedHash = (new InventoryMovementCommand(
            type: $movementType,
            lines: [],
            operationId: $operationId,
            idempotencyPayload: $payload,
        ))->requestHash();

        $existing = InventoryMovement::query()->where('operation_id', $operationId)->first();
        if ($existing !== null) {
            return $this->resolveReplay($existing, $expectedHash, $house);
        }

        return DB::transaction(function () use ($house, $actor, $supplierId, $movementType, $payload, $operationId, $quantity, $sku, $name): array {
            $this->lockConcurrentFeedIngredient($operationId, $house->getKey(), $sku, $name);

            $existingInsideTransaction = InventoryMovement::query()
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();
            if ($existingInsideTransaction !== null) {
                $expectedHash = (new InventoryMovementCommand(
                    type: $movementType,
                    lines: [],
                    operationId: $operationId,
                    idempotencyPayload: $payload,
                ))->requestHash();

                return $this->resolveReplay($existingInsideTransaction, $expectedHash, $house);
            }

            $location = StockLocation::query()
                ->where('poultry_house_id', $house->getKey())
                ->lockForUpdate()
                ->first();
            if ($location === null) {
                $location = $this->createFeedStockLocation->execute($house, $actor);
            }
            if (! $location->system_managed
                || $location->status !== StockLocationStatus::Active
                || (int) $location->poultry_house_id !== (int) $house->getKey()) {
                throw new InventoryConflict('La ubicación técnica de la planta de ración no es válida.');
            }

            $product = Product::query()
                ->where('sku', $sku)
                ->lockForUpdate()
                ->first();
            if ($product === null) {
                if (Product::query()->where('normalized_name', Str::lower($name))->exists()) {
                    throw new InventoryConflict('El nombre del ingrediente ya está registrado.');
                }

                try {
                    $product = $this->createProduct->execute([
                        'sku' => $sku,
                        'name' => $name,
                        'kind' => ProductKind::RawMaterial->value,
                        'base_unit' => BaseUnit::Gram->value,
                        'stock_tracked' => true,
                        'status' => ProductStatus::Active->value,
                    ], $actor, $operationId, 'api');
                } catch (QueryException $exception) {
                    if (! $this->isProductUniqueViolation($exception)) {
                        throw $exception;
                    }

                    $product = Product::query()->where('sku', $sku)->lockForUpdate()->first();
                    if ($product === null) {
                        throw new InventoryConflict('El SKU o nombre del ingrediente ya está registrado.', previous: $exception);
                    }
                }
            }

            if ($product->kind !== ProductKind::RawMaterial
                || $product->base_unit !== BaseUnit::Gram
                || ! $product->stock_tracked
                || $product->status !== ProductStatus::Active) {
                throw new InventoryConflict('El SKU indicado no corresponde a una materia prima activa en gramos.');
            }
            if (mb_strtolower(trim($product->name)) !== mb_strtolower($name)) {
                throw new InventoryConflict('El SKU indicado ya está registrado con otro nombre.');
            }

            $movement = $this->recordMovement->execute(new InventoryMovementCommand(
                type: $movementType,
                lines: [[
                    'product_id' => (int) $product->getKey(),
                    'stock_location_id' => (int) $location->getKey(),
                    'on_hand_delta' => $quantity->toString(),
                    'unit' => BaseUnit::Gram->value,
                ]],
                operationId: $operationId,
                supplierId: $supplierId,
                referenceType: 'feed_ingredient',
                referenceId: (string) $product->getKey(),
                idempotencyPayload: $payload,
            ), $actor);

            $stock = StockBalance::query()
                ->where('product_id', $product->getKey())
                ->where('stock_location_id', $location->getKey())
                ->with(['product', 'stockLocation'])
                ->firstOrFail();

            return compact('product', 'movement', 'stock');
        }, 3);
    }

    /**
     * Serializa altas concurrentes de la misma operación, planta, SKU o nombre
     * dentro de la transacción actual de PostgreSQL.
     */
    private function lockConcurrentFeedIngredient(int|string $operationId, int|string $houseId, string $sku, string $name): void
    {
        foreach ([
            'feed-ingredient-operation:'.$operationId,
            'feed-ingredient-house:'.$houseId,
            'feed-ingredient-sku:'.$sku,
            'feed-ingredient-name:'.Str::lower($name),
        ] as $lockKey) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);
        }
    }

    private function isProductUniqueViolation(QueryException $exception): bool
    {
        if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
            return false;
        }

        $constraint = (string) ($exception->errorInfo[2] ?? '');

        return str_contains($constraint, 'products_sku_unique')
            || str_contains($constraint, 'products_normalized_name_unique');
    }

    /** @return array{product:Product, movement:InventoryMovement, stock:StockBalance} */
    private function resolveReplay(InventoryMovement $movement, string $expectedHash, PoultryHouse $house): array
    {
        if ($movement->request_hash !== $expectedHash) {
            throw new InventoryConflict('La clave de idempotencia ya fue utilizada con otros datos.');
        }

        $movement->load(['lines.product', 'lines.stockLocation', 'supplier', 'creator']);
        $line = $movement->lines->first(static fn (InventoryMovementLine $movementLine): bool => (int) $movementLine->stockLocation?->poultry_house_id === (int) $house->getKey());
        if ($line === null || $line->product === null || $line->stockLocation === null) {
            throw new InventoryConflict('La operación existente no corresponde a la planta de ración indicada.');
        }

        $stock = StockBalance::query()
            ->where('product_id', $line->product_id)
            ->where('stock_location_id', $line->stock_location_id)
            ->with(['product', 'stockLocation'])
            ->firstOrFail();

        return ['product' => $line->product, 'movement' => $movement, 'stock' => $stock];
    }
}
