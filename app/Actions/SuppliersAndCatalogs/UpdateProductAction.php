<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class UpdateProductAction
{
    /** @var list<string> */
    private const UPDATABLE_ATTRIBUTES = ['sku', 'name', 'kind', 'base_unit', 'stock_tracked'];

    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{sku?: string, name?: string, kind?: string, base_unit?: string, stock_tracked?: bool} $attributes */
    public function execute(Product $product, array $attributes, User $actor, ?string $operationId = null, string $source = 'api', bool $recordVaccineAudit = true): Product
    {
        $this->assertAllowedAttributes($attributes);
        $auditOperationId = $operationId ?? (string) Str::uuid();

        try {
            return DB::transaction(function () use ($product, $attributes, $actor, $auditOperationId, $source, $recordVaccineAudit): Product {
                $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $locked);
                $vaccine = Vaccine::query()->where('product_id', $locked->getKey())->lockForUpdate()->first();
                if ($vaccine !== null && (! $actor->hasRole('admin') || $actor->trashed())) {
                    throw new AuthorizationException;
                }
                if ($vaccine !== null && array_key_exists('sku', $attributes) && trim((string) $attributes['sku']) !== $locked->sku) {
                    throw new SuppliersAndCatalogsConflict('El SKU de una vacuna vinculada no puede modificarse.');
                }
                if ($vaccine !== null && array_key_exists('kind', $attributes) && (string) $attributes['kind'] !== 'vaccine') {
                    throw new SuppliersAndCatalogsConflict('El tipo de una vacuna vinculada debe permanecer como vaccine.');
                }
                if ($vaccine !== null && array_key_exists('stock_tracked', $attributes) && ! (bool) $attributes['stock_tracked']) {
                    throw new SuppliersAndCatalogsConflict('La vacuna vinculada debe controlar stock.');
                }
                if ($locked->system_key === 'generic_egg' && array_intersect(array_keys($attributes), ['sku', 'name', 'kind', 'base_unit', 'stock_tracked'])) {
                    throw new SuppliersAndCatalogsConflict('El producto técnico Huevo está protegido por el módulo de stock de huevos.');
                }
                $before = $this->snapshot($locked);
                if (
                    array_key_exists('base_unit', $attributes)
                    && (string) $attributes['base_unit'] !== $locked->base_unit->value
                    && $locked->movementLines()->exists()
                ) {
                    throw new SuppliersAndCatalogsConflict('La unidad base no puede cambiarse después del primer movimiento.');
                }
                $locked->fill($attributes)->save();
                $after = $this->snapshot($locked);

                if ($before === $after) {
                    return $locked;
                }

                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $locked,
                    actor: $actor,
                    logName: 'suppliers_and_catalogs',
                    event: 'product_updated',
                    description: 'Producto actualizado',
                    properties: ['subject_snapshot' => $after],
                    attributeChanges: ['old' => $before, 'new' => $after],
                    operationId: $auditOperationId,
                    source: $source,
                ));

                if ($vaccine !== null && $recordVaccineAudit) {
                    $vaccine->touch();
                    $this->recordVaccineAudit($vaccine, $actor, $before, $after, $auditOperationId, $source);
                }

                return $locked;
            });
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23505'
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'products_normalized_name_unique')) {
                throw new SuppliersAndCatalogsConflict('El nombre del producto ya está registrado.');
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertAllowedAttributes(array $attributes): void
    {
        $unsupported = array_values(array_diff(array_keys($attributes), self::UPDATABLE_ATTRIBUTES));
        if ($unsupported !== []) {
            throw new SuppliersAndCatalogsConflict('Los campos '.implode(', ', $unsupported).' no están permitidos al actualizar un producto.');
        }
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

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function recordVaccineAudit(Vaccine $vaccine, User $actor, array $before, array $after, string $operationId, string $source): void
    {
        $snapshot = [
            'public_id' => $vaccine->public_id,
            'product_id' => $vaccine->product_id,
            'supplier_id' => $vaccine->supplier_id,
            'sku' => $after['sku'],
            'name' => $after['name'],
            'kind' => $after['kind'],
            'base_unit' => $after['base_unit'],
            'stock_tracked' => $after['stock_tracked'],
            'status' => $after['status'],
            'description' => $vaccine->description,
            'details' => $vaccine->details,
        ];
        $this->auditRecorder->record(AuditEntryData::forSubject(
            subject: $vaccine,
            actor: $actor,
            logName: 'suppliers_and_catalogs',
            event: 'vaccine_updated',
            description: 'Vacuna actualizada mediante Producto',
            properties: ['subject_snapshot' => $snapshot, 'result' => 'updated'],
            attributeChanges: ['old' => $before, 'new' => $after],
            operationId: $operationId,
            source: $source,
        ));
    }
}
