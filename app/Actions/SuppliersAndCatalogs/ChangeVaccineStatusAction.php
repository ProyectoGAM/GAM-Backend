<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class ChangeVaccineStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder, private ChangeProductStatusAction $changeProductStatus) {}

    public function execute(Vaccine $vaccine, ProductStatus $status, User $actor, string $source = 'api'): Vaccine
    {
        Gate::forUser($actor)->authorize('changeStatus', $vaccine);
        $operationId = (string) Str::uuid();

        return DB::transaction(function () use ($vaccine, $status, $actor, $source, $operationId): Vaccine {
            $product = Product::query()->whereKey($vaccine->product_id)->lockForUpdate()->firstOrFail();
            $locked = Vaccine::query()->whereKey($vaccine->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('changeStatus', $locked);
            $before = $product->status->value;
            if ($before === $status->value) {
                return $locked->load(['product', 'supplier']);
            }

            $this->changeProductStatus->execute($product, $status, $actor, $operationId, $source, false);
            $product->refresh();
            $locked->touch();
            $snapshot = [
                'public_id' => $locked->public_id,
                'product_id' => $locked->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'status' => $product->status->value,
            ];
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'vaccine_status_changed',
                description: 'Estado de vacuna actualizado',
                properties: ['subject_snapshot' => $snapshot, 'result' => 'updated'],
                attributeChanges: ['old' => ['status' => $before], 'new' => ['status' => $status->value]],
                operationId: $operationId,
                source: $source,
            ));

            return $locked->load(['product', 'supplier']);
        }, 3);
    }
}
