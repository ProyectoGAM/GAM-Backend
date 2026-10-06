<?php

namespace App\Http\Resources\Inventory;

use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggPresentationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'label' => $this->name,
            'category' => $this->category,
            'eggs_per_unit' => $this->eggs_per_unit,
            'default_unit_price' => $this->default_unit_price,
            'currency' => Money::CURRENCY,
        ];
    }
}
