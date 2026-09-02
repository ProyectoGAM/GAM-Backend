<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Inventory\EggStockTransaction;
use App\Models\Inventory\EggStockTransactionRevision;
use App\Models\Lots\EggCollection;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\Inventory\Application\PublicApi\Actions\RecordEggStockTransactionAction;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Events\EggCollectionCorrected;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CorrectEggCollectionAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private RecordEggStockTransactionAction $stock,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EggCollection $record, array $data, User $actor, bool $cancel = false, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'egg-collections.manage', $cancel ? 'eggs.cancel' : 'eggs.correct', $data['idempotency_key'], ['record' => $record->public_id, ...$data], function (string $operationId) use ($record, $data, $actor, $cancel, $source): array {
            $current = EggCollection::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($current->version !== (int) $data['version'] || $current->status !== 'recorded') {
                throw new LotsConflict('La recolección cambió de versión o ya fue cancelada.');
            }
            $transaction = EggStockTransaction::query()
                ->where('reference_type', 'egg_collection')
                ->where('reference_id', $current->public_id)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $this->snapshots->collection($current, $current->flock);
            $newQuantity = $cancel ? $current->quantity : (int) ($data['quantity'] ?? $current->quantity);
            $newOccurredAt = $cancel ? $current->occurred_at : (isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : $current->occurred_at);
            $newNotes = $cancel ? $current->notes : (array_key_exists('notes', $data) ? $data['notes'] : $current->notes);
            $reason = (string) $data['correction_reason'];
            if ($newQuantity < 1 || $newQuantity > 2147483647) {
                throw new LotsConflict('La cantidad debe ser un entero entre 1 y 2147483647.');
            }
            $dateChanged = $newOccurredAt->toIso8601String() !== $current->occurred_at->toIso8601String();

            if ($cancel) {
                $this->stock->adjust($transaction, -$current->quantity, (string) Str::uuid(), $actor, $current->occurred_at->toIso8601String(), $reason, $source);
            } elseif ($dateChanged) {
                $this->stock->adjust($transaction, -$current->quantity, (string) Str::uuid(), $actor, $current->occurred_at->toIso8601String(), $reason, $source);
                $this->stock->adjust($transaction, $newQuantity, (string) Str::uuid(), $actor, $newOccurredAt->toIso8601String(), $reason, $source);
            } elseif ($newQuantity !== $current->quantity) {
                $this->stock->adjust($transaction, $newQuantity - $current->quantity, (string) Str::uuid(), $actor, $current->occurred_at->toIso8601String(), $reason, $source);
            }

            $current->forceFill([
                'quantity' => $newQuantity,
                'occurred_at' => $newOccurredAt,
                'notes' => $newNotes,
                'status' => $cancel ? 'cancelled' : 'recorded',
                'version' => $current->version + 1,
            ])->save();
            $transaction->forceFill([
                'quantity' => $newQuantity,
                'occurred_at' => $newOccurredAt,
                'notes' => $newNotes,
                'status' => $cancel ? 'cancelled' : 'recorded',
                'version' => $transaction->version + 1,
            ])->save();
            $after = $this->snapshots->collection($current, $current->flock);
            EggStockTransactionRevision::query()->create([
                'public_id' => (string) Str::ulid(),
                'egg_stock_transaction_id' => $transaction->id,
                'operation_id' => $operationId,
                'action' => $cancel ? 'cancel' : 'correct',
                'before' => $before,
                'after' => $after,
                'correction_reason' => $reason,
                'created_by' => $actor->id,
            ]);
            $this->history->audit($current, $actor, $cancel ? 'egg_collection_cancelled' : 'egg_collection_corrected', $cancel ? 'Recolección cancelada' : 'Recolección rectificada', $operationId, $before, $after, $current->production_unit_id, $source, $reason);
            event(new EggCollectionCorrected($operationId, [$current->flock->public_id], $actor->id));

            return ['flock' => $this->snapshots->flock($current->flock), 'collection' => $after, 'stock_transaction' => $transaction->public_id];
        });
    }
}
