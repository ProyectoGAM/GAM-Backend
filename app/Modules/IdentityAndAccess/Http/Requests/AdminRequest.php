<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.dashboard.view') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
