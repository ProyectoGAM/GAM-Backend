<?php

namespace App\Http\Requests\Inventory;

final class ReturnFlockFeedingToAutomaticRequest extends FeedOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'uuid']];
    }
}
