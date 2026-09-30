<?php

namespace App\Actions\ManagementPlans;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\ManualPractice;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Support\Str;

final readonly class RecordManualPracticeAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private ResolveManagementExecutionContext $contextResolver,
        private FlockActivityJournal $activities,
        private AuditRecorder $auditRecorder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(Flock $flock, array $attributes, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'management-plans.execute',
            'management-plans.practice.record',
            $attributes['idempotency_key'],
            ['flock' => $flock->public_id, ...$attributes],
            function (string $operationId) use ($flock, $attributes, $actor, $source): array {
                $context = $this->contextResolver->execute($flock, $actor, $attributes, 'manual_practice');
                $practiceType = $attributes['practice_type'];
                $title = match ($practiceType) {
                    'beak_trimming' => 'Despique',
                    'nest_placement' => 'Colocación de nidos',
                    default => trim($attributes['title']),
                };
                $practice = new ManualPractice;
                $practice->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'flock_id' => $context->flock->getKey(),
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_snapshot' => $context->planActivityTitleSnapshot,
                    'practice_type' => $practiceType,
                    'practice_title_snapshot' => $title,
                    'occurred_at' => $context->occurredAt,
                    'responsible_user_id' => $context->responsibleUserId,
                    'responsible_name_snapshot' => $context->responsibleNameSnapshot,
                    'notes' => trim($attributes['notes']),
                    'created_by' => $actor->getKey(),
                    'operation_id' => $operationId,
                ])->save();
                $snapshot = [
                    'id' => $practice->public_id,
                    'flock_id' => $context->flock->public_id,
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_at_application' => $context->planActivityTitleSnapshot,
                    'practice_type' => $practiceType,
                    'title' => $title,
                    'occurred_at' => $context->occurredAt->toIso8601String(),
                    'responsible' => [
                        'id' => $context->responsibleUserId,
                        'name_at_application' => $context->responsibleNameSnapshot,
                    ],
                    'notes' => $practice->notes,
                    'created_by' => (int) $actor->getKey(),
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $practice,
                    actor: $actor,
                    logName: 'management_plans',
                    event: 'manual_practice_recorded',
                    description: 'Práctica manual registrada',
                    operationId: $operationId,
                    source: $source,
                    upId: $context->flock->production_unit_id,
                    properties: ['result' => 'success', 'subject_snapshot' => $snapshot],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                ));
                $this->activities->record($context->flock, $operationId, 'manual_practice', 'management-plans.practice.record');

                return ['manual_practice' => $snapshot, 'warnings' => []];
            },
        );
    }
}
