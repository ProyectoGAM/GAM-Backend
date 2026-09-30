<?php

namespace App\Http\Requests\Deliveries;

use App\Models\Deliveries\Delivery;

final class ShowDeliveryRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $delivery = $this->route('reparto');

        if ($actor === null || ! $delivery instanceof Delivery) {
            return false;
        }

        return $actor->can('delivery.monitor')
            || $actor->can('delivery.history')
            || ($delivery->driver_id === $actor->getKey() && $actor->can('delivery.view-own'));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
