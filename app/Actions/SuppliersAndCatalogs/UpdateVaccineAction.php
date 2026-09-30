<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Exceptions\SuppliersAndCatalogs\VaccineConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class UpdateVaccineAction
{
    public function __construct(private AuditRecorder $auditRecorder, private UpdateProductAction $updateProduct) {}

    /** @param array{name?: string, description?: string, details?: string|null, supplier_id?: int} $attributes */
    public function execute(Vaccine $vaccine, array $attributes, User $actor, string $source = 'api'): Vaccine
    {
        Gate::forUser($actor)->authorize('update', $vaccine);
        if ($attributes === []) {
            throw new VaccineConflict('Debes indicar al menos un campo para actualizar.');
        }

        $operationId = (string) Str::uuid();

        return DB::transaction(function () use ($vaccine, $attributes, $actor, $source, $operationId): Vaccine {
            $product = Product::query()->whereKey($vaccine->product_id)->lockForUpdate()->firstOrFail();
            $locked = Vaccine::query()->whereKey($vaccine->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            $before = $this->snapshot($locked, $product);
            $changes = [];

            if (array_key_exists('name', $attributes)) {
                $name = trim((string) $attributes['name']);
                if ($name === '' || mb_strlen($name) > 160) {
                    throw new VaccineConflict('El nombre de la vacuna no es válido.');
                }
                if ($name !== $product->name) {
                    $this->updateProduct->execute($product, ['name' => $name], $actor, $operationId, $source, false);
                    $product->refresh();
                    $changes['name'] = $product->name;
                }
            }
            if (array_key_exists('description', $attributes)) {
                $description = trim((string) $attributes['description']);
                if ($description === '' || mb_strlen($description) > 5000) {
                    throw new VaccineConflict('La descripción de la vacuna no es válida.');
                }
                if ($description !== $locked->description) {
                    $locked->description = $description;
                    $changes['description'] = $description;
                }
            }
            if (array_key_exists('details', $attributes)) {
                $details = $attributes['details'] === null ? null : trim((string) $attributes['details']);
                if ($details !== null && mb_strlen($details) > 5000) {
                    throw new VaccineConflict('Los detalles de la vacuna no son válidos.');
                }
                if ($details !== $locked->details) {
                    $locked->details = $details;
                    $changes['details'] = $details;
                }
            }
            if (array_key_exists('supplier_id', $attributes) && (int) $attributes['supplier_id'] !== $locked->supplier_id) {
                $supplier = Supplier::query()->whereKey((int) $attributes['supplier_id'])->sharedLock()->firstOrFail();
                if ($supplier->status !== SupplierStatus::Active) {
                    throw new VaccineConflict('El proveedor debe estar activo para asociarlo a la vacuna.');
                }
                $locked->supplier_id = $supplier->getKey();
                $locked->supplier_name_snapshot = $supplier->name;
                $changes['supplier_id'] = $supplier->getKey();
            }
            if ($changes === []) {
                return $locked->load(['product', 'supplier']);
            }

            if ($locked->isDirty()) {
                $locked->save();
            } else {
                $locked->touch();
            }
            $after = $this->snapshot($locked, $product);
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'vaccine_updated',
                description: 'Vacuna actualizada',
                properties: ['subject_snapshot' => $after, 'result' => 'updated'],
                attributeChanges: ['old' => $before, 'new' => $after],
                operationId: $operationId,
                source: $source,
            ));

            return $locked->load(['product', 'supplier']);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(Vaccine $vaccine, Product $product): array
    {
        return [
            'public_id' => $vaccine->public_id,
            'product_id' => $vaccine->product_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'supplier_id' => $vaccine->supplier_id,
            'supplier_name_snapshot' => $vaccine->supplier_name_snapshot,
            'description' => $vaccine->description,
            'details' => $vaccine->details,
        ];
    }
}
