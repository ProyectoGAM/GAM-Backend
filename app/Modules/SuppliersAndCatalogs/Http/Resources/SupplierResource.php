<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Resources;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Modules\Geography\Http\Resources\LocalityResource;
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
            'name' => $this->name,
            'address' => $this->address,
            'status' => $this->status->value,
            'locality' => LocalityResource::make($this->whenLoaded('locality')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
