<?php

namespace App\Http\Requests\Inventory;

final class CancelFeedManufactureRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid']];
    }
}
