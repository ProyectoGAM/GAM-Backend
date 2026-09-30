<?php

namespace App\Actions\ManagementPlans;

use App\Actions\Inventory\IssueStockAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\VaccinationApplication;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Support\Str;

final readonly class RecordVaccinationApplicationAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private ResolveManagementExecutionContext $contextResolver,
        private FlockActivityJournal $activities,
        private AuditRecorder $auditRecorder,
        private IssueStockAction $issueStock,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(Flock $flock, array $attributes, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'management-plans.execute',
            'management-plans.vaccination.apply',
            $attributes['idempotency_key'],
            ['flock' => $flock->public_id, ...$attributes],
            function (string $operationId) use ($flock, $attributes, $actor, $source): array {
                $context = $this->contextResolver->execute($flock, $actor, $attributes, 'vaccination');
                $candidate = Vaccine::query()->where('public_id', $attributes['vaccine_id'])->firstOrFail();
                $product = Product::query()->whereKey($candidate->product_id)->sharedLock()->firstOrFail();
                $vaccine = Vaccine::query()->whereKey($candidate->getKey())->sharedLock()->firstOrFail();
                if ($product->kind !== ProductKind::Vaccine || $product->status !== ProductStatus::Active) {
                    throw new LotsConflict('La vacuna seleccionada debe estar activa en el catálogo.');
                }

                $vaccineSnapshot = [
                    'id' => $vaccine->public_id,
                    'description' => $vaccine->description,
                    'details' => $vaccine->details,
                    'supplier_name_at_registration' => $vaccine->supplier_name_snapshot,
                ];
                $productSnapshot = [
                    'id' => (int) $product->getKey(),
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'kind' => $product->kind->value,
                    'base_unit' => $product->base_unit->value,
                    'stock_tracked' => $product->stock_tracked,
                ];

                $application = new VaccinationApplication;
                $application->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'flock_id' => $context->flock->getKey(),
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_snapshot' => $context->planActivityTitleSnapshot,
                    'vaccine_id' => $vaccine->getKey(),
                    'product_id' => $product->getKey(),
                    'vaccine_snapshot' => $vaccineSnapshot,
                    'product_snapshot' => $productSnapshot,
                    'occurred_at' => $context->occurredAt,
                    'responsible_user_id' => $context->responsibleUserId,
                    'responsible_name_snapshot' => $context->responsibleNameSnapshot,
                    'notes' => $attributes['notes'] ?? null,
                    'created_by' => $actor->getKey(),
                    'operation_id' => $operationId,
                ])->save();

                $inventorySnapshot = null;
                $inventoryMovementId = null;
                if (isset($attributes['stock_consumption'])) {
                    if (! $product->stock_tracked) {
                        throw new LotsConflict('La vacuna seleccionada no controla stock.');
                    }
                    $inventoryMovement = $this->issueStock->execute([
                        'idempotency_key' => $operationId,
                        'lines' => [[
                            'product_id' => (int) $product->getKey(),
                            'stock_location_id' => (int) $attributes['stock_consumption']['stock_location_id'],
                            'quantity' => (string) $attributes['stock_consumption']['quantity'],
                        ]],
                        'occurred_at' => $context->occurredAt->toIso8601String(),
                        'reason' => 'Consumo por aplicación de vacuna '.$vaccine->public_id,
                        'reference_type' => 'vaccination_application',
                        'reference_id' => $application->public_id,
                    ], $actor);
                    $inventoryMovement->loadMissing('lines.stockLocation', 'lines.product');
                    $line = $inventoryMovement->lines->first();
                    if ($line === null) {
                        throw new LotsConflict('El movimiento de stock de la vacunación no contiene su línea de consumo.');
                    }
                    $inventoryMovementId = (int) $inventoryMovement->getKey();
                    $application->forceFill([
                        'inventory_quantity' => ltrim((string) $line->on_hand_delta, '-'),
                        'stock_location_id' => $line->stock_location_id,
                        'stock_location_name_snapshot' => (string) $line->stockLocation->getAttribute('name'),
                        'inventory_movement_id' => $inventoryMovementId,
                    ])->save();
                    $inventorySnapshot = [
                        'quantity' => ltrim((string) $line->on_hand_delta, '-'),
                        'unit' => $line->unit,
                        'stock_location_id' => (int) $line->stock_location_id,
                        'stock_location_name_at_application' => (string) $line->stockLocation->getAttribute('name'),
                        'movement_operation_id' => $inventoryMovement->operation_id,
                    ];
                }

                $snapshot = [
                    'id' => $application->public_id,
                    'flock_id' => $context->flock->public_id,
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_at_application' => $context->planActivityTitleSnapshot,
                    'vaccine' => $vaccineSnapshot,
                    'product' => $productSnapshot,
                    'occurred_at' => $context->occurredAt->toIso8601String(),
                    'responsible' => [
                        'id' => $context->responsibleUserId,
                        'name_at_application' => $context->responsibleNameSnapshot,
                    ],
                    'notes' => $application->notes,
                    'inventory_consumption' => $inventorySnapshot,
                    'inventory_movement_id' => $inventoryMovementId,
                    'created_by' => (int) $actor->getKey(),
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $application,
                    actor: $actor,
                    logName: 'management_plans',
                    event: 'vaccination_application_recorded',
                    description: 'Aplicación de vacuna registrada',
                    operationId: $operationId,
                    source: $source,
                    upId: $context->flock->production_unit_id,
                    properties: ['result' => 'success', 'subject_snapshot' => $snapshot],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                ));
                $this->activities->record($context->flock, $operationId, 'vaccination', 'management-plans.vaccination.apply');

                return ['vaccination_application' => $snapshot, 'warnings' => []];
            },
        );
    }
}
