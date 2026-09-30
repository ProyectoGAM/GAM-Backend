<?php

namespace App\Actions\SuppliersAndCatalogs;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CreateProductAction
{
    /** @var list<string> */
    private const CREATABLE_ATTRIBUTES = ['sku', 'name', 'kind', 'base_unit', 'stock_tracked', 'status'];

    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{sku: string, name: string, kind: string, base_unit: string, stock_tracked?: bool, status?: string} $attributes */
    public function execute(array $attributes, User $actor, ?string $operationId = null, string $source = 'api'): Product
    {
        Gate::forUser($actor)->authorize('create', Product::class);
        $this->assertAllowedAttributes($attributes);
        $attributes = $this->normalizeRawMaterialAttributes($attributes);
        $this->assertRawMaterialAttributes($attributes);

        return DB::transaction(function () use ($attributes, $actor, $operationId, $source): Product {
            $product = Product::query()->create([
                ...$attributes,
                'status' => ProductStatus::from($attributes['status'] ?? ProductStatus::Active->value),
            ]);
            $snapshot = $this->snapshot($product);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $product,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'product_created',
                description: 'Producto creado',
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                operationId: $operationId,
                source: $source,
            ));

            return $product;
        });
    }

    /**
     * Completa los valores invariables de una materia prima cuando el cliente omite el valor por defecto.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeRawMaterialAttributes(array $attributes): array
    {
        if (($attributes['kind'] ?? null) === ProductKind::RawMaterial->value
            && ! array_key_exists('stock_tracked', $attributes)) {
            $attributes['stock_tracked'] = true;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    private function assertAllowedAttributes(array $attributes): void
    {
        $unsupported = array_values(array_diff(array_keys($attributes), self::CREATABLE_ATTRIBUTES));
        if ($unsupported !== []) {
            throw new SuppliersAndCatalogsConflict('Los campos '.implode(', ', $unsupported).' no están permitidos al crear un producto.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertRawMaterialAttributes(array $attributes): void
    {
        if (($attributes['kind'] ?? null) !== ProductKind::RawMaterial->value) {
            return;
        }

        if (($attributes['base_unit'] ?? null) !== BaseUnit::Gram->value) {
            throw new SuppliersAndCatalogsConflict('Las materias primas deben usar gramos como unidad base.');
        }
        if (array_key_exists('stock_tracked', $attributes) && ! (bool) $attributes['stock_tracked']) {
            throw new SuppliersAndCatalogsConflict('Las materias primas deben controlar stock.');
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
}
