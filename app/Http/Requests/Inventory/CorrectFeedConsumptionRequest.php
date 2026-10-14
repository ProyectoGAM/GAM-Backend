<?php

namespace App\Http\Requests\Inventory;

final class CorrectFeedConsumptionRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'receta_id' => ['required_without:cantidad_g', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'cantidad_g' => ['required_without:receta_id', ...array_slice($this->stockGramRules(false, false), 2)],
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
