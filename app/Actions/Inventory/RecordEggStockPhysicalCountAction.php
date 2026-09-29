<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Inventory\InventoryMovementType;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockTransaction;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\Inventory\RunEggStockCommand;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RecordEggStockPhysicalCountAction
{
    public function __construct(
        private RunEggStockCommand $commands,
        private EnsureEggStockAccountAction $accounts,
        private RecordInventoryMovementAction $inventory,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(ProductionUnit $unit, array $data, User $actor, string $source = 'api'): array
    {
        return $this->commands->execute(
            $actor,
            'egg-stock.adjust',
            'egg-stock.physical-count',
            $data['idempotency_key'],
            ['unit' => $unit->getKey(), ...$data],
            function (string $operationId) use ($unit, $data, $actor, $source): array {
                return DB::transaction(function () use ($unit, $data, $actor, $source, $operationId): array {
                    $lockedUnit = ProductionUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();
                    if ($lockedUnit->status !== ProductionUnitStatus::Active) {
                        throw new LotsConflict('La unidad productiva debe estar operativa para registrar nuevos movimientos.');
                    }

                    $account = $this->accounts->execute($lockedUnit);
                    Product::query()->whereKey($account->product_id)->sharedLock()->firstOrFail();
                    StockLocation::query()->whereKey($account->stock_location_id)->lockForUpdate()->firstOrFail();
                    $balance = StockBalance::query()
                        ->where('product_id', $account->product_id)
                        ->where('stock_location_id', $account->stock_location_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    try {
                        $balanceBefore = (int) BigDecimal::of((string) $balance->on_hand_quantity)
                            ->toScale(0, RoundingMode::Unnecessary)
                            ->__toString();
                    } catch (RoundingNecessaryException $exception) {
                        throw new LotsConflict('El saldo actual no representa una cantidad entera de huevos.', previous: $exception);
                    }

                    if ($balanceBefore !== (int) $data['expected_balance']) {
                        throw new LotsConflict(
                            'El saldo teórico cambió. Actualiza el saldo antes de registrar el conteo.',
                            code: 'egg_stock_balance_changed',
                            meta: ['expected_balance' => (int) $data['expected_balance'], 'current_balance' => $balanceBefore],
                        );
                    }

                    $countedQuantity = (int) $data['counted_quantity'];
                    $difference = $countedQuantity - $balanceBefore;
                    $occurredAt = CarbonImmutable::parse($data['occurred_at']);
                    $transaction = new EggStockTransaction;
                    $transaction->forceFill([
                        'public_id' => (string) Str::ulid(),
                        'production_unit_id' => $lockedUnit->getKey(),
                        'egg_stock_account_id' => $account->getKey(),
                        'type' => 'physical_count',
                        'quantity' => abs($difference),
                        'counted_quantity' => $countedQuantity,
                        'balance_before' => $balanceBefore,
                        'difference' => $difference,
                        'occurred_at' => $occurredAt,
                        'reason' => $data['reason'],
                        'status' => 'recorded',
                        'version' => 1,
                        'created_by' => $actor->getKey(),
                    ])->save();

                    if ($difference !== 0) {
                        $this->inventory->execute(new InventoryMovementCommand(
                            type: InventoryMovementType::Adjustment,
                            lines: [[
                                'product_id' => $account->product_id,
                                'stock_location_id' => $account->stock_location_id,
                                'on_hand_delta' => (string) $difference,
                            ]],
                            operationId: $operationId,
                            referenceType: 'egg_stock_transaction',
                            referenceId: $transaction->public_id,
                            reason: $data['reason'],
                            occurredAt: $occurredAt->toIso8601String(),
                            eggAccountOperation: true,
                        ), $actor, $source);
                    }

                    $direction = match (true) {
                        $difference > 0 => 'surplus',
                        $difference < 0 => 'shortage',
                        default => 'equal',
                    };
                    $this->audit->record(AuditEntryData::forSubject(
                        subject: $transaction,
                        actor: $actor,
                        logName: 'inventory',
                        event: 'egg_stock_physical_count_recorded',
                        description: 'Conteo físico de huevos registrado',
                        operationId: $operationId,
                        upId: $lockedUnit->getKey(),
                        source: $source,
                        properties: [
                            'subject_snapshot' => [
                                'id' => $transaction->public_id,
                                'production_unit_id' => $lockedUnit->getKey(),
                                'occurred_at' => $occurredAt->toIso8601String(),
                                'reason' => $data['reason'],
                                'balance_before' => $balanceBefore,
                                'counted_quantity' => $countedQuantity,
                                'difference' => $difference,
                                'actor_id' => $actor->getKey(),
                            ],
                            'direction' => $direction,
                            'result' => 'success',
                        ],
                        attributeChanges: [
                            'old' => ['balance' => $balanceBefore],
                            'new' => ['balance' => $countedQuantity, 'difference' => $difference],
                        ],
                    ));

                    return ['transaction' => $transaction->public_id];
                }, 3);
            },
        )->result;
    }
}
