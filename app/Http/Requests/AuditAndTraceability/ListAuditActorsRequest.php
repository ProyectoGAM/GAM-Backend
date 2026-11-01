<?php

namespace App\Http\Requests\AuditAndTraceability;

use Illuminate\Foundation\Http\FormRequest;

final class ListAuditActorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('audit.view') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'string', 'min:1', 'max:100'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
