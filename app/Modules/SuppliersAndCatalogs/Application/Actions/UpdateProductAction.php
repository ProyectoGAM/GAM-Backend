<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Actions;

use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\SuppliersAndCatalogs\Domain\Exceptions\SuppliersAndCatalogsConflict;
use Illuminate\Support\Facades\DB;

final readonly class UpdateProductAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{sku?: string, name?: string, kind?: string, base_unit?: string, stock_tracked?: bool} $attributes */
    public function execute(Product $product, array $attributes, User $actor): Product
    {
        return DB::transaction(function () use ($product, $attributes, $actor): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
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

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'suppliers_and_catalogs',
                event: 'product_updated',
                description: 'Producto actualizado',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                source: 'api',
            ));

            return $locked;
        });
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
