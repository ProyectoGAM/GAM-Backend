<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Actions;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;
use Illuminate\Support\Facades\DB;

final readonly class ChangeSupplierStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(Supplier $supplier, SupplierStatus $status, User $actor): Supplier
    {
        return DB::transaction(function () use ($supplier, $status, $actor): Supplier {
            $locked = Supplier::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->status->value;
            $locked->forceFill(['status' => $status])->save();

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'supplier_status_changed',
                description: 'Estado de proveedor actualizado',
                properties: ['subject_snapshot' => $this->snapshot($locked)],
                attributeChanges: ['old' => ['status' => $before], 'new' => ['status' => $status->value]],
                source: 'api',
            ));

            return $locked->load('locality.department');
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Supplier $supplier): array
    {
        return [
            'id' => (int) $supplier->getKey(),
            'name' => $supplier->name,
            'status' => $supplier->status->value,
        ];
    }
}
