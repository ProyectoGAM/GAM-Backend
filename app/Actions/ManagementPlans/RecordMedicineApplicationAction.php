<?php

namespace App\Actions\ManagementPlans;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ManagementPlans\MedicineStockBalance;
use App\Models\ManagementPlans\MedicineStockMovement;
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
                $candidate = Medicine::query()->where('public_id', $attributes['medicine_id'])->firstOrFail();
                $medicine = Medicine::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
                $balance = MedicineStockBalance::query()->firstOrCreate(
                    ['medicine_id' => $medicine->getKey()],
                    ['on_hand_quantity' => 0, 'version' => 1],
                );
                $balance = MedicineStockBalance::query()->whereKey($balance->getKey())->lockForUpdate()->firstOrFail();
                $quantity = (int) $attributes['quantity'];
                $currentBalance = (int) $balance->on_hand_quantity;
                if ($currentBalance < PHP_INT_MIN + $quantity) {
                    throw new LotsConflict('El saldo de medicamentos excede el límite que puede registrar el sistema.');
                }
                $balanceAfter = $currentBalance - $quantity;
                $balance->forceFill([
                    'on_hand_quantity' => $balanceAfter,
                    'version' => $balance->version + 1,
                ])->save();

                $applicationPublicId = (string) Str::ulid();
                $medicineSnapshot = [
                    'id' => $medicine->public_id,
                    'name' => $medicine->name,
                    'description' => $medicine->description,
                    'supplier_name_at_registration' => $medicine->supplier_name_snapshot,
                ];
                $stockMovement = new MedicineStockMovement;
                $stockMovement->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'medicine_id' => $medicine->getKey(),
                    'medicine_public_id_snapshot' => $medicine->public_id,
                    'medicine_name_snapshot' => $medicine->name,
                    'medicine_application_public_id' => $applicationPublicId,
                    'movement_type' => 'application',
                    'quantity_delta' => -$quantity,
                    'balance_after' => $balanceAfter,
                    'reason' => trim($attributes['reason']),
                    'operation_id' => $operationId,
                    'idempotency_key' => Str::lower($attributes['idempotency_key']),
                    'request_hash' => hash('sha256', json_encode([
                        'command' => 'management-plans.medication.apply',
                        'flock' => $context->flock->public_id,
                        'medicine' => $medicine->public_id,
                        'quantity' => $quantity,
                        'reason' => trim($attributes['reason']),
                    ], JSON_THROW_ON_ERROR)),
                    'created_by' => $actor->getKey(),
                ])->save();

                $application = new MedicineApplication;
                $application->forceFill([
                    'public_id' => $applicationPublicId,
                    'flock_id' => $context->flock->getKey(),
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
                    'quantity' => $quantity,
                    'reason' => trim($attributes['reason']),
                    'notes' => isset($attributes['notes']) ? trim($attributes['notes']) : null,
                    'stock_movement_id' => $stockMovement->getKey(),
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
                    'quantity' => $quantity,
                    'reason' => $application->reason,
                    'notes' => $application->notes,
                    'stock' => [
                        'movement_id' => $stockMovement->public_id,
                        'quantity_delta' => -$quantity,
                        'balance_after' => $balanceAfter,
                    ],
                    'created_by' => (int) $actor->getKey(),
                ];
                $warning = $balanceAfter < 0 ? [
                    'code' => 'MEDICINE_STOCK_NEGATIVE',
                    'message' => 'El saldo de '.$medicine->name.' quedó en '.$balanceAfter.' unidades.',
                ] : null;
                $stockSnapshot = [
                    'id' => $stockMovement->public_id,
                    'medicine_id' => $medicine->public_id,
                    'medicine_name_at_application' => $medicine->name,
                    'application_id' => $application->public_id,
                    'quantity_delta' => -$quantity,
                    'balance_before' => $currentBalance,
                    'balance_after' => $balanceAfter,
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $stockMovement,
                    actor: $actor,
                    logName: 'management_plans',
                    event: 'medicine_stock_issued_for_application',
                    description: 'Stock de medicamento descontado por aplicación',
                    operationId: $operationId,
                    source: $source,
                    upId: $context->flock->production_unit_id,
                    properties: ['result' => 'success', 'subject_snapshot' => $stockSnapshot, 'stock_warning' => $warning],
                    attributeChanges: ['old' => ['balance' => $currentBalance], 'new' => $stockSnapshot],
                ));
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
                        'stock_warning' => $warning,
                    ],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                ));
                $this->activities->record($context->flock, $operationId, 'medication', 'management-plans.medication.apply');

                return [
                    'medicine_application' => $snapshot,
                    'stock_balance' => ['quantity' => $balanceAfter, 'version' => $balance->version],
                    'warnings' => $warning === null ? [] : [$warning],
                ];
            },
        );
    }
}
