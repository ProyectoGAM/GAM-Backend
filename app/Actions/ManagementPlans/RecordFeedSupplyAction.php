<?php

namespace App\Actions\ManagementPlans;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\Lots\LotsConflict;
use App\Exceptions\ManagementPlans\FeedSupplyRetired;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\FeedSupply;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\RunLotsCommand;

final readonly class RecordFeedSupplyAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private AuditRecorder $audit, private FlockActivityJournal $activities) {}

    /** Reproduce operaciones históricas; las nuevas cargas se retiran en favor del consumo por receta y UP. */
    public function execute(Flock $flock, array $data, User $actor): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.execute', 'management-plans.feed.record', $data['idempotency_key'],
            ['flock' => $flock->public_id, ...$data], static fn (string $operationId): array => throw new FeedSupplyRetired);
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
