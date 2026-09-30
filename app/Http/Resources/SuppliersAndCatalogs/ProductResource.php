<?php

namespace App\Http\Resources\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $vaccine = $product->relationLoaded('vaccine') ? $product->getRelation('vaccine') : null;
        $systemManaged = $product->system_key === 'generic_egg';
        $specializedOwner = match (true) {
            $systemManaged => ['type' => 'egg_stock'],
            $vaccine instanceof Vaccine => ['type' => 'vaccine', 'id' => $vaccine->public_id],
            default => null,
        };

        return [
            'id' => (int) $product->getKey(),
            'sku' => $product->sku,
            'name' => $product->name,
            'kind' => $product->kind->value,
            'base_unit' => $product->base_unit->value,
            'stock_tracked' => $product->stock_tracked,
            'status' => $product->status->value,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
            'system_managed' => $systemManaged,
            'specialized_owner' => $specializedOwner,
            'capabilities' => $this->capabilities($request, $product, $systemManaged || $vaccine instanceof Vaccine),
        ];
    }

    /**
     * @return array{editable_fields: list<string>, activate: bool, deactivate: bool}
     */
    private function capabilities(Request $request, Product $product, bool $hasSpecializedOwner): array
    {
        $actor = $request->user();
        $canUpdate = ! $hasSpecializedOwner && ($actor?->can('update', $product) ?? false);
        $canChangeStatus = ! $hasSpecializedOwner && ($actor?->can('changeStatus', $product) ?? false);

        $editableFields = $canUpdate ? ['sku', 'name', 'kind', 'base_unit', 'stock_tracked'] : [];
        if ($product->has_movement_lines) {
            $editableFields = array_values(array_diff($editableFields, ['base_unit']));
        }
        if ($product->kind === ProductKind::RawMaterial && ($product->has_movement_lines || $product->has_stock_balances)) {
            $editableFields = array_values(array_diff($editableFields, ['kind', 'base_unit', 'stock_tracked']));
        }

        return [
            'editable_fields' => $editableFields,
            'activate' => $canChangeStatus && $product->status === ProductStatus::Inactive,
            'deactivate' => $canChangeStatus && $product->status === ProductStatus::Active,
        ];
    }
}
