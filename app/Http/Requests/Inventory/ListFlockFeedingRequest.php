<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Validation\Rule;

final class ListFlockFeedingRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->has('desde'), 'after_or_equal:desde')],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
