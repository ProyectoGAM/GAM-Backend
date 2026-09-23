<?php

namespace App\Http\Requests\ManagementPlans;

final class StoreMedicineApplicationRequest extends ManagementExecutionRequest
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
            ...$this->activityLinkRules(),
            'medicine_id' => ['required', 'ulid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        foreach (['responsible_user_id', 'quantity'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }
}
