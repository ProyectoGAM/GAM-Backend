<?php

namespace App\Actions\ManagementPlans;

use App\Actions\Inventory\ReverseInventoryMovementAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\InventoryMovement;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\ManagementExecutionCorrection;
use App\Models\ManagementPlans\ManualPractice;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ManagementPlans\MedicineStockBalance;
use App\Models\ManagementPlans\MedicineStockMovement;
use App\Models\ManagementPlans\RationChange;
use App\Models\ManagementPlans\VaccinationApplication;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class CorrectManagementExecutionAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $flockState,
        private ReverseInventoryMovementAction $reverseInventoryMovement,
        private FlockActivityJournal $activities,
        private AuditRecorder $auditRecorder,
    ) {}

    /** @param array{idempotency_key: string, reason: string} $attributes */
    public function execute(Flock $flock, string $executionType, string $executionPublicId, array $attributes, User $actor, string $source = 'api'): FlockOperation
    {
        $modelClass = $this->modelFor($executionType);

        return $this->commands->execute(
            $actor,
            'management-plans.execute',
            'management-plans.execution.correct',
            $attributes['idempotency_key'],
            [
                'flock' => $flock->public_id,
                'execution_type' => $executionType,
                'execution_id' => $executionPublicId,
                'reason' => trim($attributes['reason']),
            ],
            function (string $operationId) use ($flock, $executionType, $executionPublicId, $modelClass, $attributes, $actor, $source): array {
                $lockedFlock = $this->flockState->lock([$flock->public_id])->get($flock->public_id);
                /** @var Model $execution */
                $execution = $modelClass::query()
                    ->where('flock_id', $lockedFlock->getKey())
                    ->where('public_id', $executionPublicId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (ManagementExecutionCorrection::query()
                    ->where('original_type', $executionType)
                    ->where('original_public_id', $executionPublicId)
                    ->exists()) {
                    throw new LotsConflict('Este manejo ya tiene una corrección registrada.');
                }

                $originalSnapshot = $this->snapshot($executionType, $execution);
                $compensationSnapshot = match ($executionType) {
                    'vaccination' => $this->reverseVaccinationInventory($execution, $operationId, $attributes['reason'], $actor, $source),
                    'medication' => $this->restoreMedicineStock($execution, $operationId, $attributes['idempotency_key'], $attributes['reason'], $actor, $source),
                    default => null,
                };

                $correction = new ManagementExecutionCorrection;
                $correction->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'flock_id' => $lockedFlock->getKey(),
                    'original_type' => $executionType,
                    'original_public_id' => $executionPublicId,
                    'original_operation_id' => $execution->getAttribute('operation_id'),
                    'correction_reason' => trim($attributes['reason']),
                    'original_snapshot' => $originalSnapshot,
                    'compensation_snapshot' => $compensationSnapshot,
                    'occurred_at' => now(),
                    'created_by' => $actor->getKey(),
                    'operation_id' => $operationId,
                ])->save();

                $snapshot = [
                    'id' => $correction->public_id,
                    'flock_id' => $lockedFlock->public_id,
                    'original_type' => $executionType,
                    'original_public_id' => $executionPublicId,
                    'original_operation_id' => $execution->getAttribute('operation_id'),
                    'reason' => $correction->correction_reason,
                    'original_snapshot' => $originalSnapshot,
                    'compensation' => $compensationSnapshot,
                    'occurred_at' => $correction->occurred_at->toIso8601String(),
                    'created_by' => (int) $actor->getKey(),
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $correction,
                    actor: $actor,
                    logName: 'management_plans',
                    event: 'management_execution_corrected',
                    description: 'Corrección compensatoria de manejo registrada',
                    operationId: $operationId,
                    source: $source,
                    upId: $lockedFlock->production_unit_id,
                    properties: ['result' => 'success', 'subject_snapshot' => $snapshot],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                ));
                $this->activities->record($lockedFlock, $operationId, 'execution_correction', 'management-plans.execution.correct');

                return ['management_execution_correction' => $snapshot, 'warnings' => []];
            },
        );
    }

    /** @return class-string<Model> */
    private function modelFor(string $executionType): string
    {
        return match ($executionType) {
            'vaccination' => VaccinationApplication::class,
            'medication' => MedicineApplication::class,
            'ration_change' => RationChange::class,
            'manual_practice' => ManualPractice::class,
            default => throw new LotsConflict('El tipo de manejo no admite correcciones.'),
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(string $executionType, Model $execution): array
    {
        $keys = match ($executionType) {
            'vaccination' => ['public_id', 'operation_id', 'flock_id', 'plan_activity_id', 'plan_activity_title_snapshot', 'vaccine_snapshot', 'product_snapshot', 'occurred_at', 'responsible_name_snapshot', 'notes', 'inventory_quantity', 'stock_location_name_snapshot', 'inventory_movement_id'],
            'medication' => ['public_id', 'operation_id', 'flock_id', 'plan_activity_id', 'plan_activity_title_snapshot', 'medicine_public_id_snapshot', 'medicine_name_snapshot', 'medicine_description_snapshot', 'supplier_name_snapshot', 'occurred_at', 'responsible_name_snapshot', 'quantity', 'reason', 'notes', 'stock_movement_id'],
            'ration_change' => ['public_id', 'operation_id', 'flock_id', 'plan_activity_id', 'plan_activity_title_snapshot', 'ration_description', 'finished_feed_product_snapshot', 'occurred_at', 'responsible_name_snapshot', 'notes'],
            'manual_practice' => ['public_id', 'operation_id', 'flock_id', 'plan_activity_id', 'plan_activity_title_snapshot', 'practice_type', 'practice_title_snapshot', 'occurred_at', 'responsible_name_snapshot', 'notes'],
            default => [],
        };
        $snapshot = [];
        foreach ($keys as $key) {
            $value = $execution->getAttribute($key);
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(DATE_ATOM);
            }
            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /** @return array<string, mixed>|null */
    private function reverseVaccinationInventory(Model $execution, string $operationId, string $reason, User $actor, string $source): ?array
    {
        $movementId = $execution->getAttribute('inventory_movement_id');
        if ($movementId === null) {
            return null;
        }
        $movement = InventoryMovement::query()->whereKey($movementId)->lockForUpdate()->firstOrFail();
        $reversal = $this->reverseInventoryMovement->execute($movement, [
            'operation_id' => $operationId,
            'reason' => 'Corrección de aplicación de vacuna '.$execution->getAttribute('public_id').': '.trim($reason),
        ], $actor, $source);

        return [
            'kind' => 'inventory_reversal',
            'operation_id' => $reversal->operation_id,
            'movement_id' => $reversal->getKey(),
        ];
    }

    /** @return array<string, mixed> */
    private function restoreMedicineStock(Model $execution, string $operationId, string $idempotencyKey, string $reason, User $actor, string $source): array
    {
        $medicineId = (int) $execution->getAttribute('medicine_id');
        $quantity = (int) $execution->getAttribute('quantity');
        $balance = MedicineStockBalance::query()->where('medicine_id', $medicineId)->lockForUpdate()->firstOrFail();
        $before = (int) $balance->on_hand_quantity;
        if ($before > PHP_INT_MAX - $quantity) {
            throw new LotsConflict('El saldo de medicamentos excede el límite que puede registrar el sistema.');
        }
        $after = $before + $quantity;
        $balance->forceFill(['on_hand_quantity' => $after, 'version' => $balance->version + 1])->save();

        $movement = new MedicineStockMovement;
        $movement->forceFill([
            'public_id' => (string) Str::ulid(),
            'medicine_id' => $medicineId,
            'medicine_public_id_snapshot' => $execution->getAttribute('medicine_public_id_snapshot'),
            'medicine_name_snapshot' => $execution->getAttribute('medicine_name_snapshot'),
            'medicine_application_public_id' => $execution->getAttribute('public_id'),
            'movement_type' => 'correction',
            'quantity_delta' => $quantity,
            'balance_after' => $after,
            'reason' => 'Corrección de aplicación '.$execution->getAttribute('public_id').': '.trim($reason),
            'operation_id' => $operationId,
            'idempotency_key' => Str::lower($idempotencyKey),
            'request_hash' => hash('sha256', json_encode([
                'command' => 'management-plans.execution.correct',
                'medicine_application' => $execution->getAttribute('public_id'),
                'quantity_delta' => $quantity,
                'reason' => trim($reason),
            ], JSON_THROW_ON_ERROR)),
            'created_by' => $actor->getKey(),
        ])->save();
        $movementSnapshot = [
            'kind' => 'medicine_stock_replenishment',
            'movement_id' => $movement->public_id,
            'operation_id' => $movement->operation_id,
            'quantity_delta' => $quantity,
            'balance_before' => $before,
            'balance_after' => $after,
        ];
        $this->auditRecorder->record(AuditEntryData::forSubject(
            subject: $movement,
            actor: $actor,
            logName: 'management_plans',
            event: 'medicine_stock_replenished_for_correction',
            description: 'Stock de medicamento compensado por corrección',
            operationId: $operationId,
            source: $source,
            properties: ['result' => 'success', 'subject_snapshot' => $movementSnapshot],
            attributeChanges: ['old' => ['balance' => $before], 'new' => $movementSnapshot],
        ));

        return $movementSnapshot;
    }
}
