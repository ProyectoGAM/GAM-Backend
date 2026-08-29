<?php

namespace App\Modules\Inventory\Http\Requests;

final class ReleaseStockReservationRequest extends InventoryCommandRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('release', $this->route('stockReservation')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.reservation_line_id' => ['required', 'integer', 'exists:stock_reservation_lines,id'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/'],
        ];
    }
}
