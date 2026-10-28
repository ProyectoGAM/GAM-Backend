<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class EnsureMedicineProductAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(Medicine $medicine, User $actor): Product
    {
        // Futuro: no crear vínculos de inventario hasta habilitar sanidad física.
        if (! config('inventory.health_stock_enabled')) {
            throw new SuppliersAndCatalogsConflict('Los medicamentos se registran globalmente, sin stock físico.');
        }

        return DB::transaction(function () use ($medicine, $actor): Product {
            $locked = Medicine::query()->whereKey($medicine->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->product_id !== null) {
                return Product::query()->findOrFail($locked->product_id);
            }
            $product = new Product;
            $product->forceFill([
                'sku' => 'MED-'.$locked->public_id,
                'name' => mb_substr($locked->name, 0, 120).' ['.$locked->public_id.']',
                'kind' => 'medicine', 'base_unit' => 'unit', 'stock_tracked' => true, 'status' => 'active',
            ])->save();
            $locked->forceFill(['product_id' => $product->getKey()])->save();
            $medicine->setAttribute('product_id', $product->getKey());
            $snapshot = ['medicine_id' => $locked->public_id, ...$product->only(['id', 'sku', 'name', 'kind', 'base_unit', 'stock_tracked', 'status'])];
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked, actor: $actor, logName: 'suppliers_and_catalogs', event: 'medicine_product_linked',
                description: 'Medicamento vinculado al inventario físico', operationId: $locked->operation_id,
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => ['product_id' => null], 'new' => ['product_id' => (int) $product->getKey()]],
            ));

            return $product;
        }, 3);
    }
}
