<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ChangeProductStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(Product $product, ProductStatus $status, User $actor): Product
    {
        return DB::transaction(function () use ($product, $status, $actor): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->system_key === 'generic_egg') {
                throw new SuppliersAndCatalogsConflict('El producto técnico Huevo está protegido por el módulo de stock de huevos.');
            }
            $before = $locked->status->value;
            $locked->forceFill(['status' => $status])->save();

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'product_status_changed',
                description: 'Estado de producto actualizado',
                properties: ['subject_snapshot' => $this->snapshot($locked)],
                attributeChanges: ['old' => ['status' => $before], 'new' => ['status' => $status->value]],
                source: 'api',
            ));

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'sku' => $product->sku,
            'name' => $product->name,
            'status' => $product->status->value,
        ];
    }
}
