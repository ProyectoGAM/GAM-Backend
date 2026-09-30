<?php

namespace App\Http\Requests\ManagementPlans;

final class CorrectManagementExecutionRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        return $this->authorizeFlockExecution();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
