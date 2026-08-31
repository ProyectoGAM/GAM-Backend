<?php

namespace App\Modules\Inventory\Http\Requests;

final class ConsumeStockReservationRequest extends InventoryCommandRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('consume', $this->route('stockReservation')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'lineas' => ['required', 'array', 'min:1', 'max:100'],
            'lineas.*.linea_reserva_id' => ['required', 'integer', 'exists:stock_reservation_lines,id'],
            'lineas.*.cantidad' => ['required', 'string', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/'],
        ];
    }
}
