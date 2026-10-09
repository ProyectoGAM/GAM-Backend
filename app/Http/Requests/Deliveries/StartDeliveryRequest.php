<?php

namespace App\Http\Requests\Deliveries;

use App\Models\Deliveries\Delivery;
use Illuminate\Validation\Rule;

final class StartDeliveryRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.start') ?? false;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (! $this->has('production_unit_id')) {
            $replayed = Delivery::query()->where('start_idempotency_key', $this->header('Idempotency-Key'))->where('driver_id', $this->user()?->getKey())->first();
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
            'pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D'],
            'production_unit_id' => ['required', 'integer', 'exists:production_units,id'],
            'quantity' => ['required_without:items', 'prohibits:items', 'integer', 'min:1', 'max:2147483647'],
            'items' => ['required_without:quantity', 'prohibits:quantity', 'array', 'min:1', 'max:20'],
            'items.*' => ['array:unit,amount,eggs_per_unit'],
            'items.*.unit' => ['required', Rule::exists('egg_presentations', 'code')],
            'items.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,3})?$/D'],
            'items.*.eggs_per_unit' => ['required', 'integer', 'min:1'],
            'vehicle_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        unset($data['pin']);
        if (isset($data['production_unit_id'])) {
            $data['production_unit_id'] = (int) $data['production_unit_id'];
        }
        if (isset($data['quantity'])) {
            $data['quantity'] = (int) $data['quantity'];
        }

        return $data;
    }
}
