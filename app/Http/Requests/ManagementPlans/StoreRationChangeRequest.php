<?php

namespace App\Http\Requests\ManagementPlans;

final class StoreRationChangeRequest extends ManagementExecutionRequest
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
            'ration_description' => ['required', 'string', 'min:1', 'max:5000'],
            'finished_feed_product_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        foreach (['responsible_user_id', 'finished_feed_product_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }
}
