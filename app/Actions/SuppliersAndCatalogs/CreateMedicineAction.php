<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Exceptions\SuppliersAndCatalogs\MedicineConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class CreateMedicineAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{name: string, description: string, supplier_id: int, idempotency_key: string} $attributes */
    public function execute(array $attributes, User $actor, string $source = 'api'): Medicine
    {
        Gate::forUser($actor)->authorize('create', Medicine::class);

        $name = trim($attributes['name']);
        $description = trim($attributes['description']);
        if ($name === '' || mb_strlen($name) > 160 || $description === '' || mb_strlen($description) > 5000
            || $attributes['supplier_id'] < 1 || ! Str::isUuid($attributes['idempotency_key'])) {
            throw new MedicineConflict('El medicamento requiere nombre, descripción, proveedor y una clave de idempotencia válidos.');
        }

        $key = Str::lower($attributes['idempotency_key']);
        $hash = hash('sha256', json_encode([
            'name' => $name,
            'description' => $description,
            'supplier_id' => $attributes['supplier_id'],
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($attributes, $actor, $source, $name, $description, $key, $hash): Medicine {
            /** Serializa altas del mismo actor y comprueba reintentos antes de leer al proveedor mutable. */
            $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($currentActor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize('create', Medicine::class);

            $existing = Medicine::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new MedicineConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $existing;
            }

            $supplier = Supplier::query()->whereKey($attributes['supplier_id'])->sharedLock()->firstOrFail();
            if ($supplier->status !== SupplierStatus::Active) {
                throw new MedicineConflict('El proveedor debe estar activo para registrar un medicamento.');
            }

            $medicine = new Medicine;
            $medicine->forceFill([
                'public_id' => (string) Str::ulid(),
                'name' => $name,
                'description' => $description,
                'supplier_id' => $supplier->id,
                'supplier_name_snapshot' => $supplier->name,
                'created_by' => $currentActor->id,
                'created_by_name' => $currentActor->name,
                'operation_id' => (string) Str::uuid(),
                'idempotency_key' => $key,
                'request_hash' => $hash,
            ])->save();

            $snapshot = $medicine->only([
                'public_id', 'name', 'description', 'supplier_id', 'supplier_name_snapshot',
                'created_by', 'created_by_name', 'operation_id',
            ]);
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $medicine,
                actor: $currentActor,
                logName: 'suppliers_and_catalogs',
                event: 'medicine_created',
                description: 'Medicamento registrado',
                properties: ['subject_snapshot' => $snapshot, 'result' => 'created'],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                operationId: $medicine->operation_id,
                source: $source,
            ));

            return $medicine;
        }, 3);
    }
}
