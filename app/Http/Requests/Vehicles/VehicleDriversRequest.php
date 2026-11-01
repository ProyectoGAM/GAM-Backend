<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleDriversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicles.manage') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
