<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\FeedStockOperation;

final class AdjustFeedStockRequest extends FeedOperationRequest
{
    public function authorize(): bool
    {
        $ability = $this->route('product') !== null ? 'adjustIngredient' : 'adjustFeed';

        return $this->user()?->can($ability, FeedStockOperation::class) ?? false;
    }

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
