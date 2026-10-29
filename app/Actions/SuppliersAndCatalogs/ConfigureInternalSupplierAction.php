<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Exceptions\Inventory\InventoryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ConfigureInternalSupplierAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(User $actor): Supplier
    {
        return DB::transaction(function () use ($actor): Supplier {
            DB::select('SELECT pg_advisory_xact_lock(82641301)');
            $existing = Supplier::query()->where('system_key', 'internal_supplier')->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $candidates = Supplier::query()->whereRaw('lower(trim(name)) = ?', ['propio'])->lockForUpdate()->get();
            if ($candidates->count() > 1) {
                throw new InventoryConflict('Existe más de un proveedor Propio. Debes resolver la duplicación antes de configurar el proveedor interno.');
            }
            $supplier = $candidates->first() ?? new Supplier;
            if ($supplier->exists && $supplier->status !== SupplierStatus::Active) {
                throw new InventoryConflict('El proveedor Propio está inactivo. Debes revisar su estado antes de configurarlo.');
            }
            $before = $supplier->exists ? $supplier->only(['id', 'name', 'status', 'system_key']) : [];
            if (! $supplier->exists) {
                $supplier->forceFill(['name' => 'Propio', 'address' => 'Producción propia', 'status' => SupplierStatus::Active]);
            }
            $supplier->forceFill(['system_key' => 'internal_supplier'])->save();
            $after = $supplier->only(['id', 'name', 'status', 'system_key']);
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $supplier, actor: $actor, logName: 'suppliers_and_catalogs', event: 'internal_supplier_configured',
                description: 'Proveedor interno configurado', properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
            ));

            return $supplier;
        }, 3);
    }
}
