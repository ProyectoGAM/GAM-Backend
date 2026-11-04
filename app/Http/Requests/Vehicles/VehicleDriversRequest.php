<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleDriversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('can_manage_vehicles') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
