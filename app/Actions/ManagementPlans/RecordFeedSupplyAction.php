<?php

namespace App\Actions\ManagementPlans;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\FeedSupply;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Support\Str;

final readonly class RecordFeedSupplyAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private AuditRecorder $audit, private FlockActivityJournal $activities) {}

    /** Registra alimento administrado; el consumo de materias primas pertenece a elaboración. */
    public function execute(Flock $flock, array $data, User $actor): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.execute', 'management-plans.feed.record', $data['idempotency_key'],
            ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor): array {
                $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
                $this->state->open($locked);
                $date = $this->state->time($locked, $data['occurred_at']);
                $product = Product::query()->whereKey($data['product_id'])->sharedLock()->firstOrFail();
                if ($product->kind !== ProductKind::FinishedFeed || $product->status !== ProductStatus::Active) {
                    throw new LotsConflict('Selecciona un alimento terminado activo.');
                }
                $record = new FeedSupply;
                $record->forceFill(['public_id' => (string) Str::ulid(), 'flock_id' => $locked->id,
                    'poultry_house_id' => $locked->poultry_house_id, 'production_unit_id' => $locked->production_unit_id,
                    'product_id' => $product->id, 'product_name_snapshot' => $product->name,
                    'quantity' => $data['quantity'], 'unit' => $data['unit'], 'occurred_at' => $date,
                    'notes' => $data['notes'] ?? null, 'status' => 'recorded', 'operation_id' => $operationId, 'created_by' => $actor->id])->save();
                $snapshot = $record->only(['public_id', 'flock_id', 'poultry_house_id', 'production_unit_id', 'product_name_snapshot', 'quantity', 'unit', 'notes']);
                $snapshot['occurred_at'] = $date->toIso8601String();
                $this->audit->record(AuditEntryData::forSubject(subject: $record, actor: $actor, logName: 'management_plans',
                    event: 'feed_supply_recorded', description: 'Alimentación registrada', operationId: $operationId,
                    upId: $locked->production_unit_id, properties: ['result' => 'success', 'subject_snapshot' => $snapshot],
                    attributeChanges: ['old' => [], 'new' => $snapshot]));

                $this->activities->record($locked, $operationId, 'feed_supply', 'management-plans.feed.record');

                return ['feed_supply' => $snapshot];
            });
    }

    /** Anula el suministro mediante una operación auditada e idempotente. */
    public function cancel(Flock $flock, FeedSupply $supply, array $data, User $actor): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.execute', 'management-plans.feed.cancel', $data['idempotency_key'],
            ['flock' => $flock->public_id, 'supply' => $supply->public_id, ...$data], function (string $operationId) use ($flock, $supply, $data, $actor): array {
                $lockedFlock = $this->state->lock([$flock->public_id])->get($flock->public_id);
                $record = FeedSupply::query()->where('flock_id', $flock->id)->whereKey($supply->id)->lockForUpdate()->firstOrFail();
                if ($record->status !== 'recorded') {
                    throw new LotsConflict('Este suministro ya está anulado.');
                }
                $record->forceFill(['status' => 'cancelled'])->save();
                $this->audit->record(AuditEntryData::forSubject(subject: $record, actor: $actor, logName: 'management_plans',
                    event: 'feed_supply_cancelled', description: 'Alimentación anulada', operationId: $operationId,
                    upId: $record->production_unit_id, properties: ['result' => 'success', 'reason' => $data['reason']],
                    attributeChanges: ['old' => ['status' => 'recorded'], 'new' => ['status' => 'cancelled']]));

                $this->activities->record($lockedFlock, $operationId, 'feed_supply_cancelled', 'management-plans.feed.cancel');

                return ['feed_supply' => ['public_id' => $record->public_id, 'status' => 'cancelled']];
            });
    }
}
