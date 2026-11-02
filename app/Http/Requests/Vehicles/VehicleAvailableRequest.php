<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleAvailableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('can_start_deliveries') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:120']];
    }
}
