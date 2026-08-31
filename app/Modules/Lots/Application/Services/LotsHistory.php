<?php

namespace App\Modules\Lots\Application\Services;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class LotsHistory
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     */
    public function movement(string $operationId, string $type, ?Flock $from, ?Flock $to, int $quantity, array $before, array $after, CarbonImmutable $time, User $actor, ?string $reason = null, ?int $reverses = null): FlockMovement
    {
        $movement = new FlockMovement;
        $movement->forceFill([
            'public_id' => (string) Str::ulid(),
            'operation_id' => $operationId, 'type' => $type, 'quantity' => $quantity,
            'source_flock_id' => $from?->id, 'destination_flock_id' => $to?->id,
            'source_poultry_house_id' => $from === null ? null : ($before[$from->public_id]['poultry_house_id'] ?? $from->poultry_house_id),
            'destination_poultry_house_id' => $to?->poultry_house_id,
            'before' => $before, 'after' => $after, 'occurred_at' => $time,
            'created_by' => $actor->id, 'reason' => $reason, 'reverses_movement_id' => $reverses,
        ])->save();

        return $movement->setRelation('sourceFlock', $from)->setRelation('destinationFlock', $to)
            ->setRelation('reversedMovement', $reverses === null ? null : FlockMovement::query()->findOrFail($reverses));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function audit(Model $subject, User $actor, string $event, string $description, string $operationId, array $before, array $after, ?int $upId = null, string $source = 'api', ?string $reason = null): void
    {
        $this->auditRecorder->record(AuditEntryData::forSubject(
            subject: $subject, actor: $actor, logName: 'lots', event: $event,
            description: $description, operationId: $operationId, upId: $upId, source: $source,
            properties: ['result' => 'success', 'subject_snapshot' => $after, 'reason' => $reason],
            attributeChanges: ['old' => $before, 'new' => $after],
        ));
    }
}
