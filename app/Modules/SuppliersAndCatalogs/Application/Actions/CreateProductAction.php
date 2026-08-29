<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Actions;

use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use Illuminate\Support\Facades\DB;

final readonly class CreateProductAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{sku: string, name: string, kind: string, base_unit: string, stock_tracked?: bool, status?: string} $attributes */
    public function execute(array $attributes, User $actor): Product
    {
        return DB::transaction(function () use ($attributes, $actor): Product {
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
                source: 'api',
            ));

            return $product;
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
