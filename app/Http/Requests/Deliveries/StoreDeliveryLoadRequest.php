<?php

namespace App\Http\Requests\Deliveries;

use App\Models\Deliveries\DeliveryLoad;
use Illuminate\Validation\Rule;

final class StoreDeliveryLoadRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.update-own')
            && $this->delivery()->driver_id === $this->user()?->getKey();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (! $this->has('production_unit_id')) {
            $replayed = DeliveryLoad::query()->where('idempotency_key', $this->header('Idempotency-Key'))->where('delivery_id', $this->delivery()->getKey())->first();
            if ($replayed !== null) {
                $this->merge(['production_unit_id' => $replayed->production_unit_id]);
            }
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'production_unit_id' => ['required', 'integer', 'exists:production_units,id'],
            'quantity' => ['required_without:items', 'prohibits:items', 'integer', 'min:1', 'max:2147483647'],
            'items' => ['required_without:quantity', 'prohibits:quantity', 'array', 'min:1', 'max:20'],
            'items.*' => ['array:unit,amount,eggs_per_unit'],
            'items.*.unit' => ['required', Rule::exists('egg_presentations', 'code')],
            'items.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,3})?$/D'],
            'items.*.eggs_per_unit' => ['required', 'integer', 'min:1'],
        ];
    }
}
