<?php

namespace App\Modules\Inventory\Application\PublicApi\Actions;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\Inventory\Application\Services\RunEggStockCommand;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;

final readonly class RecordManualEggStockAction
{
    public function __construct(private RunEggStockCommand $commands, private RecordEggStockTransactionAction $stock, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(ProductionUnit $unit, array $data, User $actor, int $sign = 1, string $type = 'manual_receipt', string $source = 'api'): array
    {
        $command = $this->commands->execute($actor, 'egg-stock.move', 'egg-stock.record', $data['idempotency_key'], ['unit' => $unit->id, ...$data, 'type' => $type], function (string $operationId) use ($unit, $data, $actor, $sign, $type, $source): array {
            $lockedUnit = ProductionUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedUnit->status !== ProductionUnitStatus::Active) {
                throw new LotsConflict('La unidad productiva debe estar operativa para registrar nuevos movimientos.');
            }
            $stock = $this->stock->execute($lockedUnit, $type, (int) $data['quantity'], $operationId, $actor, $data['occurred_at'] ?? null, $data['reason'] ?? null, $data['notes'] ?? null, 'manual', $operationId, $sign, $source);
            $this->audit->record(AuditEntryData::forSubject(subject: $stock['transaction'], actor: $actor, logName: 'inventory', event: 'egg_stock_transaction_recorded', description: 'Movimiento de huevos registrado', operationId: $operationId, upId: $lockedUnit->id, source: $source, properties: ['type' => $type, 'quantity' => $data['quantity'], 'result' => 'success']));

            return ['transaction' => $stock['transaction']->public_id, 'movement_id' => $stock['movement']->id];
        });

        return $command->result;
    }
}
