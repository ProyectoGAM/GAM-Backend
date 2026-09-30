<?php

namespace App\Actions\ManagementPlans;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\RationChange;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Support\Str;

final readonly class RecordRationChangeAction
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
            'management-plans.ration.change',
            $attributes['idempotency_key'],
            ['flock' => $flock->public_id, ...$attributes],
            function (string $operationId) use ($flock, $attributes, $actor, $source): array {
                $context = $this->contextResolver->execute($flock, $actor, $attributes, 'ration_change');
                $productSnapshot = null;
                $productId = $attributes['finished_feed_product_id'] ?? null;
                if ($productId !== null) {
                    $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
                    if ($product->kind !== ProductKind::FinishedFeed || $product->status !== ProductStatus::Active) {
                        throw new LotsConflict('El producto seleccionado debe ser un alimento terminado activo.');
                    }
                    $productSnapshot = [
                        'id' => (int) $product->getKey(),
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'kind' => $product->kind->value,
                        'base_unit' => $product->base_unit->value,
                    ];
                }

                $change = new RationChange;
                $change->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'flock_id' => $context->flock->getKey(),
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_snapshot' => $context->planActivityTitleSnapshot,
                    'ration_description' => trim($attributes['ration_description']),
                    'finished_feed_product_id' => $productId,
                    'finished_feed_product_snapshot' => $productSnapshot,
                    'occurred_at' => $context->occurredAt,
                    'responsible_user_id' => $context->responsibleUserId,
                    'responsible_name_snapshot' => $context->responsibleNameSnapshot,
                    'notes' => $attributes['notes'] ?? null,
                    'created_by' => $actor->getKey(),
                    'operation_id' => $operationId,
                ])->save();
                $snapshot = [
                    'id' => $change->public_id,
                    'flock_id' => $context->flock->public_id,
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_at_application' => $context->planActivityTitleSnapshot,
                    'ration_description' => $change->ration_description,
                    'finished_feed_product' => $productSnapshot,
                    'occurred_at' => $context->occurredAt->toIso8601String(),
                    'responsible' => [
                        'id' => $context->responsibleUserId,
                        'name_at_application' => $context->responsibleNameSnapshot,
                    ],
                    'notes' => $change->notes,
                    'created_by' => (int) $actor->getKey(),
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $change,
                    actor: $actor,
                    logName: 'management_plans',
                    event: 'ration_change_recorded',
                    description: 'Cambio de ración registrado',
                    operationId: $operationId,
                    source: $source,
                    upId: $context->flock->production_unit_id,
                    properties: ['result' => 'success', 'subject_snapshot' => $snapshot],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                ));
                $this->activities->record($context->flock, $operationId, 'ration_change', 'management-plans.ration.change');

                return ['ration_change' => $snapshot, 'warnings' => []];
            },
        );
    }
}
