<?php

namespace App\Http\Requests\Inventory;

final class ManufactureFeedRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'receta_id' => $this->identifierRules(true), 'planta_id' => $this->identifierRules(true),
            'cantidad_g' => $this->stockGramRules(), 'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
