<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class ListEggDonationUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('egg-stock.view') ?? false) || ($this->user()?->can('egg-stock.move') ?? false);
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:160'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']];
    }
}
