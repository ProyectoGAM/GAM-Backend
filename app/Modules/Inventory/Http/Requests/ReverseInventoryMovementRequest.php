<?php

namespace App\Modules\Inventory\Http\Requests;

final class ReverseInventoryMovementRequest extends InventoryCommandRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reverse', $this->route('inventoryMovement')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
