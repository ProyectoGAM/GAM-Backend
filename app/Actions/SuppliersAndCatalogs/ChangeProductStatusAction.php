<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class ChangeProductStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(Product $product, ProductStatus $status, User $actor, ?string $operationId = null, string $source = 'api', bool $recordVaccineAudit = true): Product
    {
        $auditOperationId = $operationId ?? (string) Str::uuid();

        return DB::transaction(function () use ($product, $status, $actor, $auditOperationId, $source, $recordVaccineAudit): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('changeStatus', $locked);
            $vaccine = Vaccine::query()->where('product_id', $locked->getKey())->lockForUpdate()->first();
            if ($vaccine !== null && (! $actor->hasRole('admin') || $actor->trashed())) {
                throw new AuthorizationException;
            }
            if ($locked->system_key === 'generic_egg') {
                throw new SuppliersAndCatalogsConflict('El producto técnico Huevo está protegido por el módulo de stock de huevos.');
            }
            if ($vaccine !== null && $status === ProductStatus::Inactive && StockBalance::query()->where('product_id', $locked->getKey())->where('on_hand_quantity', '>', 0)->exists()) {
                throw new SuppliersAndCatalogsConflict('No puedes desactivar una vacuna con existencias disponibles.');
            }
            $before = $locked->status->value;
            if ($before === $status->value) {
                return $locked;
            }
            $locked->forceFill(['status' => $status])->save();

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'product_status_changed',
                description: 'Estado de producto actualizado',
                properties: ['subject_snapshot' => $this->snapshot($locked)],
                attributeChanges: ['old' => ['status' => $before], 'new' => ['status' => $status->value]],
                operationId: $auditOperationId,
                source: $source,
            ));

            if ($vaccine !== null && $recordVaccineAudit) {
                $vaccine->touch();
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $vaccine,
                    actor: $actor,
                    logName: 'suppliers_and_catalogs',
                    event: 'vaccine_status_changed',
                    description: 'Estado de vacuna actualizado mediante Producto',
                    properties: ['subject_snapshot' => [...$this->snapshot($locked), 'public_id' => $vaccine->public_id, 'product_id' => $vaccine->product_id, 'supplier_id' => $vaccine->supplier_id, 'description' => $vaccine->description, 'details' => $vaccine->details], 'result' => 'updated'],
                    attributeChanges: ['old' => ['status' => $before], 'new' => ['status' => $status->value]],
                    operationId: $auditOperationId,
                    source: $source,
                ));
            }

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $product): array
    {
        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'kind' => $product->kind->value,
            'base_unit' => $product->base_unit->value,
            'stock_tracked' => $product->stock_tracked,
            'status' => $product->status->value,
        ];
    }
}
