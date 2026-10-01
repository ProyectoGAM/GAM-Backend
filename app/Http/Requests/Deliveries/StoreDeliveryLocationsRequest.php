<?php

namespace App\Http\Requests\Deliveries;

final class StoreDeliveryLocationsRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.location.publish')
            && $this->delivery()->driver_id === $this->user()?->getKey();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['sometimes', 'nullable', 'uuid'],
            'locations' => ['required', 'array', 'min:1', 'max:100'],
            'locations.*.client_event_id' => ['required', 'uuid', 'distinct'],
            'locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'locations.*.accuracy' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'locations.*.speed' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'locations.*.captured_at' => ['required', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = $this->validated();
        $data['locations'] = array_map(static function (array $location): array {
            foreach (['latitude', 'longitude', 'accuracy', 'speed'] as $key) {
                if (isset($location[$key])) {
                    $location[$key] = (float) $location[$key];
                }
            }

            return $location;
        }, $data['locations']);

        return $data;
    }
}
