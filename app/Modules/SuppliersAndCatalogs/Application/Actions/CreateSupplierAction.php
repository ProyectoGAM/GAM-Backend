<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Actions;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;
use Illuminate\Support\Facades\DB;

final readonly class CreateSupplierAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{locality_id?: int|null, name: string, address: string, status?: string} $attributes */
    public function execute(array $attributes, User $actor): Supplier
    {
        return DB::transaction(function () use ($attributes, $actor): Supplier {
            $supplier = Supplier::query()->create([
                ...$attributes,
                'status' => SupplierStatus::from($attributes['status'] ?? SupplierStatus::Active->value),
            ]);
            $snapshot = $this->snapshot($supplier);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $supplier,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'supplier_created',
                description: 'Proveedor creado',
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                source: 'api',
            ));

            return $supplier->load('locality.department');
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
