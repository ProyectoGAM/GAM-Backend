<?php

namespace App\Actions\ManagementPlans;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Support\Str;

final readonly class RecordMedicineApplicationAction
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
            'management-plans.medication.apply',
            $attributes['idempotency_key'],
            ['flock' => $flock->public_id, ...$attributes],
            function (string $operationId) use ($flock, $attributes, $actor, $source): array {
                $context = $this->contextResolver->execute($flock, $actor, $attributes, 'medication');
                $medicine = Medicine::query()->where('public_id', $attributes['medicine_id'])->sharedLock()->firstOrFail();

                $applicationPublicId = (string) Str::ulid();
                $medicineSnapshot = [
                    'id' => $medicine->public_id,
                    'name' => $medicine->name,
                    'description' => $medicine->description,
                    'supplier_name_at_registration' => $medicine->supplier_name_snapshot,
                ];
                $application = new MedicineApplication;
                $application->forceFill([
                    'public_id' => $applicationPublicId,
                    'flock_id' => $context->flock->getKey(),
                    'poultry_house_id' => $context->flock->poultry_house_id,
                    'production_unit_id' => $context->flock->production_unit_id,
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_snapshot' => $context->planActivityTitleSnapshot,
                    'medicine_id' => $medicine->getKey(),
                    'medicine_public_id_snapshot' => $medicineSnapshot['id'],
                    'medicine_name_snapshot' => $medicineSnapshot['name'],
                    'medicine_description_snapshot' => $medicineSnapshot['description'],
                    'supplier_name_snapshot' => $medicineSnapshot['supplier_name_at_registration'],
                    'occurred_at' => $context->occurredAt,
                    'responsible_user_id' => $context->responsibleUserId,
                    'responsible_name_snapshot' => $context->responsibleNameSnapshot,
                    'reason' => trim($attributes['reason'] ?? '') === '' ? null : trim($attributes['reason']),
                    'notes' => isset($attributes['notes']) ? trim($attributes['notes']) : null,
                    'created_by' => $actor->getKey(),
                    'operation_id' => $operationId,
                ])->save();

                $snapshot = [
                    'id' => $application->public_id,
                    'flock_id' => $context->flock->public_id,
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_at_application' => $context->planActivityTitleSnapshot,
                    'medicine' => $medicineSnapshot,
                    'occurred_at' => $context->occurredAt->toIso8601String(),
                    'responsible' => [
                        'id' => $context->responsibleUserId,
                        'name_at_application' => $context->responsibleNameSnapshot,
                    ],
                    'reason' => $application->reason,
                    'notes' => $application->notes,
                    'created_by' => (int) $actor->getKey(),
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $application,
                    actor: $actor,
                    logName: 'management_plans',
                    event: 'medicine_application_recorded',
                    description: 'Aplicación de medicamento registrada',
                    operationId: $operationId,
                    source: $source,
                    upId: $context->flock->production_unit_id,
                    properties: [
                        'result' => 'success',
                        'subject_snapshot' => $snapshot,
                    ],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                ));
                $this->activities->record($context->flock, $operationId, 'medication', 'management-plans.medication.apply');

                return [
                    'medicine_application' => $snapshot,
                    'warnings' => [],
                ];
            },
        );
    }
}
