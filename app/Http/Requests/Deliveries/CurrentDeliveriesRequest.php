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
        return ['per_page' => ['sometimes', 'integer', 'between:1,100']];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = $this->validated();
        $actor = $this->user();

        if ($actor !== null && ! $actor->can('delivery.monitor')) {
            $filters['driver_id'] = $actor->getKey();
        }

        return $filters;
    }
}
