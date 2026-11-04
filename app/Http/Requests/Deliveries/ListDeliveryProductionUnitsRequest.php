<?php

namespace App\Http\Requests\Deliveries;

final class ListDeliveryProductionUnitsRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('can_view_own_deliveries') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:100'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:1000']];
    }
}
