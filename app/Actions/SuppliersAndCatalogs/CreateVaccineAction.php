<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Exceptions\SuppliersAndCatalogs\VaccineConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class CreateVaccineAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private CreateProductAction $createProduct,
    ) {}

    /** @param array{sku: string, name: string, description: string, details?: string|null, supplier_id: int, base_unit?: string|null, idempotency_key: string} $attributes */
    public function execute(array $attributes, User $actor, string $source = 'api'): Vaccine
    {
        Gate::forUser($actor)->authorize('create', Vaccine::class);
        $sku = trim($attributes['sku']);
        $name = trim($attributes['name']);
        $description = trim($attributes['description']);
        $details = array_key_exists('details', $attributes) && $attributes['details'] !== null ? trim($attributes['details']) : null;
        $unit = $attributes['base_unit'] ?? null;
        if ($sku === '' || mb_strlen($sku) > 80 || $name === '' || mb_strlen($name) > 160 || $description === '' || mb_strlen($description) > 5000 || ($details !== null && mb_strlen($details) > 5000) || $attributes['supplier_id'] < 1 || ! Str::isUuid($attributes['idempotency_key']) || ($unit !== null && BaseUnit::tryFrom($unit) === null)) {
            throw new VaccineConflict('La vacuna requiere SKU, nombre, descripción, proveedor y una clave de idempotencia válidos.');
        }

        $key = Str::lower($attributes['idempotency_key']);
        $hash = hash('sha256', json_encode([
            'sku' => $sku,
            'name' => $name,
            'description' => $description,
            'details' => $details,
            'supplier_id' => $attributes['supplier_id'],
            'base_unit' => $unit,
        ], JSON_THROW_ON_ERROR));
        $operationId = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($actor, $source, $sku, $name, $description, $details, $unit, $key, $hash, $attributes, $operationId): Vaccine {
                $currentActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
                if ($currentActor === null) {
                    throw new AuthorizationException;
                }
                Gate::forUser($currentActor)->authorize('create', Vaccine::class);

                $replay = Vaccine::query()->where('created_by', $currentActor->getKey())->where('idempotency_key', $key)->first();
                if ($replay !== null) {
                    if ($replay->request_hash !== $hash) {
                        throw new VaccineConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                    }

                    return $replay->load(['product', 'supplier']);
                }

                $supplier = Supplier::query()->whereKey($attributes['supplier_id'])->sharedLock()->firstOrFail();
                if ($supplier->status !== SupplierStatus::Active) {
                    throw new VaccineConflict('El proveedor debe estar activo para registrar una vacuna.');
                }

                $product = Product::query()->where('sku', $sku)->lockForUpdate()->first();
                if ($product === null) {
                    $product = $this->createProduct->execute([
                        'sku' => $sku,
                        'name' => $name,
                        'kind' => ProductKind::Vaccine->value,
                        'base_unit' => $unit ?? BaseUnit::Dose->value,
                        'stock_tracked' => true,
                        'status' => ProductStatus::Active->value,
                    ], $currentActor, $operationId, $source);
                } else {
                    if ($product->status !== ProductStatus::Active || $product->kind !== ProductKind::Vaccine || ! $product->stock_tracked) {
                        throw new VaccineConflict('El SKU indicado debe corresponder a un producto vacuna activo que controle stock.');
                    }
                    if (Str::lower(trim($product->name)) !== Str::lower($name)) {
                        throw new VaccineConflict('El nombre no coincide con el producto identificado por el SKU.');
                    }
                    if ($unit !== null && $product->base_unit->value !== $unit) {
                        throw new VaccineConflict('La unidad base no coincide con el producto identificado por el SKU.');
                    }
                }

                if (Vaccine::query()->where('product_id', $product->getKey())->exists()) {
                    throw new VaccineConflict('El producto indicado ya tiene una ficha de vacuna.');
                }

                $vaccine = new Vaccine;
                $vaccine->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'product_id' => $product->getKey(),
                    'supplier_id' => $supplier->getKey(),
                    'description' => $description,
                    'details' => $details,
                    'supplier_name_snapshot' => $supplier->name,
                    'created_by' => $currentActor->getKey(),
                    'created_by_name' => $currentActor->name,
                    'operation_id' => $operationId,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ])->save();
                $snapshot = $this->snapshot($vaccine, $product);
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $vaccine,
                    actor: $currentActor,
                    logName: 'suppliers_and_catalogs',
                    event: 'vaccine_created',
                    description: 'Vacuna registrada',
                    properties: ['subject_snapshot' => $snapshot, 'result' => 'created'],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                    operationId: $vaccine->operation_id,
                    source: $source,
                ));

                return $vaccine->load(['product', 'supplier']);
            }, 3);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23505') {
                throw new VaccineConflict('El SKU, nombre o producto ya está asociado a otra vacuna.', previous: $exception);
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Vaccine $vaccine, Product $product): array
    {
        return [
            'public_id' => $vaccine->public_id,
            'product_id' => $vaccine->product_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'base_unit' => $product->base_unit->value,
            'status' => $product->status->value,
            'supplier_id' => $vaccine->supplier_id,
            'supplier_name_snapshot' => $vaccine->supplier_name_snapshot,
            'description' => $vaccine->description,
            'details' => $vaccine->details,
        ];
    }
}
