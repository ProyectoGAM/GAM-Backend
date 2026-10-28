<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Inventory\StockLocationType;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Exceptions\Inventory\InventoryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ManagementPlans\VaccinationApplication;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Services\Inventory\StockLocationLocks;
use App\Services\Inventory\StockProductCompatibility;
use App\Services\Lots\LotsAuthorization;
use App\ValueObjects\Inventory\InventoryQuantity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RecordInventoryMovementAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private StockProductCompatibility $productCompatibility,
        private StockLocationLocks $locationLocks,
    ) {}

    public function execute(InventoryMovementCommand $command, User $actor, string $source = 'api', ?FeedStockOperation $feedCompensation = null, ?StockBalance $regularization = null, ?string $requiredPermission = 'inventory.move', ?InventoryMovement $healthCompensation = null): InventoryMovement
    {
        return DB::transaction(function () use ($command, $actor, $source, $feedCompensation, $regularization, $requiredPermission, $healthCompensation): InventoryMovement {
            $currentActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            if ($requiredPermission !== null) {
                LotsAuthorization::ensure($currentActor, $requiredPermission);
            }
            if ($healthCompensation !== null) {
                LotsAuthorization::ensure($currentActor, 'management-plans.execute');
            }
            $existing = $this->existingMovement($command->operationId);
            if ($existing !== null && (int) $existing->created_by !== (int) $currentActor->getKey()) {
                throw new InventoryConflict('La clave de idempotencia pertenece a otra operación.');
            }

            return $this->executeWithinTransaction($command, $currentActor, $source, $feedCompensation, $regularization, $healthCompensation);
        }, 3);
    }

    private function executeWithinTransaction(InventoryMovementCommand $command, User $actor, string $source, ?FeedStockOperation $feedCompensation, ?StockBalance $regularization, ?InventoryMovement $healthCompensation): InventoryMovement
    {
        $legacyRequestHash = $this->legacyRequestHash($command);
        $normalizedCommand = $this->normalizeCommand($command);
        $requestHash = $normalizedCommand->requestHash();
        $existing = $this->existingMovement($normalizedCommand->operationId);

        if ($existing !== null) {
            return $this->resolveReplay($existing, $requestHash, $legacyRequestHash);
        }

        try {
            return DB::transaction(function () use ($normalizedCommand, $actor, $requestHash, $legacyRequestHash, $source, $feedCompensation, $regularization, $healthCompensation): InventoryMovement {
                $existingInsideTransaction = $this->existingMovement($normalizedCommand->operationId);
                if ($existingInsideTransaction !== null) {
                    return $this->resolveReplay($existingInsideTransaction, $requestHash, $legacyRequestHash);
                }

                if ($normalizedCommand->lines === []) {
                    throw new InventoryConflict('La operación debe contener al menos una línea.');
                }

                $compensatedProductIds = $feedCompensation === null ? [] : $this->feedCompensationProducts($normalizedCommand, $feedCompensation);
                if ($healthCompensation !== null) {
                    $this->assertHealthCompensation($normalizedCommand, $healthCompensation);
                }
                $lineKeys = [];
                $locationIds = [];
                $normalizedLines = [];
                $productIds = [];
                foreach ($normalizedCommand->lines as $line) {
                    $key = $line['product_id'].':'.$line['stock_location_id'];
                    if (isset($lineKeys[$key])) {
                        throw new InventoryConflict('No puedes repetir el mismo producto y ubicación en una operación.');
                    }
                    $lineKeys[$key] = true;
                    $productIds[$line['product_id']] = true;
                }
                $products = Product::query()
                    ->whereIn('id', array_keys($productIds))
                    ->orderBy('id')
                    ->sharedLock()
                    ->get()
                    ->keyBy('id');

                foreach ($normalizedCommand->lines as $line) {
                    /** Bloquea los productos antes de consultar su estado y unidad. */
                    $product = $products->get($line['product_id']);
                    $isHistoricalRecount = $regularization !== null && $normalizedCommand->type === InventoryMovementType::Adjustment
                        && count($normalizedCommand->lines) === 1 && (int) $regularization->product_id === $line['product_id']
                        && (int) $regularization->stock_location_id === $line['stock_location_id'];
                    if ($product === null || (! $isHistoricalRecount && $healthCompensation === null && (($product->status->value !== 'active' && ! in_array((int) $product->getKey(), $compensatedProductIds, true)) || ! $product->stock_tracked))) {
                        throw new InventoryConflict('El producto indicado no está activo o no controla stock.');
                    }
                    if ($product->system_key === 'generic_egg' && ! $normalizedCommand->eggAccountOperation) {
                        throw new InventoryConflict('El producto técnico Huevo sólo puede moverse desde el módulo de stock de huevos.');
                    }

                    try {
                        $onHandDelta = InventoryQuantity::from(
                            (string) $line['on_hand_delta'],
                            $product->base_unit,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw new InventoryConflict($exception->getMessage(), previous: $exception);
                    }

                    if ($onHandDelta->isZero()) {
                        throw new InventoryConflict('La operación debe modificar al menos un saldo.');
                    }

                    $isEggAccount = EggStockAccount::query()
                        ->where('product_id', $line['product_id'])
                        ->where('stock_location_id', $line['stock_location_id'])
                        ->exists();
                    if ($normalizedCommand->eggAccountOperation && ($product->system_key !== 'generic_egg' || ! $isEggAccount)) {
                        throw new InventoryConflict('La operación técnica sólo puede utilizar una cuenta válida de huevos.');
                    }
                    if ($isEggAccount && ! $normalizedCommand->eggAccountOperation) {
                        throw new InventoryConflict('Las cuentas técnicas de huevos sólo pueden modificarse desde el módulo de huevos.');
                    }

                    $normalizedLines[] = [
                        ...$line,
                        'on_hand_delta' => $onHandDelta->toString(),
                    ];
                    $locationIds[$line['stock_location_id']] = true;
                }

                $supplierId = $normalizedCommand->supplierId;
                if ($supplierId !== null || ($normalizedCommand->type === InventoryMovementType::Receipt && ! $normalizedCommand->eggAccountOperation)) {
                    $supplier = Supplier::query()
                        ->when($supplierId !== null, fn (Builder $query): Builder => $query->whereKey($supplierId), fn (Builder $query): Builder => $query->where('system_key', 'internal_supplier'))
                        ->sharedLock()->first();
                    if ($supplier === null || $supplier->status !== SupplierStatus::Active) {
                        throw new InventoryConflict($supplierId === null ? 'El proveedor interno Propio no está configurado o está inactivo.' : 'El proveedor indicado no está activo.');
                    }
                    $supplierId = (int) $supplier->getKey();
                }

                $locations = $this->locationLocks->lock(array_keys($locationIds));

                if ($locations->count() !== count($locationIds) || $locations->contains(fn (StockLocation $location): bool => ! $this->productCompatibility->isOperational($location)
                    && ($regularization === null || (int) $regularization->stock_location_id !== (int) $location->getKey()))) {
                    throw new InventoryConflict('Una de las ubicaciones indicadas no está activa.');
                }

                foreach ($normalizedLines as $line) {
                    $product = $products->get($line['product_id']);
                    $location = $locations->get($line['stock_location_id']);
                    if ($regularization !== null && (int) $regularization->product_id === $line['product_id'] && (int) $regularization->stock_location_id === $line['stock_location_id']) {
                        $this->assertRegularization($normalizedCommand, $regularization, $product, $location, $line['on_hand_delta']);

                        continue;
                    }
                    // Una corrección histórica sólo devuelve las líneas originales, sin habilitar sanidad nueva.
                    if ($healthCompensation !== null && $location->type === StockLocationType::Health
                        && in_array($product->kind, [ProductKind::Medicine, ProductKind::Vaccine], true)) {
                        continue;
                    }
                    $this->productCompatibility->assertCompatible($product, $location);
                }

                $sortedLines = $normalizedLines;
                usort($sortedLines, static fn (array $left, array $right): int => [$left['stock_location_id'], $left['product_id']] <=> [$right['stock_location_id'], $right['product_id']]);
                $now = now();
                StockBalance::query()->insertOrIgnore(array_map(
                    static fn (array $line): array => [
                        'product_id' => $line['product_id'],
                        'stock_location_id' => $line['stock_location_id'],
                        'on_hand_quantity' => '0.000000',
                        'minimum_quantity' => '0.000000',
                        'allow_negative' => $normalizedCommand->eggAccountOperation
                            || ($locations->get($line['stock_location_id'])->poultryHouse?->type === PoultryHouseType::Feed
                                && $products->get($line['product_id'])->kind === ProductKind::RawMaterial),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $sortedLines,
                ));

                $balances = [];
                $balanceChanges = [];
                foreach ($sortedLines as $line) {
                    $balance = StockBalance::query()
                        ->where('product_id', $line['product_id'])
                        ->where('stock_location_id', $line['stock_location_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $balances[$line['product_id'].':'.$line['stock_location_id']] = $balance;
                }

                foreach ($sortedLines as $line) {
                    $balance = $balances[$line['product_id'].':'.$line['stock_location_id']];
                    $previousOnHand = BigDecimal::of((string) $balance->on_hand_quantity)->toScale(6);
                    $onHand = $previousOnHand->plus($line['on_hand_delta'])->toScale(6);
                    $product = $products->get($line['product_id']);
                    $location = $locations->get($line['stock_location_id']);
                    $isFeedRawMaterial = $location->poultry_house_id !== null
                        && $location->poultryHouse?->type === PoultryHouseType::Feed
                        && $product->kind === ProductKind::RawMaterial;
                    $allowNegative = $normalizedCommand->eggAccountOperation || $isFeedRawMaterial;
                    $previousAllowNegative = (bool) $balance->allow_negative;

                    try {
                        InventoryQuantity::from((string) $onHand, $product->base_unit);
                    } catch (InvalidArgumentException $exception) {
                        throw new InventoryConflict($exception->getMessage(), previous: $exception);
                    }

                    if ($onHand->isNegative() && ! $allowNegative) {
                        throw new InventoryConflict('El stock disponible resultante no puede ser negativo.');
                    }

                    $balance->forceFill([
                        'on_hand_quantity' => (string) $onHand,
                        'allow_negative' => $allowNegative,
                    ])->save();
                    $balanceChanges[] = [
                        'product_id' => (int) $product->getKey(), 'product_name' => $product->name,
                        'stock_location_id' => (int) $location->getKey(), 'stock_location_name' => $location->name,
                        'location_type' => $location->type->value, 'unit' => $product->base_unit->value,
                        'production_unit_id' => $location->production_unit_id,
                        'before' => (string) $previousOnHand, 'after' => (string) $onHand,
                        'allow_negative_before' => $previousAllowNegative, 'allow_negative_after' => $allowNegative,
                    ];

                    if ($isFeedRawMaterial && ! $previousOnHand->isNegative() && $onHand->isNegative()) {
                        $this->auditRecorder->record(AuditEntryData::forSubject(
                            subject: $balance,
                            actor: $actor,
                            logName: 'inventory',
                            event: 'inventory_stock_became_negative',
                            description: 'El saldo de inventario pasó a ser negativo',
                            operationId: $normalizedCommand->operationId,
                            source: $source,
                            upId: $location->production_unit_id,
                            properties: [
                                'subject_snapshot' => [
                                    'product_id' => (int) $product->getKey(),
                                    'product_sku' => $product->sku,
                                    'product_name' => $product->name,
                                    'stock_location_id' => (int) $location->getKey(),
                                    'stock_location_name' => $location->name,
                                    'poultry_house_id' => (int) $location->poultry_house_id,
                                    'production_unit_id' => (int) $location->production_unit_id,
                                    'on_hand_quantity' => (string) $onHand,
                                ],
                                'product_id' => (int) $line['product_id'],
                                'stock_location_id' => (int) $line['stock_location_id'],
                                'previous_quantity' => (string) $previousOnHand,
                                'resulting_quantity' => (string) $onHand,
                                'result' => 'negative_balance',
                            ],
                            attributeChanges: [
                                'old' => [
                                    'on_hand_quantity' => (string) $previousOnHand,
                                    'allow_negative' => $previousAllowNegative,
                                ],
                                'new' => [
                                    'on_hand_quantity' => (string) $onHand,
                                    'allow_negative' => true,
                                ],
                            ],
                        ));
                    }
                }

                $movement = new InventoryMovement;
                $movement->forceFill([
                    'operation_id' => $normalizedCommand->operationId,
                    'request_hash' => $requestHash,
                    'type' => $normalizedCommand->type,
                    'supplier_id' => $supplierId,
                    'reference_type' => $normalizedCommand->referenceType,
                    'reference_id' => $normalizedCommand->referenceId,
                    'reason' => $normalizedCommand->reason,
                    'occurred_at' => $normalizedCommand->occurredAt === null ? now() : $normalizedCommand->occurredAt,
                    'created_by' => $actor->getKey(),
                    'reverses_movement_id' => $normalizedCommand->reversesMovementId,
                ])->save();

                foreach ($sortedLines as $line) {
                    $movementLine = new InventoryMovementLine;
                    $movementLine->forceFill([
                        'inventory_movement_id' => $movement->getKey(),
                        'product_id' => $line['product_id'],
                        'stock_location_id' => $line['stock_location_id'],
                        'unit' => $products->get($line['product_id'])->base_unit->value,
                        'on_hand_delta' => $line['on_hand_delta'],
                    ])->save();
                }

                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $movement,
                    actor: $actor,
                    logName: 'inventory',
                    event: 'inventory_movement_recorded',
                    description: 'Movimiento de inventario registrado',
                    operationId: $normalizedCommand->operationId,
                    traceId: null,
                    source: $source,
                    upId: null,
                    properties: [
                        'movement_type' => $normalizedCommand->type->value,
                        'supplier_id' => $supplierId,
                        'reference_type' => $normalizedCommand->referenceType,
                        'reference_id' => $normalizedCommand->referenceId,
                        'line_count' => count($sortedLines),
                        'product_ids' => array_values(array_unique(array_column($sortedLines, 'product_id'))),
                        'stock_location_ids' => array_values(array_unique(array_column($sortedLines, 'stock_location_id'))),
                        'result' => 'success',
                        'subject_snapshot' => ['balances' => $balanceChanges, 'supplier_id' => $supplierId],
                    ],
                    attributeChanges: [
                        'old' => ['balances' => array_map(static fn (array $change): array => ['product_id' => $change['product_id'], 'stock_location_id' => $change['stock_location_id'], 'quantity' => $change['before'], 'allow_negative' => $change['allow_negative_before']], $balanceChanges)],
                        'new' => ['balances' => array_map(static fn (array $change): array => ['product_id' => $change['product_id'], 'stock_location_id' => $change['stock_location_id'], 'quantity' => $change['after'], 'allow_negative' => $change['allow_negative_after']], $balanceChanges)],
                    ],
                ));

                return $movement->load([
                    'lines.product' => fn (BelongsTo $productRelation): Builder => $this->productResourceMetadata($productRelation),
                    'lines.stockLocation',
                    'supplier',
                    'creator',
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existingAfterRace = $this->existingMovement($normalizedCommand->operationId);
            if ($existingAfterRace === null) {
                throw $exception;
            }

            return $this->resolveReplay($existingAfterRace, $requestHash, $legacyRequestHash);
        }
    }

    private function legacyRequestHash(InventoryMovementCommand $command): ?string
    {
        if ($command->idempotencyPayload !== null
            || array_filter($command->lines, static fn (array $line): bool => ($line['unit'] ?? null) !== null) !== []) {
            return null;
        }

        $legacyLines = array_map(static function (array $line): array {
            unset($line['unit']);

            return $line;
        }, $command->lines);

        return (new InventoryMovementCommand(
            type: $command->type,
            lines: $legacyLines,
            operationId: $command->operationId,
            supplierId: $command->supplierId,
            referenceType: $command->referenceType,
            referenceId: $command->referenceId,
            reason: $command->reason,
            occurredAt: $command->occurredAt,
            reversesMovementId: $command->reversesMovementId,
            eggAccountOperation: $command->eggAccountOperation,
        ))->requestHash();
    }

    /**
     * Sólo admite la inversa completa de una elaboración ligada a su cancelación transaccional.
     *
     * @return list<int>
     */
    private function feedCompensationProducts(InventoryMovementCommand $command, FeedStockOperation $original): array
    {
        $original = FeedStockOperation::query()->whereKey($original->getKey())->where('kind', 'manufacture')->sharedLock()->first();
        $cancellation = FeedStockOperation::query()->where('operation_id', $command->operationId)->where('kind', 'cancellation')->first();
        if ($original === null || $cancellation === null
            || (int) $cancellation->cancels_operation_id !== (int) $original->getKey()
            || $command->type !== InventoryMovementType::Reversal
            || $command->reversesMovementId !== (int) $original->inventory_movement_id
            || $command->referenceType !== 'feed_manufacture_cancellation'
            || $command->referenceId !== (string) $cancellation->getKey()) {
            throw new InventoryConflict('La compensación debe corresponder a la cancelación de una elaboración original.');
        }
        $movement = InventoryMovement::query()->whereKey($original->inventory_movement_id)->firstOrFail()->load('lines');
        if ($movement->reference_type !== 'feed_manufacture' || $movement->reference_id !== (string) $original->getKey()
            || count($command->lines) !== $movement->lines->count() || $movement->reversal()->exists()) {
            throw new InventoryConflict('La compensación no corresponde al movimiento original de elaboración.');
        }
        foreach ($command->lines as $line) {
            $originalLine = $movement->lines->first(fn ($item): bool => (int) $item->product_id === $line['product_id']
                && (int) $item->stock_location_id === $line['stock_location_id']);
            if ($originalLine === null || BigDecimal::of((string) $originalLine->on_hand_delta)->compareTo(0) >= 0
                || BigDecimal::of($line['on_hand_delta'])->compareTo(BigDecimal::of((string) $originalLine->on_hand_delta)->negated()) !== 0) {
                throw new InventoryConflict('Debes devolver exactamente los ingredientes del movimiento original.');
            }
        }

        return $movement->lines->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /** Sólo admite la inversa exacta de un consumo vinculado a una aplicación sanitaria existente. */
    private function assertHealthCompensation(InventoryMovementCommand $command, InventoryMovement $original): void
    {
        $movement = InventoryMovement::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail()->load('lines');
        $application = match ($movement->reference_type) {
            'medicine_application' => MedicineApplication::query()->where('public_id', $movement->reference_id)->where('inventory_movement_id', $movement->getKey())->exists(),
            'vaccination_application' => VaccinationApplication::query()->where('public_id', $movement->reference_id)->where('inventory_movement_id', $movement->getKey())->exists(),
            default => false,
        };
        if (! $application || $command->type !== InventoryMovementType::Reversal
            || $command->reversesMovementId !== (int) $movement->getKey()
            || $command->referenceType !== 'inventory_movement' || $command->referenceId !== (string) $movement->getKey()
            || count($command->lines) !== $movement->lines->count() || $movement->reversal()->exists()) {
            throw new InventoryConflict('La compensación sanitaria debe corresponder a una aplicación original.');
        }
        foreach ($command->lines as $line) {
            $originalLine = $movement->lines->first(fn ($item): bool => (int) $item->product_id === $line['product_id']
                && (int) $item->stock_location_id === $line['stock_location_id']);
            if ($originalLine === null || BigDecimal::of((string) $originalLine->on_hand_delta)->compareTo(0) >= 0
                || BigDecimal::of($line['on_hand_delta'])->compareTo(BigDecimal::of((string) $originalLine->on_hand_delta)->negated()) !== 0) {
                throw new InventoryConflict('Debes devolver exactamente el consumo sanitario original.');
            }
        }
    }

    private function assertRegularization(InventoryMovementCommand $command, StockBalance $candidate, Product $product, StockLocation $location, string $delta): void
    {
        $balance = StockBalance::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
        $before = BigDecimal::of((string) $balance->on_hand_quantity);
        $after = $before->plus($delta);
        if ($command->referenceType !== 'stock_regularization' || $this->productCompatibility->isCompatible($product, $location)
            || $before->isZero() || (! $after->isZero() && ($before->isNegative() !== $after->isNegative() || $after->abs()->compareTo($before->abs()) >= 0))) {
            throw new InventoryConflict('La regularización sólo puede reducir un saldo histórico incompatible hacia cero.');
        }
        if (count($command->lines) === 1 && ! $after->isZero()) {
            throw new InventoryConflict('El recuento de regularización debe dejar el saldo incompatible en cero.');
        }
        if (count($command->lines) > 1) {
            $counterpart = array_values(array_filter($command->lines, fn (array $line): bool => $line['stock_location_id'] !== (int) $balance->stock_location_id));
            if ($command->type !== InventoryMovementType::Transfer || $before->isNegative() || count($counterpart) !== 1
                || $counterpart[0]['product_id'] !== (int) $balance->product_id || BigDecimal::of($counterpart[0]['on_hand_delta'])->compareTo(BigDecimal::of($delta)->negated()) !== 0) {
                throw new InventoryConflict('La regularización debe conservar la cantidad trasladada a un destino compatible.');
            }
        }
    }

    private function normalizeCommand(InventoryMovementCommand $command): InventoryMovementCommand
    {
        if ($command->lines === []) {
            return $command;
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['product_id'],
            $command->lines,
        )));
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $lines = [];

        foreach ($command->lines as $line) {
            $product = $products->get((int) $line['product_id']);
            if ($product === null) {
                $lines[] = $line;

                continue;
            }

            $inputUnit = null;
            if (array_key_exists('unit', $line) && $line['unit'] !== null) {
                $inputUnit = BaseUnit::tryFrom((string) $line['unit']);
                if ($inputUnit === null) {
                    throw new InventoryConflict('La unidad de entrada no es válida.');
                }
            }

            try {
                $delta = InventoryQuantity::fromInput(
                    (string) $line['on_hand_delta'],
                    $inputUnit,
                    $product->base_unit,
                );
            } catch (InvalidArgumentException $exception) {
                throw new InventoryConflict($exception->getMessage(), previous: $exception);
            }

            // La unidad se convierte antes del hash y se omite para conservar
            // la compatibilidad con las operaciones históricas sin unidad.
            $canonicalLine = [
                ...$line,
                'on_hand_delta' => $delta->toString(),
            ];
            unset($canonicalLine['unit']);
            $lines[] = $canonicalLine;
        }

        return new InventoryMovementCommand(
            type: $command->type,
            lines: $lines,
            operationId: $command->operationId,
            supplierId: $command->supplierId,
            referenceType: $command->referenceType,
            referenceId: $command->referenceId,
            reason: $command->reason,
            occurredAt: $command->occurredAt,
            reversesMovementId: $command->reversesMovementId,
            idempotencyPayload: $command->idempotencyPayload,
            eggAccountOperation: $command->eggAccountOperation,
        );
    }

    private function existingMovement(string $operationId): ?InventoryMovement
    {
        return InventoryMovement::query()->where('operation_id', $operationId)->first();
    }

    private function resolveReplay(InventoryMovement $movement, string $requestHash, ?string $legacyRequestHash = null): InventoryMovement
    {
        if (! in_array($movement->request_hash, array_values(array_filter([$requestHash, $legacyRequestHash])), true)) {
            throw new InventoryConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
        }

        return $movement->load([
            'lines.product' => fn (BelongsTo $productRelation): Builder => $this->productResourceMetadata($productRelation),
            'lines.stockLocation',
            'supplier',
            'creator',
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23505';
    }

    /**
     * Conserva el tipo concreto del producto al cargar metadatos públicos.
     *
     * @param  BelongsTo<Product, InventoryMovementLine>  $relation
     * @return Builder<Product>
     */
    private function productResourceMetadata(BelongsTo $relation): Builder
    {
        return $relation->getQuery()->withResourceMetadata();
    }
}
