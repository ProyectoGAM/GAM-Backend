<?php

namespace App\Http\Requests\Inventory;

final class ChangeFlockFeedingRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'receta_id' => ['required_without:cantidad_diaria_g', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'cantidad_diaria_g' => ['required_without:receta_id', ...array_slice($this->stockGramRules(true, false), 2)],
        ];
    }
}
