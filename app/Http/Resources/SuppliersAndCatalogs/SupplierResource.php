<?php

namespace App\Http\Resources\SuppliersAndCatalogs;

use App\Http\Resources\Geography\LocalityResource;
use App\Models\SuppliersAndCatalogs\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
final class SupplierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'nombre' => $this->name,
            'direccion' => $this->address,
            'estado' => $this->status->value,
            'localidad' => LocalityResource::make($this->whenLoaded('locality')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
