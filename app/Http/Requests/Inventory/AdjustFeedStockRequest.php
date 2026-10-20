<?php

namespace App\Http\Requests\Inventory;

final class AdjustFeedStockRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'accion' => ['required', 'in:sumar,restar,establecer'],
            'valor_g' => $this->stockGramRules($this->input('accion') !== 'establecer', $this->route('product') === null),
            'motivo' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
