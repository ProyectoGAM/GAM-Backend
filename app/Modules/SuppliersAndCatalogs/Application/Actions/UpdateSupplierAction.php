<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Actions;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final readonly class UpdateSupplierAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{locality_id?: int|null, name?: string, address?: string} $attributes */
    public function execute(Supplier $supplier, array $attributes, User $actor): Supplier
    {
        return DB::transaction(function () use ($supplier, $attributes, $actor): Supplier {
            $locked = Supplier::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $locked->fill($attributes)->save();
            $after = $this->snapshot($locked);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'supplier_updated',
                description: 'Proveedor actualizado',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                source: 'api',
            ));

            return $locked->load('locality.department');
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Supplier $supplier): array
    {
        return [
            'locality_id' => $supplier->locality_id,
            'name' => $supplier->name,
            'address' => $supplier->address,
            'status' => $supplier->status->value,
        ];
    }
}
