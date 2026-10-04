<?php

namespace App\Http\Requests\Deliveries;

final class CurrentDeliveriesRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null
            && ($actor->can('delivery.monitor') || $actor->can('delivery.view-own'));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'production_unit_id' => ['sometimes', 'integer', 'exists:production_units,id'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = $this->validated();
        if (isset($filters['production_unit_id'])) {
            $filters['production_unit_id'] = (int) $filters['production_unit_id'];
        }

        $actor = $this->user();

        if ($actor !== null && ! $actor->can('delivery.monitor')) {
            $filters['driver_id'] = $actor->getKey();
        }

        return $filters;
    }
}
