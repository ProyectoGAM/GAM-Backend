<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\StockReservation;
use App\Modules\Inventory\Domain\Enums\StockReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListStockReservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', StockReservation::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'estado' => ['sometimes', Rule::enum(StockReservationStatus::class)],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
