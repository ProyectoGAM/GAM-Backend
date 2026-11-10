<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class ListEggDonationUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('can_view_egg_stock') ?? false) || ($this->user()?->can('can_move_egg_stock') ?? false);
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:160'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']];
    }
}
