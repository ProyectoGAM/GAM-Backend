<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Foundation\Http\FormRequest;

final class ListReferenceOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
