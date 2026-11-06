<?php

namespace App\Http\Requests\Inventory;

final class ConfirmManualFeedingRequest extends FeedOperationRequest
{
    protected string $permissionAbility = 'manageFeeding';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'configuracion_id' => $this->identifierRules(required: true),
        ];
    }
}
