<?php

namespace App\Modules\Inventory\Application\PublicApi\Actions;

use App\Models\Inventory\EggStockTransaction;
use App\Models\Inventory\EggStockTransactionRevision;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\Inventory\Application\Services\RunEggStockCommand;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CorrectEggStockTransactionAction
{
    public function __construct(private RunEggStockCommand $commands, private RecordEggStockTransactionAction $stock, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(EggStockTransaction $transaction, array $data, User $actor, string $source = 'api'): array
    {
        if ($transaction->reference_type === 'egg_collection') {
            throw new LotsConflict('Las transacciones originadas por una recolección deben corregirse desde su endpoint.');
        }

        return $this->commands->execute($actor, 'egg-stock.adjust', 'egg-stock.correct', $data['idempotency_key'], ['transaction' => $transaction->public_id, ...$data], function (string $operationId) use ($transaction, $data, $actor, $source): array {
            $current = EggStockTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($current->version !== (int) $data['version'] || $current->status !== 'recorded') {
                throw new LotsConflict('El movimiento cambió de versión o ya fue cancelado.');
            }
            $before = $this->snapshot($current);
            $newQuantity = (int) ($data['quantity'] ?? $current->quantity);
            $newDate = isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : $current->occurred_at;
            $newReason = array_key_exists('reason', $data) ? $data['reason'] : $current->reason;
            $newNotes = array_key_exists('notes', $data) ? $data['notes'] : $current->notes;
            $sign = in_array($current->type, ['manual_receipt'], true) ? 1 : -1;
            $dateChanged = $newDate->toIso8601String() !== $current->occurred_at->toIso8601String();
            if ($dateChanged) {
                $this->stock->adjust($current, -$current->quantity * $sign, (string) Str::uuid(), $actor, $current->occurred_at->toIso8601String(), (string) $data['correction_reason'], $source);
                $this->stock->adjust($current, $newQuantity * $sign, (string) Str::uuid(), $actor, $newDate->toIso8601String(), (string) $data['correction_reason'], $source);
            } elseif ($newQuantity !== $current->quantity) {
                $this->stock->adjust($current, ($newQuantity - $current->quantity) * $sign, (string) Str::uuid(), $actor, $current->occurred_at->toIso8601String(), (string) $data['correction_reason'], $source);
            }
            $current->forceFill(['quantity' => $newQuantity, 'occurred_at' => $newDate, 'reason' => $newReason, 'notes' => $newNotes, 'version' => $current->version + 1])->save();
            $after = $this->snapshot($current);
            EggStockTransactionRevision::query()->create(['public_id' => (string) Str::ulid(), 'egg_stock_transaction_id' => $current->id, 'operation_id' => $operationId, 'action' => 'correct', 'before' => $before, 'after' => $after, 'correction_reason' => $data['correction_reason'], 'created_by' => $actor->id]);
            $this->audit->record(AuditEntryData::forSubject(subject: $current, actor: $actor, logName: 'inventory', event: 'egg_stock_transaction_corrected', description: 'Movimiento de huevos rectificado', operationId: $operationId, upId: $current->production_unit_id, source: $source, properties: ['result' => 'success'], attributeChanges: ['old' => $before, 'new' => $after]));

            return ['transaction' => $current->public_id];
        })->result;
    }

    /** @return array<string, mixed> */
    private function snapshot(EggStockTransaction $transaction): array
    {
        return ['id' => $transaction->public_id, 'tipo' => $transaction->type, 'cantidad' => $transaction->quantity, 'ocurrido_en' => $transaction->occurred_at->toIso8601String(), 'motivo' => $transaction->reason, 'observaciones' => $transaction->notes, 'estado' => $transaction->status, 'version' => $transaction->version];
    }
}
