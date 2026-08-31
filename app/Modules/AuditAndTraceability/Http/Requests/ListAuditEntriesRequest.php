<?php

namespace App\Modules\AuditAndTraceability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListAuditEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('audit.view') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'log_name' => ['sometimes', 'string', 'max:100'],
            'event' => ['sometimes', 'string', 'max:100'],
            'operation_id' => ['sometimes', 'uuid'],
            'trace_id' => ['sometimes', 'uuid'],
            'source' => ['sometimes', 'string', 'max:50'],
            'actor_type' => ['sometimes', 'string', 'max:255'],
            'actor_id' => ['sometimes', 'integer'],
            'subject_type' => ['sometimes', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'integer'],
            'up_id' => ['sometimes', 'integer'],
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
